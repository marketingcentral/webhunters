/**
 * Tela Itens do painel: /admin/itens — o App\Controllers\Admin\ItemsController do PHP.
 * Governa a tabela `items`.
 *
 * O controller é a POLÍTICA da tela — o que é um valor aceitável, o que pode ser excluído e
 * o que precisa ser registrado. O SQL está em repo/admin-items-repo.ts, os rótulos em
 * dataMock('admin-itens') e o desenho em views/pages/admin-itens.ts.
 *
 * DUAS DECISÕES QUE EXPLICAM O RESTO DO ARQUIVO (iguais ao PHP):
 *
 * 1. VALIDAÇÃO NO SERVIDOR, SEMPRE, A PARTIR DE UM ESQUEMA FIXO (CAMPOS).
 *    O objeto gravado no banco é montado campo a campo a partir dessa constante, nunca do
 *    POST inteiro. Um campo extra injetado no formulário não tem para onde ir.
 *
 * 2. EXCLUSÃO PRECISA PROVAR QUE NÃO DESTRÓI NADA.
 *    `player_items.item_id` e `shop_offers.item_id` apagam EM CASCATA. Por isso a exclusão
 *    tem duas etapas — uma tela de confirmação que MOSTRA os números medidos, e um POST que
 *    reconfere esses números antes de apagar.
 *
 * Erro de formulário não perde o que o operador digitou: os valores e as mensagens por campo
 * vão para a sessão e voltam no GET seguinte (POST/Redirect/GET).
 *
 * DIFERENÇAS PARA O PHP (limitação de plataforma, comentadas onde acontecem):
 *   - JSON: o erro de JSON.parse não tem o texto do json_last_error_msg(); a mensagem usa
 *     sempre "Syntax error" (o caso de longe mais comum no PHP). E o JS não distingue 1 de
 *     1.0 nem `{}` de `[]` (o PHP com `true` regravava `{}` como `[]`): aqui `{}` fica `{}`.
 */
import { ctx } from '../../core/context';
import { dataMock, flash, icon, url } from '../../core/helpers';
import { html } from '../../core/html';
import type { AppRequest } from '../../core/request';
import type { AppResponse } from '../../core/response';
import type { Params } from '../../core/router';
import type { Dict } from '../../core/view';
import { corta } from '../../repo/admin-repo';
import * as ItemsRepo from '../../repo/admin-items-repo';
import type { Usos } from '../../repo/admin-items-repo';
import { adminItens } from '../../views/pages/admin-itens';
import { AdminController } from './admin-controller';

/** Chave de sessão que carrega erros + valores digitados de um POST recusado. */
const SESSAO_FORM = 'adm_itens_form';

interface Regra {
  tipo: 'texto' | 'inteiro' | 'decimal' | 'bool' | 'json';
  max?: number;
  min?: number;
  obrigatorio?: boolean;
}

/**
 * O contrato de escrita da tela: coluna => tipo. É esta lista, e só ela, que define quais
 * colunas de `items` o painel escreve. As faixas espelham o tipo da coluna no MySQL.
 */
const CAMPOS: Record<string, Regra> = {
  slug: { tipo: 'texto', max: 64, obrigatorio: true },
  name: { tipo: 'texto', max: 120, obrigatorio: true },
  short_name: { tipo: 'texto', max: 32 },
  category: { tipo: 'texto', max: 24, obrigatorio: true },
  subtype: { tipo: 'texto', max: 32 },
  slot_type: { tipo: 'texto', max: 24 },
  grid_w: { tipo: 'inteiro', min: 1, max: 20, obrigatorio: true },
  grid_h: { tipo: 'inteiro', min: 1, max: 20, obrigatorio: true },
  weight_kg: { tipo: 'decimal', min: 0, max: 999.999, obrigatorio: true },
  rarity: { tipo: 'texto', max: 16, obrigatorio: true },
  image: { tipo: 'texto', max: 160, obrigatorio: true },
  description: { tipo: 'texto', max: 400 },
  base_price: { tipo: 'inteiro', min: 0, max: 2000000000, obrigatorio: true },
  currency: { tipo: 'texto', max: 12, obrigatorio: true },
  stackable: { tipo: 'bool' },
  max_stack: { tipo: 'inteiro', min: 1, max: 65535, obrigatorio: true },
  durability_max: { tipo: 'inteiro', min: 1, max: 65535 },
  grid_cols: { tipo: 'inteiro', min: 1, max: 255 },
  grid_rows: { tipo: 'inteiro', min: 1, max: 255 },
  ammo_caliber: { tipo: 'texto', max: 24 },
  mag_capacity: { tipo: 'inteiro', min: 1, max: 65535 },
  attach_slots: { tipo: 'json' },
  fits_calibers: { tipo: 'json' },
  meta: { tipo: 'json' },
};

