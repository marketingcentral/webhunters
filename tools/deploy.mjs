/**
 * Deploy do Extract Hunters para produção (https://extracthunters.com.br) via FTP.
 *
 *   node tools/deploy.mjs --dry-run          lista o que subiria, sem enviar nada
 *   node tools/deploy.mjs                    sobe só o que mudou (compara tamanho+data)
 *   node tools/deploy.mjs --only assets/css  restringe a um subcaminho
 *   node tools/deploy.mjs --force            reenvia tudo, ignorando a comparação
 *   node tools/deploy.mjs --ls app           lista um diretório remoto e sai
 *   node tools/deploy.mjs --clean            remove do servidor o que não é de produção
 *
 * Credenciais vêm de .env (FTP_IP / FTP_USER / FTP_PASS) e NUNCA são impressas.
 *
 * A conta FTP cai no HOME do cPanel, não no docroot: tudo é resolvido a partir de
 * REMOTO_RAIZ ('public_html'). Use --remote-root para apontar para outro lugar.
 *
 * O que NÃO sobe (ver NEGAR): .env, .git, tools/, captures/, db/, docs/, node_modules,
 * as referências de arte. O servidor tem o .env dele.
 */
import { Client } from 'basic-ftp';
import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { join, relative, posix, sep } from 'node:path';

const RAIZ = 'C:/wamp64/www/extracthunters';
const args = process.argv.slice(2);
const flag = (n) => args.includes(n);
const valor = (n) => { const i = args.indexOf(n); return i >= 0 ? args[i + 1] : null; };

const DRY = flag('--dry-run');
const FORCE = flag('--force');
const CLEAN = flag('--clean');
const ONLY = valor('--only');
const LS = valor('--ls');

/**
 * Onde a aplicação vive, a partir de onde o FTP cai.
 *
 * Host atual (aaPanel + OpenLiteSpeed): a conta FTP cai no docroot do site
 * (/www/wwwroot/extracthunters.com.br) e a aplicação vive na subpasta `unreal` — que é o
 * que a URL https://extracthunters.com.br/unreal/ reflete. A raiz do FTP tem só `unreal`
 * e `.user.ini`.
 *
 * No host anterior (cPanel) a conta caía no HOME e o docroot era `public_html`.
 *
 * CUIDADO ao conferir: `--ls ""` lista o REMOTO_RAIZ, não a raiz do FTP. Ver index.php e
 * app/ na saída não prova que eles estão na raiz — prova que estão no docroot que já está
 * configurado aqui. Para ver a raiz de verdade, use `--remote-root "" --ls ""`. Trocar
 * este valor por engano espalha ~500 arquivos na pasta errada.
 */
const REMOTO_RAIZ = (valor('--remote-root') ?? 'unreal').replace(/^\/|\/$/g, '');
const remotoDe = (rel) => (REMOTO_RAIZ ? `${REMOTO_RAIZ}/${rel}` : rel).replace(/\/+$/, '');

/**
 * Coisas que estão no servidor mas NÃO deveriam: a árvore de desenvolvimento inteira foi
 * publicada uma vez, incluindo dois .env com as credenciais de FTP e MySQL. Hoje o
 * .htaccess devolve 403 nelas, mas bloqueio de servidor é configuração — se o arquivo
 * for trocado ou o host mudar o AllowOverride, tudo vaza de uma vez. --clean remove.
 */
const LIMPAR = [
  '.env',
  'extracthunters',            // cópia antiga do site num subdiretório, com outro .env
  'tools',
  'captures',
  'db',
  'docs',
  'O-QUE-VAMOS-CRIAR',
  'SPEC.md',
];

/** Caminhos que nunca vão para produção. Testado contra o caminho relativo com "/". */
const NEGAR = [
  /^\.env$/,                       // o servidor tem o seu; subir o local vazaria o MySQL local
  /^\.git(\/|$)/,
  /^tools(\/|$)/,                  // scripts de dev, puppeteer, node_modules
  /^captures(\/|$)/,
  /^db\/backups(\/|$)/,
  /^O-QUE-VAMOS-CRIAR(\/|$)/,      // referências de arte, centenas de MB
  // Matéria-prima e etapas intermediárias do pipeline de arte. Nada no site, nas views
  // nem no banco aponta para cá (verificado: das 78 referências de imagem, 77 resolvem
  // em assets/img/ ou media/ e a única que não resolve está quebrada há tempos).
  // Juntas somam 118 MB dos 149 MB de assets/ — mais de dois terços do peso publicado,
  // e ficavam abertas por HTTP: rope.png sozinha eram 2 MB baixáveis em produção.
  /^assets\/img\/_gen(\/|$)/,      // saídas intermediárias (magenta, keyed) — 81,6 MB
  /^assets\/assets(\/|$)/,         // RAW do usuário, fonte do pipeline — 36,6 MB
  /^node_modules(\/|$)/,
  /(^|\/)\.DS_Store$/,
  /(^|\/)Thumbs\.db$/,
  /^\.claude(\/|$)/,
  /^app\/db\.local\.php$/,         // config de banco é POR AMBIENTE
  /^docs(\/|$)/,                   // documentação interna
  /\.md$/,                         // SPEC.md e afins
  /\.(bak|orig|log|tmp|fix)$/,     // sobras de edição (habilidades.css.fix já ia junto)
  /^nul$/,                         // artefato do Windows (redirect para NUL vira arquivo)
  // db/ inteiro: nada em app/ ou index.php inclui esses arquivos em runtime, então em
  // produção eles seriam só superfície de ataque. Já estiveram executáveis por HTTP lá
  // (https://.../db/migrate.php respondia 200 — um GET dropava o banco). Se algum dia
  // precisar rodar um patch no servidor, suba o script de propósito e apague depois.
  /^db(\/|$)/,
];

