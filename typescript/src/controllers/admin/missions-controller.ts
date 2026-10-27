/**
 * Tela MISSÕES do painel: /admin/missoes — o App\Controllers\Admin\MissionsController do PHP.
 *
 * Governa: `missions` (catálogo) e `mission_rewards` (o que a missão paga). Lê, sem escrever,
 * `player_missions` — porque é ela que decide se uma exclusão é permitida.
 *
 * TRÊS DECISÕES QUE EXPLICAM O RESTO DO ARQUIVO (iguais ao PHP)
 * 1. A tela é UMA rota. Lista, formulário e confirmação de exclusão são estados de
 *    /admin/missoes (?nova, ?editar=<id>, ?excluir=<id>), não páginas diferentes.
 * 2. Erro de validação NÃO redireciona: o POST de salvar renderiza a própria tela de volta
 *    (422), com o que o operador digitou e uma mensagem por campo. Sucesso redireciona (PRG).
 * 3. Exclusão é bloqueada, não avisada, quando há jogador em campo (`player_missions` tem
 *    ON DELETE CASCADE). A saída real é marcar como BLOQUEADA.
 *
 * Vocabulário: origem, facção, faixa de reputação e teto de esquadrão saem de GameRules;
 * tipo, dificuldade, atividade humana e espécie de recompensa saem de dataMock('admin-missoes').
 * Quem une isso ao que o banco já guarda é repo/admin-mission-repo.ts.
 */
import { SQUAD_MAX } from '../../core/game-rules';
import { dataMock, flash } from '../../core/helpers';
import type { AppRequest } from '../../core/request';
import type { AppResponse } from '../../core/response';
import type { Params } from '../../core/router';
import type { Dict } from '../../core/view';
import { corta } from '../../repo/admin-repo';
import * as MissionRepo from '../../repo/admin-mission-repo';
import type { Recompensa } from '../../repo/admin-mission-repo';
import { adminMissoes } from '../../views/pages/admin-missoes';
import { AdminController } from './admin-controller';

/** Teto de caracteres por coluna, igual ao db/schema.sql. */
const LIMITES: Record<string, number> = {
  slug: 64, name: 120, giver: 80, region: 80,
  difficulty: 16, objective: 400, image: 160, human_activity: 16,
  requires_faction: 16, requires_tier: 24,
};

/** Quantas linhas de recompensa o formulário oferece. */
const LINHAS_RECOMPENSA = 6;

/** Chaves dos filtros da listagem (a ordem é a da query string do redirect). */
const FILTROS = ['q', 'tipo', 'origem', 'dificuldade', 'estado', 'ordem', 'pagina'];

/** Erros por campo: nome do campo -> mensagem. */
type Erros = Record<string, string>;

/** O que o POST com erro força na tela (o $forcar do PHP). */
interface Forcar {
  modo?: string;
  id?: number;
  valores?: Dict;
  recompensas?: Recompensa[];
  erros?: Erros;
}

/** `isset($lista[$k])` de um array associativo. */
const tem = (lista: unknown, k: string): boolean =>
  lista !== null && typeof lista === 'object' && Object.prototype.hasOwnProperty.call(lista, k) && (lista as Dict)[k] != null;

/** (int) do PHP para uma string: prefixo numérico, senão 0. */
const intPhp = (v: unknown): number => {
  const n = Number.parseInt(String(v ?? '').trim(), 10);
  return Number.isNaN(n) ? 0 : n;
};

export class AdminMissionsController extends AdminController {
  // ================================================================================ GET

  async index(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    return this.tela(req);
  }

  // =============================================================================== POST

