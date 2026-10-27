/**
 * Senhas — o password_hash()/password_verify() do PHP.
 *
 * O PHP grava bcrypt (`$2y$10$...`, PASSWORD_DEFAULT). `$2y$` é o mesmo algoritmo de `$2a$`/
 * `$2b$` (o "y" só marca que a implementação do PHP não tem o bug antigo do crypt_blowfish),
 * então o bcryptjs valida esses hashes trocando só o prefixo. Contas que vierem do MySQL
 * entram com a senha que já tinham.
 *
 * Custo: bcrypt em JavaScript puro é caro em CPU (~60-120 ms no custo 10). No plano Workers
 * Paid isso cabe folgado; no Free (10 ms de CPU) não cabe. Por isso existe o segundo formato:
 *   PASSWORD_ALGO=pbkdf2  -> senhas novas e re-hash no login usam PBKDF2-SHA256 nativo do
 *                            WebCrypto (100.000 iterações, o máximo que o Workers aceita).
 * verify() aceita os dois sempre; needsRehash() diz quando o login deve regravar o hash.
 * Ver o plano completo no README.
 */
import bcrypt from 'bcryptjs';

const PBKDF2_ITER = 100_000;
const PREFIXO_PBKDF2 = '$pbkdf2-sha256$';

function b64(bytes: ArrayBuffer | Uint8Array): string {
  const u = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
  return btoa(String.fromCharCode(...u));
}

function deB64(s: string): Uint8Array {
  return Uint8Array.from(atob(s), (c) => c.charCodeAt(0));
}

async function pbkdf2(senha: string, salt: Uint8Array, iter: number): Promise<Uint8Array> {
  const chave = await crypto.subtle.importKey('raw', new TextEncoder().encode(senha), 'PBKDF2', false, ['deriveBits']);
  const bits = await crypto.subtle.deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt, iterations: iter }, chave, 256);
  return new Uint8Array(bits);
}

function iguais(a: Uint8Array, b: Uint8Array): boolean {
  if (a.length !== b.length) return false;
  let r = 0;
  for (let i = 0; i < a.length; i++) r |= a[i]! ^ b[i]!;
  return r === 0;
}

export async function hash(senha: string, algo: string | undefined): Promise<string> {
  if (algo === 'pbkdf2') {
    const salt = new Uint8Array(16);
    crypto.getRandomValues(salt);
    const h = await pbkdf2(senha, salt, PBKDF2_ITER);
    return `${PREFIXO_PBKDF2}i=${PBKDF2_ITER}$${b64(salt)}$${b64(h)}`;
  }
  // Mesmo formato do PHP (custo 10, prefixo $2y$): o banco continua legível pelo app PHP.
  return bcrypt.hashSync(senha, 10).replace(/^\$2[ab]\$/, '$2y$');
}

export async function verify(senha: string, armazenado: string): Promise<boolean> {
  if (armazenado.startsWith(PREFIXO_PBKDF2)) {
    const [, , params, salt, h] = armazenado.split('$');
    const iter = Number((params ?? '').replace('i=', ''));
    if (!iter || !salt || !h) return false;
    return iguais(await pbkdf2(senha, deB64(salt), iter), deB64(h));
  }
  if (/^\$2[aby]\$\d{2}\$/.test(armazenado)) {
    return bcrypt.compareSync(senha, armazenado.replace(/^\$2y\$/, '$2a$'));
  }
  return false;
}

/** O hash guardado está num formato diferente do configurado? (re-hash no próximo login) */
export function needsRehash(armazenado: string, algo: string | undefined): boolean {
  return algo === 'pbkdf2' ? !armazenado.startsWith(PREFIXO_PBKDF2) : !/^\$2[aby]\$10\$/.test(armazenado);
}

let hashFalso: string | null = null;

/**
 * Gasta o mesmo tempo de uma verificação real quando o usuário não existe: sem isso o tempo
 * de resposta denuncia quais contas existem (enumeração). O hash é gerado uma vez por isolate.
 */
export async function verifyDummy(senha: string, algo: string | undefined): Promise<void> {
  hashFalso ??= await hash(crypto.randomUUID(), algo);
  await verify(senha, hashFalso);
}
