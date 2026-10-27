/**
 * Tela Transações do painel: /admin/transacoes — o App\Controllers\Admin\TransactionsController do PHP.
 *
 * Um LIVRO-CAIXA, e livro-caixa não se edita: a tela lê, recorta, soma e exporta (CSV). As
 * rotas de escrita (salvar/excluir) existem e respondem com a recusa explicada e auditada.
 *
 * O controller só traduz a URL em filtro válido; o SQL está em repo/transactions-repo.ts e a
 * geometria (SVG das barras) na view. A URL é entrada de usuário: período, moeda, ordenação,
 * direção e página passam por lista branca; data fora de formato vira ERRO DE CAMPO e a tela
 * volta para a janela padrão dizendo que voltou.
 *
 * A MOEDA NUNCA É SOMADA: o total do período vem sempre quebrado por moeda.
 *
 * Datas: "hoje" e o período são calculados em UTC, como as colunas do D1 (ver README, "Datas").
 */
import { dataMock, flash, num } from '../../core/helpers';
import type { AppRequest } from '../../core/request';
import { AppResponse } from '../../core/response';
import type { Params } from '../../core/router';
import type { Dict } from '../../core/view';
import { agoraUnix, corta, formatarData, paraUnix } from '../../repo/admin-repo';
import * as TransactionsRepo from '../../repo/transactions-repo';
import { adminTransacoes } from '../../views/pages/admin-transacoes';
import { AdminController } from './admin-controller';

/** Janelas do seletor de período, em dias. `tudo` é tratado à parte. */
const JANELAS = [7, 30, 90];
/** Janela usada quando a URL não pede nada. */
const JANELA_PADRAO = 30;
/** Linhas por página da lista. */
const POR_PAGINA = 25;
/** Teto do CSV: um export não pode virar um jeito de derrubar o painel. */
const TETO_CSV = 5000;

interface Extremos {
  n: number;
  primeira: string | null;
  ultima: string | null;
}

export interface FiltroTela {
  f: TransactionsRepo.Filtro;
  periodo: string;
  ord: string;
  dir: string;
  bruto: { de: string; ate: string };
  erros: Record<string, string>;
}

const ymd = (ts: number) => formatarData(ts, 'Y-m-d');

export class TransactionsController extends AdminController {
  async index(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;

    const [extremos, moedas] = await Promise.all([TransactionsRepo.extremos(), TransactionsRepo.moedasUsadas()]);
    const filtro = this.filtro(req, extremos, moedas);

    // O CSV sai ANTES de montar a tela (mesmo filtro e ordenação, sem gráfico nem KPI).
    if (req.query('export') === 'csv') return this.csv(filtro);

    const total = await TransactionsRepo.contar(filtro.f);
    const paginas = Math.max(1, Math.ceil(total / POR_PAGINA));
    const pagina = Math.min(Math.max(1, Number.parseInt(req.query('p') ?? '1', 10) || 0), paginas);

    // Os agregados são lidos pela tela E pela topbar: calculados uma vez só.
    const [totais, porMoeda, serie, linhas, itens, jogadores] = await Promise.all([
      TransactionsRepo.totais(filtro.f),
      TransactionsRepo.porMoeda(filtro.f),
      TransactionsRepo.serie(filtro.f),
      TransactionsRepo.lista(filtro.f, filtro.ord, filtro.dir, POR_PAGINA, (pagina - 1) * POR_PAGINA),
      TransactionsRepo.topItens(filtro.f, 6),
      TransactionsRepo.topJogadores(filtro.f, 6),
    ]);

    return this.render(adminTransacoes, this.adminPage('transacoes', 'Transações', 'Compras da loja, por período e por moeda'), {
      cromo: dataMock('admin-transacoes'),
      filtro,
      extremos,
      moedas,

      totais,
      moeda_a_moeda: porMoeda,
      serie,
      linhas,
      itens,
      jogadores,

      paginacao: {
        pagina,
        paginas,
        total,
        por: POR_PAGINA,
        primeiro: total === 0 ? 0 : (pagina - 1) * POR_PAGINA + 1,
        ultimo: Math.min(total, pagina * POR_PAGINA),
      },

      // A casca do painel lê estas duas chaves.
      admin_kpis: this.kpisDaTopbar(totais, porMoeda),
      admin_alerts: [],
    });
  }