/** Erros por campo: nome do campo -> mensagem. */
type Erros = Record<string, string>;

/** O que um POST recusado deixa na sessão. */
interface Devolucao {
  erros?: Erros;
  valores?: Record<string, string>;
  id?: number;
}

/** (int) do PHP para uma string: prefixo numérico, senão 0. */
const intPhp = (v: unknown): number => {
  const n = Number.parseInt(String(v ?? '').trim(), 10);
  return Number.isNaN(n) ? 0 : n;
};

/** mb_strlen. */
const tamanho = (s: string): number => Array.from(s).length;

/** O `$a + $b` do PHP para arrays associativos: chaves de `b` só entram se faltarem em `a`. */
const somar = (a: Record<string, string>, b: Record<string, string>): Record<string, string> => ({
  ...a,
  ...Object.fromEntries(Object.entries(b).filter(([k]) => !(k in a))),
});

/** O número no formato que o PHP escreve numa string ("999.999", "0", "2000000000"). */
const numPhp = (n: number | undefined): string => String(n ?? 0);

export class AdminItemsController extends AdminController {
  async index(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;

    const cromo = (dataMock('admin-itens') ?? {}) as Dict;
    const filtros = this.filtros(req);
    // `p` é o nome curto na URL; o repo pagina por `pagina`.
    const lista = await ItemsRepo.listar({ ...filtros, pagina: intPhp(filtros.p ?? 1) });

    // Vocabulário oferecido = o que a tela conhece MAIS o que já existe no banco.
    const [catsBanco, rarsBanco, resumo] = await Promise.all([
      ItemsRepo.categoriasUsadas(),
      ItemsRepo.raridadesUsadas(),
      ItemsRepo.resumo(),
    ]);

    // Formulário: ?form=novo abre em branco, ?form=<id> abre preenchido. O que voltou de
    // um POST recusado vence o banco.
    const devolvido = this.consumirDevolucao();
    const edicao = await this.itemDoFormulario(req, devolvido);

    // Exclusão: ?excluir=<id> é só a tela de confirmação. Quem apaga é o POST.
    let excluir: Dict | null = null;
    const alvoId = intPhp(req.query('excluir') ?? 0);
    if (alvoId > 0) {
      const alvo = await ItemsRepo.porId(alvoId);
      if (alvo !== null) excluir = { item: alvo, usos: await ItemsRepo.usos(alvoId) };
    }

    const page = this.adminPage('itens', 'Itens', 'Catálogo, raridade e atributos', {
      actions: html`<a class="adm-btn adm-btn--primary" href="${url('admin/itens', somar(filtros, { form: 'novo' }))}#adm-form">${icon('add')}<span>Novo item</span></a>`,
    });

    return this.render(adminItens, page, {
      cromo,
      admin_conta: this.admin(),

      resumo,
      linhas: lista.linhas,
      total: lista.total,
      pagina: lista.pagina,
      paginas: lista.paginas,
      por_pagina: ItemsRepo.POR_PAGINA,

      filtros,
      tem_filtro: (filtros.q ?? '') !== '' || (filtros.cat ?? '') !== '' || (filtros.rar ?? '') !== '',
      cats_banco: catsBanco,
      rars_banco: rarsBanco,

      edicao,
      erros: devolvido.erros ?? {},
      excluir,
    });
  }

