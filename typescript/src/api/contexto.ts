/**
 * Quem está falando com a API, e a caneta com que ele assina o que faz — App\Api\Contexto.
 *
 * TODA ESCRITA DA API PASSA POR `auditar()`, na mesma `admin_audit_log` do painel: ação com
 * prefixo `api_`, `admin_id` NULO (quem fez foi uma chave, não uma pessoa), resumo terminando
 * em "via chave <nome> (<prefixo>)" e `meta.api_key` com id, prefixo e nome.
 */
import { registrarAuditoria } from './gestao-repo';
import type { Obj } from './resposta';

/** Linha de `api_keys` já autenticada (sem o hash). */
export interface ChaveApi {
  id: number;
  name: string;
  prefix: string;
  scopes: string[];
  rate_limit_per_min: number;
  [k: string]: unknown;
}

export class Contexto {
  constructor(
    public readonly chave: ChaveApi,
    public readonly ip: string,
  ) {}

  chaveId(): number {
    return Number(this.chave.id ?? 0) | 0;
  }
  prefixo(): string {
    return String(this.chave.prefix ?? '');
  }
  nomeDaChave(): string {
    return String(this.chave.name ?? '');
  }
  escopos(): string[] {
    return Array.isArray(this.chave.scopes) ? this.chave.scopes : [];
  }
  temEscopo(escopo: string): boolean {
    return this.escopos().includes(escopo);
  }

  /** Uma linha na trilha de auditoria, assinada pela chave. Nunca lança. */
  async auditar(acao: string, entidade: string, entidadeId: string | number | null, resumo: string, meta: Obj = {}): Promise<void> {
    const assinatura = `via chave ${this.nomeDaChave()} (${this.prefixo()})`;
    await registrarAuditoria(
      null,
      'api_' + acao,
      entidade,
      entidadeId !== null ? String(entidadeId) : null,
      resumo.trim() + ' — ' + assinatura,
      // `$meta + [...]` do PHP: a chave do lado esquerdo vence.
      'api_key' in meta ? meta : { ...meta, api_key: { id: this.chaveId(), prefix: this.prefixo(), name: this.nomeDaChave() } },
      this.ip,
    );
  }
}
