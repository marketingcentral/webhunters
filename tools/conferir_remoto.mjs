/**
 * Baixa alguns arquivos sensíveis do servidor e compara com os locais, SEM enviar nada.
 *
 * Existe por causa de uma armadilha já paga: a produção teve credenciais de MySQL fixas
 * dentro de app/Core/Database.php. Publicar o arquivo local por cima derrubava a conexão
 * do site em silêncio. Antes de sobrescrever infraestrutura, olhe o que está lá.
 *
 * Também confirma que app/db.local.php EXISTE no servidor — ele fica fora do deploy de
 * propósito, então num host novo ele simplesmente não está lá.
 */
import { Client } from 'basic-ftp';
import { readFileSync, existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { createHash } from 'node:crypto';

const RAIZ = 'C:/wamp64/www/extracthunters';
const TMP = join(RAIZ, 'tools', '_remoto_tmp');

const env = Object.fromEntries(
  readFileSync(join(RAIZ, '.env'), 'utf8')
    .split(/\r?\n/)
    .filter((l) => l.includes('=') && !l.trim().startsWith('#'))
    .map((l) => { const i = l.indexOf('='); return [l.slice(0, i).trim(), l.slice(i + 1).trim()]; }),
);

const ALVOS = [
  'app/Core/Database.php',
  'app/Core/Config.php',
  'app/Core/Security.php',
  '.htaccess',
  'index.php',
  'app/db.local.php',      // não é publicado: só queremos saber se existe
];

const sha = (b) => createHash('sha256').update(b).digest('hex').slice(0, 12);

if (!existsSync(TMP)) mkdirSync(TMP, { recursive: true });

const c = new Client(30000);
c.ftp.verbose = false;
try {
  try {
    await c.access({ host: env.FTP_IP, user: env.FTP_USER, password: env.FTP_PASS, secure: true, secureOptions: { rejectUnauthorized: false } });
  } catch {
    await c.access({ host: env.FTP_IP, user: env.FTP_USER, password: env.FTP_PASS, secure: false });
  }
  console.log('conectado\n');

  for (const alvo of ALVOS) {
    const local = join(RAIZ, alvo);
    const destino = join(TMP, alvo.replace(/[\\/]/g, '__'));
    /*
     * A aplicação vive em unreal/ dentro da raiz do FTP (ver REMOTO_RAIZ no deploy.mjs).
     * Sem esse prefixo tudo volta "não existe" — foi o que me fez achar, erradamente, que
     * este servidor não aceitava caminho nos comandos. Aceita; o caminho é que estava curto.
     */
    const remotoAlvo = 'unreal/' + alvo;
    const barra = remotoAlvo.lastIndexOf('/');
    const dir = barra >= 0 ? remotoAlvo.slice(0, barra) : '';
    const nome = barra >= 0 ? remotoAlvo.slice(barra + 1) : remotoAlvo;
    let remoto = null;
    try {
      await c.cd('/');
      if (dir) await c.cd(dir);
      await c.downloadTo(destino, nome);
      remoto = readFileSync(destino);
    } catch (err) {
      console.log(`  ${alvo.padEnd(26)} erro: ${String(err && err.message).slice(0, 110)}`);
      remoto = null;
      continue;
    }

    const temLocal = existsSync(local);
    if (remoto === null) {
      console.log(`  ${alvo.padEnd(26)} REMOTO NÃO EXISTE` + (temLocal ? '  (local existe)' : ''));
      continue;
    }
    if (!temLocal) {
      console.log(`  ${alvo.padEnd(26)} só no servidor (${remoto.length} b)`);
      continue;
    }
    const l = readFileSync(local);
    const igual = l.equals(remoto);
    console.log(`  ${alvo.padEnd(26)} ${igual ? 'IDÊNTICO' : 'DIFERENTE'}  local=${l.length}b/${sha(l)}  remoto=${remoto.length}b/${sha(remoto)}`);
  }
} finally {
  c.close();
}
