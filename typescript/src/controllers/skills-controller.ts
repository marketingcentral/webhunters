/**
 * Tela HABILIDADES — o App\Controllers\SkillsController do PHP: árvore de talentos por user_id.
 *
 * Catálogo (definição dos nós) vem da tabela `skills`; o progresso vem de `player_skills`
 * e os pontos disponíveis de `players.skill_points`. O dataMock('habilidades') só dá os
 * textos fixos (rótulos, descrição/ícone de cada categoria).
 */
import * as DB from '../core/database';
import { dataMock, flash } from '../core/helpers';
import type { AppRequest } from '../core/request';
import type { AppResponse } from '../core/response';
import type { Params } from '../core/router';
import type { Dict } from '../core/view';
import * as CatalogRepo from '../repo/catalog-repo';
import { habilidades } from '../views/pages/habilidades';
import { Controller } from './controller';

/** slug -> linha de `skills`, na ordem do SELECT (category, tier, col). */
type Catalogo = Map<string, Dict>;
/** slug -> nível do jogador. */
type Niveis = Record<string, number>;

const nivel = (levels: Niveis, slug: string): number => Number(levels[slug] ?? 0) | 0;

export class SkillsController extends Controller {
  async index(req: AppRequest, params: Params): Promise<AppResponse> {
    const chrome: Dict = dataMock('habilidades');
    const uid = this.userId();
    const player = await this.player();

    const [catalog, levels] = await Promise.all([this.catalog(), this.playerLevels(uid)]);
    const cats = this.categories(catalog, levels, (chrome.categories ?? []) as Dict[]);
    const slugs = cats.map((c) => String(c.slug));

    // pick() devolve o padrão sem parâmetro e lança 404 para categoria inválida.
    const active = String(this.pick(params.categoria, slugs, slugs[0] ?? 'combate'));

    const tree = this.tree(catalog, levels, active);
    const tiers = this.tiers(tree.length);
    const cols = this.cols(tree);

    const points = Number(player.skill_points ?? 0) | 0;
    const sel = this.detail(catalog, levels, active, req.query('hab'), points, chrome);

    const data = {
      title: String(chrome.title ?? 'CATEGORIAS'),
      points_label: String(chrome.points_label ?? 'PONTOS DISPONÍVEIS'),
      points,
      redistribute: chrome.redistribute ?? { label: 'REDISTRIBUIR', key: 'R' },
      reset_category: chrome.reset_category ?? { label: 'REDEFINIR CATEGORIA', key: 'R' },
      unlocked_label: String(chrome.unlocked_label ?? 'HABILIDADES DESBLOQUEADAS'),
      level_label: String(chrome.level_label ?? 'NÍVEL'),
      categories: cats,
      tiers,
      cols,
      active_category: active,
      category: cats.find((c) => c.slug === active) ?? {},
      tree,
      selected: sel,
      // refund = pontos que voltam (texto do flash). learned = existe algo para zerar
      // (é ISTO que habilita o botão: habilidade de custo 0 também precisa ser redefinível).
      refund: this.refundable(catalog, levels, active),
      refund_all: this.refundable(catalog, levels, null),
      learned: this.learnedCount(catalog, levels, active),
      learned_all: this.learnedCount(catalog, levels, null),
    };

    const page = this.page('habilidades', 'Habilidades');
    return this.render(habilidades, page, data);
  }