  /** Cria ou atualiza uma missão e o conjunto inteiro das suas recompensas. */
  async salvar(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    const id = intPhp(req.input('id') ?? 0);
    const antes = id > 0 ? await MissionRepo.porId(id) : null;
    if (id > 0 && antes === null) {
      flash('error', 'Missão não encontrada: ela pode ter sido excluída em outra aba.');
      return this.redirect('admin/missoes');
    }

    const [dados, recompensas, erros] = await this.validar(req, id);

    if (Object.keys(erros).length > 0) {
      // Volta o formulário com o que foi digitado — nada de perder o objetivo escrito.
      return this.tela(req, {
        modo: id > 0 ? 'editar' : 'nova',
        id,
        valores: dados,
        recompensas,
        erros,
      }, 422);
    }

    const novoId = await MissionRepo.salvar(dados, recompensas, id > 0 ? id : null);

    // A auditoria guarda o DIFF, não o registro inteiro.
    const mudou = antes === null ? [] : this.diferencas(antes, dados);
    const meta: Dict = { slug: dados.slug, recompensas: recompensas.length };
    if (mudou.length > 0) meta.mudou = mudou;
    await this.auditar(
      antes === null ? 'missao_criada' : 'missao_editada',
      'missao',
      novoId,
      (antes === null ? 'Missão criada: ' : 'Missão editada: ') + String(dados.name),
      meta,
    );

    flash('ok', antes === null
      ? 'Missão "' + String(dados.name) + '" criada.'
      : 'Missão "' + String(dados.name) + '" atualizada.');

    return this.redirect('admin/missoes', { ...this.filtrosDaUrl(req), destaque: String(novoId) });
  }

  /** Exclui uma missão — e recusa quando há jogador em campo com ela. */
  async excluir(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    const id = intPhp(req.input('id') ?? 0);
    const missao = await MissionRepo.porId(id);

    if (missao === null) {
      flash('error', 'Missão não encontrada: ela pode já ter sido excluída.');
      return this.redirect('admin/missoes');
    }

    // A checagem é refeita AQUI, e não só na tela de confirmação: entre abrir a
    // confirmação e clicar em excluir, um jogador pode ter aceitado a missão.
    const uso = await MissionRepo.usoPorJogadores(id);
    if (uso.em_campo > 0) {
      await this.auditar('missao_exclusao_bloqueada', 'missao', id,
        'Exclusão recusada: ' + uso.em_campo + ' jogador(es) em campo.',
        { slug: String(missao.slug), em_campo: uso.em_campo });
      flash('error',
        `A missão "${String(missao.name)}" não foi excluída: ${uso.em_campo} jogador(es) estão com ela em campo agora.`);
      return this.redirect('admin/missoes', { editar: String(id) });
    }

    const recompensas = (await MissionRepo.recompensasDe([id]))[id] ?? [];
    await MissionRepo.excluir(id);

    await this.auditar('missao_excluida', 'missao', id,
      'Missão excluída: ' + String(missao.name),
      {
        slug: String(missao.slug),
        tipo: String(missao.type),
        origem: String(missao.origin),
        recompensas: recompensas.length,
        registros_jogador: uso.total,
      });

    flash('ok', 'Missão "' + String(missao.name) + '" excluída.');
    return this.redirect('admin/missoes', this.filtrosDaUrl(req));
  }

  // ============================================================================== tela

