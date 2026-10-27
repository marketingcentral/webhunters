/**
 * Tela EDITAR MAPA do painel: /admin/mapas/editor — o App\Controllers\Admin\MapEditorController
 * do PHP.
 *
 * É o lugar onde se COMPÕE o mapa: a arte de um lado, a paleta de ícones pré-fabricados do
 * outro, e o operador arrasta um para cima da outra. A coordenada continua sendo a mesma
 * fração no banco — o que muda é que ela é PRODUZIDA por um gesto sobre a própria arte.
 *
 * QUATRO DECISÕES QUE EXPLICAM O CÓDIGO (iguais ao PHP)
 *
 * 1. GRAVAÇÃO POR DIFERENÇA, não por apagar-e-reinserir. O editor manda o conjunto inteiro,
 *    mas o servidor casa por `id`: quem já existia é ATUALIZADO, quem sumiu do conjunto é
 *    apagado, quem chegou sem id é criado. Ids estáveis = auditoria legível.
 * 2. O `meta` de cada marcador é MESCLADO com o que estava gravado: chaves que esta tela não
 *    edita (gravadas por patch) sobrevivem ao "Salvar".
 * 3. O POST é um FORMULÁRIO comum (x-www-form-urlencoded) com um campo JSON (`marcadores`),
 *    e não um corpo application/json: CSRF, req.input() e erro iguais às outras telas.
 * 4. O editor NÃO cria missão: só fixa no terreno uma missão que já existe (`meta.mission_id`).
 *
 * DIFERENÇA PARA O PHP: lá o salvamento era Database::transaction() chamando
 * MapsRepo::atualizarMarcador/criarMarcador/excluirMarcador uma a uma. O D1 não tem transação
 * interativa, então tudo é validado ANTES e gravado num único DB.batch() (atômico) — por isso
 * os statements são montados aqui com DB.prepare()/DB.insertStmt() (mesmo SQL do
 * repo/admin-maps-repo.ts) em vez de chamar as funções async do repositório.
 * A opacidade continua fora do lote, gravada antes dele, exatamente como no PHP (lá também
 * ficava fora da transação, com a auditoria própria).
 */
import * as DB from '../../core/database';
import { dataMock, flash, url } from '../../core/helpers';
import { html } from '../../core/html';
import type { AppRequest } from '../../core/request';
import type { AppResponse } from '../../core/response';
import type { Params } from '../../core/router';
import type { Dict } from '../../core/view';
import * as MapPointsRepo from '../../repo/admin-map-points-repo';
import * as MapsRepo from '../../repo/admin-maps-repo';
import { paraMapa } from '../../repo/admin-mission-repo';
import { adminMapasEditor } from '../../views/pages/admin-mapas-editor';
import { AdminController } from './admin-controller';
import { CORES } from './map-points-controller';

/** Teto de marcadores por mapa. Não é regra de jogo: é freio contra um POST absurdo. */
const MAX_MARCADORES = 400;

/** Casas decimais de `map_markers.x/y` — a coluna era DECIMAL(6,4) no MySQL. */
const COORD_CASAS = 4;

/** Arte usada quando o mapa ainda não declarou a sua (`maps.meta.world_image`). */
const ARTE_PADRAO = 'bg/map-world.webp';

/**
 * Opacidade da arte quando o mapa nada diz, e o piso do que se pode gravar (abaixo de 0,15
 * não há mais mapa — o operador ficaria com uma tela preta sem entender o motivo).
 */
const OPACIDADE_PADRAO = 0.65;
const OPACIDADE_MIN = 0.15;

/** Chaves de `meta` que o editor sabe editar (o resto vira "extras", só para avisar). */
const CHAVES_EDITOR = ['name', 'id', 'point_type', 'icon', 'color', 'label', 'radius', 'mission_id', 'glyph'];

// ----------------------------------------------------------------- conversões do PHP

/** round() do PHP: meio para longe do zero, com a correção de 1.005 → 1.01. */
function roundPhp(v: number, casas = 0): number {
  if (!Number.isFinite(v)) return v;
  const a = Math.abs(v);
  const s = String(a);
  const r = s.includes('e')
    ? Math.round(a * 10 ** casas) / 10 ** casas
    : Number(Math.round(Number(s + 'e' + casas)) + 'e-' + casas);
  return v < 0 ? -r : r;
}

