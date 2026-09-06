/**
 * Procura credenciais reais nos arquivos que o git levaria para o repositório.
 *
 * Lê os valores do .env e verifica se algum deles aparece em qualquer arquivo rastreável.
 * Também busca padrões genéricos (chaves de API, blocos de chave privada) que possam ter
 * escapado. NUNCA imprime o valor encontrado — só o arquivo, a linha e qual chave casou.
 *
 * Motivo de existir: segredo publicado no GitHub não se apaga. Remover num commit seguinte
 * não adianta — o histórico e os forks guardam cópia, e a credencial tem de ser trocada.
 * Vale mais um minuto de varredura do que uma rotação de senha de emergência.
 *
 * Uso: node tools/varrer_segredos.mjs
 * Sai com código 1 se achar qualquer coisa.
 */
import { execSync } from 'node:child_process';
import { readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

const RAIZ = 'C:/wamp64/www/extracthunters';

/*
 * Valores do .env que jamais podem aparecer em arquivo versionado.
 *
 * Nem tudo no .env é segredo: FTP_USER é "extracthunters" (o nome do projeto, que aparece
 * em centenas de linhas) e MYSQL_HOST é "localhost". Tratar esses como segredo enterra o
 * alarme de verdade em ruído — e um alarme ruidoso é um alarme ignorado. Então só entram:
 *
 *   - chaves cujo NOME diz que é segredo (PASS, KEY, SECRET, TOKEN, APIFY)
 *   - ou valores com cara de credencial: 12+ caracteres misturando caixa e dígito
 */
const ehNomeDeSegredo = (k) => /PASS|SENHA|KEY|SECRET|TOKEN|APIFY|CREDENTIAL/i.test(k);
const pareceCredencial = (v) =>
  v.length >= 12 && /[a-z]/.test(v) && /[A-Z]/.test(v) && /[0-9]/.test(v);

const segredos = [];
const ignorados = [];
try {
  for (const linha of readFileSync(join(RAIZ, '.env'), 'utf8').split(/\r?\n/)) {
    const i = linha.indexOf('=');
    if (i < 1 || linha.trim().startsWith('#')) continue;
    const chave = linha.slice(0, i).trim();
    const valor = linha.slice(i + 1).trim();
    if (valor.length < 8) continue;
    if (ehNomeDeSegredo(chave) || pareceCredencial(valor)) segredos.push({ chave, valor });
    else ignorados.push(chave);
  }
} catch {
  console.error('Sem .env para comparar — varredura incompleta.');
}

/* Padrões que denunciam segredo mesmo sem estar no .env. */
const PADROES = [
  [/-----BEGIN [A-Z ]*PRIVATE KEY-----/, 'bloco de chave privada'],
  [/\bAIza[0-9A-Za-z_-]{30,}/, 'chave de API Google'],
  [/\bsk-[A-Za-z0-9]{20,}/, 'chave de API OpenAI'],
  [/\bghp_[A-Za-z0-9]{30,}/, 'token do GitHub'],
  [/\bapify_api_[A-Za-z0-9]{20,}/, 'token da Apify'],
];

/* Arquivos que o git levaria: rastreados + não rastreados que não estão ignorados. */
const lista = execSync('git ls-files --cached --others --exclude-standard', { cwd: RAIZ, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })
  .split(/\r?\n/).filter(Boolean);

const BINARIO = /\.(png|jpe?g|webp|gif|ico|woff2?|ttf|otf|glb|mp4|webm|zip|pdf|exe|dll)$/i;

const achados = [];
let lidos = 0;

for (const rel of lista) {
  if (BINARIO.test(rel)) continue;
  const abs = join(RAIZ, rel);
  let txt;
  try {
    if (statSync(abs).size > 4 * 1024 * 1024) continue;   // arquivo enorme: não é fonte
    txt = readFileSync(abs, 'utf8');
  } catch { continue; }
  lidos++;

  const linhas = txt.split(/\r?\n/);
  for (let n = 0; n < linhas.length; n++) {
    for (const { chave, valor } of segredos) {
      if (linhas[n].includes(valor)) achados.push({ rel, linha: n + 1, motivo: `valor de ${chave}` });
    }
    for (const [re, nome] of PADROES) {
      if (re.test(linhas[n])) achados.push({ rel, linha: n + 1, motivo: nome });
    }
  }
}

console.log(`  arquivos que o git levaria: ${lista.length}  (${lidos} lidos como texto)`);
console.log(`  chaves tratadas como segredo: ${segredos.map((s) => s.chave).join(', ') || '(nenhuma)'}`);
console.log(`  chaves ignoradas (não são segredo): ${ignorados.join(', ') || '(nenhuma)'}`);

if (achados.length === 0) {
  console.log('\n  Nenhum segredo encontrado.');
  process.exit(0);
}

console.log(`\n  ${achados.length} OCORRÊNCIA(S) — resolver ANTES de publicar:`);
for (const a of achados) console.log(`    ${a.rel}:${a.linha}  (${a.motivo})`);
process.exit(1);
