/**
 * RECEBIMENTO DE IMAGEM DO PAINEL — POST /admin/upload (JSON).
 * Port do App\Controllers\Admin\UploadController do PHP.
 *
 * Único endereço por onde arquivo entra vindo do /admin. As telas guardam no banco um CAMINHO
 * relativo a /media (ex.: "uploads/mapas/x.jpg"); aqui o arquivo é gravado e esse caminho
 * volta para o campo de texto (assets/js/admin/upload.js). Nada muda no banco: o formulário
 * só é gravado quando o operador salvar. TODA saída é JSON, inclusive as recusas.
 *
 * DIFERENÇAS PARA O PHP (limitação/decisão da plataforma):
 *   - Não há disco: o arquivo vai para o R2 (binding MEDIA) com a chave
 *     `media/uploads/<entidade>/<nome>`; o Worker serve /media/* do R2 (src/index.ts, que
 *     entrega com Content-Type do objeto + nosniff e nunca entrega .svg/.html/.php).
 *   - getimagesize() + finfo viram a leitura da ASSINATURA e do cabeçalho do próprio arquivo
 *     (PNG IHDR, JPEG SOFn, WEBP VP8/VP8L/VP8X): o tipo e as dimensões saem do conteúdo, a
 *     extensão e o Content-Type do cliente são ignorados, como no PHP.
 *   - SVG é RECUSADO. No PHP ele entrava após a lista de recusa, mas o .htaccess de
 *     media/uploads/ proibia servi-lo (e o /media do Worker também recusa .svg): aceitar
 *     só gravaria um arquivo que nunca aparece. Ícone vetorial continua pela origem `glyph`.
 *   - Teto fixo de 2 MiB (no PHP era o menor entre 2 MiB e o php.ini — que valia 2M).
 *   - Sessão de jogador sem papel administrativo recebe JSON 403 (no PHP, a página 403).
 *   - Não há .htaccess a garantir: o R2 não executa nada.
 */
import { ctx } from '../../core/context';
import { flash, media } from '../../core/helpers';
import { HttpException } from '../../core/http-exception';
import type { AppRequest } from '../../core/request';
import { AppResponse } from '../../core/response';
import type { Params } from '../../core/router';
import { checkCsrf } from '../../core/security';
import { corta } from '../../repo/admin-repo';
import { AdminController } from './admin-controller';

/** Pastas permitidas dentro de media/uploads/. Lista fechada: a entidade decide a chave no R2. */
const ENTIDADES = ['missoes', 'conquistas', 'mapas', 'eventos', 'noticias', 'itens', 'loja'];

/** Teto de 2 MiB (ver o docblock do PHP: o maior fundo do jogo em PNG dá ~1,2 MB). */
const MAX_BYTES = 2 * 1024 * 1024;

/** Dimensões aceitas. O mínimo pega arquivo-isca de 1x1; o máximo pega imagem de câmera. */
const MIN_LADO = 16;
const MAX_LADO = 8000;

/** Nome do campo de arquivo no formulário. */
const CAMPO = 'arquivo';

/** Imagem reconhecida pelo conteúdo. */
interface Imagem {
  ext: 'jpg' | 'png' | 'webp';
  mime: string;
  largura: number;
  altura: number;
}