  /** POST /habilidades/aprender — gasta pontos e sobe um nível da habilidade. */
  async learn(req: AppRequest, _p: Params): Promise<AppResponse> {
    this.csrf(req);
    const uid = this.userId();
    const slug = req.input('habilidade', '') ?? '';

    const catalog = await this.catalog();
    const node = catalog.get(slug) ?? null;
    if (uid <= 0 || node === null) {
      flash('error', 'Habilidade desconhecida.');
      return this.back(String(node?.category ?? ''), slug);
    }

    const levels = await this.playerLevels(uid);
    const cur = nivel(levels, slug);
    const max = Number(node.max_level) | 0;
    const cost = Number(node.cost) | 0;
    const cat = String(node.category);

    if (cur >= max) {
      flash('error', 'Esta habilidade já está no nível máximo.');
      return this.back(cat, slug);
    }
    if (!this.available(catalog, levels, node)) {
      flash('error', 'Aprenda antes a habilidade do tier anterior nesta coluna.');
      return this.back(cat, slug);
    }

    const points = Number(await DB.value('SELECT skill_points FROM players WHERE user_id = :u', { u: uid })) | 0;
    if (points < cost) {
      flash('error', 'Pontos de habilidade insuficientes.');
      return this.back(cat, slug);
    }

    // Database::transaction() -> batch() atômico do D1
    await DB.batch([
      DB.prepare('UPDATE players SET skill_points = skill_points - :c WHERE user_id = :u AND skill_points >= :c2', { c: cost, u: uid, c2: cost }),
      DB.prepare(
        `INSERT INTO player_skills (user_id, skill_id, level) VALUES (:u, :s, :l)
         ON CONFLICT(user_id, skill_id) DO UPDATE SET level = excluded.level`,
        { u: uid, s: Number(node.id), l: cur + 1 },
      ),
    ]);

    flash('ok', String(node.name) + ' agora está no nível ' + (cur + 1) + '/' + max + '.');
    return this.back(cat, slug);
  }

  /** POST /habilidades/redefinir — zera a categoria (ou tudo) e devolve os pontos gastos. */
  async reset(req: AppRequest, _p: Params): Promise<AppResponse> {
    this.csrf(req);
    const uid = this.userId();
    const scope = req.input('escopo', 'categoria') ?? 'categoria';
    const cat = req.input('categoria', '') ?? '';

    const catalog = await this.catalog();
    const cats = [...new Set([...catalog.values()].map((r) => String(r.category)))];
    if (uid <= 0 || (scope === 'categoria' && !cats.includes(cat))) {
      flash('error', 'Categoria desconhecida.');
      return this.back(cats.includes(cat) ? cat : (cats[0] ?? ''), null);
    }

    const levels = await this.playerLevels(uid);
    const only = scope === 'tudo' ? null : cat;
    const refund = this.refundable(catalog, levels, only);
    const ids: number[] = [];
    for (const [slug, row] of catalog) {
      if ((only === null || String(row.category) === only) && nivel(levels, slug) > 0) ids.push(Number(row.id));
    }

    if (!ids.length) {
      flash('error', scope === 'tudo' ? 'Nenhum ponto de habilidade aplicado para redistribuir.' : 'Nenhuma habilidade aprendida nesta categoria.');
      return this.back(cat, null);
    }

    const p: Dict = { u: uid };
    ids.forEach((id, i) => (p['s' + i] = id));
    const stmts = [DB.prepare(`DELETE FROM player_skills WHERE user_id = :u AND skill_id IN (${ids.map((_, i) => ':s' + i).join(',')})`, p)];
    if (refund > 0) stmts.push(DB.prepare('UPDATE players SET skill_points = skill_points + :r WHERE user_id = :u', { r: refund, u: uid }));
    await DB.batch(stmts);

    flash('ok', scope === 'tudo'
      ? 'Árvore redistribuída: ' + refund + ' ponto(s) devolvido(s).'
      : 'Categoria redefinida: ' + refund + ' ponto(s) devolvido(s).');
    return this.back(cat, null);
  }

  // ---------------------------------------------------------------- helpers

  /** Catálogo indexado por slug (cacheado por requisição no CatalogRepo). */
  private async catalog(): Promise<Catalogo> {
    const out: Catalogo = new Map();
    for (const row of await CatalogRepo.skills()) out.set(String(row.slug), row);
    return out;
  }

  /** Nível do jogador por slug de habilidade (0 quando ainda não aprendida). */
  private async playerLevels(uid: number): Promise<Niveis> {
    if (uid <= 0) return {};
    const rows = await DB.all(
      'SELECT s.slug, ps.level FROM player_skills ps JOIN skills s ON s.id = ps.skill_id WHERE ps.user_id = :u',
      { u: uid },
    );
    const out: Niveis = {};
    for (const r of rows) out[String(r.slug)] = Number(r.level) | 0;
    return out;
  }

