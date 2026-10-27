/**
 * Tela Conquistas do painel: /admin/conquistas — o App\Controllers\Admin\AchievementsController
 * do PHP.
 *
 * Governa `achievements` e `achievement_rewards`, e LÊ `player_achievements` — é dessa
 * terceira tabela que sai a única regra de negócio real da tela: conquista que já entrou no
 * histórico de um jogador não se apaga.
 *
 * COMO A TELA SE ORGANIZA (igual ao PHP)
 * Uma rota GET só. O que a coluna da direita mostra depende da URL, e não de JavaScript:
 *
 *   /admin/conquistas                -> lista + painel de categorias
 *   /admin/conquistas?novo=1         -> lista + formulário em branco
 *   /admin/conquistas?editar=7       -> lista + formulário preenchido
 *   /admin/conquistas?excluir=7      -> lista + confirmação (ou o bloqueio, com o motivo)
 *
 * A confirmação de exclusão é uma TELA, não um confirm() do navegador. Nenhuma exclusão
 * acontece por GET — o botão da confirmação é um POST.
 *
 * ONDE MORA CADA COISA
 *   SQL .................. repo/admin-achievement-repo.ts
 *   texto e rótulo ....... dataMock('admin-conquistas')
 *   validação ............ aqui, no SERVIDOR
 *   desenho .............. views/pages/admin-conquistas.ts
 */
import { dataMock, flash, icon, num, url } from '../../core/helpers';
import { html, type SafeHtml } from '../../core/html';
import type { AppRequest } from '../../core/request';
import type { AppResponse } from '../../core/response';
import type { Params } from '../../core/router';
import type { Dict } from '../../core/view';
import { corta } from '../../repo/admin-repo';
import * as AchievementRepo from '../../repo/admin-achievement-repo';
import type { RecompensaNova } from '../../repo/admin-achievement-repo';
import { adminConquistas } from '../../views/pages/admin-conquistas';
import { AdminController } from './admin-controller';

/** Linhas por página. A tabela ainda rola por dentro do cartão; isto é o limite da consulta. */
const POR_PAGINA = 20;

/** Limites que espelham o esquema (achievements) — validar contra o banco é tarde demais. */
const MAX_SLUG = 64;
const MAX_NOME = 120;
const MAX_DESC = 400;
const MAX_ICONE = 48;
const MAX_META = 1000000;
const MAX_ORDEM = 65535; // SMALLINT UNSIGNED no MySQL

/** Filtros da lista, já domados. */
interface Filtros {
  busca: string;
  categoria: string;
  estado: string;
  ordem: string;
  pagina: number;
}

/** (int) do PHP para uma string: prefixo numérico, senão 0. */
const intPhp = (v: unknown): number => {
  const n = Number.parseInt(String(v ?? '').trim(), 10);
  return Number.isNaN(n) ? 0 : n;
};

/** mb_strlen. */
const tamanho = (s: string): number => Array.from(s).length;

/** sprintf só com %s, na ordem. */
const sprintf = (fmt: string, ...args: string[]): string => {
  let i = 0;
  return fmt.replace(/%s/g, () => args[i++] ?? '');
};

const obj = (v: unknown): Dict => (v !== null && typeof v === 'object' && !Array.isArray(v) ? (v as Dict) : {});

/** `$a + $b` do PHP: as chaves do operando da ESQUERDA vencem e vêm primeiro. */
const somaEsq = (a: Record<string, string>, b: Record<string, string>): Record<string, string> => {
  const out = { ...a };
  for (const [k, v] of Object.entries(b)) if (!(k in out)) out[k] = v;
  return out;
};

export class AdminAchievementsController extends AdminController {
  async index(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    return this.tela(req);
  }