const env = (() => {
  const txt = readFileSync(join(RAIZ, '.env'), 'utf8');
  const o = {};
  for (const linha of txt.split(/\r?\n/)) {
    const m = linha.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/);
    if (m) o[m[1]] = m[2];
  }
  return o;
})();

for (const k of ['FTP_IP', 'FTP_USER', 'FTP_PASS']) {
  if (!env[k]) { console.error(`.env não tem ${k}`); process.exit(2); }
}

const negado = (rel) => NEGAR.some((re) => re.test(rel));

/** Todos os arquivos que devem existir em produção, como caminhos relativos com "/". */
function listarLocais(dir = RAIZ, out = []) {
  for (const nome of readdirSync(dir)) {
    const abs = join(dir, nome);
    const rel = relative(RAIZ, abs).split(sep).join('/');
    if (negado(rel)) continue;
    const st = statSync(abs);
    if (st.isDirectory()) listarLocais(abs, out);
    else out.push({ rel, abs, size: st.size, mtime: st.mtime });
  }
  return out;
}

const cliente = new Client(30000);
cliente.ftp.verbose = false;

/** Abre (ou reabre) a sessão. O host derruba o socket de controle em operações longas. */
async function conectar() {
  try {
    await cliente.access({
      host: env.FTP_IP, user: env.FTP_USER, password: env.FTP_PASS,
      secure: true, secureOptions: { rejectUnauthorized: false },
    });
  } catch {
    await cliente.access({ host: env.FTP_IP, user: env.FTP_USER, password: env.FTP_PASS, secure: false });
  }
}
const reconectar = async () => { try { cliente.close(); } catch { /* já fechado */ } await conectar(); };

/**
 * Remove uma pasta inteira sobrevivendo a queda de conexão.
 *
 * `removeDir()` de uma vez não aguenta árvores grandes (tools/node_modules tem milhares
 * de arquivos e o servidor fecha o socket de controle no meio). Aqui a remoção é feita
 * de baixo para cima, um diretório por vez, reconectando e retomando quando cai — como
 * apagar é idempotente, o que já sumiu é simplesmente pulado na retomada.
 */
async function removerPasta(caminho, rotulo, profundidade = 0) {
  for (let tentativa = 1; tentativa <= 6; tentativa++) {
    try {
      let itens;
      try { itens = await cliente.list(caminho); }
      catch { return; }                               // já não existe

      for (const item of itens) {
        const filho = `${caminho}/${item.name}`;
        if (item.isDirectory) await removerPasta(filho, `${rotulo}/${item.name}`, profundidade + 1);
        else await cliente.remove(filho);
      }
      await cliente.removeEmptyDir(caminho);
      if (profundidade === 0) process.stdout.write('');
      return;
    } catch (e) {
      if (tentativa === 6) throw e;
      process.stdout.write(`\n    (conexão caiu em ${rotulo}; reconectando e retomando ${tentativa}/5)`);
      await reconectar();
    }
  }
}