  /** unlocked/total por categoria calculados sobre o progresso real do jogador. */
  private categories(catalog: Catalogo, levels: Niveis, chrome: Dict[]): Dict[] {
    const meta = new Map<string, Dict>();
    for (const c of chrome) meta.set(String(c.slug), c);
    const agg = new Map<string, { unlocked: number; total: number }>();
    for (const [slug, row] of catalog) {
      const cat = String(row.category);
      if (!agg.has(cat)) agg.set(cat, { unlocked: 0, total: 0 });
      const a = agg.get(cat)!;
      a.total++;
      if (nivel(levels, slug) > 0) a.unlocked++;
    }
    // ordem: a do dataMock primeiro, depois qualquer categoria extra vinda do banco
    const out: Dict[] = [];
    for (const [slug, c] of meta) {
      const a = agg.get(slug);
      if (a) {
        out.push({
          slug,
          label: String(c.label ?? slug.toUpperCase()),
          desc: String(c.desc ?? ''),
          icon: String(c.icon ?? 'star'),
          unlocked: a.unlocked,
          total: a.total,
        });
        agg.delete(slug);
      }
    }
    for (const [slug, a] of agg) {
      out.push({ slug, label: slug.toUpperCase(), desc: '', icon: 'star', unlocked: a.unlocked, total: a.total });
    }
    return out;
  }

  /** Matriz tiers x colunas no formato que a view espera (fileiras/colunas compactadas, como o array_values do PHP). */
  private tree(catalog: Catalogo, levels: Niveis, cat: string): Dict[][] {
    const rows = new Map<number, Map<number, Dict>>();
    for (const [slug, row] of catalog) {
      if (String(row.category) !== cat) continue;
      const j = Math.max(1, Number(row.tier) | 0) - 1;
      const i = Math.max(1, Number(row.col) | 0) - 1;
      const cur = nivel(levels, slug);
      const max = Math.max(1, Number(row.max_level) | 0);
      if (!rows.has(j)) rows.set(j, new Map());
      rows.get(j)!.set(i, {
        slug,
        name: String(row.name),
        icon: String(row.icon ?? 'star'),
        state: cur >= max ? 'unlocked' : cur > 0 ? 'progress' : 'locked',
        cur: Math.min(cur, max),
        max,
        cost: Number(row.cost) | 0,
        desc: String(row.description ?? ''),
        available: this.available(catalog, levels, row),
        // o JS remonta o texto do PRÓXIMO NÍVEL com os mesmos dados do catálogo
        effect: String(row.effect ?? ''),
        step: row.step === null || row.step === undefined ? '' : this.fmtNumber(Number(row.step)),
        unit: String(row.unit ?? ''),
        image: String(row.image ?? ''),
      });
    }
    return [...rows.keys()].sort((a, b) => a - b).map((j) => {
      const r = rows.get(j)!;
      return [...r.keys()].sort((a, b) => a - b).map((i) => r.get(i)!);
    });
  }

  /** Algarismos romanos para os tiers presentes na categoria. */
  private tiers(n: number): string[] {
    return ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII'].slice(0, Math.max(1, n));
  }

  /** Um nó do tier 1 sempre pode ser aprendido; nos demais exige o nó acima na coluna. */
  private available(catalog: Catalogo, levels: Niveis, node: Dict): boolean {
    const tier = Number(node.tier) | 0;
    if (tier <= 1) return true;
    for (const [slug, row] of catalog) {
      if (String(row.category) === String(node.category) && (Number(row.tier) | 0) === tier - 1 && (Number(row.col) | 0) === (Number(node.col) | 0)) {
        return nivel(levels, slug) > 0;
      }
    }
    return true;
  }

  /** Pontos que voltariam ao redefinir a categoria (ou toda a árvore quando cat é null). */
  private refundable(catalog: Catalogo, levels: Niveis, cat: string | null): number {
    let sum = 0;
    for (const [slug, row] of catalog) {
      if (cat !== null && String(row.category) !== cat) continue;
      sum += (Number(row.cost) | 0) * nivel(levels, slug);
    }
    return sum;
  }