/** (int) do PHP sobre um valor vindo de JSON. */
function intDe(v: unknown): number {
  if (typeof v === 'number') return Number.isFinite(v) ? Math.trunc(v) : 0;
  if (typeof v === 'boolean') return v ? 1 : 0;
  if (typeof v === 'string') {
    const n = Number.parseInt(v.trim(), 10);
    return Number.isNaN(n) ? 0 : n;
  }
  return 0;
}

/** (float) do PHP sobre um valor vindo de JSON (null/ausente → padrão, como o `??`). */
function floatDe(v: unknown, def: number): number {
  if (v === null || v === undefined) return def;
  if (typeof v === 'number') return v;
  if (typeof v === 'boolean') return v ? 1 : 0;
  if (typeof v === 'string') {
    const n = Number.parseFloat(v.trim());
    return Number.isNaN(n) ? 0 : n;
  }
  if (Array.isArray(v)) return v.length > 0 ? 1 : 0;
  if (typeof v === 'object') return Object.keys(v).length > 0 ? 1 : 0;
  return 0;
}

/** (string) do PHP sobre um valor vindo de JSON. */
function strDe(v: unknown): string {
  if (v === null || v === undefined || v === false) return '';
  if (v === true) return '1';
  if (typeof v === 'object') return 'Array';
  return String(v);
}

/** trim() do PHP (só os brancos ASCII que ele tira). */
const trimPhp = (s: string): string => s.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');

/** mb_substr($s, 0, $n). */
const mbCorta = (s: string, n: number): string => Array.from(s).slice(0, n).join('');

/** is_numeric() do PHP para texto (decimal, com sinal/expoente; sem hexadecimal). */
const ehNumerico = (s: string): boolean => /^\s*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?\s*$/.test(s);

/** Objeto JSON (array associativo do PHP). Lista vira objeto de chaves numéricas, como no (array). */
function comoDict(v: unknown): Dict {
  if (Array.isArray(v)) return Object.assign({}, v) as Dict;
  if (v !== null && typeof v === 'object') return { ...(v as Dict) };
  return {};
}

/** Linha preparada para gravar. */
interface Linha {
  kind: string;
  label: string;
  x: number;
  y: number;
  meta: string;
}

export class AdminMapEditorController extends AdminController {
  // ================================================================================ GET

  async index(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;

    const mapas = await MapsRepo.paraEditor();
    if (mapas.length === 0) return this.semMapa();

    const id = intDe(req.query('mapa') ?? 0);
    let mapa = id > 0 ? await MapsRepo.porId(id) : null;
    if (mapa === null) mapa = await MapsRepo.porId(Number(mapas[0]!.id) | 0);
    if (mapa === null) return this.semMapa();

    const mapaId = Number(mapa.id) | 0;
    const metaArr = comoDict(mapa.meta_arr);
    const arteBruta = strDe(metaArr.world_image ?? '');
    const arte = arteBruta === '' || arteBruta === '0' ? ARTE_PADRAO : arteBruta;

    const [marcadores, paleta, missoes] = await Promise.all([
      this.marcadoresParaTela(mapaId),
      MapPointsRepo.porCategoria(),
      paraMapa(),
    ]);

    const dados: Dict = {
      cromo: dataMock('admin-mapas-editor'),
      mapas,
      mapa,
      arte,
      opacidade: this.opacidade(metaArr),
      marcadores,
      paleta,
      missoes,
      kinds: this.kinds(),
      cores: CORES,
      glifos: dataMock('map_glyphs'),
      max: MAX_MARCADORES,
    };

    const page = this.adminPage(
      'mapas-editor',
      'Editar mapa',
      String(mapa.name ?? '') + ' · ' + marcadores.length + ' marcador(es)',
    );
    page.actions = html`<a class="adm-btn adm-btn--ghost" href="${url('admin/mapas/pontos')}">Criar pontos</a><a class="adm-btn adm-btn--ghost" href="${url('admin/mapas', { id: String(mapaId) })}">Cadastro do mapa</a>`;

    return this.render(adminMapasEditor, page, dados);
  }

  // =============================================================================== POST