  /** POST /admin/transacoes/salvar — recusa explicada (e auditada). */
  async salvar(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);
    return this.recusar('transacao_escrita_recusada', 'salvar');
  }

  /** POST /admin/transacoes/excluir — mesma recusa, mesma razão. */
  async excluir(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);
    return this.recusar('transacao_exclusao_recusada', 'excluir');
  }

  // ============================================================================ entrada

  /** A URL virando filtro válido, com os erros de campo que a view desenha. */
  private filtro(req: AppRequest, extremos: Extremos, moedasUsadas: string[]): FiltroTela {
    const msg = ((dataMock('admin-transacoes') as Dict).erros ?? {}) as Record<string, string>;
    const erros: Record<string, string> = {};
    const hojeTs = paraUnix(ymd(agoraUnix()));
    const hoje = ymd(hojeTs);
    const menosDias = (dias: number) => ymd(hojeTs - (dias - 1) * 86400);

    let periodo = req.query('periodo') ?? '';
    const deBruto = (req.query('de') ?? '').trim();
    const ateBruto = (req.query('ate') ?? '').trim();

    // Datas digitadas ganham do seletor de período.
    if (deBruto !== '' || ateBruto !== '') periodo = 'custom';
    else if (!['tudo', '7', '30', '90'].includes(periodo)) periodo = String(JANELA_PADRAO);

    let de: string;
    let ate: string;
    if (periodo === 'custom') {
      let d = this.data(deBruto);
      let a = this.data(ateBruto);
      if (deBruto !== '' && d === null) erros.de = String(msg.data ?? 'Data inválida.');
      if (ateBruto !== '' && a === null) erros.ate = String(msg.data ?? 'Data inválida.');

      // Um lado só preenchido é intenção legítima: "de X até hoje", "tudo até X".
      d ??= this.primeiroDia(extremos);
      a ??= hoje;

      if (Object.keys(erros).length === 0 && d > a) erros.de = String(msg.invertido ?? 'A data inicial é posterior à final.');
      if (Object.keys(erros).length === 0 && d > hoje) erros.de = String(msg.futuro ?? 'A data inicial é posterior a hoje.');

      if (Object.keys(erros).length > 0) {
        // Recusado o recorte, a tela volta para a janela padrão E diz que voltou.
        periodo = String(JANELA_PADRAO);
        a = hoje;
        d = menosDias(JANELA_PADRAO);
      }
      de = d;
      ate = a;
    } else if (periodo === 'tudo') {
      de = this.primeiroDia(extremos);
      ate = hoje;
    } else {
      let dias = Number.parseInt(periodo, 10) || 0;
      dias = JANELAS.includes(dias) ? dias : JANELA_PADRAO;
      ate = hoje;
      de = menosDias(dias);
    }

    let moeda = (req.query('moeda') ?? '').trim();
    if (moeda !== '' && !moedasUsadas.includes(moeda)) {
      erros.moeda = String(msg.moeda ?? 'Moeda desconhecida.');
      moeda = '';
    }

    let ord = req.query('ord') ?? 'data';
    if (!TransactionsRepo.ordensValidas().includes(ord)) ord = 'data';
    const dir = (req.query('dir') ?? 'desc').toLowerCase() === 'asc' ? 'asc' : 'desc';

    return {
      f: { de, ate, moeda, busca: corta((req.query('q') ?? '').trim(), 60) },
      periodo,
      ord,
      dir,
      bruto: { de: deBruto, ate: ateBruto },
      erros,
    };
  }

  /** Data no formato do <input type="date"> ou null. Rejeita 2026-02-31. */
  private data(v: string): string | null {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v);
    if (!m) return null;
    const ano = Number(m[1]);
    const mes = Number(m[2]);
    const dia = Number(m[3]);
    if (ano < 1 || mes < 1 || mes > 12 || dia < 1) return null;
    const ultimo = new Date(Date.UTC(ano, mes, 0)).getUTCDate();
    return dia <= ultimo ? v : null;
  }

  /** O primeiro dia com movimento (período "tudo"); sem compra nenhuma, os 30 dias padrão. */
  private primeiroDia(extremos: Extremos): string {
    if (extremos.primeira === null) return ymd(agoraUnix() - (JANELA_PADRAO - 1) * 86400);
    return ymd(paraUnix(extremos.primeira));
  }

  // ============================================================================ saídas

  /**
   * O mesmo recorte em CSV: separador `;` e BOM (o destino é o Excel pt-BR). Auditado.
   */
  private async csv(filtro: FiltroTela): Promise<AppResponse> {
    const [linhas, total] = await Promise.all([
      TransactionsRepo.lista(filtro.f, filtro.ord, filtro.dir, TETO_CSV, 0),
      TransactionsRepo.contar(filtro.f),
    ]);

    await this.auditar('transacoes_export', 'player_purchases', null, 'Exportou ' + linhas.length + ' de ' + total + ' transações em CSV.', {
      ...filtro.f,
      ordem: filtro.ord + ' ' + filtro.dir,
      teto: TETO_CSV,
    });

    const cab = ['id', 'data_hora', 'jogador_id', 'jogador', 'item_id', 'item', 'quantidade', 'preco_unitario', 'total', 'moeda'];
    let corpo = linhaCsv(cab);
    for (const l of linhas) {
      corpo += linhaCsv([
        Number(l.id) | 0,
        String(l.bought_at),
        Number(l.user_id) | 0,
        String(l.username ?? ''),
        Number(l.item_id) | 0,
        String(l.item_name ?? ''),
        Number(l.quantity) | 0,
        Math.trunc(Number(l.price)),
        Math.trunc(Number(l.total)),
        String(l.currency),
      ]);
    }

    const nome = 'transacoes_' + filtro.f.de + '_a_' + filtro.f.ate + (filtro.f.moeda !== '' ? '_' + filtro.f.moeda : '') + '.csv';

    // BOM de UTF-8: sem ele o Excel lê Ã§ onde está ç.
    return new AppResponse('﻿' + corpo, 200, {
      'Content-Type': 'text/csv; charset=UTF-8',
      'Content-Disposition': 'attachment; filename="' + nome + '"',
    });
  }

  /** A recusa de escrita, igual para salvar e excluir. */
  private async recusar(acao: string, tentativa: string): Promise<AppResponse> {
    const cromo = dataMock('admin-transacoes') as Dict;
    await this.auditar(acao, 'player_purchases', null, 'Tentativa de ' + tentativa + ' negada: a tela de transações é somente leitura.');
    flash('error', String(cromo.somente_leitura ?? 'Transações não podem ser alteradas pelo painel.'));
    return this.redirect('admin/transacoes');
  }

  /**
   * Os indicadores compactos da topbar, no recorte em tela. O terceiro mostra a moeda que
   * dominou o período (mais transações) com o total DELA.
   */
  private kpisDaTopbar(totais: { transacoes: number; jogadores: number }, porMoeda: Dict[]): Dict[] {
    let dominante: Dict | null = null;
    for (const m of porMoeda) {
      if (dominante === null || (Number(m.transacoes) | 0) > (Number(dominante.transacoes) | 0)) dominante = m;
    }
    return [
      { icon: 'receipt_long', label: 'Transações no período', value: num(totais.transacoes), tone: 'cyan' },
      { icon: 'group', label: 'Jogadores compradores', value: num(totais.jogadores), tone: 'purple' },
      {
        icon: 'payments',
        label: dominante !== null ? 'Total em ' + dominante.label : 'Total do período',
        value: dominante !== null ? num(Math.trunc(Number(dominante.total))) : null,
        sub: dominante !== null && porMoeda.length > 1 ? '+' + (porMoeda.length - 1) + ' outra(s) moeda(s)' : null,
        tone: 'gold',
      },
    ];
  }
}

/** fputcsv($h, $campos, ';', '"', ''): aspas só quando o campo pede (; " espaço, quebra, tab). */
function linhaCsv(campos: Array<string | number>): string {
  return (
    campos
      .map((c) => {
        const s = String(c);
        return /[;"\n\r\t ]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
      })
      .join(';') + '\n'
  );
}
