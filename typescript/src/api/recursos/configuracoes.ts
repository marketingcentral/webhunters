/**
 * /api/v1/settings — os parâmetros de `admin_settings` (App\Api\ConfiguracoesRecurso).
 *
 * A chave não vai na URL (o roteador não aceita `_` em `{param}`): leitura é `?key=`, escrita
 * é PUT na coleção com {"values": {...}} — TUDO OU NADA, validado com as regras da tela
 * (app/Data/admin-config.php -> dataMock('admin-config')). A API não cria nem apaga chave.
 */
import { dataMock } from '../../core/helpers';
import type { AppResponse } from '../../core/response';
import type { Contexto } from '../contexto';
import * as Infra from '../infra-repo';
import { Recurso, type Rota } from '../recurso';
import type { Requisicao } from '../requisicao';
import { erro, ok, type Obj } from '../resposta';

/** Linha do banco -> objeto da API: `value` cru (texto) e `typed` convertido. */
function saida(l: Obj): Obj {
  const bruto = l.value === null || l.value === undefined ? null : String(l.value);
  const kind = String(l.kind);
  let typed: unknown = bruto;
  if (bruto !== null) {
    if (kind === 'int') typed = /^\s*[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?\s*$/.test(bruto) ? Math.trunc(Number(bruto)) : null;
    else if (kind === 'bool') typed = bruto === '1';
    else if (kind === 'json') {
      try {
        typed = JSON.parse(bruto);
      } catch {
        typed = null;
      }
    }
  }
  return {
    key: String(l.key),
    label: String(l.label ?? ''),
    group: String(l.grupo ?? ''),
    kind,
    value: bruto,
    typed,
    updated_at: String(l.updated_at ?? ''),
    updated_by: l.updated_por === null || l.updated_por === undefined ? null : String(l.updated_por),
  };
}

export class ConfiguracoesRecurso extends Recurso {
  nome(): string {
    return 'settings';
  }

  rotas(): Rota[] {
    return [
      ['GET', '', 'listar', 'Lista os parâmetros (use ?key= para um só, ?grupo= para um grupo).', '?grupo=mundo'],
      ['PUT', '', 'gravar', 'Grava vários parâmetros de uma vez: {"values":{"chave":"valor"}}.', ''],
    ];
  }

  protected async listar(req: Requisicao): Promise<AppResponse> {
    const chave = req.consulta('key');
    const grupo = req.consulta('grupo');
    if (chave !== '') {
      const linha = await Infra.configuracao(chave);
      return linha === null ? this.naoEncontrado('Não existe parâmetro com esta chave.') : ok(saida(linha));
    }
    let linhas = await Infra.configuracoes();
    if (grupo !== '') linhas = linhas.filter((l) => String(l.grupo) === grupo);
    return ok(linhas.map(saida), { total: linhas.length });
  }

  /** Grava um conjunto de parâmetros. Primeira passada valida tudo; só então grava. */
  protected async gravar(req: Requisicao, ctx: Contexto): Promise<AppResponse> {
    const pedido = req.corpo().values;
    const vazio = pedido === null || typeof pedido !== 'object' || Object.keys(pedido).length === 0;
    if (vazio) {
      return erro('entrada_invalida', '', {
        values: 'Envie um objeto `values` com pelo menos uma chave: {"values":{"squad_max":"4"}}.',
      });
    }

    const regras = (dataMock('admin-config')?.chaves ?? {}) as Record<string, Obj>;
    const erros: Record<string, string> = {};
    const prontos: Record<string, { valor: string | null; antes: unknown }> = {};

    for (const [chave, bruto] of Object.entries(pedido as Obj)) {
      const linha = await Infra.configuracao(chave);
      if (linha === null) {
        erros[chave] = 'Não existe parâmetro com esta chave. A API não cria chave nova.';
        continue;
      }
      const ehObjeto = bruto !== null && typeof bruto === 'object';
      if (ehObjeto && String(linha.kind) !== 'json') {
        erros[chave] = 'Esperava um valor simples, não um objeto.';
        continue;
      }
      const textoBruto = ehObjeto
        ? JSON.stringify(bruto)
        : typeof bruto === 'boolean'
          ? bruto
            ? '1'
            : '0'
          : bruto === null || bruto === undefined
            ? ''
            : String(bruto);
      const v = Infra.validarConfiguracao(String(linha.kind), textoBruto, regras[chave] ?? {});
      if (!v.ok) {
        erros[chave] = v.erro;
        continue;
      }
      prontos[chave] = { valor: v.valor, antes: linha.value };
    }
    if (Object.keys(erros).length) return erro('entrada_invalida', '', erros);

    // Segunda passada: grava. Valor igual não vira linha de auditoria.
    const mudadas: Record<string, { from: unknown; to: string | null }> = {};
    for (const [chave, p] of Object.entries(prontos)) {
      if (await Infra.gravarConfiguracao(chave, p.valor, 0)) mudadas[chave] = { from: p.antes, to: p.valor };
    }
    const nomes = Object.keys(mudadas);
    if (nomes.length) {
      await ctx.auditar(
        'config_alterada',
        'admin_settings',
        nomes.slice(0, 5).join(','),
        `Alterou ${nomes.length} parâmetro(s) pela API: ${nomes.join(', ')}.`,
        { mudancas: mudadas },
      );
    }

    const todas = await Infra.configuracoes();
    return ok({
      changed: nomes,
      unchanged: Object.keys(prontos).filter((k) => !(k in mudadas)),
      values: todas.filter((l) => String(l.key) in prontos).map(saida),
    });
  }
}