  /** Grava o conjunto de marcadores do mapa. Ver decisões 1, 2 e 3 no topo. */
  async salvar(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    const mapaId = intDe(req.input('mapa_id', '0'));
    const mapa = mapaId > 0 ? await MapsRepo.porId(mapaId) : null;
    if (mapa === null) {
      return this.voltar(0, 'Mapa não encontrado — nada foi alterado.', 'error');
    }

    // json_decode(..., true): lista OU objeto JSON contam como array; o resto é ilegível.
    let bruto: unknown[] | null = null;
    try {
      const v: unknown = JSON.parse(String(req.input('marcadores', '[]')));
      if (Array.isArray(v)) bruto = v;
      else if (v !== null && typeof v === 'object') bruto = Object.values(v as Dict);
    } catch {
      bruto = null;
    }
    if (bruto === null) {
      return this.voltar(mapaId, 'Não consegui ler o que o editor enviou — nada foi alterado.', 'error');
    }
    if (bruto.length > MAX_MARCADORES) {
      return this.voltar(mapaId, 'Limite de ' + MAX_MARCADORES + ' marcadores por mapa — nada foi alterado.', 'error');
    }

    const antes = new Map<number, Dict>();
    for (const m of await MapsRepo.marcadores(mapaId)) antes.set(Number(m.id) | 0, m);

    const kinds = this.kinds();
    const tipos = await MapPointsRepo.porSlug();
    const manter = new Set<number>();
    const novos: Linha[] = [];
    const alterados = new Map<number, Linha>();
    let recusados = 0;

    for (const linha of bruto) {
      if (linha === null || typeof linha !== 'object') {
        recusados++;
        continue;
      }
      const l = comoDict(linha);
      const preparado = this.prepararLinha(l, kinds, tipos, antes);
      if (preparado === null) {
        recusados++;
        /* Linha recusada de um marcador que EXISTE não pode virar exclusão. Ausente do
           conjunto e presente-porém-inválido são coisas diferentes: sem isto, um único campo
           corrompido apagaria o marcador em silêncio. */
        const idRecusado = intDe(l.id ?? 0);
        if (idRecusado > 0 && antes.has(idRecusado)) manter.add(idRecusado);
        continue;
      }

      const [id, dados] = preparado;
      if (id > 0) {
        manter.add(id);
        if (this.mudou(antes.get(id)!, dados)) alterados.set(id, dados);
      } else {
        novos.push(dados);
      }
    }

    const apagar = [...antes.keys()].filter((id) => !manter.has(id));

    /* A opacidade viaja no MESMO envio dos marcadores. Vai para `maps.meta` por json_set
       (o JSON_SET do MySQL), que preserva todas as outras chaves do JSON. */
    const metaMapa = comoDict(mapa.meta_arr);
    const opAntes = this.opacidade(metaMapa);
    const opNova = this.normalizarOpacidade(req.input('opacidade', ''), opAntes);
    const opMudou = Math.abs(opNova - opAntes) > 0.0001;
    if (opMudou) {
      await DB.run(
        "UPDATE maps SET meta = json_set(COALESCE(meta, '{}'), '$.map_opacity', :o) WHERE id = :id",
        { o: opNova, id: mapaId },
      );
      await this.auditar('mapa_opacidade', 'map', mapaId,
        'Opacidade da arte de "' + String(mapa.name ?? '') + '" ajustada.',
        { antes: opAntes, depois: opNova });
    }

    if (alterados.size === 0 && novos.length === 0 && apagar.length === 0) {
      if (opMudou) {
        return this.voltar(mapaId, 'Opacidade da arte gravada em ' + Math.trunc(roundPhp(opNova * 100)) + '%.');
      }
      return this.voltar(
        mapaId,
        recusados > 0
          ? 'Nada foi alterado — ' + recusados + ' marcador(es) chegaram inválidos e foram ignorados.'
          : 'Nada mudou no mapa.',
        recusados > 0 ? 'error' : 'ok',
      );
    }

    // O Database::transaction() do PHP: tudo num lote atômico, na mesma ordem.
    const lote: D1PreparedStatement[] = [];
    for (const [id, d] of alterados) {
      lote.push(DB.prepare(
        'UPDATE map_markers SET kind = :kind, label = :label, x = :x, y = :y, meta = :meta WHERE id = :id',
        { kind: d.kind, label: d.label, x: d.x, y: d.y, meta: d.meta, id },
      ));
    }
    for (const d of novos) {
      lote.push(DB.insertStmt('map_markers', { map_id: mapaId, kind: d.kind, label: d.label, x: d.x, y: d.y, meta: d.meta }));
    }
    for (const id of apagar) {
      lote.push(DB.prepare('DELETE FROM map_markers WHERE id = :id', { id }));
    }
    await DB.batch(lote);

    await this.auditar('mapa_editor_salvo', 'map', mapaId,
      'Marcadores de "' + String(mapa.name ?? '') + '" atualizados pelo editor.',
      { criados: novos.length, alterados: alterados.size, excluidos: apagar.length, ignorados: recusados });

    const partes: string[] = [];
    if (novos.length > 0) partes.push(novos.length + ' criado(s)');
    if (alterados.size > 0) partes.push(alterados.size + ' movido(s) ou editado(s)');
    if (apagar.length > 0) partes.push(apagar.length + ' removido(s)');
    if (opMudou) partes.push('opacidade em ' + Math.trunc(roundPhp(opNova * 100)) + '%');
    let msg = 'Mapa salvo: ' + partes.join(', ') + '.';
    if (recusados > 0) msg += ' ' + recusados + ' marcador(es) inválidos foram ignorados.';

    return this.voltar(mapaId, msg);
  }