  /** POST /admin/itens/salvar — cria (id vazio) ou edita (id existente). */
  async salvar(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    const filtros = this.filtrosDoPost(req);
    const id = intPhp(req.input('id') ?? 0);
    const atual = id > 0 ? await ItemsRepo.porId(id) : null;

    if (id > 0 && atual === null) {
      // O item sumiu entre abrir o formulário e salvar (outro operador excluiu).
      flash('error', 'Este item não existe mais — ele foi excluído enquanto o formulário estava aberto.');
      return this.redirect('admin/itens', filtros);
    }

    const [dados, erros] = await this.validar(req, atual);
    const nErros = Object.keys(erros).length;

    if (nErros > 0) {
      this.devolver(erros, this.valoresCrus(req), id);
      flash('error', nErros === 1
        ? 'Um campo precisa de correção — a mensagem está abaixo dele.'
        : nErros + ' campos precisam de correção — as mensagens estão abaixo deles.');
      return this.redirect('admin/itens', somar(filtros, { form: id > 0 ? String(id) : 'novo' }));
    }

    if (atual === null) {
      const novoId = await ItemsRepo.criar(dados);
      await this.auditar('item_criado', 'item', novoId, `Item criado: ${String(dados.name)} (${String(dados.slug)}).`, {
        slug: dados.slug,
        category: dados.category,
        rarity: dados.rarity,
        price: String(dados.base_price) + ' ' + String(dados.currency),
      });
      flash('ok', `Item “${String(dados.name)}” cadastrado.`);
      return this.redirect('admin/itens', filtros);
    }

    // Só o que MUDOU vai para o UPDATE e para a auditoria.
    const mudou = this.diferenca(atual, dados);
    const cols = Object.keys(mudou);
    if (cols.length === 0) {
      flash('info', 'Nada foi alterado — os valores enviados são iguais aos que já estavam gravados.');
      return this.redirect('admin/itens', filtros);
    }

    const parcial: Dict = {};
    for (const c of cols) parcial[c] = dados[c];
    await ItemsRepo.atualizar(id, parcial);

    const anterior: Dict = {};
    const novo: Dict = {};
    for (const c of cols) {
      anterior[c] = mudou[c]!.de;
      novo[c] = mudou[c]!.para;
    }
    await this.auditar('item_editado', 'item', id, `Item editado: ${String(dados.name)} (${String(dados.slug)}).`, {
      campos: cols,
      anterior,
      novo,
    });
    flash('ok', `Item “${String(dados.name)}” atualizado — ${cols.length} campo(s) alterado(s).`);

    return this.redirect('admin/itens', filtros);
  }

  /** POST /admin/itens/excluir — só executa quando NADA referencia o item. */
  async excluir(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    const filtros = this.filtrosDoPost(req);
    const id = intPhp(req.input('id') ?? 0);
    const item = await ItemsRepo.porId(id);

    if (item === null) {
      flash('error', 'Item não encontrado — talvez já tenha sido excluído.');
      return this.redirect('admin/itens', filtros);
    }

    // Reconferência: os números da tela de confirmação são de ANTES do clique.
    const usos = await ItemsRepo.usos(id);
    if (usos.total > 0) {
      await this.auditar('item_exclusao_bloqueada', 'item', id,
        `Exclusão bloqueada: ${String(item.name)} ainda é referenciado.`, { ...usos });
      flash('error', 'Não dá para excluir “' + String(item.name) + '”: ' + this.motivoDoBloqueio(usos));
      return this.redirect('admin/itens', somar(filtros, { excluir: String(id) }));
    }

    await ItemsRepo.excluir(id);
    // A linha inteira vai para o log: depois do DELETE, a auditoria é a única cópia que resta.
    await this.auditar('item_excluido', 'item', id, `Item excluído: ${String(item.name)} (${String(item.slug)}).`, item);
    flash('ok', `Item “${String(item.name)}” excluído do catálogo.`);

    return this.redirect('admin/itens', filtros);
  }

  /* ====================================================================== validação */