export class AdminUploadController extends AdminController {
  async enviar(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    /* A guarda da base devolve REDIRECT para /admin/login; para um XHR isso viraria "erro
       desconhecido". Então a guarda é lida e traduzida para JSON. */
    let bloqueio: AppResponse | null;
    try {
      bloqueio = await this.exigirAdmin();
    } catch (e) {
      if (e instanceof HttpException && e.status === 403) {
        return AppResponse.json({ ok: false, erro: 'Área restrita à administração.' }, 403);
      }
      throw e;
    }
    if (bloqueio) {
      // O flash que a guarda deixou é para a tela de login; aqui ele sobraria na sessão.
      flash('error');
      return this.falha(401, 'Sessão do painel expirada. Recarregue a página e entre de novo.');
    }

    try {
      checkCsrf(req);
    } catch {
      return this.falha(419, 'Token de sessão inválido ou expirado. Recarregue a página.');
    }

    const entidade = (req.input('entidade') ?? '').trim().toLowerCase();
    if (!ENTIDADES.includes(entidade)) {
      return this.falha(422, 'Destino de upload desconhecido: "' + entidade + '".');
    }

    // Campo ausente, repetido ou `arquivo[]`: um por vez, sempre.
    const arquivo = req.file(CAMPO);
    if (arquivo === null) {
      return this.falha(422, 'Nenhum arquivo recebido. Escolha uma imagem e tente de novo.');
    }

    const bytes = arquivo.size;
    if (bytes <= 0) {
      return this.falha(422, 'O arquivo chegou vazio (0 byte).');
    }
    if (bytes > MAX_BYTES) {
      return this.falha(
        413,
        `Imagem de ${humano(bytes)} — o limite é ${humano(MAX_BYTES)}. Reduza a resolução ou salve em JPEG.`,
      );
    }

    // ---- conteúdo de verdade -------------------------------------------------------
    const dados = new Uint8Array(await arquivo.arrayBuffer());

    if (pareceSvg(dados)) {
      return this.falha(422, 'SVG recusado: o servidor não entrega vetor enviado pelo painel. Envie PNG, WEBP ou JPG.');
    }

    const tipo = detectar(dados);
    if (tipo === 'outro') {
      return this.falha(422, 'Formato não aceito. Envie PNG, WEBP ou JPG.');
    }
    if (tipo === null) {
      return this.falha(422, 'O arquivo enviado não é uma imagem (nenhum cabeçalho de imagem foi encontrado nele).');
    }

    /* Segunda leitura do MESMO arquivo (o finfo do PHP): a assinatura diz o formato, o
       cabeçalho interno tem de confirmar com dimensões coerentes. Um híbrido montado para
       parecer imagem só no começo morre aqui. */
    const img = medir(dados, tipo);
    if (img === null) {
      return this.falha(422, `O conteúdo do arquivo (desconhecido) não corresponde a uma imagem ${tipo.toUpperCase()} válida.`);
    }
    const { largura: larg, altura: alt } = img;
    if (larg < MIN_LADO || alt < MIN_LADO) {
      return this.falha(422, `Imagem pequena demais (${larg}x${alt}). O mínimo é ${MIN_LADO}x${MIN_LADO}.`);
    }
    if (larg > MAX_LADO || alt > MAX_LADO) {
      return this.falha(422, `Imagem grande demais (${larg}x${alt}). O máximo é ${MAX_LADO} px de lado.`);
    }

    // ---- destino (R2) ----------------------------------------------------------------
    const bucket = ctx().env.MEDIA;
    let nome = '';
    try {
      nome = await nomeUnico(bucket, entidade, arquivo.name ?? '', img.ext);
      await bucket.put(`media/uploads/${entidade}/${nome}`, dados, {
        httpMetadata: { contentType: img.mime },
      });
    } catch (e) {
      console.error('[extracthunters] upload R2:', e);
      return this.falha(500, 'Falha ao gravar o arquivo no servidor. Verifique o armazenamento de mídia (R2).');
    }

    const caminho = 'uploads/' + entidade + '/' + nome;

    await this.auditar('upload_imagem', entidade, null, 'Imagem enviada: ' + caminho, {
      arquivo: caminho,
      bytes,
      mime: img.mime,
      dimensao: larg + 'x' + alt,
      // Só para a trilha de auditoria; nunca usado para montar caminho.
      original: corta(arquivo.name ?? '', 120),
    });

    return AppResponse.json({
      ok: true,
      caminho, // é isto que vai para o <input> e para o banco
      url: media(caminho), // só para a pré-visualização
      bytes,
      tamanho: humano(bytes),
      largura: larg,
      altura: alt,
    });
  }

  /** Resposta de recusa. Sempre JSON; auditada quando há sessão (tentativa repetida é sinal). */
  private async falha(status: number, mensagem: string): Promise<AppResponse> {
    if (status !== 401) {
      await this.auditar('upload_recusado', 'sistema', null, 'Upload recusado: ' + mensagem, { status });
    }
    return AppResponse.json({ ok: false, erro: mensagem }, status);
  }
}

// ================================================================================ apoio

/** "1,3 MB" / "512 KB" — igual ao humano() do PHP. */
function humano(bytes: number): string {
  if (bytes >= 1024 * 1024) return String(Math.round((bytes / 1048576) * 10) / 10).replace('.', ',') + ' MB';
  return Math.max(1, Math.round(bytes / 1024)) + ' KB';
}

/** O arquivo se anuncia como SVG/XML/HTML? Triagem do começo (1 KB), como o pareceSvg() do PHP. */
function pareceSvg(d: Uint8Array): boolean {
  const inicio = new TextDecoder().decode(d.subarray(0, 1024));
  return /<\s*svg[\s>]/i.test(inicio);
}

const comeca = (d: Uint8Array, assinatura: number[], em = 0) => assinatura.every((b, i) => d[em + i] === b);
const ascii = (d: Uint8Array, de: number, n: number) => String.fromCharCode(...d.subarray(de, de + n));

/**
 * Tipo pela assinatura (números mágicos). null = nenhum cabeçalho de imagem; 'outro' = é
 * imagem, mas de formato fora da lista (GIF, BMP, TIFF, ICO, AVIF...).
 */
