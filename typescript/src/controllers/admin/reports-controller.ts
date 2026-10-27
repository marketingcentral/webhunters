/**
 * Tela RELATÓRIOS do painel: /admin/relatorios — o App\Controllers\Admin\ReportsController do PHP.
 *
 * Governa `player_bug_reports` (RELATÓRIO DE BUG) e `support_tickets` (SUPORTE). Duas abas,
 * a mesma lista, o mesmo detalhe — o vocabulário de cada tabela mora em repo/reports-repo.ts
 * e os rótulos em dataMock('admin-relatorios').
 *
 * O detalhe é SERVIDO (`?ver=12`), não aberto por JavaScript: o link é compartilhável e a
 * tela funciona sem JS. A exclusão confirma em duas requisições (`?confirmar=<id>`).
 *
 * ESCRITA: `salvar()` muda o estado de UM relatório e `excluir()` apaga um JÁ ENCERRADO.
 * Ambas passam por CSRF, revalidam contra a whitelist do repositório, auditam e respondem
 * com redirect (POST/Redirect/GET) para o mesmo recorte de onde o POST saiu.
 */
import { dataMock, flash, num } from '../../core/helpers';
import type { AppRequest } from '../../core/request';
import type { AppResponse } from '../../core/response';
import type { Params } from '../../core/router';
import type { Dict } from '../../core/view';
import { corta, formatarData } from '../../repo/admin-repo';
import * as ReportsRepo from '../../repo/reports-repo';
import * as SupportRepo from '../../repo/support-repo';
import { adminRelatorios } from '../../views/pages/admin-relatorios';
import { AdminController } from './admin-controller';

/** Linhas por página. Vinte cabem na lista sem a página inteira rolar em 1366×768. */
const POR_PAGINA = 20;

export class ReportsController extends AdminController {
  async index(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;

    const cromo = dataMock('admin-relatorios') as Dict;
    const aba = this.aba(req.query('aba', '') ?? '');
    const filtros = await this.filtros(req, aba, false);
    const pagina = Math.max(1, Number.parseInt(req.query('pag', '1') ?? '1', 10) || 0);

    const [lista, contagens, resumo, detalhe, grupos] = await Promise.all([
      ReportsRepo.listar(aba, filtros, pagina, POR_PAGINA),
      ReportsRepo.contagemPorEstado(aba, filtros),
      ReportsRepo.resumo(),
      // Um `ver` de outra aba simplesmente não abre nada (melhor que um 404 sobre a lista).
      ReportsRepo.detalhe(aba, Number.parseInt(req.query('ver', '0') ?? '0', 10) || 0),
      this.grupos(aba),
    ]);

    const page = this.adminPage('relatorios', 'Relatórios', String(cromo.abas?.[aba]?.sub ?? 'Bugs e chamados de suporte'));

    return this.render(adminRelatorios, page, {
      cromo,
      aba,
      filtros,
      lista,
      contagens,
      detalhe,
      grupos,
      pesos: this.pesos(aba, cromo),
      resumo,
      erro: req.query('erro', '') ?? '',
      // A exclusão é confirmada em DUAS requisições, não num confirm() (a CSP proíbe inline).
      confirmar: Number.parseInt(req.query('confirmar', '0') ?? '0', 10) || 0,
      admin_kpis: this.kpisTopo(resumo),
    });
  }

  /**
   * POST /admin/relatorios/salvar — muda o estado de um relatório. Só existe UM campo
   * editável: o texto é o que o jogador escreveu, e reescrevê-lo destruiria a prova do bug.
   */
  async salvar(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    const aba = this.aba(req.input('aba', '') ?? '');
    const id = Number.parseInt(req.input('id', '0') ?? '0', 10) || 0;
    // `novo` e não `status`: o formulário carrega TAMBÉM o filtro `status` da listagem.
    const novo = req.input('novo', '') ?? '';
    const volta: Record<string, string> = { ...(await this.filtros(req, aba, true)), aba, ver: String(id) };

    const atual = await ReportsRepo.detalhe(aba, id);
    if (atual === null) return this.voltar(volta, 'nao_encontrado');
    if (!ReportsRepo.estados(aba).includes(novo)) return this.voltar(volta, 'status_invalido');
    // Clique sem efeito: a mensagem diz isso e não grava auditoria que não mudou nada.
    if (novo === atual.status) return this.voltar(volta, 'status_igual');

    if (!(await ReportsRepo.mudarEstado(aba, id, novo))) return this.voltar(volta, 'nao_encontrado');

    await this.auditar('relatorio_estado', aba === 'bugs' ? 'bug' : 'ticket', id, `${atual.codigo}: ${atual.status} → ${novo}`, {
      aba,
      codigo: atual.codigo,
      de: atual.status,
      para: novo,
      user_id: atual.user_id,
    });

    const rot = (dataMock('admin-relatorios') as Dict).status?.[aba]?.[novo]?.rotulo ?? novo;
    flash('ok', `${atual.codigo} agora está como ${String(rot)}.`);
    return this.voltar(volta);
  }