  /** Converte o POST em linha de banco. Devolve [dados, erros] — dados só vale sem erros. */
  private async validar(req: AppRequest, atual: Dict | null): Promise<[Dict, Erros]> {
    const cromo = (dataMock('admin-itens') ?? {}) as Dict;
    const dados: Dict = {};
    const erros: Erros = {};

    for (const [campo, regra] of Object.entries(CAMPOS)) {
      const cru = String(req.input(campo) ?? '').trim();

      // Checkbox não enviado = desmarcado; não é "campo vazio".
      if (regra.tipo === 'bool') {
        dados[campo] = cru !== '' && cru !== '0' ? 1 : 0;
        continue;
      }

      if (cru === '') {
        if (regra.obrigatorio) {
          erros[campo] = 'Campo obrigatório.';
          continue;
        }
        dados[campo] = null; // coluna anulável: vazio é NULL, nunca string vazia
        continue;
      }

      switch (regra.tipo) {
        case 'inteiro': {
          if (!/^\d{1,10}$/.test(cru)) {
            erros[campo] = 'Use apenas números inteiros, sem sinal.';
            break;
          }
          const n = Number.parseInt(cru, 10);
          if (n < regra.min! || n > regra.max!) {
            erros[campo] = `Valor fora da faixa aceita (${numPhp(regra.min)} a ${numPhp(regra.max)}).`;
            break;
          }
          dados[campo] = n;
          break;
        }

        case 'decimal': {
          // O teclado brasileiro digita vírgula; a coluna quer ponto.
          const norm = cru.replace(/,/g, '.');
          if (!/^\d{1,4}(\.\d{1,3})?$/.test(norm)) {
            erros[campo] = 'Use números com até três casas decimais (ex.: 3,600).';
            break;
          }
          const f = Number.parseFloat(norm);
          if (f < regra.min! || f > regra.max!) {
            erros[campo] = `Valor fora da faixa aceita (${numPhp(regra.min)} a ${numPhp(regra.max)}).`;
            break;
          }
          // number_format($f, 3, '.', ''): string com 3 casas (a coluna REAL converte sozinha).
          dados[campo] = f.toFixed(3);
          break;
        }

        case 'json': {
          let decodificado: unknown;
          try {
            decodificado = JSON.parse(cru);
          } catch {
            // json_last_error_msg() não existe no JS: o texto do PHP para o caso comum.
            erros[campo] = 'JSON inválido: Syntax error.';
            break;
          }
          // Regravado a partir do que foi decodificado: o banco recebe JSON canônico.
          // (JSON.stringify já não escapa barra nem Unicode, como os flags do PHP.)
          dados[campo] = JSON.stringify(decodificado);
          break;
        }

        default:
          if (tamanho(cru) > regra.max!) {
            erros[campo] = `Máximo de ${regra.max} caracteres (enviados: ${tamanho(cru)}).`;
            break;
          }
          dados[campo] = cru;
      }
    }

    /* ---- regras que dependem de mais de um campo ---- */

    if (!('slug' in erros) && dados.slug !== undefined && dados.slug !== null) {
      // strtolower do PHP 8 é só ASCII; o regex abaixo recusa o resto de qualquer jeito.
      const slug = String(dados.slug).replace(/[A-Z]/g, (c) => c.toLowerCase());
      if (!/^[a-z0-9][a-z0-9-]{1,63}$/.test(slug)) {
        erros.slug = 'Use minúsculas, números e hífen; comece por letra ou número (mínimo 2 caracteres).';
      } else if (await ItemsRepo.slugEmUso(slug, intPhp(atual?.id ?? 0))) {
        erros.slug = 'Já existe outro item com este slug.';
      } else {
        dados.slug = slug;
      }
    }

    if (!('name' in erros) && tamanho(String(dados.name ?? '')) < 2) {
      erros.name = 'O nome precisa de pelo menos 2 caracteres.';
    }

    // Categoria/raridade/moeda/slot: o valor precisa ser conhecido OU já existir no banco.
    const [catsBanco, rarsBanco] = await Promise.all([ItemsRepo.categoriasUsadas(), ItemsRepo.raridadesUsadas()]);
    const chaves = (v: unknown): string[] => (v !== null && typeof v === 'object' ? Object.keys(v) : []);
    this.exigirVocabulario(dados, erros, 'category', chaves(cromo.categorias), Object.keys(catsBanco));
    this.exigirVocabulario(dados, erros, 'rarity', chaves(cromo.raridades), Object.keys(rarsBanco));
    this.exigirVocabulario(dados, erros, 'currency', chaves(cromo.moedas), []);
    this.exigirVocabulario(dados, erros, 'slot_type', chaves(cromo.slots), []);

    if (!('subtype' in erros) && (dados.subtype ?? null) !== null && !/^[a-z0-9_]+$/.test(String(dados.subtype))) {
      erros.subtype = 'Use minúsculas, números e sublinhado (ex.: assault_rifle).';
    }

    // O caminho da imagem vira URL em media()/img(). Barra inicial, `..` e espaço abririam
    // caminho para fora da pasta de mídia ou para um link quebrado.
    if (!('image' in erros) && dados.image !== undefined && dados.image !== null
      && !/^[a-z0-9][a-z0-9._/-]*\.(png|jpg|jpeg|webp|gif|svg)$/i.test(String(dados.image))) {
      erros.image = 'Caminho relativo dentro de /media, terminando em .png/.jpg/.webp (ex.: items/ak103.png).';
    }

    // Empilhamento: os dois campos precisam contar a mesma história.
    const empilha = intPhp(dados.stackable ?? 0) === 1;
    const pilha = intPhp(dados.max_stack ?? 1);
    if (!('max_stack' in erros)) {
      if (empilha && pilha < 2) {
        erros.max_stack = 'Item empilhável precisa de pilha máxima de pelo menos 2.';
      } else if (!empilha && pilha > 1) {
        // Não é erro do operador: é uma correção óbvia.
        dados.max_stack = 1;
      }
    }

    // Grade interna de contêiner: coluna sem linha não descreve grade nenhuma.
    const temCols = (dados.grid_cols ?? null) !== null;
    const temRows = (dados.grid_rows ?? null) !== null;
    if (temCols !== temRows) {
      const faltando = temCols ? 'grid_rows' : 'grid_cols';
      erros[faltando] = 'Preencha colunas E linhas da grade interna, ou deixe as duas em branco.';
    }

    // JSON de estrutura: precisa ser lista/objeto (is_array do PHP), não um número solto.
    for (const campo of ['attach_slots', 'fits_calibers']) {
      if (!(campo in erros) && (dados[campo] ?? null) !== null) {
        const d: unknown = JSON.parse(String(dados[campo]));
        if (d === null || typeof d !== 'object') {
          erros[campo] = 'Esperado um array JSON (ex.: ["7.62x39"]).';
        }
      }
    }

    return [dados, erros];
  }