function detectar(d: Uint8Array): Imagem['ext'] | 'outro' | null {
  if (d.length >= 8 && comeca(d, [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])) return 'png';
  if (d.length >= 3 && comeca(d, [0xff, 0xd8, 0xff])) return 'jpg';
  if (d.length >= 12 && ascii(d, 0, 4) === 'RIFF' && ascii(d, 8, 4) === 'WEBP') return 'webp';
  if (d.length >= 6 && (ascii(d, 0, 6) === 'GIF87a' || ascii(d, 0, 6) === 'GIF89a')) return 'outro';
  if (d.length >= 2 && ascii(d, 0, 2) === 'BM') return 'outro';
  if (d.length >= 4 && (comeca(d, [0x49, 0x49, 0x2a, 0x00]) || comeca(d, [0x4d, 0x4d, 0x00, 0x2a]))) return 'outro';
  if (d.length >= 4 && comeca(d, [0x00, 0x00, 0x01, 0x00])) return 'outro';
  if (d.length >= 12 && ascii(d, 4, 4) === 'ftyp' && /^avi[fs]|^hei[cx]|^mif1/.test(ascii(d, 8, 4))) return 'outro';
  return null;
}

/** Dimensões lidas do cabeçalho interno do formato (o getimagesize()). null = estrutura inválida. */
function medir(d: Uint8Array, ext: Imagem['ext']): Imagem | null {
  const v = new DataView(d.buffer, d.byteOffset, d.byteLength);
  if (ext === 'png') {
    // O primeiro chunk tem de ser IHDR com 13 bytes.
    if (d.length < 33 || v.getUint32(8) !== 13 || ascii(d, 12, 4) !== 'IHDR') return null;
    return { ext, mime: 'image/png', largura: v.getUint32(16), altura: v.getUint32(20) };
  }
  if (ext === 'jpg') {
    let i = 2;
    while (i + 9 < d.length) {
      if (d[i] !== 0xff) return null;
      const m = d[i + 1]!;
      if (m === 0xff) { i++; continue; } // preenchimento
      if (m === 0xd8 || m === 0x01 || (m >= 0xd0 && m <= 0xd7)) { i += 2; continue; }
      if (m === 0xd9 || m === 0xda) return null; // fim/scan antes de achar o SOF
      const tam = v.getUint16(i + 2);
      if (tam < 2) return null;
      // SOF0..SOF15, exceto DHT (C4), JPG (C8) e DAC (CC).
      if (m >= 0xc0 && m <= 0xcf && m !== 0xc4 && m !== 0xc8 && m !== 0xcc) {
        return { ext, mime: 'image/jpeg', altura: v.getUint16(i + 5), largura: v.getUint16(i + 7) };
      }
      i += 2 + tam;
    }
    return null;
  }
  // WEBP: RIFF....WEBP + chunk VP8 / VP8L / VP8X
  if (d.length < 30) return null;
  const chunk = ascii(d, 12, 4);
  if (chunk === 'VP8 ') {
    if (!comeca(d, [0x9d, 0x01, 0x2a], 23)) return null;
    return { ext, mime: 'image/webp', largura: v.getUint16(26, true) & 0x3fff, altura: v.getUint16(28, true) & 0x3fff };
  }
  if (chunk === 'VP8L') {
    if (d[20] !== 0x2f) return null;
    const b = v.getUint32(21, true);
    return { ext, mime: 'image/webp', largura: (b & 0x3fff) + 1, altura: ((b >>> 14) & 0x3fff) + 1 };
  }
  if (chunk === 'VP8X') {
    const l = (d[24]! | (d[25]! << 8) | (d[26]! << 16)) + 1;
    const a = (d[27]! | (d[28]! << 8) | (d[29]! << 16)) + 1;
    return { ext, mime: 'image/webp', largura: l, altura: a };
  }
  return null;
}

/** Acento -> letra sem acento ANTES do filtro (mesma tabela do strtr do PHP). */
const SEM_ACENTO: Record<string, string> = {
  á: 'a', à: 'a', ã: 'a', â: 'a', ä: 'a', é: 'e', ê: 'e', ë: 'e', è: 'e',
  í: 'i', ì: 'i', î: 'i', ï: 'i', ó: 'o', ò: 'o', õ: 'o', ô: 'o', ö: 'o',
  ú: 'u', ù: 'u', û: 'u', ü: 'u', ç: 'c', ñ: 'n',
};

/**
 * Nome final: pedaço legível do nome original ([a-z0-9-], até 48) + 8 hex aleatórios +
 * extensão DEDUZIDA do conteúdo. Nada do nome do cliente sobrevive como caminho.
 */
async function nomeUnico(bucket: R2Bucket, entidade: string, nomeCliente: string, ext: string): Promise<string> {
  const arq = nomeCliente.replace(/\\/g, '/').split('/').pop() ?? '';
  const ponto = arq.lastIndexOf('.');
  let base = (ponto > 0 ? arq.slice(0, ponto) : arq).toLowerCase();
  base = base.replace(/[áàãâäéêëèíìîïóòõôöúùûüçñ]/g, (c) => SEM_ACENTO[c] ?? c);
  base = base.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  base = base === '' ? 'img' : base.slice(0, 48);

  for (;;) {
    const sufixo = [...crypto.getRandomValues(new Uint8Array(4))].map((b) => b.toString(16).padStart(2, '0')).join('');
    const nome = `${base}-${sufixo}.${ext}`;
    if ((await bucket.head(`media/uploads/${entidade}/${nome}`)) === null) return nome;
  }
}