  /**
   * POST /admin/relatorios/excluir — apaga um relatório encerrado. Duas travas: só
   * encerrado, e o conteúdo inteiro vai para o `meta` da auditoria ANTES do DELETE.
   */
  async excluir(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    const aba = this.aba(req.input('aba', '') ?? '');
    const id = Number.parseInt(req.input('id', '0') ?? '0', 10) || 0;
    const volta: Record<string, string> = { ...(await this.filtros(req, aba, true)), aba };

    const alvo = await ReportsRepo.detalhe(aba, id);
    if (alvo === null) return this.voltar({ ...volta, ver: String(id) }, 'nao_encontrado');
    if (!ReportsRepo.encerra(aba, String(alvo.status))) return this.voltar({ ...volta, ver: String(id) }, 'excluir_aberto');

    await this.auditar('relatorio_excluido', aba === 'bugs' ? 'bug' : 'ticket', id, `${alvo.codigo} excluído (${alvo.titulo})`, {
      aba,
      codigo: alvo.codigo,
      titulo: alvo.titulo,
      estado: alvo.status,
      grupo: alvo.grupo,
      peso: alvo.peso,
      user_id: alvo.user_id,
      jogador: alvo.jogador,
      aberto_em: formatarData(Number(alvo.criado) | 0, 'c'),
      texto: corta(String(alvo.descricao ?? ''), 4000),
      passos: corta(String(alvo.passos ?? ''), 4000),
    });

    if (!(await ReportsRepo.excluir(aba, id))) return this.voltar({ ...volta, ver: String(id) }, 'nao_encontrado');

    flash('ok', alvo.codigo + ' foi excluído. O conteúdo ficou guardado na auditoria.');
    return this.voltar(volta); // sem `ver`: o detalhe acabou de deixar de existir
  }

  // ========================================================================== apoio

  /** Aba pedida, ou a primeira. Valor desconhecido não é 404 — é a aba padrão. */
  private aba(pedida: string): string {
    const abas = ReportsRepo.abas();
    return abas.includes(pedida) ? pedida : abas[0]!;
  }

  /** Filtros da requisição, validados contra as listas fechadas (inválido vira ''). */
  private async filtros(req: AppRequest, aba: string, doPost: boolean): Promise<Record<string, string>> {
    const ler = (k: string): string => (doPost ? req.input(k, '') : req.query(k, '')) ?? '';
    const status = ler('status');
    const grupo = ler('grupo');
    const peso = ler('peso');
    const ord = ler('ord');

    const cromo = dataMock('admin-relatorios') as Dict;
    const pesosOk = Object.keys((aba === 'bugs' ? cromo.severidades : cromo.prioridades) ?? {});
    // A categoria é conferida contra o que EXISTE na tabela (build antiga continua filtrável).
    const emUso = grupo !== '' ? await ReportsRepo.gruposEmUso(aba) : [];

    return {
      // 80 caracteres: acima disso é colagem acidental, não busca.
      q: corta(ler('q').trim(), 80),
      status: ReportsRepo.estados(aba).includes(status) ? status : '',
      grupo: emUso.includes(grupo) ? grupo : '',
      peso: pesosOk.includes(peso) ? peso : '',
      ord: ['recentes', 'antigos', 'peso'].includes(ord) ? ord : 'recentes',
      pag: String(Math.max(1, Number.parseInt(ler('pag'), 10) || 0)),
    };
  }

  /**
   * Opções do seletor de CATEGORIA: os valores que existem na tabela, com o rótulo que o
   * jogador vê (áreas de dataMock('relatorio-de-bug') e categorias de support_categories).
   */
  private async grupos(aba: string): Promise<Array<{ valor: string; rotulo: string }>> {
    const rotulos: Record<string, string> = {};
    if (aba === 'bugs') {
      for (const a of ((dataMock('relatorio-de-bug') as Dict).areas ?? []) as Dict[]) rotulos[String(a.slug)] = String(a.label);
    } else {
      try {
        for (const c of await SupportRepo.categories()) rotulos[c.slug] = c.label;
      } catch {
        // Catálogo de suporte indisponível: os slugs crus ainda filtram.
      }
    }
    return (await ReportsRepo.gruposEmUso(aba)).map((valor) => ({ valor, rotulo: rotulos[valor] ?? valor.toUpperCase() }));
  }

  /** Opções de GRAVIDADE (bugs) ou PRIORIDADE (chamados), do mais grave para o menos. */
  private pesos(aba: string, cromo: Dict): Array<{ valor: string; rotulo: string; tom: string }> {
    const mapa = ((aba === 'bugs' ? cromo.severidades : cromo.prioridades) ?? {}) as Record<string, Dict>;
    return Object.entries(mapa).map(([valor, meta]) => ({
      valor,
      rotulo: String(meta?.rotulo ?? valor),
      tom: String(meta?.tom ?? 'muted'),
    }));
  }

  /** Os três KPIs da topbar: a FILA, não o total. */
  private kpisTopo(r: ReportsRepo.Resumo): Dict[] {
    return [
      { icon: 'bug_report', label: 'Bugs em aberto', value: num(r.bugs_abertos), tone: r.bugs_abertos > 0 ? 'amber' : 'lime', sub: '/ ' + num(r.bugs_total) },
      { icon: 'support_agent', label: 'Chamados em aberto', value: num(r.tickets_abertos), tone: r.tickets_abertos > 0 ? 'cyan' : 'lime', sub: '/ ' + num(r.tickets_total) },
      { icon: 'priority_high', label: 'Alta e crítica', value: num(r.criticos), tone: r.criticos > 0 ? 'red' : 'lime' },
    ];
  }

  /** Volta para a listagem no MESMO recorte de onde o POST saiu (só o que não é padrão vai na URL). */
  private voltar(estado: Record<string, string>, erro = ''): AppResponse {
    const e = { ...estado };
    if (erro !== '') {
      const msgs = ((dataMock('admin-relatorios') as Dict).erros ?? {}) as Record<string, string>;
      flash('error', String(msgs[erro] ?? 'Não foi possível concluir a ação.'));
      e.erro = erro;
    }
    const limpo: Record<string, string> = {};
    for (const [k, v] of Object.entries(e)) {
      if (v !== '' && v !== '0' && !(k === 'ord' && v === 'recentes') && !(k === 'pag' && v === '1')) limpo[k] = v;
    }
    return this.redirect('admin/relatorios', limpo);
  }
}