  /** Um valor de lista fechada. `extras` traz o que o banco já contém. */
  private exigirVocabulario(dados: Dict, erros: Erros, campo: string, aceitos: string[], extras: string[]): void {
    const valor = dados[campo] ?? null;
    if (valor === null || campo in erros) return;
    if (!aceitos.includes(String(valor)) && !extras.includes(String(valor))) {
      erros[campo] = 'Valor não reconhecido para este campo.';
    }
  }

  /**
   * Colunas realmente alteradas: { coluna: { de, para } }.
   * Comparação como STRING de propósito; as colunas JSON são comparadas pela estrutura
   * (decodificadas), senão a formatação diferente acusaria mudança em todo salvamento.
   */
  private diferenca(antes: Dict, depois: Dict): Record<string, { de: unknown; para: unknown }> {
    const mudou: Record<string, { de: unknown; para: unknown }> = {};
    for (const [col, novo] of Object.entries(depois)) {
      if (!(col in antes)) continue;
      const velho: unknown = antes[col] === undefined ? null : antes[col];

      if (CAMPOS[col]?.tipo === 'json') {
        // O `===` de arrays do PHP: mesma estrutura, mesma ordem — JSON.stringify compara isso.
        const de = velho === null ? null : this.jsonCanonico(String(velho));
        const para = novo === null ? null : this.jsonCanonico(String(novo));
        if (de !== para) mudou[col] = { de: velho, para: novo };
        continue;
      }

      const de = velho === null ? null : String(velho);
      const para = novo === null ? null : String(novo);
      if (de !== para) mudou[col] = { de: velho, para: novo };
    }
    return mudou;
  }

  /** json_decode + reencode para comparar estrutura; JSON inválido no banco vale como "null" (json_decode devolvia null). */
  private jsonCanonico(s: string): string {
    try {
      return JSON.stringify(JSON.parse(s));
    } catch {
      return 'null';
    }
  }