  /**
   * Cria ou atualiza. O id vem do corpo do POST: `id=0` (ou ausente) cria.
   * Erro de validação NÃO redireciona — a tela é redesenhada com os valores digitados e uma
   * mensagem por campo, em 422.
   */
  async salvar(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    let id = Math.max(0, intPhp(req.input('id') ?? 0));
    const antes = id > 0 ? await AchievementRepo.porId(id) : null;

    if (id > 0 && antes === null) {
      // A conquista sumiu entre abrir o formulário e salvar (outro operador apagou).
      flash('error', 'Esta conquista não existe mais — nada foi gravado.');
      return this.voltar(req);
    }

    const [valores, recompensas, erros] = await this.validar(req, id);

    if (Object.keys(erros).length > 0) {
      return this.tela(req, {
        form: {
          modo: id > 0 ? 'editar' : 'novo',
          id,
          valores,
          recompensas,
          erros,
          uso: id > 0 ? await AchievementRepo.uso(id) : null,
        },
      }, 422);
    }

    // Sem erros, as recompensas são as linhas validadas (amount/item_id numéricos).
    const recs = recompensas as unknown as RecompensaNova[];

    if (id > 0) {
      await AchievementRepo.atualizar(id, valores, recs);
      await this.auditar(
        'conquista_editada',
        'conquista',
        id,
        sprintf(this.frase('auditoria.editada', 'Editou a conquista "%s".'), valores.name!),
        { slug: valores.slug, mudou: this.diferencas(antes ?? {}, valores, recs) },
      );
      flash('ok', 'Conquista "' + valores.name + '" atualizada.');
    } else {
      id = await AchievementRepo.criar(valores, recs);
      await this.auditar(
        'conquista_criada',
        'conquista',
        id,
        sprintf(this.frase('auditoria.criada', 'Criou a conquista "%s".'), valores.name!),
        { slug: valores.slug, categoria: valores.category, recompensas: recs.length },
      );
      flash('ok', 'Conquista "' + valores.name + '" criada.');
    }

    // Volta para a lista com os MESMOS filtros e com a linha salva destacada.
    return this.voltar(req, { destaque: String(id) });
  }

  /**
   * Exclui — se puder. A trava é conferida AQUI, de novo, e não só na tela: o botão desarmado
   * da lista é conforto do operador, não segurança.
   */
  async excluir(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    const id = Math.max(0, intPhp(req.input('id') ?? 0));
    const row = id > 0 ? await AchievementRepo.porId(id) : null;

    if (row === null) {
      flash('error', 'Conquista não encontrada — nada foi excluído.');
      return this.voltar(req);
    }

    const uso = await AchievementRepo.uso(id);
    if (uso.desbloqueios > 0) {
      const motivo = this.motivoDaTrava(String(row.name), uso.desbloqueios);
      await this.auditar(
        'conquista_exclusao_bloqueada',
        'conquista',
        id,
        sprintf(this.frase('auditoria.bloqueada', 'Tentou excluir "%s", já desbloqueada por jogador.'), String(row.name)),
        { desbloqueios: uso.desbloqueios, progresso: uso.progresso },
      );
      flash('error', motivo);
      // Devolve para a MESMA confirmação: lá o motivo está escrito por extenso, com os números.
      return this.voltar(req, { excluir: String(id) });
    }

    await AchievementRepo.excluir(id);
    await this.auditar(
      'conquista_excluida',
      'conquista',
      id,
      sprintf(this.frase('auditoria.excluida', 'Excluiu a conquista "%s".'), String(row.name)),
      {
        slug: String(row.slug),
        categoria: String(row.category),
        recompensas: (Array.isArray(row.recompensas) ? row.recompensas : []).length,
        // Progresso perdido junto (CASCADE) — o log tem que dizer o tamanho do estrago.
        progresso_perdido: uso.progresso,
      },
    );

    flash('ok', 'Conquista "' + String(row.name) + '" excluída.');
    return this.voltar(req);
  }

  /* =====================================================================================
     MONTAGEM DA TELA
     ===================================================================================== */