  /**
   * Monta a tela inteira. É o mesmo método para o GET da lista e para o POST que voltou
   * com erro — a diferença viaja em `forcar`.
   */
  private async tela(req: AppRequest, forcar: Forcar = {}, status = 200): Promise<AppResponse> {
    const txt = (dataMock('admin-missoes') ?? {}) as Dict;
    const vocab = await MissionRepo.vocabulario();

    /* Os filtros são lidos do POST TAMBÉM: quando o formulário volta com erro de validação,
       a URL é /admin/missoes/salvar e a query string ficou para trás. O formulário reenvia
       os filtros em campos ocultos. */
    const par = (k: string): string => String(req.query(k) ?? req.input(k) ?? '');

    const filtros: Dict = {
      q: par('q'),
      tipo: this.naLista(par('tipo'), vocab.tipos),
      origem: this.naLista(par('origem'), vocab.origens),
      dificuldade: this.naLista(par('dificuldade'), vocab.dificuldades),
      estado: this.naLista(par('estado'), txt.estados ?? {}),
      ordem: this.naLista(par('ordem'), txt.ordenacoes ?? {}) || 'ordem',
      pagina: Math.max(1, intPhp(par('pagina')) || 1),
    };

    const lista = await MissionRepo.listar(filtros);
    const ids = lista.linhas.map((l) => Number(l.id) | 0);
    const recs = await MissionRepo.recompensasDe(ids);
    for (const l of lista.linhas) l.premios = recs[Number(l.id) | 0] ?? [];

    // ---- qual painel está aberto: lista pura, formulário ou confirmação de exclusão
    let modo = String(forcar.modo ?? '');
    if (modo === '') {
      if (req.query('nova') !== null) modo = 'nova';
      else if (intPhp(req.query('editar') ?? 0) > 0) modo = 'editar';
      else if (intPhp(req.query('excluir') ?? 0) > 0) modo = 'excluir';
    }

    let edicao: Dict | null = null;
    let alvo: Dict | null = null;

    if (modo === 'nova' || modo === 'editar') {
      const id = intPhp(forcar.id ?? req.query('editar') ?? 0);
      const original = id > 0 ? await MissionRepo.porId(id) : null;
      if (modo === 'editar' && original === null) {
        modo = ''; // a missão sumiu (outra aba): a lista se explica sozinha
      } else {
        let premios: Recompensa[] = [];
        if (forcar.recompensas !== undefined) {
          premios = forcar.recompensas;
        } else if (original !== null) {
          // A missão em edição pode estar fora da página atual da lista — daí a busca própria.
          const oid = Number(original.id) | 0;
          premios = (await MissionRepo.recompensasDe([oid]))[oid] ?? [];
        }
        edicao = {
          id: original !== null ? Number(original.id) | 0 : 0,
          valores: forcar.valores !== undefined ? forcar.valores : this.valoresDe(original),
          recompensas: premios,
          erros: forcar.erros ?? {},
          uso: original !== null ? await MissionRepo.usoPorJogadores(Number(original.id) | 0) : null,
        };
      }
    } else if (modo === 'excluir') {
      const id = intPhp(req.query('excluir') ?? 0);
      const alvoM = await MissionRepo.porId(id);
      if (alvoM === null) {
        modo = '';
      } else {
        const [uso, recsAlvo] = await Promise.all([MissionRepo.usoPorJogadores(id), MissionRepo.recompensasDe([id])]);
        alvo = { missao: alvoM, uso, recompensas: recsAlvo[id] ?? [] };
      }
    }

    const page = this.adminPage('missoes', String(txt.titulo ?? 'Missões'), String(txt.subtitulo ?? ''));

    const [resumo, itens, sugestoes] = await Promise.all([
      MissionRepo.resumo(),
      MissionRepo.itens(),
      MissionRepo.sugestoes(),
    ]);

    return this.render(adminMissoes, page, {
      cromo: txt,
      vocab,
      resumo,
      lista,
      filtros,
      modo,
      edicao,
      alvo,
      itens,
      sugestoes,
      squad_max: SQUAD_MAX,
      destaque: intPhp(req.query('destaque') ?? 0),
      linhas_recompensa: LINHAS_RECOMPENSA,
    }, 'admin', status);
  }

  // ========================================================================= validação

  /**
   * Lê o POST, sanea e valida. Devolve SEMPRE os três: os dados prontos para o banco, as
   * recompensas e os erros por campo. Com erros, os dados voltam para reencher o formulário.
   */
  private async validar(req: AppRequest, id: number): Promise<[Dict, Recompensa[], Erros]> {
    const vocab = await MissionRepo.vocabulario();
    const erros: Erros = {};

    const texto = (campo: string): string => corta(String(req.input(campo) ?? ''), LIMITES[campo] ?? 255);

    // ---------------------------------------------------------------- identidade
    let slug = texto('slug').toLowerCase();
    slug = slug.replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '');
    const nome = texto('name');

    if (slug === '') {
      erros.slug = 'Informe o slug — é ele que liga a missão ao progresso do jogador.';
    } else if (Array.from(slug).length < 3) {
      erros.slug = 'O slug precisa de pelo menos 3 caracteres.';
    } else if (await MissionRepo.slugEmUso(slug, id)) {
      erros.slug = 'Já existe outra missão com este slug.';
    }
    if (nome === '') {
      erros.name = 'Informe o nome que o jogador lê no quadro de contratos.';
    }