  // ============================================================================== APOIO

  /**
   * Valida e normaliza uma linha vinda do editor. Devolve [id, dados] — id 0 = marcador
   * novo — ou null quando a linha não merece ir para o banco (recusada em silêncio,
   * contando, para não perder os trinta e nove pontos bons por causa de um estragado).
   */
  private prepararLinha(
    l: Dict,
    kinds: Record<string, string>,
    tipos: Map<string, Dict>,
    antes: Map<number, Dict>,
  ): [number, Linha] | null {
    const id = intDe(l.id ?? 0);
    if (id > 0 && !antes.has(id)) {
      // Marcador de outro mapa (ou já apagado por outra aba): não é para ser tocado aqui.
      return null;
    }

    const x = floatDe(l.x, -1);
    const y = floatDe(l.y, -1);
    if (x < 0 || x > 1 || y < 0 || y > 1) return null;

    const tipoSlug = strDe(l.point_type ?? '');
    const tipo = tipos.get(tipoSlug) ?? null;

    // O `kind` vem do tipo do catálogo quando há um; o do cliente só é aceito se for do
    // vocabulário do jogo.
    let kind = strDe(l.kind ?? '');
    if (tipo !== null) {
      kind = String(tipo.kind);
    } else if (!Object.prototype.hasOwnProperty.call(kinds, kind)) {
      return null;
    }

    let nome = mbCorta(trimPhp(strDe(l.name ?? '')), 80);
    if (nome === '') nome = tipo !== null ? String(tipo.label) : kind;

    /* meta: o que estava gravado, por baixo; o que o editor conhece, por cima. */
    const meta: Dict = id > 0 ? comoDict(antes.get(id)!.meta_arr) : {};
    meta.name = nome;
    meta.id = strDe(meta.id ?? '') !== '' ? meta.id : this.slug(nome, x, y);

    if (tipoSlug !== '' && tipo !== null) meta.point_type = tipoSlug;

    const cor = strDe(l.color ?? '');
    if (cor !== '') meta.color = cor;

    // `label` decide se o nome aparece ao lado do ícone no mapa do jogador.
    const rotulo = strDe(l.label ?? '');
    meta.label = rotulo === 'right' || rotulo === 'below' ? rotulo : false;

    const raio = floatDe(l.radius, 0);
    if (raio > 0) meta.radius = roundPhp(Math.min(40, raio), 2);
    else delete meta.radius;

    const missao = intDe(l.mission_id ?? 0);
    if (missao > 0) meta.mission_id = missao;
    else delete meta.mission_id;

    /* Ícone próprio deste marcador: só entra quando é DIFERENTE do que o tipo já daria. */
    const icone = trimPhp(strDe(l.icon ?? ''));
    if (icone !== '' && this.iconeValido(icone) && (tipo === null || icone !== String(tipo.icon))) {
      meta.icon = icone;
    } else {
      delete meta.icon;
    }

    return [id, {
      kind,
      label: nome,
      x: roundPhp(x, COORD_CASAS),
      y: roundPhp(y, COORD_CASAS),
      // JSON.stringify não escapa barra nem unicode (= JSON_UNESCAPED_UNICODE|SLASHES).
      meta: JSON.stringify(meta),
    }];
  }

