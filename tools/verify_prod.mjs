/**
 * Diagnóstico da produção (https://extracthunters.com.br). Só leitura: não envia nada.
 *
 *   node tools/verify_prod.mjs
 *   node tools/verify_prod.mjs --base http://localhost/extracthunters   (compara com o local)
 *
 * Sai com código 2 se algum caso falhar.
 */
const args = process.argv.slice(2);
const i = args.indexOf('--base');
const BASE = (i >= 0 ? args[i + 1] : 'https://extracthunters.com.br').replace(/\/$/, '');

let falhas = 0;
const check = (nome, ok, detalhe = '') => {
  if (!ok) falhas++;
  console.log(`${ok ? 'OK  ' : 'FALHA'} ${nome}${detalhe ? ' — ' + detalhe : ''}`);
};

const pegar = async (caminho, opts = {}) => {
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), 20000);
  try {
    const r = await fetch(BASE + caminho, { redirect: 'manual', signal: ctrl.signal, ...opts });
    const corpo = opts.method === 'HEAD' ? '' : await r.text();
    return { status: r.status, headers: r.headers, corpo };
  } catch (e) {
    return { status: 0, headers: new Headers(), corpo: '', erro: e.message };
  } finally { clearTimeout(t); }
};

console.log(`alvo: ${BASE}\n`);

// ---- 1) rotas amigáveis: é aqui que um RewriteBase errado aparece ----
const ROTAS = ['login', 'inicio', 'inventario', 'jogar', 'hud', 'mapa', 'loja'];
const amigaveis = [];
for (const r of ROTAS) {
  const { status } = await pegar('/' + r);
  amigaveis.push(`${r}=${status}`);
}
const okAmigaveis = amigaveis.every((x) => /=(200|30\d)$/.test(x));
check('rotas amigáveis respondem', okAmigaveis, amigaveis.join(' '));

// ---- 2) as mesmas rotas por index.php (isolam "é o rewrite?" de "é o PHP?") ----
const viaIndex = [];
for (const r of ROTAS.slice(0, 3)) {
  const { status } = await pegar('/index.php/' + r);
  viaIndex.push(`${r}=${status}`);
}
const okIndex = viaIndex.every((x) => x.endsWith('=200'));
check('rotas por /index.php respondem', okIndex, viaIndex.join(' '));

if (!okAmigaveis && okIndex) {
  console.log('\n  >> O PHP está de pé e o rewrite NÃO. Sintoma clássico de RewriteBase');
  console.log('     fixo no .htaccess apontando para uma pasta que não existe no servidor.\n');
}

// ---- 3) a raiz redireciona para o login ----
const raiz = await pegar('/');
check('raiz redireciona para /login',
  [301, 302, 303, 307].includes(raiz.status) && (raiz.headers.get('location') || '').includes('login'),
  `HTTP ${raiz.status} -> ${raiz.headers.get('location') || '(sem Location)'}`);

// ---- 4) nada sensível servido ----
const SENSIVEIS = ['.env', 'app/routes.php', 'app/db.local.php', 'db/schema.sql',
  'db/catalog.php', 'docs/DB-NOTES.md', 'tools/deploy.mjs', '.git/config'];
const vazados = [];
for (const p of SENSIVEIS) {
  const { status } = await pegar('/' + p);
  if (status === 200) vazados.push(p);
}
check('arquivos sensíveis bloqueados', vazados.length === 0,
  vazados.length ? 'VAZANDO: ' + vazados.join(', ') : `${SENSIVEIS.length} caminhos checados`);

// ---- 5) assets estáticos ----
const css = await pegar('/assets/css/tokens.css');
check('assets estáticos servindo', css.status === 200 && css.corpo.includes('--c-'), `HTTP ${css.status}`);

// ---- 6) cabeçalhos de segurança ----
const h = raiz.headers;
const faltando = ['content-security-policy', 'x-content-type-options', 'x-frame-options',
  'referrer-policy'].filter((n) => !h.get(n));
check('cabeçalhos de segurança presentes', faltando.length === 0,
  faltando.length ? 'faltando: ' + faltando.join(', ') : 'CSP, nosniff, DENY, referrer');

// ---- 7) o banco está conectado? (a tela de início só mostra isso vindo do MySQL) ----
const inicio = await pegar('/index.php/inicio');
const temDados = /TEMPORADA\s*\d+/i.test(inicio.corpo) && /season__hex/.test(inicio.corpo);
check('banco conectado (dados dinâmicos na tela)', temDados,
  temDados ? (inicio.corpo.match(/TEMPORADA\s*\d+/i) || [''])[0] : 'nenhum dado de temporada na página');

// ---- 8) nenhum erro de PHP vazando ----
const erroPhp = /(Fatal error|Parse error|Warning:|Notice:|SQLSTATE|Uncaught)/i.exec(inicio.corpo);
check('sem erro de PHP na resposta', !erroPhp, erroPhp ? erroPhp[0] : '');

console.log(falhas === 0 ? '\nProdução OK.' : `\n${falhas} problema(s).`);
process.exit(falhas ? 2 : 0);