async function main() {
  await cliente.access({
    host: env.FTP_IP,
    user: env.FTP_USER,
    password: env.FTP_PASS,
    secure: true,
    secureOptions: { rejectUnauthorized: false },
  }).catch(async (e) => {
    // alguns hosts não têm FTPS; cai para FTP simples avisando
    console.warn('FTPS falhou (' + e.message + '); tentando FTP simples.');
    await cliente.access({ host: env.FTP_IP, user: env.FTP_USER, password: env.FTP_PASS, secure: false });
  });

  const home = await cliente.pwd();
  console.log(`conectado · home: ${home} · docroot: ${REMOTO_RAIZ || '(raiz)'}`);

  if (LS !== null) {
    const alvo = LS === '' ? (REMOTO_RAIZ || '.') : (LS.startsWith('/') ? LS.slice(1) : remotoDe(LS));
    for (const f of await cliente.list(alvo)) {
      const tipo = f.isDirectory ? 'dir ' : 'file';
      console.log(`  ${tipo} ${String(f.size).padStart(9)}  ${f.modifiedAt ? f.modifiedAt.toISOString().slice(0, 16) : ''}  ${f.name}`);
    }
    return;
  }

  if (CLEAN) {
    console.log('\nRemovendo do servidor o que não é de produção:\n');
    // Uma listagem do docroot resolve arquivo x pasta de todos de uma vez. Perguntar
    // item a item com list() é ambíguo: num arquivo o servidor devolve o próprio.
    const noDocroot = new Map();
    for (const f of await cliente.list(REMOTO_RAIZ || '.')) noDocroot.set(f.name, f.isDirectory);

    for (const alvo of LIMPAR) {
      const caminho = remotoDe(alvo);
      if (!noDocroot.has(alvo)) { console.log(`  (ausente)  ${alvo}`); continue; }
      const tipo = noDocroot.get(alvo) ? 'dir' : 'file';

      if (DRY) { console.log(`  [dry] remover ${tipo === 'dir' ? 'pasta  ' : 'arquivo'}  ${alvo}`); continue; }
      try {
        if (tipo === 'dir') await removerPasta(caminho, alvo);
        else await cliente.remove(caminho);
        console.log(`  removido  ${alvo}`);
      } catch (e) { console.log(`  FALHOU    ${alvo}: ${e.message.slice(0, 70)}`); }
      await reconectar();
      await cliente.cd(home);
    }
    console.log(DRY ? '\ndry-run: nada foi removido.' : '\nlimpeza concluída.');
    return;
  }

  let locais = listarLocais();
  if (ONLY) locais = locais.filter((f) => f.rel === ONLY || f.rel.startsWith(ONLY.replace(/\/$/, '') + '/'));
  if (locais.length === 0) { console.log('nada a enviar (--only não casou com nada)'); return; }

  // Índice do remoto por diretório, para comparar tamanho e não reenviar o que é igual.
  const remoto = new Map();
  if (!FORCE) {
    const dirs = [...new Set(locais.map((f) => posix.dirname(f.rel)))];
    for (const d of dirs) {
      try {
        for (const item of await cliente.list(remotoDe(d === '.' ? '' : d) || '.')) {
          if (!item.isDirectory) remoto.set(d === '.' ? item.name : `${d}/${item.name}`, item.size);
        }
      } catch { /* diretório ainda não existe lá */ }
    }
  }

  const enviar = FORCE ? locais : locais.filter((f) => remoto.get(f.rel) !== f.size);
  const iguais = locais.length - enviar.length;

  console.log(`${locais.length} arquivo(s) locais · ${iguais} já idêntico(s) · ${enviar.length} a enviar`);
  if (enviar.length === 0) { console.log('produção já está em dia.'); return; }

  const total = enviar.reduce((s, f) => s + f.size, 0);
  console.log(`total: ${(total / 1048576).toFixed(2)} MB\n`);

  if (DRY) {
    for (const f of enviar) console.log(`  [dry] ${String(f.size).padStart(9)}  ${f.rel}`);
    console.log('\ndry-run: nada foi enviado.');
    return;
  }

  // O host derruba o socket de controle em sessões longas. Sem retomada, um envio de
  // centenas de arquivos morre no meio e deixa a produção pela metade — que é pior do
  // que não ter começado.
  let n = 0;
  let falhas = 0;
  for (const f of enviar) {
    const dir = posix.dirname(f.rel);
    let ok = false;
    for (let tentativa = 1; tentativa <= 4 && !ok; tentativa++) {
      try {
        if (dir !== '.') { await cliente.ensureDir(remotoDe(dir)); await cliente.cd(home); }
        await cliente.uploadFrom(f.abs, remotoDe(f.rel));
        ok = true;
      } catch (e) {
        if (tentativa === 4) { console.log(`  FALHOU  ${f.rel}: ${e.message.slice(0, 60)}`); falhas++; break; }
        process.stdout.write(`\n    (conexão caiu em ${f.rel}; reconectando ${tentativa}/3)`);
        await reconectar();
      }
    }
    n++;
    if (n % 25 === 0 || n === enviar.length) console.log(`  ${n}/${enviar.length}`);
  }
  console.log(`\n${n - falhas} arquivo(s) enviado(s)${falhas ? `, ${falhas} falha(s)` : ''}.`);
  if (falhas) { process.exitCode = 1; }
}

main()
  .catch((e) => { console.error('ERRO: ' + e.message); process.exitCode = 1; })
  .finally(() => cliente.close());