  /**
   * Desenha a tela inteira. É usada pelo GET e também pelo POST que falhou na validação —
   * por isso recebe `over`: os valores digitados vencem os que estavam no banco.
   */
  private async tela(req: AppRequest, over: Dict = {}, status = 200): Promise<AppResponse> {
    const cromo = obj(dataMock('admin-conquistas'));
    const filtros = await this.filtros(req);

    const [pagina, resumo, cats] = await Promise.all([
      AchievementRepo.catalogo({ ...filtros, por_pagina: POR_PAGINA }),
      AchievementRepo.resumo(),
      AchievementRepo.categorias(),
    ]);

    // Formulário e confirmação são o MESMO lugar na tela: nunca os dois ao mesmo tempo.
    const form: Dict | null = (over.form as Dict | undefined) ?? (await this.formularioDaUrl(req));
    const exclusao = form === null ? await this.exclusaoDaUrl(req) : null;

    const page = this.adminPage('conquistas', 'Conquistas', 'Metas, categorias e recompensas do catálogo', {
      actions: this.botaoNova(filtros, form),
    });

    return this.render(adminConquistas, page, {
      cromo,
      resumo,
      catalogo: pagina,
      cats,
      filtros,
      form,
      exclusao,
      itens: form !== null ? await AchievementRepo.itens() : [],
      destaque: Math.max(0, intPhp(req.query('destaque') ?? 0)),
      // O teto de recompensas é regra do repositório; a view desenha os campos por ele.
      max_recompensas: AchievementRepo.MAX_RECOMPENSAS,

      // A casca lê estas duas chaves. Nenhum número inventado: os três são medidos.
      admin_kpis: [
        { icon: 'military_tech', label: 'Conquistas', value: num(resumo.conquistas), tone: 'lime',
          sub: 'em ' + num(resumo.categorias) + ' cat.' },
        { icon: 'lock_open', label: 'Desbloqueios', value: num(resumo.desbloqueios), tone: 'cyan',
          sub: resumo.jogadores > 0 ? 'por ' + num(resumo.jogadores) + ' jog.' : null },
        { icon: 'hourglass_empty', label: 'Sem uso', value: num(resumo.paradas), tone: resumo.paradas > 0 ? 'amber' : 'lime' },
      ],
    }, 'admin', status);
  }

  /**
   * Filtros vindos da URL, já domados: ordenação e estado só valem se estiverem na lista, e
   * a categoria só vale se EXISTIR no banco.
   */
  private async filtros(req: AppRequest): Promise<Filtros> {
    const cromo = obj(dataMock('admin-conquistas'));
    const ordens = Object.keys(obj(cromo.ordens));
    const estados = Object.keys(obj(cromo.estados));

    /* A tela também é desenhada por um POST que falhou na validação (URL sem query): os
       filtros viajam então nos campos ocultos f_* do próprio formulário. */
    const de = req.isPost()
      ? (k: string): string => String(req.input('f_' + k) ?? '')
      : (k: string): string => String(req.query(k) ?? '');

    let categoria = de('cat');
    if (categoria !== '') {
      const existentes = (await AchievementRepo.categorias()).map((c) => c.slug);
      categoria = existentes.includes(categoria) ? categoria : '';
    }

    const ordem = de('ordem');
    const estado = de('estado');

    return {
      busca: corta(de('q'), 80),
      categoria,
      estado: estados.includes(estado) ? estado : '',
      ordem: ordens.includes(ordem) ? ordem : 'ordem',
      pagina: Math.max(1, intPhp(de('pagina') !== '' ? de('pagina') : '1')),
    };
  }

  /** O formulário pedido pela URL (?novo=1 ou ?editar=N), ou null (painel de categorias). */
  private async formularioDaUrl(req: AppRequest): Promise<Dict | null> {
    const id = Math.max(0, intPhp(req.query('editar') ?? 0));
    if (id > 0) {
      const row = await AchievementRepo.porId(id);
      if (row === null) {
        flash('error', 'Conquista não encontrada.');
        return null;
      }
      return {
        modo: 'editar',
        id,
        valores: {
          slug: String(row.slug),
          name: String(row.name),
          category: String(row.category),
          description: String(row.description),
          icon: String(row.icon),
          target: String(row.target),
          sort_order: String(row.sort_order),
        },
        recompensas: (row.recompensas as Dict[]).map((r) => ({
          kind: String(r.kind),
          amount: String(r.amount),
          item_id: r.item_id === null ? '' : String(r.item_id),
        })),
        erros: {},
        uso: await AchievementRepo.uso(id),
      };
    }

    if ((req.query('novo') ?? '') === '') return null;

    // Formulário em branco já vem com a categoria filtrada escolhida e a próxima posição livre.
    let categoria = (await this.filtros(req)).categoria;
    categoria = categoria !== '' ? categoria : this.primeiraCategoria();

    return {
      modo: 'novo',
      id: 0,
      valores: {
        slug: '',
        name: '',
        category: categoria,
        description: '',
        icon: '',
        target: '1',
        sort_order: String(await AchievementRepo.proximaOrdem(categoria)),
      },
      recompensas: [],
      erros: {},
      uso: null,
    };
  }