  /**
   * Mudou de verdade? Sem isto todo salvamento escreveria todas as linhas do mapa, e a
   * auditoria diria "43 alterados" mesmo quando o operador moveu um ponto só.
   */
  private mudou(antes: Dict, novo: Linha): boolean {
    const mesmaCoord = (a: unknown, b: unknown): boolean => Math.abs(Number(a) - Number(b)) < 0.00005;
    return String(antes.kind) !== novo.kind
      || strDe(antes.label ?? '') !== novo.label
      || !mesmaCoord(antes.x, novo.x)
      || !mesmaCoord(antes.y, novo.y)
      || JSON.stringify(antes.meta_arr ?? []) !== novo.meta;
  }

  /** Marcadores no formato que o editor consome (frações, chaves de meta promovidas). */
  private async marcadoresParaTela(mapaId: number): Promise<Dict[]> {
    const out: Dict[] = [];
    for (const m of await MapsRepo.marcadores(mapaId)) {
      const meta = comoDict(m.meta_arr);
      out.push({
        id: Number(m.id) | 0,
        kind: String(m.kind),
        name: strDe(meta.name ?? m.label ?? ''),
        x: roundPhp(Number(m.x), COORD_CASAS),
        y: roundPhp(Number(m.y), COORD_CASAS),
        point_type: strDe(meta.point_type ?? ''),
        icon: strDe(meta.icon ?? ''), // '' = herda o do tipo
        color: strDe(meta.color ?? 'white'),
        label: typeof meta.label === 'string' ? meta.label : '',
        radius: floatDe(meta.radius, 0),
        mission_id: intDe(meta.mission_id ?? 0),
        // chaves que o editor não edita, só para ele AVISAR que existem antes de salvar
        extras: Object.keys(meta).filter((k) => !CHAVES_EDITOR.includes(k)),
      });
    }
    return out;
  }

  /**
   * O ícone está no formato `origem:valor` e a origem é uma das três conhecidas? Confere a
   * FORMA, não a existência.
   */
  private iconeValido(icon: string): boolean {
    const [origem, valor] = MapPointsRepo.icone(icon);
    return icon === origem + ':' + valor && /^[A-Za-z0-9._/-]{1,160}$/.test(valor);
  }

  /** Opacidade gravada no mapa, já dentro dos limites. */
  private opacidade(meta: Dict): number {
    return meta.map_opacity !== undefined && meta.map_opacity !== null
      ? this.normalizarOpacidade(strDe(meta.map_opacity), OPACIDADE_PADRAO)
      : OPACIDADE_PADRAO;
  }

  /**
   * Texto do controle -> fração 0,15..1. Valor vazio ou ilegível NÃO vira o padrão: volta o
   * que já estava gravado.
   */
  private normalizarOpacidade(bruto: string | null, atual: number): number {
    const v = bruto ?? '';
    if (v === '' || !ehNumerico(v)) return atual;
    return roundPhp(Math.max(OPACIDADE_MIN, Math.min(1, Number(v.trim()))), 2);
  }

  /** kind => rótulo, o vocabulário que o jogo sabe desenhar. */
  private kinds(): Record<string, string> {
    const out: Record<string, string> = {};
    const marc = ((dataMock('admin-mapas') as Dict).marcadores ?? {}) as Record<string, Dict>;
    for (const [kind, info] of Object.entries(marc)) out[kind] = String(info?.label ?? kind);
    return out;
  }

  /** Slug do marcador: nome sem acento + posição (iconv TRANSLIT aproximado por NFD). */
  private slug(nome: string, x: number, y: number): string {
    const s = nome.normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[^a-zA-Z0-9]+/g, '-').toLowerCase().replace(/^-+|-+$/g, '');
    return (s !== '' ? s : 'ponto') + '-' + Math.trunc(roundPhp(x * 1000)) + Math.trunc(roundPhp(y * 1000));
  }

  private semMapa(): AppResponse {
    flash('error', 'Nenhum mapa cadastrado ainda — crie um em Mapas antes de abrir o editor.');
    return this.redirect('admin/mapas');
  }

  private voltar(mapaId: number, msg: string, tipo = 'ok'): AppResponse {
    flash(tipo, msg);
    return this.redirect('admin/mapas/editor', mapaId > 0 ? { mapa: String(mapaId) } : {});
  }
}