  /** Painel da direita: nó pedido em ?hab=, senão o primeiro em progresso, senão o primeiro não aprendido. */
  private detail(catalog: Catalogo, levels: Niveis, cat: string, want: string | null, points: number, chrome: Dict): Dict {
    const inCat = new Map<string, Dict>();
    for (const [slug, row] of catalog) if (String(row.category) === cat) inCat.set(slug, row);
    if (!inCat.size) return {};

    let node: Dict | null = want !== null && inCat.has(want) ? inCat.get(want)! : null;
    if (node === null) {
      for (const [slug, row] of inCat) {
        // primeiro parcialmente aprendido
        const lvl = nivel(levels, slug);
        if (lvl > 0 && lvl < (Number(row.max_level) | 0)) {
          node = row;
          break;
        }
      }
    }
    if (node === null) {
      for (const [slug, row] of inCat) {
        // senão o primeiro ainda não aprendido
        if (nivel(levels, slug) === 0) {
          node = row;
          break;
        }
      }
    }
    node ??= inCat.values().next().value!;

    const slug = String(node.slug);
    const cur = nivel(levels, slug);
    const max = Math.max(1, Number(node.max_level) | 0);
    const cost = Number(node.cost) | 0;
    const desc = String(node.description ?? '');
    const ok = this.available(catalog, levels, node);
    const next = this.nextText(cur, max);

    return {
      slug,
      name: String(node.name),
      level: Math.min(cur, max),
      max,
      image: 'ui/skill-preview.jpg',
      desc,
      desc_highlight: this.firstNumber(desc),
      next_title: 'PRÓXIMO NÍVEL',
      next,
      next_highlight: this.firstNumber(next),
      cost_title: 'CUSTO',
      cost,
      cost_label: String(chrome.cost_suffix ?? 'PONTO(S) DE HABILIDADE'),
      cta: { label: 'APRENDER HABILIDADE', key: 'F' },
      cta_hold: 'SEGURAR PARA APRENDER',
      can_learn: cur < max && ok && points >= cost,
      available: ok,
      reason: cur >= max
        ? 'Nível máximo atingido.'
        : !ok ? 'Requer a habilidade do tier anterior nesta coluna.'
          : points < cost ? 'Pontos de habilidade insuficientes.' : '',
    };
  }

  /** Mesmo texto que o JS gera ao trocar de nó, para o servidor e o cliente não divergirem. */
  private nextText(cur: number, max: number): string {
    if (cur >= max) return 'Habilidade já está no nível máximo.';
    return cur === 0
      ? 'Desbloqueia o nível 1 de ' + max + ' desta habilidade.'
      : 'Evolui para o nível ' + (cur + 1) + ' de ' + max + ' desta habilidade.';
  }

  private firstNumber(text: string): string {
    const m = /\d+(?:[.,]\d+)?%?/u.exec(text);
    return m ? m[0] : '';
  }

  /** Quantas colunas a grade da árvore precisa: a fileira mais larga (já compactada). */
  private cols(tree: Dict[][]): number {
    let max = 0;
    for (const row of tree) max = Math.max(max, row.length);
    return Math.max(1, max);
  }

  /** Número por nível no formato pt-BR, sem casas decimais inúteis ("12" e não "12,0"). */
  private fmtNumber(n: number): string {
    return Math.abs(n - Math.round(n)) < 0.0001 ? String(Math.round(n)) : n.toFixed(1).replace('.', ',');
  }

  /** Quantas habilidades o jogador tem com nível > 0 (na categoria, ou em todas). */
  private learnedCount(catalog: Catalogo, levels: Niveis, cat: string | null): number {
    let n = 0;
    for (const [slug, row] of catalog) {
      if (cat !== null && String(row.category ?? '') !== cat) continue;
      if (nivel(levels, slug) > 0) n++;
    }
    return n;
  }

  /** Volta para a árvore mantendo categoria e nó selecionado. */
  private back(cat: string, slug: string | null): AppResponse {
    const path = 'habilidades' + (cat !== '' ? '/' + cat : '');
    return this.redirect(path, slug !== null && slug !== '' ? { hab: slug } : {});
  }
}