  /** A confirmação de exclusão pedida por ?excluir=N — com os números que justificam a decisão. */
  private async exclusaoDaUrl(req: AppRequest): Promise<Dict | null> {
    const id = Math.max(0, intPhp(req.query('excluir') ?? 0));
    if (id <= 0) return null;
    const row = await AchievementRepo.porId(id);
    if (row === null) {
      flash('error', 'Conquista não encontrada.');
      return null;
    }
    const uso = await AchievementRepo.uso(id);
    return {
      conquista: row,
      uso,
      bloqueada: uso.desbloqueios > 0,
      motivo: uso.desbloqueios > 0 ? this.motivoDaTrava(String(row.name), uso.desbloqueios) : '',
    };
  }

  /* =====================================================================================
     VALIDAÇÃO — no servidor, campo a campo
     ===================================================================================== */

  /**
   * Lê o POST e devolve [valores, recompensas, erros]. Os valores voltam SEMPRE (mesmo
   * inválidos): é com eles que o formulário é redesenhado.
   */
  private async validar(req: AppRequest, id: number): Promise<[Record<string, string>, Dict[], Dict]> {
    const cromo = obj(dataMock('admin-conquistas'));
    const msg = obj(cromo.erros);
    const erros: Dict = {};

    const nome = corta(String(req.input('name') ?? ''), 200);
    let slug = corta(String(req.input('slug') ?? ''), 100).toLowerCase();
    // Slug em branco com nome preenchido não é erro: é o caso comum.
    if (slug === '' && nome !== '') slug = this.slugificar(nome);

    const categoria = String(req.input('category') ?? '');
    const descricao = String(req.input('description') ?? '');
    const icone = corta(String(req.input('icon') ?? ''), 60).toLowerCase();
    const meta = String(req.input('target') ?? '');
    let ordem = String(req.input('sort_order') ?? '');

    /* ---- slug: é a chave que o jogo usa; é o campo mais rígido da tela ---- */
    if (slug === '') {
      erros.slug = String(msg.slug_vazio ?? 'Informe o slug.');
    } else if (tamanho(slug) > MAX_SLUG) {
      erros.slug = String(msg.slug_longo ?? 'Slug longo demais.');
    } else if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug)) {
      erros.slug = String(msg.slug_formato ?? 'Formato inválido.');
    } else {
      const dono = await AchievementRepo.porSlug(slug, id);
      if (dono !== null) {
        erros.slug = sprintf(String(msg.slug_repetido ?? 'Slug em uso por %s.'), String(dono.name));
      }
    }

    /* ---- nome ---- */
    if (nome === '') {
      erros.name = String(msg.nome_vazio ?? 'Informe o nome.');
    } else if (tamanho(nome) > MAX_NOME) {
      erros.name = String(msg.nome_longo ?? 'Nome longo demais.');
    }

    /* ---- categoria: do catálogo de rótulos OU já existente no banco ---- */
    if (categoria === '' || !(await this.categoriasAceitas()).includes(categoria)) {
      erros.category = String(msg.categoria ?? 'Escolha uma categoria.');
    }

    /* ---- descrição ---- */
    if (tamanho(descricao) > MAX_DESC) {
      erros.description = sprintf(String(msg.descricao ?? 'Máximo de 400 (%s).'), num(tamanho(descricao)));
    }

    /* ---- ícone: ligadura Material Symbols, o mesmo alfabeto que icon() aceita ---- */
    if (icone !== '' && (!/^[a-z0-9_]+$/.test(icone) || tamanho(icone) > MAX_ICONE)) {
      erros.icon = String(msg.icone ?? 'Ícone inválido.');
    }

    /* ---- meta e ordem: inteiros, dentro do que a coluna aguenta ---- */
    if (!/^\d{1,7}$/.test(meta) || intPhp(meta) < 1 || intPhp(meta) > MAX_META) {
      erros.target = String(msg.meta ?? 'Meta inválida.');
    }
    if (ordem === '') ordem = '0';
    if (!/^\d{1,5}$/.test(ordem) || intPhp(ordem) > MAX_ORDEM) {
      erros.sort_order = String(msg.ordem ?? 'Ordem inválida.');
    }

    const [recompensas, errosRec] = await this.validarRecompensas(req, msg);
    if (Object.keys(errosRec).length > 0) erros.recompensas = errosRec;

    return [
      {
        slug,
        name: nome,
        category: categoria,
        description: descricao,
        icon: icone,
        target: meta,
        sort_order: ordem,
      },
      recompensas,
      erros,
    ];
  }

  /**
   * As linhas de recompensa. Linha com tipo vazio é slot não usado do formulário e some sem
   * reclamar; linha com tipo preenchido tem que fechar. Erros indexados pela linha (String(i)).
   */
  private async validarRecompensas(req: AppRequest, msg: Dict): Promise<[Dict[], Record<string, string>]> {
    const cromo = obj(dataMock('admin-conquistas'));
    const rotulos = obj(cromo.recompensas);

    const tipos = req.inputArray('rec_kind');
    const vals = req.inputArray('rec_amount');
    const itens = req.inputArray('rec_item');

    let linhas: Dict[] = [];
    const erros: Record<string, string> = {};
    const vistos = new Set<string>();

    for (let i = 0; i < tipos.length; i++) {
      const tipo = String(tipos[i]);
      if (tipo === '') continue;

      const valor = String(vals[i] ?? '');
      const item = String(itens[i] ?? '');
      const rotulo = String(obj(rotulos[tipo]).label ?? tipo);

      if (!AchievementRepo.RECOMPENSAS.includes(tipo)) {
        erros[String(i)] = String(msg.recompensa_tipo ?? 'Tipo inválido.');
        continue;
      }

      if (tipo === 'item') {
        if (!/^\d{1,10}$/.test(item) || !(await AchievementRepo.itemExiste(intPhp(item)))) {
          erros[String(i)] = String(msg.recompensa_item ?? 'Escolha o item.');
          continue;
        }
        // Quantidade do item: em branco vale 1, que é o que o jogo entrega hoje.
        const qtd = /^\d{1,7}$/.test(valor) ? Math.max(1, intPhp(valor)) : 1;
        linhas.push({ kind: 'item', amount: qtd, item_id: intPhp(item) });
        continue;
      }

      // xp/cash/points: um número por tipo. Duas linhas de XP na mesma conquista é ambiguidade.
      if (vistos.has(tipo)) {
        erros[String(i)] = sprintf(String(msg.recompensa_dupla ?? '%s repetido.'), rotulo);
        continue;
      }
      // O tipo é marcado ANTES de conferir o valor (ver o PHP: mostra a repetição de uma vez).
      vistos.add(tipo);

      if (!/^\d{1,9}$/.test(valor) || intPhp(valor) <= 0) {
        erros[String(i)] = sprintf(String(msg.recompensa_valor ?? 'Valor de %s inválido.'), rotulo);
        continue;
      }

      linhas.push({ kind: tipo, amount: intPhp(valor), item_id: null });
    }

    if (linhas.length > AchievementRepo.MAX_RECOMPENSAS) {
      erros[String(AchievementRepo.MAX_RECOMPENSAS)] = sprintf(
        String(msg.recompensa_demais ?? 'No máximo %s recompensas.'),
        String(AchievementRepo.MAX_RECOMPENSAS),
      );
      linhas = linhas.slice(0, AchievementRepo.MAX_RECOMPENSAS);
    }

    // Os valores voltam para a tela do jeito que vieram (inclusive os recusados).
    const devolver: Dict[] = [];
    tipos.forEach((tipo, i) => {
      if (String(tipo) === '') return;
      devolver.push({ kind: String(tipo), amount: String(vals[i] ?? ''), item_id: String(itens[i] ?? '') });
    });

    return [Object.keys(erros).length === 0 ? linhas : devolver, erros];
  }

  /* =====================================================================================
     APOIO
     ===================================================================================== */

  /** Categorias que o formulário aceita: as do catálogo de rótulos mais as já usadas no banco. */
  private async categoriasAceitas(): Promise<string[]> {
    const cromo = obj(dataMock('admin-conquistas'));
    return [...new Set([
      ...Object.keys(obj(cromo.categorias)),
      ...(await AchievementRepo.categorias()).map((c) => c.slug),
    ])];
  }

  private primeiraCategoria(): string {
    const cromo = obj(dataMock('admin-conquistas'));
    return Object.keys(obj(cromo.categorias))[0] ?? 'combate';
  }

  /** A frase da trava, com os dois números que o operador precisa para entender o "não". */
  private motivoDaTrava(nome: string, desbloqueios: number): string {
    const cromo = obj(dataMock('admin-conquistas'));
    const modelo = String(obj(cromo.micro).trava_excluir ?? 'Não dá para excluir: %s já está no histórico de %s.');
    return sprintf(modelo, '"' + nome + '"', num(desbloqueios) + ' jogador' + (desbloqueios === 1 ? '' : 'es'));
  }

  /** Texto de dataMock('admin-conquistas') por caminho pontilhado, com padrão. */
  private frase(caminho: string, padrao: string): string {
    let no: unknown = dataMock('admin-conquistas');
    for (const parte of caminho.split('.')) {
      if (no === null || typeof no !== 'object' || !Object.prototype.hasOwnProperty.call(no, parte)) return padrao;
      no = (no as Dict)[parte];
    }
    return typeof no === 'string' ? no : padrao;
  }

  /** O que mudou de fato, para o admin_audit_log (só os nomes dos campos). */
  private diferencas(antes: Dict, depois: Record<string, string>, recompensas: RecompensaNova[]): string[] {
    const s = (v: unknown): string => (v === null || v === undefined ? '' : String(v));
    const mudou: string[] = [];
    for (const campo of ['slug', 'name', 'category', 'description', 'icon', 'target', 'sort_order']) {
      if (s(antes[campo]) !== s(depois[campo])) mudou.push(campo);
    }
    const antesRec = Array.isArray(antes.recompensas) ? antes.recompensas : [];
    if (antesRec.length !== recompensas.length) mudou.push('recompensas');
    return mudou;
  }

  /** Botão da topbar. Some quando o formulário de criação JÁ está aberto — vira "Fechar". */
  private botaoNova(filtros: Filtros, form: Dict | null): SafeHtml {
    if (String(form?.modo ?? '') === 'novo') {
      return html`<a class="adm-btn adm-btn--ghost" href="${url('admin/conquistas', this.querystring(filtros))}">${icon('close')}<span>Fechar</span></a>`;
    }
    return html`<a class="adm-btn adm-btn--primary" href="${url('admin/conquistas', somaEsq({ novo: '1' }, this.querystring(filtros)))}">${icon('add')}<span>Nova conquista</span></a>`;
  }

  /** Volta para a lista preservando os filtros que estavam na URL do formulário. */
  private voltar(req: AppRequest, extra: Record<string, string> = {}): AppResponse {
    // Os filtros viajam no POST (campos ocultos do formulário) para sobreviver ao redirect.
    const filtros: Filtros = {
      busca: String(req.input('f_q') ?? ''),
      categoria: String(req.input('f_cat') ?? ''),
      estado: String(req.input('f_estado') ?? ''),
      ordem: String(req.input('f_ordem') ?? ''),
      pagina: Math.max(1, intPhp(req.input('f_pagina') ?? 1)),
    };
    return this.redirect('admin/conquistas', somaEsq(extra, this.querystring(filtros)));
  }

  /** Filtros -> query string, sem os vazios. */
  private querystring(f: Filtros): Record<string, string> {
    const q: Record<string, string> = {};
    if (f.busca !== '') q.q = f.busca;
    if (f.categoria !== '') q.cat = f.categoria;
    if (f.estado !== '') q.estado = f.estado;
    if (f.ordem !== 'ordem' && f.ordem !== '') q.ordem = f.ordem;
    if (f.pagina > 1) q.pagina = String(f.pagina);
    return q;
  }

  /** "CAÇADOR EXPERIENTE" -> "cacador-experiente". */
  private slugificar(texto: string): string {
    const mapa: Record<string, string> = {
      á: 'a', à: 'a', â: 'a', ã: 'a', ä: 'a',
      é: 'e', ê: 'e', è: 'e', ë: 'e',
      í: 'i', ì: 'i', î: 'i', ï: 'i',
      ó: 'o', ò: 'o', ô: 'o', õ: 'o', ö: 'o',
      ú: 'u', ù: 'u', û: 'u', ü: 'u',
      ç: 'c', ñ: 'n',
    };
    let t = texto.trim().toLowerCase();
    t = Array.from(t, (c) => mapa[c] ?? c).join('');
    t = t.replace(/[^a-z0-9]+/g, '-');
    return t.replace(/^-+|-+$/g, '');
  }
}