    // ------------------------------------------------------------------- operação
    let tipo = String(req.input('type') ?? '');
    if (!tem(vocab.tipos, tipo)) {
      erros.type = 'Escolha um tipo de contrato.';
      tipo = Object.keys(vocab.tipos ?? {})[0] ?? 'contrato';
    }
    let origem = String(req.input('origin') ?? '');
    if (!tem(vocab.origens, origem)) {
      erros.origin = 'Escolha de quem vem esta missão.';
      origem = 'hunter_command';
    }
    let dificuldade = String(req.input('difficulty') ?? '');
    if (dificuldade !== '' && !tem(vocab.dificuldades, dificuldade)) {
      erros.difficulty = 'Dificuldade desconhecida.';
      dificuldade = '';
    }
    let atividade = String(req.input('human_activity') ?? '');
    if (atividade !== '' && !tem(vocab.atividades, atividade)) {
      erros.human_activity = 'Escala desconhecida.';
      atividade = '';
    }

    const duracao = this.inteiro(req, 'duration_min', 1, 600, erros, 'A duração vai de 1 a 600 minutos.');
    const nivel = this.inteiro(req, 'level_req', 0, 999, erros, 'O nível mínimo vai de 0 a 999.');
    const meta = this.inteiro(req, 'target', 0, 32767, erros, 'A meta vai de 0 a 32767.');
    const ordem = this.inteiro(req, 'sort_order', 0, 65535, erros, 'A ordem vai de 0 a 65535.') ?? 0;
    const ato = this.inteiro(req, 'act', 1, 4, erros, 'O ato da campanha vai de 1 a 4.');

    // Ato é conceito de campanha: melhor recusar do que gravar calado numa diária.
    if (ato !== null && tipo !== 'principal') {
      erros.act = 'Ato só existe em missão do tipo PRINCIPAL.';
    }

    // ----------------------------------------------------------------- esquadrão
    const teto = SQUAD_MAX;
    const sqMin = this.inteiro(req, 'squad_min', 1, teto, erros, 'O mínimo do esquadrão vai de 1 a ' + teto + '.') ?? 1;
    const sqMax = this.inteiro(req, 'squad_max', 1, teto, erros, 'Esquadrão Hunter tem no máximo ' + teto + ' operadores.') ?? teto;
    if (sqMin > sqMax) {
      erros.squad_min = 'O mínimo não pode ser maior que o máximo.';
    }

    // ---------------------------------------------------------------- requisitos
    let faccao = String(req.input('requires_faction') ?? '');
    if (faccao !== '' && !tem(vocab.faccoes, faccao)) {
      erros.requires_faction = 'Facção desconhecida.';
      faccao = '';
    }
    let faixa = String(req.input('requires_tier') ?? '');
    if (faixa !== '' && !tem(vocab.faixas, faixa)) {
      erros.requires_tier = 'Faixa de reputação desconhecida.';
      faixa = '';
    }
    const rep = this.inteiro(req, 'requires_rep', 0, 999999, erros, 'A reputação mínima vai de 0 a 999999.');

    // Faixa e reputação são requisitos DE UMA FACÇÃO: sem facção, não valem.
    if (faccao === '' && (faixa !== '' || rep !== null)) {
      erros.requires_faction = 'Escolha a facção: faixa e reputação são requisitos dela.';
    }

    const dados: Dict = {
      slug,
      name: nome,
      giver: this.ouNulo(texto('giver')),
      region: this.ouNulo(texto('region')),
      type: tipo,
      difficulty: this.ouNulo(dificuldade),
      duration_min: duracao,
      // `players_max` é a coluna legada que a HUD ainda lê (o teto do esquadrão).
      players_max: sqMax,
      level_req: nivel,
      objective: this.ouNulo(texto('objective')),
      target: meta,
      image: this.ouNulo(texto('image')),
      locked: req.input('locked') !== null ? 1 : 0,
      sort_order: ordem,
      origin: origem,
      act: tipo === 'principal' ? ato : null,
      requires_faction: this.ouNulo(faccao),
      requires_tier: faccao !== '' ? this.ouNulo(faixa) : null,
      requires_rep: faccao !== '' ? rep : null,
      squad_min: sqMin,
      squad_max: sqMax,
      human_activity: this.ouNulo(atividade),
    };