  /** Frase única que explica o bloqueio, na ordem do estrago que a exclusão causaria. */
  private motivoDoBloqueio(usos: Usos): string {
    const partes: string[] = [];
    if (usos.instancias > 0) {
      partes.push(usos.instancias + ' unidade(s) no inventário de ' + usos.jogadores + ' jogador(es)');
    }
    if (usos.ofertas > 0) partes.push(usos.ofertas + ' oferta(s) na loja');
    if (usos.missoes > 0) partes.push(usos.missoes + ' recompensa(s) de missão');
    if (usos.conquistas > 0) partes.push(usos.conquistas + ' recompensa(s) de conquista');

    return 'ele ainda é referenciado por ' + partes.join(', ') + '.';
  }

  /* =================================================================== estado da tela */

  /** Filtros da listagem, saneados. Prontos para voltar como query string em qualquer redirect. */
  private filtros(req: AppRequest): Record<string, string> {
    const cromo = (dataMock('admin-itens') ?? {}) as Dict;
    const ord = String(req.query('ord') ?? '');
    const q = corta(String(req.query('q') ?? '').trim(), 64);
    const ordens = (cromo.ordenacoes ?? {}) as Dict;

    const f: Record<string, string> = {};
    if (q !== '') f.q = q;
    const cat = String(req.query('cat') ?? '').trim();
    if (cat !== '') f.cat = corta(cat, 24);
    const rar = String(req.query('rar') ?? '').trim();
    if (rar !== '') f.rar = corta(rar, 16);
    if (ord !== '' && Object.prototype.hasOwnProperty.call(ordens, ord) && ordens[ord] != null) f.ord = ord;

    const p = intPhp(req.query('p') ?? 1);
    if (p > 1) f.p = String(p);

    return f;
  }

  /** Os mesmos filtros, vindos dos campos ocultos do formulário (f_q, f_cat...). */
  private filtrosDoPost(req: AppRequest): Record<string, string> {
    const f: Record<string, string> = {};
    for (const chave of ['q', 'cat', 'rar', 'ord', 'p']) {
      const v = String(req.input('f_' + chave) ?? '').trim();
      if (v !== '') f[chave] = corta(v, 64);
    }
    return f;
  }

  /** A linha que o formulário deve mostrar, ou null quando o formulário está fechado. */
  private async itemDoFormulario(req: AppRequest, devolvido: Devolucao): Promise<Dict | null> {
    const form = String(req.query('form') ?? '').trim();
    if (form === '') return null;

    let base: Dict = {
      id: 0, grid_w: 1, grid_h: 1, weight_kg: '0.000',
      rarity: 'comum', currency: 'credits', stackable: 0,
      max_stack: 1, base_price: 0,
    };

    if (form !== 'novo') {
      const item = await ItemsRepo.porId(intPhp(form));
      if (item === null) return null;
      base = item;
    }

    // Valores recusados voltam por cima: o operador corrige o que errou, não redigita tudo.
    const valores = devolvido.valores ?? {};
    if (Object.keys(valores).length > 0 && intPhp(devolvido.id ?? 0) === intPhp(base.id ?? 0)) {
      base = { ...base, ...valores };
    }
    return base;
  }

  /** Guarda erros + o que foi digitado para o GET seguinte. */
  private devolver(erros: Erros, valores: Record<string, string>, id: number): void {
    ctx().session.set(SESSAO_FORM, { erros, valores, id });
  }

  /** Lê e APAGA a devolução: recarregar a tela depois de corrigir não pode ressuscitar o erro. */
  private consumirDevolucao(): Devolucao {
    const s = ctx().session;
    const d = s.get<Devolucao>(SESSAO_FORM);
    s.delete(SESSAO_FORM);
    return d !== null && typeof d === 'object' && !Array.isArray(d) ? d : {};
  }

  /** O que o operador digitou, exatamente como digitou — é isso que volta ao formulário recusado. */
  private valoresCrus(req: AppRequest): Record<string, string> {
    const out: Record<string, string> = {};
    for (const campo of Object.keys(CAMPOS)) {
      out[campo] = corta(String(req.input(campo) ?? '').trim(), 4000);
    }
    out.id = String(intPhp(req.input('id') ?? 0));
    return out;
  }
}
