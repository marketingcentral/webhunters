/**
 * Tela ADMIN · MAPAS (/admin/mapas) — app/Views/pages/admin-mapas.php. Layout 'admin'.
 *
 * A view não consulta banco e não decide regra: tudo chega pronto de
 * controllers/admin/maps-controller.ts (que lê repo/admin-maps-repo.ts). O que existe aqui é
 * marcação, formatação e a escolha de qual estado desenhar.
 *
 * A tela tem DOIS modos, e nunca os dois ao mesmo tempo:
 *   lista  — catálogo com busca, filtros, ordenação por coluna e paginação;
 *   editor — um mapa: cadastro, estado do setor (região) e a grade de marcadores.
 *
 * Sem JavaScript nenhum. A confirmação de exclusão é uma tela servida pelo servidor
 * (?excluir=…), a ordenação são links e os filtros são um <form method="get">. Os marcadores
 * <!--eh:lista--> / <!--/eh:lista--> (core/fragmento.ts) estão escritos literalmente.
 */
import { csrfField, icon, num, url } from '../../core/helpers';
import { html, join, raw, type SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { adminUpload } from '../components/admin-upload';

/** (string) do PHP. */
const str = (v: unknown, def = ''): string => {
  if (v === null || v === undefined) return def;
  if (v === false) return '';
  if (v === true) return '1';
  if (typeof v === 'object') return '';
  return String(v);
};
/** (int) do PHP. */
const int = (v: unknown, def = 0): number => {
  if (v === null || v === undefined) return def;
  if (typeof v === 'number') return Math.trunc(v);
  const n = Number.parseInt(String(v).trim(), 10);
  return Number.isNaN(n) ? 0 : n;
};
const obj = (v: unknown): Dict => (v !== null && typeof v === 'object' ? (v as Dict) : {});
const lista = (v: unknown): Dict[] => (Array.isArray(v) ? (v as Dict[]) : v !== null && typeof v === 'object' ? (Object.values(v) as Dict[]) : []);
const tem = (o: Dict, k: string): boolean => Object.prototype.hasOwnProperty.call(o, k) && o[k] != null;
/** !empty() do PHP. */
const cheio = (v: unknown): boolean => !(v === null || v === undefined || v === false || v === 0 || v === '' || v === '0' || (Array.isArray(v) && v.length === 0));

/** number_format($v, 4, sep, '') — metade para longe do zero, como o round() do PHP. */
const fmt4 = (v: number, sep: string): string => {
  const r = (Math.sign(v) * Math.round(Math.abs(v) * 1e4 + 1e-7)) / 1e4;
  return r.toFixed(4).replace('.', sep);
};

export function adminMapas(s: Scope): SafeHtml {
  const data = s.data;
  const cromo = obj(data.cromo);
  const colunas = obj(cromo.colunas);
  const ajudas = obj(cromo.ajudas);
  const vazios = obj(cromo.vazios);
  const cores = obj(cromo.cores);
  const ancoras = obj(cromo.rotulos);

  const modo = str(data.modo, 'lista');
  const filtros = obj(data.filtros);
  const tipos = obj(data.tipos);
  const erros = obj(data.erros);

  /* ---- apoios de formulário ------------------------------------------------ */

  /** ' is-invalid' para o .adm-field quando o campo tem erro. */
  const inv = (campo: string): string => (tem(erros, campo) ? ' is-invalid' : '');

  /** O <p class="adm-error"> do campo (null quando está válido). */
  const msg = (campo: string): SafeHtml | null => {
    if (!tem(erros, campo)) return null;
    return html`<p class="adm-error">${icon('error')}<span>${str(erros[campo])}</span></p>`;
  };
  /** `$msg('x') ?: '<p class="adm-hint">…</p>'` */
  const msgOuDica = (campo: string, dica: string): SafeHtml => msg(campo) ?? html`<p class="adm-hint">${dica}</p>`;

  /** Coordenada como o operador lê: 4 casas, vírgula decimal. */
  const coord = (v: number): string => fmt4(v, ',');
  /** Valor de coordenada dentro de um <input type=number>: ponto, sempre. */
  const coordIn = (v: number): string => fmt4(v, '.');

  /** <option>s de um mapa valor => rótulo. */
  const opcoes = (mapa: Dict, atual: string): SafeHtml =>
    join(Object.entries(mapa).map(([valor, rotulo]) =>
      html`<option value="${valor}"${atual === valor ? ' selected' : ''}>${str(rotulo)}</option>`));

  if (modo === 'lista') {
    return telaLista();
  }
  return telaEditor();

  // ============================================================================ LISTA
  function telaLista(): SafeHtml {
    const linhas = lista(data.linhas);
    const total = int(data.total, 0);
    const pagina = int(data.pagina, 1);
    const paginas = int(data.paginas, 1);
    const porPagina = int(data.por_pagina, 20);
    const filtrando = cheio(data.filtrando);

    const ordemAtual = str(filtros.ordem, 'ordem');
    const dirAtual = str(filtros.dir, 'asc').toLowerCase() === 'desc' ? 'desc' : 'asc';

    /* Link de qualquer estado da lista preservando busca e filtros. */
    const linkLista = (troca: Record<string, string | number>): string => {
      const base: Record<string, string | number> = {
        busca: str(filtros.busca),
        tipo: str(filtros.tipo),
        estado: str(filtros.estado),
        ordem: str(filtros.ordem),
        dir: str(filtros.dir),
      };
      const q: Record<string, string | number> = {};
      for (const [k, v] of Object.entries({ ...base, ...troca })) {
        if (String(v) !== '') q[k] = v;
      }
      return url('admin/mapas', q);
    };

    let corpo: SafeHtml;
    if (linhas.length === 0) {
      const vazio = filtrando ? obj(vazios.sem_mapa_filtro) : obj(vazios.sem_mapa);
      corpo = html`
    <div class="adm-empty">
      ${icon(filtrando ? 'search_off' : 'map')}
      <h2 class="adm-empty__title">${str(vazio.titulo, 'Nada por aqui')}</h2>
      <p class="adm-empty__txt">${str(vazio.txt)}</p>
      ${filtrando
        ? html`<a class="adm-btn adm-btn--ghost" href="${url('admin/mapas')}">${icon('filter_alt_off')}<span>Limpar os filtros</span></a>`
        : html`<a class="adm-btn adm-btn--primary" href="${url('admin/mapas', { novo: 1 })}">${icon('add_location_alt')}<span>Cadastrar o primeiro mapa</span></a>`}
    </div>`;
    } else {
      const cabecalho = join(Object.entries(colunas).map(([chave, col0]) => {
        const col = obj(col0);
        const ordena = col.ordena ?? false;
        const chaveOrdem = ordena === true ? chave : typeof ordena === 'string' ? ordena : '';
        const ativo = chaveOrdem !== '' && chaveOrdem === ordemAtual;
        // Clicar na coluna já ordenada INVERTE; clicar numa nova começa crescente.
        const proxDir = ativo && dirAtual === 'asc' ? 'desc' : 'asc';
        const classe = (str(col.classe) + (ativo ? ' is-sorted' : '')).trim();
        const sort = ativo ? raw(' aria-sort="' + (dirAtual === 'asc' ? 'ascending' : 'descending') + '"') : '';
        return html`
            <th class="${classe}"${sort}>
              ${chaveOrdem !== ''
                ? html`<a class="mapas-ord" href="${linkLista({ ordem: chaveOrdem, dir: proxDir, pagina: '' })}">
                    <span>${str(col.label)}</span>
                    ${icon(ativo ? (dirAtual === 'asc' ? 'arrow_upward' : 'arrow_downward') : 'unfold_more', 'mapas-ord__ico')}
                  </a>`
                : str(col.label)}
            </th>`;
      }));

      const corpoTabela = join(linhas.map((m) => {
        const id = int(m.id);
        const kind = str(m.kind);
        const ue = str(m.unreal_level).trim();
        const lmin = m.level_min ?? null;
        const lmax = m.level_max ?? null;
        const nivel = lmin === null && lmax === null
          ? '—'
          : lmin !== null && lmax !== null ? int(lmin) + '–' + int(lmax) : String(int(lmin ?? lmax));
        const config = [str(m.mode), str(m.players)].filter((v) => v !== '').join(' · ').trim();
        return html`
            <tr>
              <td class="tr num">${String(int(m.sort_order))}</td>
              <td>
                <a class="mapas-nome" href="${url('admin/mapas', { id })}">
                  <strong>${str(m.name)}</strong>
                  <small>${str(m.slug)}</small>
                </a>
                ${ue !== ''
                  ? html`<span class="mapas-ue" title="Level da Unreal">${icon('deployed_code', 'mapas-ue__ico')}${ue}</span>`
                  : html`<span class="mapas-ue mapas-ue--none" title="Nenhum level da Unreal apontado neste cadastro">${icon('deployed_code_alert', 'mapas-ue__ico')}sem level da Unreal</span>`}
              </td>
              <td><span class="adm-badge ${kind === 'region' ? 'adm-badge--info' : 'adm-badge--purple'}">${str(tipos[kind] ?? kind)}</span></td>
              <td class="adm-table__wrap">${config !== '' ? config : html`<span class="mapas-vazio">—</span>`}</td>
              <td class="tr num">${nivel}</td>
              <td class="tr num">${String(int(m.marcadores ?? 0))}</td>
              <td>
                ${int(m.locked) === 1
                  ? html`<span class="adm-badge adm-badge--warn">${icon('lock')}Bloqueado</span>`
                  : html`<span class="adm-badge adm-badge--ok">Aberto</span>`}
              </td>
              <td class="adm-table__acts">
                <a class="adm-btn adm-btn--sm" href="${url('admin/mapas', { id })}">${icon('edit')}<span>Editar</span></a>
              </td>
            </tr>`;
      }));

      const de = (pagina - 1) * porPagina + 1;
      const ate = Math.min(total, pagina * porPagina);
      const paginador: SafeHtml[] = [];
      if (paginas > 1) {
        paginador.push(html`
          <a class="adm-pager__btn${pagina <= 1 ? ' is-disabled' : ''}" href="${linkLista({ pagina: Math.max(1, pagina - 1) })}" aria-label="Página anterior">${icon('chevron_left')}</a>`);
        for (let p = 1; p <= paginas; p++) {
          paginador.push(html`
            <a class="adm-pager__btn${p === pagina ? ' is-active' : ''}" href="${linkLista({ pagina: p })}">${String(p)}</a>`);
        }
        paginador.push(html`
          <a class="adm-pager__btn${pagina >= paginas ? ' is-disabled' : ''}" href="${linkLista({ pagina: Math.min(paginas, pagina + 1) })}" aria-label="Próxima página">${icon('chevron_right')}</a>`);
      }

      corpo = html`
    <div class="adm-tablewrap">
      <table class="adm-table mapas-tabela">
        <thead>
          <tr>${cabecalho}
            <th class="adm-table__acts"><span class="sr-only">Ações</span></th>
          </tr>
        </thead>
        <tbody>${corpoTabela}
        </tbody>
      </table>
    </div>

    <div class="adm-card__foot">
      <div class="adm-pager">
        <span class="adm-pager__info" data-adm-total>
          Mostrando <span class="num">${String(de)}</span>–<span class="num">${String(ate)}</span>
          de <span class="num">${num(total)}</span> mapa${total === 1 ? '' : 's'}${filtrando ? ' no filtro atual' : ''}
        </span>${join(paginador)}
      </div>
    </div>`;
    }

    return html`
<!--eh:lista-->
<section class="adm-card mapas-lista">
  <form class="adm-toolbar" method="get" action="${url('admin/mapas')}" role="search"
        data-adm-preservar="filtros">
    <div class="adm-toolbar__grow">
      <label class="adm-search">
        ${icon('search')}
        <span class="sr-only">Buscar mapa</span>
        <input class="adm-input" type="search" name="busca" value="${str(filtros.busca)}"
               placeholder="Nome, identificador, modo ou level da Unreal" autocomplete="off">
      </label>
    </div>

    <label class="mapas-filtro">
      <span class="sr-only">Tipo</span>
      <select class="adm-select" name="tipo">
        <option value="">Todos os tipos</option>
        ${opcoes(tipos, str(filtros.tipo))}
      </select>
    </label>

    <label class="mapas-filtro">
      <span class="sr-only">Estado</span>
      <select class="adm-select" name="estado">
        ${opcoes(obj(cromo.estados), str(filtros.estado))}
      </select>
    </label>

    <input type="hidden" name="ordem" value="${ordemAtual}">
    <input type="hidden" name="dir" value="${dirAtual}">

    <button class="adm-btn" type="submit">${icon('filter_alt')}<span>Filtrar</span></button>
    ${filtrando ? html`<a class="adm-btn adm-btn--ghost" href="${url('admin/mapas')}">${icon('filter_alt_off')}<span>Limpar</span></a>` : ''}
  </form>
${corpo}
</section>
<!--/eh:lista-->
`;
  }

  // =========================================================================== EDITOR
  function telaEditor(): SafeHtml {
    const mapa = data.mapa !== null && typeof data.mapa === 'object' ? (data.mapa as Dict) : null;
    const novo = cheio(data.novo) || mapa === null;
    const form = obj(data.form);
    const marcadores = lista(data.marcadores);
    const mkPost = obj(data.mk_post);
    const novoPost = obj(data.novo_post);
    const confirmar = data.confirmar !== null && typeof data.confirmar === 'object' ? (data.confirmar as Dict) : null;
    const vinculos = obj(data.vinculos);
    const vinculoTxt = str(data.vinculo_txt);
    const metaExtra = (Array.isArray(data.meta_extra) ? data.meta_extra : []) as string[];
    const mkTipos = obj(data.marcador_tipos);
    const exTipos = obj(data.extracao_tipos);
    const exEstados = obj(data.extracao_estados);
    const atividades = obj(data.atividades);
    const capPadrao = obj(data.capacidade_padrao);

    const mapId = int(form.id ?? 0);
    const ehRegiao = str(form.kind, 'region') === 'region';

    /* Linhas de extração: as que existem mais duas em branco, para acrescentar sem recarregar. */
    const exLinhas: Dict[] = [...lista(form.extracoes)];
    exLinhas.push({ name: '', type: 'publica', state: 'active' });
    exLinhas.push({ name: '', type: 'publica', state: 'active' });

    const nErros = Object.keys(erros).length;

    /** <option>s de atividade (degrau => cor), comparando em maiúsculas. */
    const opAtividade = (atual: string): SafeHtml =>
      join(Object.keys(atividades).map((degrau) =>
        html`<option value="${degrau}"${atual.toUpperCase() === degrau ? ' selected' : ''}>${degrau}</option>`));

    /** <option>s de extração (slug => {label}). */
    const opExtracao = (cat: Dict, atual: string): SafeHtml =>
      join(Object.entries(cat).map(([slug, t]) =>
        html`<option value="${slug}"${atual === slug ? ' selected' : ''}>${str(obj(t).label)}</option>`));

    // ---- confirmações de exclusão
    let confirma: SafeHtml | string = '';
    if (confirmar !== null && str(confirmar.tipo) === 'mapa') {
      const n = int(confirmar.marcadores);
      confirma = html`
  <section class="adm-card mapas-confirma">
    <div class="adm-card__head">
      <h2 class="adm-card__title">${icon('delete_forever')}Excluir o mapa "${str(confirmar.nome)}"?</h2>
    </div>
    <div class="adm-card__body">
      <p class="mapas-confirma__txt">
        Isto apaga o cadastro
        ${n > 0
          ? html`e, junto com ele, <strong class="num">${String(n)}</strong>
          marcador${n === 1 ? '' : 'es'} — extrações, bases e ninhos que este mapa desenha no jogo.`
          : html`deste mapa. Ele não tem marcadores.`}
        Não há desfazer.
      </p>
      <form class="adm-btns mapas-confirma__acts" method="post" action="${url('admin/mapas/excluir')}">
        ${csrfField()}
        <input type="hidden" name="alvo" value="mapa">
        <input type="hidden" name="id" value="${str(confirmar.id)}">
        <button class="adm-btn adm-btn--danger" type="submit">${icon('delete_forever')}<span>Sim, excluir definitivamente</span></button>
        <a class="adm-btn adm-btn--ghost" href="${url('admin/mapas', { id: str(confirmar.id) })}">${icon('close')}<span>Cancelar</span></a>
      </form>
    </div>
  </section>
`;
    } else if (confirmar !== null && str(confirmar.tipo) === 'marcador') {
      confirma = html`
  <section class="adm-card mapas-confirma">
    <div class="adm-card__head">
      <h2 class="adm-card__title">${icon('wrong_location')}Excluir o marcador "${str(confirmar.nome)}"?</h2>
    </div>
    <div class="adm-card__body">
      <p class="mapas-confirma__txt">
        Tipo <strong>${str(confirmar.kind)}</strong>, em
        X <span class="num">${coord(Number(confirmar.x))}</span> ·
        Y <span class="num">${coord(Number(confirmar.y))}</span>.
        Ele some do mapa no jogo assim que você confirmar. Não há desfazer.
      </p>
      <form class="adm-btns mapas-confirma__acts" method="post" action="${url('admin/mapas/excluir')}">
        ${csrfField()}
        <input type="hidden" name="alvo" value="marcador">
        <input type="hidden" name="marcador_id" value="${str(confirmar.id)}">
        <button class="adm-btn adm-btn--danger" type="submit">${icon('delete_forever')}<span>Sim, excluir o marcador</span></button>
        <a class="adm-btn adm-btn--ghost" href="${url('admin/mapas', { id: mapId })}">${icon('close')}<span>Cancelar</span></a>
      </form>
    </div>
  </section>
`;
    }

    const erroTopo = nErros > 0
      ? html`
  <div class="adm-flash adm-flash--error mapas-erro-topo" role="alert">
    ${icon('error')}
    <span>Nada foi gravado: ${String(nErros)} campo${nErros === 1 ? '' : 's'} precisa${nErros === 1 ? '' : 'm'} de correção. Os campos estão marcados abaixo.</span>
  </div>
`
      : '';

    // ---- estado do setor (região) ou cartão de partida
    const setor = ehRegiao
      ? html`
        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field${inv('chave')}">
            <label class="adm-label" for="f-chave">Chave na URL do jogo</label>
            <input class="adm-input mono" id="f-chave" name="chave" maxlength="64"
                   value="${str(form.chave)}" placeholder="floresta-verde">
            ${msgOuDica('chave', 'Vazio usa o identificador sem o prefixo "regiao-".')}
          </div>

          <div class="adm-field${inv('nivel_txt')}">
            <label class="adm-label" for="f-nivel">Faixa de nível exibida</label>
            <input class="adm-input" id="f-nivel" name="nivel_txt" maxlength="16"
                   value="${str(form.nivel_txt)}" placeholder="1-10">
            ${msg('nivel_txt')}
          </div>

          <div class="adm-field${inv('icone')}">
            <label class="adm-label" for="f-icone">Ícone</label>
            <input class="adm-input mono" id="f-icone" name="icone" maxlength="32"
                   value="${str(form.icone)}" placeholder="pine">
            ${msg('icone')}
          </div>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field${inv('cap_total')}">
            <label class="adm-label" for="f-cap">Capacidade total</label>
            <input class="adm-input num" id="f-cap" name="cap_total" type="number" min="0" max="200" step="1"
                   value="${str(form.cap_total)}" placeholder="${str(capPadrao.total ?? 20)}">
            ${msgOuDica('cap_total', str(ajudas.capacidade))}
          </div>

          <div class="adm-field${inv('cap_hunters')}">
            <label class="adm-label" for="f-caph">Cota de Hunters</label>
            <input class="adm-input num" id="f-caph" name="cap_hunters" type="number" min="0" max="200" step="1"
                   value="${str(form.cap_hunters)}" placeholder="${str(capPadrao.hunters ?? 12)}">
            ${msg('cap_hunters')}
          </div>

          <div class="adm-field${inv('cap_merc')}">
            <label class="adm-label" for="f-capm">Cota de Mercenários</label>
            <input class="adm-input num" id="f-capm" name="cap_merc" type="number" min="0" max="200" step="1"
                   value="${str(form.cap_merc)}" placeholder="${str(capPadrao.mercenaries ?? 8)}">
            ${msg('cap_merc')}
          </div>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field${inv('infestacao')}">
            <label class="adm-label" for="f-inf">Infestação Veyra</label>
            <input class="adm-input num" id="f-inf" name="infestacao" type="number" min="0" max="100" step="1"
                   value="${str(form.infestacao)}" placeholder="0">
            ${msgOuDica('infestacao', str(ajudas.infestacao))}
          </div>

          <div class="adm-field${inv('veyra')}">
            <label class="adm-label" for="f-veyra">Atividade Veyra</label>
            <select class="adm-select" id="f-veyra" name="veyra">
              <option value="">— não informada —</option>
              ${opAtividade(str(form.veyra))}
            </select>
            ${msg('veyra')}
          </div>

          <div class="adm-field${inv('humana')}">
            <label class="adm-label" for="f-humana">Atividade humana</label>
            <select class="adm-select" id="f-humana" name="humana">
              <option value="">— não informada —</option>
              ${opAtividade(str(form.humana))}
            </select>
            ${msg('humana')}
          </div>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field${inv('clima')}">
            <label class="adm-label" for="f-clima">Clima atual</label>
            <input class="adm-input" id="f-clima" name="clima" maxlength="32"
                   value="${str(form.clima)}" placeholder="Variável">
            ${msg('clima')}
          </div>

          <div class="adm-field${inv('setor')}">
            <label class="adm-label" for="f-setor">Estado do setor</label>
            <input class="adm-input" id="f-setor" name="setor" maxlength="24"
                   value="${str(form.setor)}" placeholder="ATIVO">
            ${msg('setor')}
          </div>

          <div class="adm-field">
            <span class="adm-label">Mundo</span>
            <label class="adm-switch">
              <input class="adm-switch__in" type="checkbox" name="persistente" value="1"${cheio(form.persistente) ? ' checked' : ''}>
              <span class="adm-switch__ui"></span>
              <span>Persistente</span>
            </label>
            <p class="adm-hint">Desligado, a região deixa de anunciar mundo persistente no painel de informações.</p>
          </div>
        </div>

        <div class="adm-sep"></div>

        <p class="adm-label mapas-sub">Extrações</p>
        <p class="adm-hint mapas-sub__hint">${str(ajudas.extracoes)}</p>

        <div class="adm-tablewrap">
          <table class="adm-table adm-table--compact mapas-ex">
            <thead>
              <tr>
                <th>Nome do ponto</th>
                <th>Tipo</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>${join(exLinhas.map((x, i) => html`
                <tr>
                  <td>
                    <input class="adm-input" name="ex[${String(i)}][name]" maxlength="80"
                           value="${str(x.name)}" placeholder="ROTA SUL"
                           aria-label="Nome da extração ${String(i + 1)}">
                  </td>
                  <td class="mapas-ex__sel">
                    <select class="adm-select${inv('ex.' + i + '.type')}" name="ex[${String(i)}][type]" aria-label="Tipo da extração ${String(i + 1)}">
                      ${opExtracao(exTipos, str(x.type))}
                    </select>
                  </td>
                  <td class="mapas-ex__sel">
                    <select class="adm-select${inv('ex.' + i + '.state')}" name="ex[${String(i)}][state]" aria-label="Estado da extração ${String(i + 1)}">
                      ${opExtracao(exEstados, str(x.state))}
                    </select>
                  </td>
                </tr>`))}
            </tbody>
          </table>
        </div>

        <div class="adm-sep"></div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field${inv('evento_nome')}">
            <label class="adm-label" for="f-ev">Evento em cartaz</label>
            <input class="adm-input" id="f-ev" name="evento_nome" maxlength="80"
                   value="${str(form.evento_nome)}" placeholder="ENTREGA AÉREA">
            ${msgOuDica('evento_nome', str(ajudas.evento))}
          </div>

          <div class="adm-field${inv('evento_prazo')}">
            <label class="adm-label" for="f-evp">Prazo</label>
            <input class="adm-input num" id="f-evp" name="evento_prazo" maxlength="24"
                   value="${str(form.evento_prazo)}" placeholder="12:34">
            ${msg('evento_prazo')}
          </div>

          <div class="adm-field${inv('evento_rotulo')}">
            <label class="adm-label" for="f-evr">Rótulo do prazo</label>
            <input class="adm-input" id="f-evr" name="evento_rotulo" maxlength="32"
                   value="${str(form.evento_rotulo)}" placeholder="TERMINA EM">
            ${msg('evento_rotulo')}
          </div>
        </div>`
      : html`
        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field${inv('tamanho')}">
            <label class="adm-label" for="f-tam">Tamanho</label>
            <input class="adm-input" id="f-tam" name="tamanho" maxlength="24"
                   value="${str(form.tamanho)}" placeholder="MÉDIO">
            ${msg('tamanho')}
          </div>

          <div class="adm-field${inv('bioma')}">
            <label class="adm-label" for="f-bio">Bioma</label>
            <input class="adm-input" id="f-bio" name="bioma" maxlength="60"
                   value="${str(form.bioma)}" placeholder="Floresta / Montanha">
            ${msg('bioma')}
          </div>

          <div class="adm-field${inv('clima')}">
            <label class="adm-label" for="f-clima">Clima</label>
            <input class="adm-input" id="f-clima" name="clima" maxlength="32"
                   value="${str(form.clima)}" placeholder="Nublado">
            ${msg('clima')}
          </div>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field${inv('recorde')}">
            <label class="adm-label" for="f-rec">Melhor tempo</label>
            <input class="adm-input num" id="f-rec" name="recorde" maxlength="16"
                   value="${str(form.recorde)}" placeholder="04:12">
            ${msg('recorde')}
          </div>

          <div class="adm-field${inv('recorde_por')}">
            <label class="adm-label" for="f-recp">Recorde de</label>
            <input class="adm-input" id="f-recp" name="recorde_por" maxlength="40"
                   value="${str(form.recorde_por)}" placeholder="V1P3R_88">
            ${msg('recorde_por')}
          </div>

          <div class="adm-field${inv('icone')}">
            <label class="adm-label" for="f-icone">Ícone</label>
            <input class="adm-input mono" id="f-icone" name="icone" maxlength="32"
                   value="${str(form.icone)}" placeholder="mk-base">
            ${msg('icone')}
          </div>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field${inv('infestacao')}">
            <label class="adm-label" for="f-inf">Infestação Veyra</label>
            <input class="adm-input num" id="f-inf" name="infestacao" type="number" min="0" max="100" step="1"
                   value="${str(form.infestacao)}" placeholder="0">
            ${msg('infestacao')}
          </div>

          <div class="adm-field${inv('humana')}">
            <label class="adm-label" for="f-humana">Atividade humana</label>
            <select class="adm-select" id="f-humana" name="humana">
              <option value="">— não informada —</option>
              ${opAtividade(str(form.humana))}
            </select>
            ${msg('humana')}
          </div>

          <div class="adm-field"></div>
        </div>`;

    /* Honestidade sobre o JSON: existe dado que este formulário não mostra, mas também não apaga. */
    const preservado = metaExtra.length > 0
      ? html`
        <p class="mapas-preservado">
          ${icon('database')}
          <span>
            <strong class="num">${String(metaExtra.length)}</strong>
            chave${metaExtra.length === 1 ? '' : 's'} de <code>meta</code> que esta tela não edita
            (${metaExtra.slice(0, 8).join(', ')}${metaExtra.length > 8 ? '…' : ''})
            ${metaExtra.length === 1 ? 'é preservada' : 'são preservadas'} ao salvar.
          </span>
        </p>`
      : '';

    const excluirMapa = !novo
      ? html`
          <span class="adm-spacer"></span>
          ${Object.keys(vinculos).length > 0
            ? html`<span class="mapas-bloqueio" title="${vinculoTxt}">
              ${icon('lock')}
              <span>Não pode ser excluído: ${vinculoTxt}</span>
            </span>`
            : html`<a class="adm-btn adm-btn--danger" href="${url('admin/mapas', { id: mapId, excluir: 'mapa' })}">
              ${icon('delete')}<span>Excluir mapa</span>
            </a>`}`
      : '';

    const cadastro = html`
<form class="adm-form mapas-editor" method="post" action="${url('admin/mapas/salvar')}">
  ${csrfField()}
  <input type="hidden" name="acao" value="mapa">
  <input type="hidden" name="id" value="${String(mapId)}">

  <section class="adm-card">
    <div class="adm-card__head">
      <h2 class="adm-card__title">
        ${novo ? 'Novo mapa' : 'Cadastro'}
        <small>${novo ? 'O identificador e o tipo definem onde ele aparece no jogo.' : 'O que o jogador vê na lista de mapas e no Mapa do Mundo.'}</small>
      </h2>
    </div>
    <div class="adm-card__body">
      <div class="adm-form__row adm-form__row--3">
        <div class="adm-field${inv('name')}">
          <label class="adm-label" for="f-name">Nome<span class="adm-label__req">*</span></label>
          <input class="adm-input" id="f-name" name="name" maxlength="80" required
                 value="${str(form.name)}" placeholder="FLORESTA VERDE">
          ${msg('name')}
        </div>

        <div class="adm-field${inv('slug')}">
          <label class="adm-label" for="f-slug">Identificador<span class="adm-label__req">*</span></label>
          <input class="adm-input mono" id="f-slug" name="slug" maxlength="64" required
                 value="${str(form.slug)}" placeholder="regiao-floresta-verde">
          ${msgOuDica('slug', str(ajudas.slug))}
        </div>

        <div class="adm-field${inv('kind')}">
          <label class="adm-label" for="f-kind">Tipo<span class="adm-label__req">*</span></label>
          <select class="adm-select" id="f-kind" name="kind">
            ${opcoes(tipos, str(form.kind))}
          </select>
          ${msgOuDica('kind', str(ajudas.kind))}
        </div>
      </div>

      <div class="adm-form__row adm-form__row--3">
        <div class="adm-field${inv('mode')}">
          <label class="adm-label" for="f-mode">Modo</label>
          <input class="adm-input" id="f-mode" name="mode" maxlength="40"
                 value="${str(form.mode)}" placeholder="MATA-MATA EM EQUIPE">
          ${msg('mode')}
        </div>

        <div class="adm-field${inv('players')}">
          <label class="adm-label" for="f-players">Jogadores</label>
          <input class="adm-input" id="f-players" name="players" maxlength="16"
                 value="${str(form.players)}" placeholder="6v6">
          ${msg('players')}
        </div>

        <div class="adm-field${inv('sort_order')}">
          <label class="adm-label" for="f-ordem">Ordem na lista</label>
          <input class="adm-input num" id="f-ordem" name="sort_order" type="number" min="0" max="65535" step="1"
                 value="${str(form.sort_order, '0')}">
          ${msgOuDica('sort_order', str(ajudas.sort_order))}
        </div>
      </div>

      <div class="adm-form__row adm-form__row--3">
        <div class="adm-field${inv('level_min')}">
          <label class="adm-label" for="f-lmin">Nível mínimo</label>
          <input class="adm-input num" id="f-lmin" name="level_min" type="number" min="0" max="999" step="1"
                 value="${str(form.level_min)}" placeholder="—">
          ${msg('level_min')}
        </div>

        <div class="adm-field${inv('level_max')}">
          <label class="adm-label" for="f-lmax">Nível máximo</label>
          <input class="adm-input num" id="f-lmax" name="level_max" type="number" min="0" max="999" step="1"
                 value="${str(form.level_max)}" placeholder="—">
          ${msg('level_max')}
        </div>

        <div class="adm-field">
          <span class="adm-label">Disponibilidade</span>
          <label class="adm-switch">
            <input class="adm-switch__in" type="checkbox" name="locked" value="1"${cheio(form.locked) ? ' checked' : ''}>
            <span class="adm-switch__ui"></span>
            <span>Bloqueado para os jogadores</span>
          </label>
          <p class="adm-hint">${str(ajudas.locked)}</p>
        </div>
      </div>

      <div class="adm-form__row">
        <div class="adm-field${inv('unreal_level')}">
          <label class="adm-label" for="f-ue">Level da Unreal</label>
          <input class="adm-input mono" id="f-ue" name="unreal_level" maxlength="200"
                 value="${str(form.unreal_level)}" placeholder="/Game/Maps/L_FlorestaVerde">
          ${msgOuDica('unreal_level', str(ajudas.unreal_level))}
        </div>

        <div class="adm-field${inv('image')}">
          <label class="adm-label" for="f-img">Imagem</label>
          ${adminUpload({
            id: 'f-img',
            name: 'image',
            entidade: 'mapas',
            valor: str(form.image),
            maxlength: 160,
            mono: true,
            placeholder: 'bg/map-world.jpg',
            invalido: inv('image') !== '',
          })}
          ${msgOuDica('image', str(ajudas.image))}
        </div>
      </div>

      <div class="adm-field${inv('description')}">
        <label class="adm-label" for="f-desc">Descrição</label>
        <textarea class="adm-textarea" id="f-desc" name="description" maxlength="400" rows="3"
                  placeholder="O que o jogador precisa saber antes de entrar.">${str(form.description)}</textarea>
        ${msgOuDica('description', 'Até 400 caracteres.')}
      </div>
    </div>
  </section>

  <section class="adm-card">
    <div class="adm-card__head">
      <h2 class="adm-card__title">
        Estado do setor
        <small>
          ${ehRegiao
            ? 'Capacidade, infestação, atividade e extrações — o que o Mapa do Mundo lê deste mapa.'
            : 'Este cadastro é um mapa de PARTIDA: o mundo persistente não se aplica. Os campos abaixo são os do cartão de seleção de mapa.'}
        </small>
      </h2>
    </div>
    <div class="adm-card__body">${setor}
${preservado}
    </div>

    <div class="adm-card__foot">
      <div class="adm-form__actions mapas-acoes">
        <button class="adm-btn adm-btn--primary" type="submit">
          ${icon('save')}<span>${novo ? 'Criar mapa' : 'Salvar cadastro'}</span>
        </button>
        <a class="adm-btn adm-btn--ghost" href="${url('admin/mapas')}">${icon('close')}<span>Cancelar</span></a>
${excluirMapa}
      </div>
    </div>
  </section>
</form>
`;

    if (novo) {
      return html`${confirma}${erroTopo}${cadastro}`;
    }

    // ---- grade de marcadores: um formulário para N linhas
    const nMk = marcadores.length;
    const grade = nMk === 0
      ? html`
        <div class="adm-empty">
          ${icon('wrong_location')}
          <h2 class="adm-empty__title">${str(obj(vazios.sem_marcador).titulo)}</h2>
          <p class="adm-empty__txt">${str(obj(vazios.sem_marcador).txt)}</p>
        </div>`
      : html`
        <div class="adm-tablewrap">
          <table class="adm-table adm-table--compact mapas-mk">
            <thead>
              <tr>
                <th>Tipo</th>
                <th>Rótulo</th>
                <th class="tr">X</th>
                <th class="tr">Y</th>
                <th>Cor</th>
                <th>Âncora</th>
                <th class="adm-table__acts"><span class="sr-only">Excluir</span></th>
              </tr>
            </thead>
            <tbody>${join(marcadores.map((mk) => {
              const mid = int(mk.id);
              const meta = obj(mk.meta_arr);
              const post = obj(mkPost[String(mid)]);
              /* Em erro de validação a linha volta com o que foi DIGITADO nela. */
              const vKind = str(post.kind ?? mk.kind);
              const vLab = str(post.label ?? (mk.label ?? ''));
              const vX = tem(post, 'x') ? str(post.x) : coordIn(Number(mk.x));
              const vY = tem(post, 'y') ? str(post.y) : coordIn(Number(mk.y));
              const vCor = str(post.color ?? (meta.color ?? ''));
              const vAnc = str(post.anchor ?? (typeof meta.label === 'string' ? meta.label : ''));
              const p = 'mk.' + mid + '.';
              const invalida = tem(erros, p + 'kind') || tem(erros, p + 'x') || tem(erros, p + 'y');
              const nome = vLab !== '' ? vLab : String(mid);
              return html`
                <tr${invalida ? raw(' class="is-invalid-row"') : ''}>
                  <td class="mapas-mk__tipo">
                    <select class="adm-select${inv(p + 'kind')}" name="mk[${String(mid)}][kind]" aria-label="Tipo do marcador ${nome}">
                      ${opcoes(mkTipos, vKind)}
                    </select>
                    ${msg(p + 'kind')}
                  </td>
                  <td>
                    <input class="adm-input" name="mk[${String(mid)}][label]" maxlength="80"
                           value="${vLab}" aria-label="Rótulo do marcador ${String(mid)}" placeholder="sem rótulo">
                  </td>
                  <td class="mapas-mk__coord">
                    <input class="adm-input num${inv(p + 'x')}" name="mk[${String(mid)}][x]" type="number"
                           min="0" max="1" step="0.0001" value="${vX}" aria-label="Coordenada X do marcador ${String(mid)}">
                    ${msg(p + 'x')}
                  </td>
                  <td class="mapas-mk__coord">
                    <input class="adm-input num${inv(p + 'y')}" name="mk[${String(mid)}][y]" type="number"
                           min="0" max="1" step="0.0001" value="${vY}" aria-label="Coordenada Y do marcador ${String(mid)}">
                    ${msg(p + 'y')}
                  </td>
                  <td class="mapas-mk__cor">
                    <select class="adm-select" name="mk[${String(mid)}][color]" aria-label="Cor do marcador ${String(mid)}">
                      <option value="">— padrão —</option>
                      ${opcoes(cores, vCor)}
                    </select>
                  </td>
                  <td class="mapas-mk__anc">
                    <select class="adm-select" name="mk[${String(mid)}][anchor]" aria-label="Âncora do rótulo do marcador ${String(mid)}">
                      ${opcoes(ancoras, vAnc)}
                    </select>
                  </td>
                  <td class="adm-table__acts">
                    <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--danger"
                       href="${url('admin/mapas', { id: mapId, excluir: 'marcador', mk: mid })}"
                       title="Excluir este marcador" aria-label="Excluir o marcador ${nome}">${icon('delete')}</a>
                  </td>
                </tr>`;
            }))}
            </tbody>
          </table>
        </div>

        <div class="adm-card__foot">
          <div class="adm-form__actions mapas-acoes">
            <button class="adm-btn adm-btn--primary" type="submit">${icon('save')}<span>Salvar marcadores</span></button>
            <span class="adm-meta">${str(ajudas.coordenada)}</span>
          </div>
        </div>`;

    return html`${confirma}${erroTopo}${cadastro}
  <form class="mapas-mk-form" method="post" action="${url('admin/mapas/salvar')}">
    ${csrfField()}
    <input type="hidden" name="acao" value="marcadores">
    <input type="hidden" name="id" value="${String(mapId)}">

    <section class="adm-card">
      <div class="adm-card__head">
        <h2 class="adm-card__title">
          Marcadores
          <small>
            Coordenadas de 0 a 1 sobre a imagem do mapa: X cresce para a direita, Y para baixo.
            Excluir abre a confirmação e descarta edições ainda não salvas nesta grade.
          </small>
        </h2>
        <div class="adm-card__tools">
          <span class="adm-meta"><span class="num">${String(nMk)}</span> marcador${nMk === 1 ? '' : 'es'}</span>
        </div>
      </div>
${grade}
    </section>
  </form>

  <form class="mapas-mk-novo" method="post" action="${url('admin/mapas/salvar')}">
    ${csrfField()}
    <input type="hidden" name="acao" value="marcador_novo">
    <input type="hidden" name="id" value="${String(mapId)}">

    <section class="adm-card">
      <div class="adm-card__head">
        <h2 class="adm-card__title">Novo marcador<small>Ele aparece no mapa do jogo assim que for gravado.</small></h2>
      </div>
      <div class="adm-card__body">
        <div class="mapas-novo-grid">
          <div class="adm-field${inv('novo.kind')}">
            <label class="adm-label" for="n-kind">Tipo<span class="adm-label__req">*</span></label>
            <select class="adm-select" id="n-kind" name="novo[kind]">
              ${opcoes(mkTipos, str(novoPost.kind, 'loot'))}
            </select>
            ${msg('novo.kind')}
          </div>

          <div class="adm-field">
            <label class="adm-label" for="n-label">Rótulo</label>
            <input class="adm-input" id="n-label" name="novo[label]" maxlength="80"
                   value="${str(novoPost.label)}" placeholder="VILA ABANDONADA">
          </div>

          <div class="adm-field${inv('novo.x')}">
            <label class="adm-label" for="n-x">X<span class="adm-label__req">*</span></label>
            <input class="adm-input num" id="n-x" name="novo[x]" type="number" min="0" max="1" step="0.0001"
                   value="${str(novoPost.x, '0.5000')}">
            ${msg('novo.x')}
          </div>

          <div class="adm-field${inv('novo.y')}">
            <label class="adm-label" for="n-y">Y<span class="adm-label__req">*</span></label>
            <input class="adm-input num" id="n-y" name="novo[y]" type="number" min="0" max="1" step="0.0001"
                   value="${str(novoPost.y, '0.5000')}">
            ${msg('novo.y')}
          </div>

          <div class="adm-field">
            <label class="adm-label" for="n-cor">Cor</label>
            <select class="adm-select" id="n-cor" name="novo[color]">
              <option value="">— padrão —</option>
              ${opcoes(cores, str(novoPost.color))}
            </select>
          </div>

          <div class="adm-field">
            <label class="adm-label" for="n-anc">Âncora</label>
            <select class="adm-select" id="n-anc" name="novo[anchor]">
              ${opcoes(ancoras, str(novoPost.anchor))}
            </select>
          </div>

          <div class="adm-field mapas-novo-grid__acao">
            <span class="adm-label" aria-hidden="true">&nbsp;</span>
            <button class="adm-btn adm-btn--primary" type="submit">${icon('add_location_alt')}<span>Adicionar</span></button>
          </div>
        </div>
        <p class="adm-hint">${str(ajudas.coordenada)}</p>
      </div>
    </section>
  </form>
`;
  }
}