    const [recompensas, errosFinais] = await this.recompensasDoPost(req, vocab, erros);
    return [dados, recompensas, errosFinais];
  }

  /**
   * Lê as linhas de recompensa do POST. Linha sem espécie escolhida é linha vazia — o
   * formulário sempre oferece linhas a mais, e linha em branco não é erro.
   */
  private async recompensasDoPost(req: AppRequest, vocab: Dict, errosIn: Erros): Promise<[Recompensa[], Erros]> {
    // No PHP o array de erros entrava por valor e voltava no retorno: cópia aqui também.
    const erros: Erros = { ...errosIn };
    const especies = (vocab.recompensas ?? {}) as Record<string, Dict>;
    const kinds = req.inputArray('rec_kind');
    const amounts = req.inputArray('rec_amount');
    const itensPost = req.inputArray('rec_item');

    const catalogo = new Set<number>();
    for (const it of await MissionRepo.itens()) catalogo.add(Number(it.id) | 0);

    const out: Recompensa[] = [];
    const usadas = new Set<string>();
    kinds.forEach((kindBruto, i) => {
      const kind = String(kindBruto);
      if (kind === '') return;
      const campo = 'rec_' + i;
      if (!tem(especies, kind)) {
        erros[campo] = 'Espécie de recompensa desconhecida.';
        return;
      }
      const forma = String(especies[kind]!.forma ?? 'quantia');
      const itemId = intPhp(itensPost[i] ?? 0);
      let qtd = intPhp(amounts[i] ?? 0);

      if (forma === 'item') {
        if (itemId <= 0 || !catalogo.has(itemId)) {
          erros[campo] = 'Escolha o item do catálogo que esta recompensa entrega.';
          return;
        }
        // Um item é UM item: `amount` não é quantidade de itens aqui.
        out.push({ kind, amount: 1, item_id: itemId });
        return;
      }

      // Acesso e intel existem ou não existem — não têm quantia.
      if (forma === 'unico') {
        qtd = 1;
      } else if (qtd <= 0) {
        erros[campo] = 'Informe uma quantidade maior que zero.';
        return;
      }

      // Duas linhas da mesma espécie viram dois selos iguais no cartão: sempre engano.
      if (usadas.has(kind)) {
        erros[campo] = 'Esta espécie já foi usada em outra linha.';
        return;
      }
      usadas.add(kind);
      out.push({ kind, amount: qtd, item_id: null });
    });

    return [out, erros];
  }

  /** Inteiro opcional dentro de uma faixa. Fora dela, erro por campo (e null nos dados). */
  private inteiro(req: AppRequest, campo: string, min: number, max: number, erros: Erros, msg: string): number | null {
    const bruto = String(req.input(campo) ?? '');
    if (bruto === '') return null;
    if (!/^-?\d+$/.test(bruto)) {
      erros[campo] = msg;
      return null;
    }
    const n = Number.parseInt(bruto, 10);
    if (n < min || n > max) {
      erros[campo] = msg;
      return null;
    }
    return n;
  }

  /** String vazia vira NULL: a coluna é anulável e '' seria um valor a mais significando nada. */
  private ouNulo(v: string): string | null {
    return v === '' ? null : v;
  }

  /** O valor está entre as chaves da lista? Senão, '' (filtro ignorado). */
  private naLista(v: string, lista: unknown): string {
    return v !== '' && tem(lista, v) ? v : '';
  }

  /** Valores iniciais do formulário a partir da linha do banco (ou o padrão do jogo). */
  private valoresDe(m: Dict | null): Dict {
    if (m === null) {
      return {
        slug: '', name: '', giver: null, region: null,
        type: 'contrato', difficulty: null, duration_min: null,
        level_req: null, objective: null, target: null, image: null,
        locked: 0, sort_order: 0, origin: 'hunter_command', act: null,
        requires_faction: null, requires_tier: null, requires_rep: null,
        squad_min: 1, squad_max: SQUAD_MAX, human_activity: null,
      };
    }
    return m;
  }

  /** O que mudou entre a linha antiga e a nova — só os nomes das colunas. */
  private diferencas(antes: Dict, depois: Dict): string[] {
    const s = (v: unknown): string => (v === null || v === undefined ? '' : String(v));
    return Object.keys(depois).filter((col) => s(antes[col]) !== s(depois[col]));
  }

  /** Os filtros da listagem, para o redirect devolver o operador exatamente onde ele estava. */
  private filtrosDaUrl(req: AppRequest): Record<string, string> {
    const out: Record<string, string> = {};
    for (const k of FILTROS) {
      const v = String(req.input(k) ?? req.query(k) ?? '');
      if (v !== '') out[k] = v;
    }
    return out;
  }
}
