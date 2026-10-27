/**
 * A requisição vista PELA API — App\Core\Api\Requisicao do PHP.
 *
 * 1. CORPO EM JSON lido do Request cru (o AppRequest só entende formulário). No PHP o corpo
 *    era lido de php://input; aqui é `req.raw.text()`, lido uma vez em `de()`. Para isso o
 *    AppRequest NÃO consome o corpo das rotas /api/v1 (ver core/request.ts).
 * 2. A CHAVE só do cabeçalho `Authorization: Bearer <chave>` — nunca da querystring.
 * 3. SEGMENTOS da rota depois de /api/v1, já partidos.
 */
import type { AppRequest } from '../core/request';
import type { Obj } from './resposta';

/** Prefixo público da API. */
export const PREFIXO = '/api/v1';

/** Teto do corpo aceito (256 KiB). Acima disso é 413. */
export const CORPO_MAX_BYTES = 262144;

/** O caminho (já sem a base da instalação) é uma chamada de /api/v1? Casamento ESTREITO. */
export function ehCaminhoDaApi(path: string): boolean {
  return path === PREFIXO || path.startsWith(PREFIXO + '/');
}

export class Requisicao {
  private readonly _segmentos: string[];
  private _corpo: Obj = {};
  private _erroCorpo = '';

  private constructor(
    private readonly base: AppRequest,
    private readonly _metodo: string,
    private readonly _caminho: string,
  ) {
    const resto = _caminho.slice(PREFIXO.length);
    this._segmentos = resto.replace(/^\/+|\/+$/g, '').split('/').filter((s) => s !== '');
  }

  /** Monta a requisição e já lê o corpo (async no Worker; no PHP era preguiçoso e síncrono). */
  static async de(base: AppRequest): Promise<Requisicao> {
    const r = new Requisicao(base, base.method(), base.path());
    if (r.escreve()) await r.lerCorpo();
    return r;
  }

  metodo(): string {
    return this._metodo;
  }
  caminho(): string {
    return this._caminho;
  }
  ip(): string {
    return this.base.ip();
  }
  segmentos(): string[] {
    return this._segmentos;
  }
  /** Segmento por posição (0 = recurso), ou '' quando não existe. */
  segmento(i: number): string {
    return this._segmentos[i] ?? '';
  }
  /** O recurso pedido: o primeiro segmento depois de /api/v1. */
  recurso(): string {
    return this.segmento(0).toLowerCase();
  }
  /** Muda estado? Decide o verbo do escopo e se o corpo é lido. */
  escreve(): boolean {
    return this._metodo !== 'GET';
  }

  /** A chave apresentada (só `Bearer`), ou '' quando não veio. */
  chaveApresentada(): string {
    const bruto = (this.base.header('Authorization') ?? '').trim();
    if (bruto === '') return '';
    const m = /^Bearer\s+(\S+)$/i.exec(bruto);
    return m ? m[1]! : '';
  }

  /** Veio algum `Authorization`, ainda que ilegível? */
  temAutorizacao(): boolean {
    return (this.base.header('Authorization') ?? '').trim() !== '';
  }

  /** Valor da querystring, string podada. */
  consulta(nome: string, padrao = ''): string {
    return this.base.query(nome) ?? padrao;
  }

  /** Inteiro da querystring, preso entre min e max (ilegível vira o padrão). */
  consultaInt(nome: string, padrao: number, min: number, max: number): number {
    const bruto = this.consulta(nome);
    if (bruto === '' || !/^-?\d{1,9}$/.test(bruto)) return padrao;
    return Math.max(min, Math.min(max, parseInt(bruto, 10)));
  }

  /** O corpo já decodificado ({} quando não há). */
  corpo(): Obj {
    return this._corpo;
  }

  /** '' | 'json_invalido' | 'corpo_grande'. */
  erroCorpo(): string {
    return this._erroCorpo;
  }

  private async lerCorpo(): Promise<void> {
    const raw = this.base.raw;
    const tipo = raw.headers.get('Content-Type') ?? '';

    // multipart: no PHP o php://input chega vazio e o corpo é o $_POST (sem o _csrf).
    if (tipo.includes('multipart/form-data')) {
      try {
        const fd = await raw.formData();
        const post: Obj = {};
        for (const [k, v] of fd.entries()) if (typeof v === 'string' && k !== '_csrf') post[k] = v;
        this._corpo = post;
      } catch {
        this._corpo = {};
      }
      return;
    }

    let bruto = '';
    try {
      bruto = raw.bodyUsed ? '' : await raw.text();
    } catch {
      bruto = '';
    }
    if (bruto === '') return;

    if (new TextEncoder().encode(bruto).length > CORPO_MAX_BYTES) {
      this._erroCorpo = 'corpo_grande';
      return;
    }
    let dados: unknown;
    try {
      dados = JSON.parse(bruto);
    } catch {
      this._erroCorpo = 'json_invalido';
      return;
    }
    // Só objeto na raiz. Lista vazia `[]` passa (no PHP vira array vazio), lista cheia não.
    if (dados === null || typeof dados !== 'object' || (Array.isArray(dados) && dados.length > 0)) {
      this._erroCorpo = 'json_invalido';
      return;
    }
    this._corpo = Array.isArray(dados) ? {} : (dados as Obj);
  }
}
