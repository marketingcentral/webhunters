/**
 * Tela ADMIN · MISSÕES (/admin/missoes) — app/Views/pages/admin-missoes.php. Layout 'admin'.
 *
 * A view NÃO consulta o banco e NÃO valida nada: tudo chega pronto de
 * controllers/admin/missions-controller.ts + repo/admin-mission-repo.ts. Aqui só existe desenho.
 *
 * A tela tem TRÊS estados, e um só de cada vez (s.data.modo):
 *   ''          lista com busca, filtros, ordenação e paginação;
 *   nova/editar formulário;
 *   excluir     confirmação (ou o bloqueio, quando há jogador em campo).
 *
 * Sem JavaScript próprio nesta tela. Todo estado é query string (?nova, ?editar=, ?excluir=)
 * e todo envio é <form>. Os marcadores <!--eh:lista--> / <!--/eh:lista--> (core/fragmento.ts)
 * estão escritos literalmente, como no PHP.
 */
import { csrfField, icon, num, url } from '../../core/helpers';
import { html, join, raw, type SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { adminUpload } from '../components/admin-upload';

/** sprintf só com %s/%d, na ordem. */
function sprintf(fmt: string, ...args: Array<string | number>): string {
  let i = 0;
  return fmt.replace(/%[sd]/g, () => String(args[i++] ?? ''));
}

const str = (v: unknown, def = ''): string => (v === null || v === undefined ? def : String(v));
const int = (v: unknown, def = 0): number => {
  const n = Number.parseInt(String(v ?? ''), 10);
  return Number.isNaN(n) ? def : n;
};
const vazioDe = (v: unknown): boolean => v === null || v === undefined || v === '' || v === 0 || v === '0';
const obj = (v: unknown): Dict => (v !== null && typeof v === 'object' && !Array.isArray(v) ? (v as Dict) : {});
const tem = (lista: Dict, k: string): boolean => Object.prototype.hasOwnProperty.call(lista, k) && lista[k] != null;

export function adminMissoes(s: Scope): SafeHtml {
  const data = s.data;
  const cromo = obj(data.cromo);
  const vocab = obj(data.vocab);
  const resumo = obj(data.resumo);
  const lista = obj(data.lista);
  const filtros = obj(data.filtros);
  const modo = str(data.modo);
  const edicao = data.edicao && typeof data.edicao === 'object' ? (data.edicao as Dict) : null;
  const alvo = data.alvo && typeof data.alvo === 'object' ? (data.alvo as Dict) : null;
  const itens = (Array.isArray(data.itens) ? data.itens : []) as Dict[];
  const sugest = obj(data.sugestoes);
  const squadMax = int(data.squad_max, 4);
  const destaque = int(data.destaque, 0);
  const nRec = int(data.linhas_recompensa, 6);

  const colunas = obj(cromo.colunas);
  const micro = obj(cromo.micro);
  const vazios = obj(cromo.vazios);
  const campos = obj(cromo.campos);
  const fTxt = obj(cromo.form);
  const exTxt = obj(cromo.excluir);
  const campo = (k: string, p: 'label' | 'dica', def = ''): string => str(obj(campos[k])[p], def);

  const linhas = (Array.isArray(lista.linhas) ? lista.linhas : []) as Dict[];
  const total = int(lista.total, 0);
  const geral = int(lista.total_geral, 0);
  const pagina = int(lista.pagina, 1);
  const paginas = int(lista.paginas, 1);

  const fq = str(filtros.q);
  const ftipo = str(filtros.tipo);
  const forigem = str(filtros.origem);
  const fdif = str(filtros.dificuldade);
  const festado = str(filtros.estado);
  const fordem = str(filtros.ordem);

  /* Filtros ativos = o que estreitou a lista. `ordem` e `pagina` não contam. */
  const filtrosAtivos = [fq, ftipo, forigem, fdif, festado].some((v) => v !== '');

  /** Query string da listagem — mantém o operador no mesmo lugar depois de qualquer desvio. */
  const contexto: Record<string, string> = {};
  for (const [k, v] of Object.entries({
    q: fq,
    tipo: ftipo,
    origem: forigem,
    dificuldade: fdif,
    estado: festado,
    ordem: fordem !== 'ordem' ? fordem : '',
    pagina: pagina > 1 ? String(pagina) : '',
  })) {
    if (v !== '') contexto[k] = v;
  }

  /* ======================================================================================
     AUXILIARES DE DESENHO
     ====================================================================================== */

  /** Selo de um valor de lista (tipo, origem, dificuldade…), com a cor que o vocabulário deu. */
  const badge = (valor: unknown, l: Dict, vazio = '—'): SafeHtml => {
    const v = str(valor);
    if (v === '' || !tem(l, v)) {
      return v === ''
        ? html`<span class="mis-none">${vazio}</span>`
        : html`<span class="adm-badge adm-badge--muted" title="Valor fora do vocabulário do jogo">${v.toUpperCase()}</span>`;
    }
    const item = obj(l[v]);
    const orfao = !!item.orfao;
    return html`<span class="adm-badge adm-badge--${str(item.tom, 'muted')}${orfao ? ' mis-orfao' : ''}"${
      orfao ? raw(' title="Valor gravado no banco que não está no vocabulário do jogo"') : ''
    }>${str(item.label)}</span>`;
  };

  /** Pips de dificuldade: o MESMO desenho de 1 a 4 que o jogador vê no cartão da HUD. */
  const pips = (dif: unknown): SafeHtml => {
    const d = str(dif);
    const l = obj(vocab.dificuldades);
    if (d === '' || !tem(l, d)) return html`<span class="mis-none">—</span>`;
    const it = obj(l[d]);
    const nivel = int(it.nivel, 0);
    let pipsHtml: SafeHtml = raw('');
    if (nivel > 0) {
      const is = [1, 2, 3, 4].map((i) => html`<i class="mis-dif__pip${i <= nivel ? ' is-on' : ''}"></i>`);
      pipsHtml = html`<span class="mis-dif__pips" aria-hidden="true">${is}</span>`;
    }
    return html`<span class="mis-dif mis-dif--${str(it.tom, 'muted')}" title="${sprintf(str(micro.pips, 'Dificuldade %s de 4'), String(nivel))}">${pipsHtml}<span class="mis-dif__txt">${str(it.label)}</span></span>`;
  };

  /** Recompensa -> texto curto do selo (a quantia quando há uma, o nome do item quando é item). */
  const premioTxt = (r: Dict): { texto: string; tom: string; titulo: string } => {
    const especie = obj(obj(vocab.recompensas)[str(r.kind)]);
    const rotulo = str(especie.label, str(r.kind).toUpperCase());
    const forma = str(especie.forma, 'quantia');
    const tom = str(especie.tom, 'muted');
    const texto =
      forma === 'item' ? str(r.item, 'Item removido do catálogo')
      : forma === 'unico' ? rotulo
      : num(int(r.amount, 0)) + ' ' + rotulo;
    return { texto, tom, titulo: rotulo + ' · ' + texto };
  };

  /** Campo de formulário com erro por campo: a classe, o parágrafo de erro e os aria-*. */
  const erroDe = (c: string): { cls: string; html: SafeHtml; aria: SafeHtml } => {
    const msg = str(obj(edicao?.erros)[c]);
    return {
      cls: msg !== '' ? ' is-invalid' : '',
      html: msg !== '' ? html`<p class="adm-error" id="err-${c}">${icon('error')}<span>${msg}</span></p>` : raw(''),
      aria: msg !== '' ? html` aria-invalid="true" aria-describedby="err-${c}"` : raw(''),
    };
  };

  /** Valor atual de um campo do formulário. */
  const val = (c: string, padrao = ''): string => {
    const v = obj(edicao?.valores)[c];
    return v === null || v === undefined ? padrao : String(v);
  };

  /** <option> de uma lista do vocabulário. */
  const options = (l: Dict, atual: string, vazio = ''): SafeHtml => {
    const out: SafeHtml[] = [];
    if (vazio !== '') out.push(html`<option value="">${vazio}</option>`);
    for (const [chave, item0] of Object.entries(l)) {
      if (chave === '') continue;
      const item = obj(item0);
      let rot = str(item.label, chave);
      if (item.nota) rot += ' — ' + str(item.nota);
      if (item.orfao) rot += ' (fora do vocabulário)';
      out.push(html`<option value="${chave}"${chave === atual ? raw(' selected') : ''}>${rot}</option>`);
    }
    return join(out);
  };

  /** Campos ocultos que reenviam a listagem: o POST volta para a mesma busca e a mesma página. */
  const contextoOculto = (): SafeHtml =>
    join(Object.entries(contexto).map(([k, v]) => html`<input type="hidden" name="${k}" value="${v}">`));

  // ------------------------------------------------------------------ RESUMO DO CATÁLOGO
  /* Quatro números que respondem "o catálogo está saudável?" antes de qualquer rolagem. */
  const kpis = [
    { icone: 'flag', tom: 'lime', rotulo: 'Missões cadastradas', valor: int(resumo.total), sub: num(int(resumo.publicadas)) + ' no quadro' },
    { icone: 'lock', tom: 'amber', rotulo: 'Bloqueadas', valor: int(resumo.bloqueadas), sub: 'fora do quadro' },
    { icone: 'groups', tom: 'cyan', rotulo: 'Em campo agora', valor: int(resumo.em_campo), sub: num(int(resumo.jogadores)) + ' jogador(es)' },
    { icone: 'money_off', tom: 'red', rotulo: 'Sem recompensa', valor: int(resumo.sem_recompensa), sub: 'nada a pagar' },
  ];
  const secKpis = html`<section class="mis-kpis" aria-label="Resumo do catálogo de missões">
    ${kpis.map((k) => html`
      <article class="adm-kpi adm-kpi--${k.tom}">
        <span class="adm-kpi__ico">${icon(k.icone)}</span>
        <span class="adm-kpi__txt">
          <span class="adm-kpi__label">${k.rotulo}</span>
          <span class="adm-kpi__value num">${num(k.valor)}<small>${k.sub}</small></span>
        </span>
      </article>`)}
  </section>`;

  let corpo: SafeHtml;
  if (modo === 'excluir' && alvo !== null) corpo = telaExcluir();
  else if ((modo === 'nova' || modo === 'editar') && edicao !== null) corpo = telaForm(edicao);
  else corpo = telaLista();

  return html`<div class="mis">

  <!-- =============================================================== RESUMO DO CATÁLOGO -->
  ${secKpis}
${corpo}
</div>
`;

  // =========================================================================== CONFIRMAÇÃO
  function telaExcluir(): SafeHtml {
    const m = obj(alvo!.missao);
    const uso = obj(alvo!.uso);
    const premios = (Array.isArray(alvo!.recompensas) ? alvo!.recompensas : []) as Dict[];
    const emCampo = int(uso.em_campo, 0);
    const bloqueado = emCampo > 0;
    const voltar = str(exTxt.voltar, 'Voltar à lista');

    let miolo: SafeHtml;
    if (bloqueado) {
      /* Bloqueio, não aviso: player_missions cai por CASCADE. A tela diz quantos são e qual é
         a saída que não destrói nada. */
      const fatos = Object.entries(obj(uso.por_status)).map(
        ([st, n]) => html`<li><span class="mis-danger__k">${st.toUpperCase()}</span><span class="num">${num(int(n))}</span></li>`,
      );
      miolo = html`
        <p class="mis-danger__lead">
          ${sprintf(str(exTxt.bloqueio), String(emCampo))}
        </p>
        <p class="mis-danger__out">${icon('lightbulb')}<span>${str(exTxt.saida)}</span></p>
        <ul class="mis-danger__facts">
          ${fatos}
        </ul>
        <div class="adm-form__actions">
          <a class="adm-btn adm-btn--primary" href="${url('admin/missoes', { editar: str(m.id), ...contexto })}">
            ${icon('edit')}<span>Abrir para marcar como bloqueada</span>
          </a>
          <a class="adm-btn adm-btn--ghost" href="${url('admin/missoes', contexto)}">${voltar}</a>
        </div>`;
    } else {
      miolo = html`
        <p class="mis-danger__lead">
          ${sprintf(str(exTxt.pergunta, 'Excluir %s?'), '“' + str(m.name) + '”')}
        </p>
        <p class="mis-danger__note">
          ${sprintf(str(exTxt.consequencia), String(premios.length))}
        </p>
        ${int(uso.total, 0) > 0
          ? html`
          <p class="mis-danger__note">
            ${sprintf('%d registro(s) de progresso já concluído ou disponível também serão apagados.', int(uso.total))}
          </p>`
          : ''}
        <form class="adm-form__actions" method="post" action="${url('admin/missoes/excluir')}">
          ${csrfField()}
          ${contextoOculto()}
          <input type="hidden" name="id" value="${str(m.id)}">
          <button class="adm-btn adm-btn--danger" type="submit">
            ${icon('delete_forever')}<span>${str(exTxt.confirmar, 'Sim, excluir')}</span>
          </button>
          <a class="adm-btn adm-btn--ghost" href="${url('admin/missoes', contexto)}">${voltar}</a>
        </form>`;
    }

    return html`  <!-- ==================================================================== CONFIRMAÇÃO -->
  <section class="adm-card mis-danger${bloqueado ? ' is-blocked' : ''}">
    <div class="adm-card__head">
      <h2 class="adm-card__title">
        ${icon(bloqueado ? 'block' : 'delete_forever')}
        ${str(exTxt.titulo, 'Excluir missão')}
        <small>${str(m.name)} · ${str(m.slug)}</small>
      </h2>
    </div>
    <div class="adm-card__body mis-danger__body">${miolo}
    </div>
  </section>
`;
  }

  // ============================================================================ FORMULÁRIO
  function telaForm(ed: Dict): SafeHtml {
    const novo = int(ed.id, 0) === 0;
    const erros = obj(ed.erros);
    const nErros = Object.keys(erros).length;
    const usoEd = ed.uso && typeof ed.uso === 'object' ? (ed.uso as Dict) : null;
    const premEd = (Array.isArray(ed.recompensas) ? ed.recompensas : []) as Dict[];
    const recEspecies = obj(vocab.recompensas);

    const er = {
      slug: erroDe('slug'), name: erroDe('name'), sort_order: erroDe('sort_order'), type: erroDe('type'),
      origin: erroDe('origin'), act: erroDe('act'), difficulty: erroDe('difficulty'), duration_min: erroDe('duration_min'),
      human_activity: erroDe('human_activity'), level_req: erroDe('level_req'), squad_min: erroDe('squad_min'),
      squad_max: erroDe('squad_max'), target: erroDe('target'), requires_faction: erroDe('requires_faction'),
      requires_tier: erroDe('requires_tier'), requires_rep: erroDe('requires_rep'),
    };
    const locked = int(val('locked', '0'), 0) === 1;

    /* Linhas fixas em vez de "adicionar linha": sem JavaScript próprio, um botão de adicionar
       seria um recarregamento por linha. Linha em branco não é erro — é linha vazia. */
    const linhasRec: SafeHtml[] = [];
    for (let i = 0; i < nRec; i++) {
      const r: Dict = premEd[i] ?? { kind: '', amount: '', item_id: null };
      const e = erroDe('rec_' + i);
      const kind = str(r.kind);
      linhasRec.push(html`
            <div class="mis-rw__row${e.cls !== '' ? ' is-invalid' : ''}">
              <select class="adm-select" name="rec_kind[]" aria-label="Espécie da recompensa ${String(i + 1)}">
                <option value="">— vazia —</option>
                ${Object.entries(recEspecies).map(([k, esp]) => html`
                  <option value="${k}"${k === kind ? raw(' selected') : ''}>${str(obj(esp).label)}</option>`)}
              </select>
              <input class="adm-input num tr" name="rec_amount[]" type="number" min="0" step="1"
                     aria-label="Quantidade da recompensa ${String(i + 1)}"
                     value="${str(r.amount)}">
              <select class="adm-select" name="rec_item[]" aria-label="Item da recompensa ${String(i + 1)}">
                <option value="">— nenhum —</option>
                ${itens.map((it) => html`
                  <option value="${str(it.id)}"${int(r.item_id, 0) === int(it.id, 0) ? raw(' selected') : ''}>
                    ${str(it.name)} · ${str(it.category)}
                  </option>`)}
              </select>
              ${e.html}
            </div>`);
    }

    return html`  <!-- ====================================================================== FORMULÁRIO -->
  <form class="adm-card mis-form" method="post" action="${url('admin/missoes/salvar')}" novalidate>
    ${csrfField()}
    ${contextoOculto()}
    <input type="hidden" name="id" value="${str(ed.id, '0')}">

    <div class="adm-card__head">
      <h2 class="adm-card__title">
        ${icon(novo ? 'add_circle' : 'edit')}
        ${novo ? str(fTxt.nova, 'Nova missão') : str(fTxt.editar, 'Editar missão')}
        ${!novo ? html`<small>${val('slug')}</small>` : ''}
      </h2>
      <div class="adm-card__tools">
        ${usoEd !== null && int(usoEd.em_campo) > 0
          ? html`
          <span class="adm-badge adm-badge--warn" title="${sprintf(str(micro.em_campo, '%s'), str(usoEd.em_campo))}">
            ${num(int(usoEd.em_campo))} EM CAMPO
          </span>`
          : ''}
        <a class="adm-card__link" href="${url('admin/missoes', contexto)}">${icon('arrow_back')}Voltar à lista</a>
      </div>
    </div>

    ${nErros > 0
      ? html`
      <p class="mis-form__alerta" role="alert">
        ${icon('error')}<span>${sprintf('%d campo(s) precisam de correção. Nada foi gravado.', nErros)}</span>
      </p>`
      : ''}

    <div class="adm-card__body adm-form mis-form__body">

      <!-- ------------------------------------------------------------------ identidade -->
      <fieldset class="mis-fs">
        <legend class="mis-fs__leg">${icon('badge')}<span>${str(fTxt.identidade, 'Identidade')}</span></legend>

        <div class="adm-form__row">
          <div class="adm-field${er.slug.cls}">
            <label class="adm-label" for="f-slug">${campo('slug', 'label', 'Slug')}<span class="adm-label__req">*</span></label>
            <input class="adm-input" id="f-slug" name="slug" type="text" maxlength="64" required
                   value="${val('slug')}" placeholder="sinal-perdido" spellcheck="false"${er.slug.aria}>
            ${er.slug.html}
            <p class="adm-hint">${campo('slug', 'dica')}</p>
          </div>

          <div class="adm-field${er.name.cls}">
            <label class="adm-label" for="f-name">${campo('name', 'label', 'Nome')}<span class="adm-label__req">*</span></label>
            <input class="adm-input" id="f-name" name="name" type="text" maxlength="120" required
                   value="${val('name')}" placeholder="SINAL PERDIDO"${er.name.aria}>
            ${er.name.html}
            <p class="adm-hint">${campo('name', 'dica')}</p>
          </div>
        </div>

        <div class="adm-form__row">
          <div class="adm-field">
            <label class="adm-label" for="f-giver">${campo('giver', 'label', 'Contratante')}</label>
            <input class="adm-input" id="f-giver" name="giver" type="text" maxlength="80" list="mis-givers" value="${val('giver')}">
            <p class="adm-hint">${campo('giver', 'dica')}</p>
          </div>
          <div class="adm-field">
            <label class="adm-label" for="f-region">${campo('region', 'label', 'Região')}</label>
            <input class="adm-input" id="f-region" name="region" type="text" maxlength="80" list="mis-regioes" value="${val('region')}">
            <p class="adm-hint">${campo('region', 'dica')}</p>
          </div>
        </div>

        <datalist id="mis-regioes">${((sugest.regioes ?? []) as unknown[]).map((r) => html`<option value="${str(r)}"></option>`)}</datalist>
        <datalist id="mis-givers">${((sugest.contratantes ?? []) as unknown[]).map((g) => html`<option value="${str(g)}"></option>`)}</datalist>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field">
            <label class="adm-label" for="f-image">${campo('image', 'label', 'Imagem')}</label>
            ${adminUpload({
              id: 'f-image',
              name: 'image',
              entidade: 'missoes',
              valor: val('image'),
              maxlength: 160,
              placeholder: 'missions/mission-sinal-perdido.jpg',
            })}
            <p class="adm-hint">${campo('image', 'dica')}</p>
          </div>
          <div class="adm-field${er.sort_order.cls}">
            <label class="adm-label" for="f-sort">${campo('sort_order', 'label', 'Ordem')}</label>
            <input class="adm-input num" id="f-sort" name="sort_order" type="number" min="0" max="65535" step="1"
                   value="${val('sort_order', '0')}"${er.sort_order.aria}>
            ${er.sort_order.html}
            <p class="adm-hint">${campo('sort_order', 'dica')}</p>
          </div>
          <div class="adm-field mis-field--switch">
            <span class="adm-label">${campo('locked', 'label', 'Bloqueada')}</span>
            <label class="adm-switch">
              <input class="adm-switch__in" type="checkbox" name="locked" value="1"${locked ? raw(' checked') : ''}>
              <span class="adm-switch__ui"></span>
              <span>${locked ? 'Fora do quadro' : 'No quadro de contratos'}</span>
            </label>
            <p class="adm-hint">${campo('locked', 'dica')}</p>
          </div>
        </div>
      </fieldset>

      <!-- -------------------------------------------------------------------- operação -->
      <fieldset class="mis-fs">
        <legend class="mis-fs__leg">${icon('explore')}<span>${str(fTxt.operacao, 'Operação')}</span></legend>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field${er.type.cls}">
            <label class="adm-label" for="f-type">${campo('type', 'label', 'Tipo')}<span class="adm-label__req">*</span></label>
            <select class="adm-select" id="f-type" name="type"${er.type.aria}>
              ${options(obj(vocab.tipos), val('type', 'contrato'))}
            </select>
            ${er.type.html}
          </div>
          <div class="adm-field${er.origin.cls}">
            <label class="adm-label" for="f-origin">${campo('origin', 'label', 'Origem')}<span class="adm-label__req">*</span></label>
            <select class="adm-select" id="f-origin" name="origin"${er.origin.aria}>
              ${options(obj(vocab.origens), val('origin', 'hunter_command'))}
            </select>
            ${er.origin.html}
            <p class="adm-hint">${campo('origin', 'dica')}</p>
          </div>
          <div class="adm-field${er.act.cls}">
            <label class="adm-label" for="f-act">${campo('act', 'label', 'Ato')}</label>
            <input class="adm-input num" id="f-act" name="act" type="number" min="1" max="4" step="1" value="${val('act')}"${er.act.aria}>
            ${er.act.html}
            <p class="adm-hint">${campo('act', 'dica')}</p>
          </div>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field${er.difficulty.cls}">
            <label class="adm-label" for="f-dif">${campo('difficulty', 'label', 'Dificuldade')}</label>
            <select class="adm-select" id="f-dif" name="difficulty"${er.difficulty.aria}>
              ${options(obj(vocab.dificuldades), val('difficulty'), 'Não definida')}
            </select>
            ${er.difficulty.html}
          </div>
          <div class="adm-field${er.duration_min.cls}">
            <label class="adm-label" for="f-dur">${campo('duration_min', 'label', 'Duração (min)')}</label>
            <input class="adm-input num" id="f-dur" name="duration_min" type="number" min="1" max="600" step="1" value="${val('duration_min')}"${er.duration_min.aria}>
            ${er.duration_min.html}
          </div>
          <div class="adm-field${er.human_activity.cls}">
            <label class="adm-label" for="f-ha">${campo('human_activity', 'label', 'Atividade humana')}</label>
            <select class="adm-select" id="f-ha" name="human_activity"${er.human_activity.aria}>
              ${options(obj(vocab.atividades), val('human_activity'), 'Não informada')}
            </select>
            ${er.human_activity.html}
            <p class="adm-hint">${campo('human_activity', 'dica')}</p>
          </div>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field${er.level_req.cls}">
            <label class="adm-label" for="f-lvl">${campo('level_req', 'label', 'Nível mínimo')}</label>
            <input class="adm-input num" id="f-lvl" name="level_req" type="number" min="0" max="999" step="1" value="${val('level_req')}"${er.level_req.aria}>
            ${er.level_req.html}
            <p class="adm-hint">${campo('level_req', 'dica')}</p>
          </div>
          <div class="adm-field${er.squad_min.cls}">
            <label class="adm-label" for="f-sqmin">${campo('squad_min', 'label', 'Esquadrão mín.')}</label>
            <input class="adm-input num" id="f-sqmin" name="squad_min" type="number" min="1" max="${String(squadMax)}" step="1" value="${val('squad_min', '1')}"${er.squad_min.aria}>
            ${er.squad_min.html}
          </div>
          <div class="adm-field${er.squad_max.cls}">
            <label class="adm-label" for="f-sqmax">${campo('squad_max', 'label', 'Esquadrão máx.')}</label>
            <input class="adm-input num" id="f-sqmax" name="squad_max" type="number" min="1" max="${String(squadMax)}" step="1" value="${val('squad_max', String(squadMax))}"${er.squad_max.aria}>
            ${er.squad_max.html}
            <p class="adm-hint">${sprintf(campo('squad_max', 'dica', '%s'), String(squadMax))}</p>
          </div>
        </div>

        <div class="adm-form__row mis-row--objetivo">
          <div class="adm-field">
            <label class="adm-label" for="f-obj">${campo('objective', 'label', 'Objetivo')}</label>
            <textarea class="adm-textarea" id="f-obj" name="objective" maxlength="400" rows="4">${val('objective')}</textarea>
            <p class="adm-hint">${campo('objective', 'dica')}</p>
          </div>
          <div class="adm-field${er.target.cls}">
            <label class="adm-label" for="f-target">${campo('target', 'label', 'Meta')}</label>
            <input class="adm-input num" id="f-target" name="target" type="number" min="0" max="32767" step="1" value="${val('target')}"${er.target.aria}>
            ${er.target.html}
            <p class="adm-hint">${campo('target', 'dica')}</p>
          </div>
        </div>
      </fieldset>

      <!-- ------------------------------------------------------------------ requisitos -->
      <fieldset class="mis-fs">
        <legend class="mis-fs__leg">${icon('verified_user')}<span>${str(fTxt.requisitos, 'Requisitos de acesso')}</span></legend>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field${er.requires_faction.cls}">
            <label class="adm-label" for="f-fac">${campo('requires_faction', 'label', 'Exige facção')}</label>
            <select class="adm-select" id="f-fac" name="requires_faction"${er.requires_faction.aria}>
              ${options(obj(vocab.faccoes), val('requires_faction'), 'Sem exigência de facção')}
            </select>
            ${er.requires_faction.html}
            <p class="adm-hint">${campo('requires_faction', 'dica')}</p>
          </div>
          <div class="adm-field${er.requires_tier.cls}">
            <label class="adm-label" for="f-tier">${campo('requires_tier', 'label', 'Faixa mínima')}</label>
            <select class="adm-select" id="f-tier" name="requires_tier"${er.requires_tier.aria}>
              ${options(obj(vocab.faixas), val('requires_tier'), 'Sem faixa mínima')}
            </select>
            ${er.requires_tier.html}
          </div>
          <div class="adm-field${er.requires_rep.cls}">
            <label class="adm-label" for="f-rep">${campo('requires_rep', 'label', 'Reputação mínima')}</label>
            <input class="adm-input num" id="f-rep" name="requires_rep" type="number" min="0" max="999999" step="1" value="${val('requires_rep')}"${er.requires_rep.aria}>
            ${er.requires_rep.html}
            <p class="adm-hint">${campo('requires_rep', 'dica')}</p>
          </div>
        </div>
      </fieldset>

      <!-- ----------------------------------------------------------------- recompensas -->
      <fieldset class="mis-fs">
        <legend class="mis-fs__leg">${icon('redeem')}<span>${str(fTxt.recompensas, 'Recompensas')}</span></legend>

        ${itens.length === 0
          ? html`
          <p class="adm-hint mis-rw__aviso">${icon('info')}<span>${str(vazios.sem_item)}</span></p>`
          : ''}

        <div class="mis-rw">
          <div class="mis-rw__head">
            <span>Espécie</span><span class="tr">Quantidade</span><span>Item do catálogo</span>
          </div>${linhasRec}
        </div>
        <p class="adm-hint">A quantidade vale para XP, créditos, dinheiro, pontos e reputação. <strong>Item do catálogo</strong> ignora a quantidade (é um item). <strong>Acesso</strong> e <strong>Intel</strong> existem ou não existem — também ignoram.</p>
      </fieldset>

      <div class="adm-form__actions">
        <button class="adm-btn adm-btn--primary" type="submit">${icon('save')}<span>${str(fTxt.salvar, 'Salvar missão')}</span></button>
        <a class="adm-btn adm-btn--ghost" href="${url('admin/missoes', contexto)}">${str(fTxt.cancelar, 'Cancelar')}</a>
        ${!novo
          ? html`
          <a class="adm-btn adm-btn--danger adm-spacer" href="${url('admin/missoes', { excluir: str(ed.id), ...contexto })}">
            ${icon('delete')}<span>Excluir</span>
          </a>`
          : ''}
      </div>
    </div>
  </form>
`;
  }

  // ================================================================================= LISTA
  function telaLista(): SafeHtml {
    const vazio = linhas.length === 0;

    const tabela = vazio
      ? html`
        <div class="adm-empty">
          ${icon(geral === 0 ? 'flag' : 'search_off')}
          <p class="adm-empty__title">${geral === 0 ? 'Catálogo vazio' : 'Nada com estes filtros'}</p>
          <p class="adm-empty__txt">
            ${geral === 0 ? str(vazios.sem_missao) : sprintf(str(vazios.sem_filtro), num(geral))}
          </p>
          ${geral === 0
            ? html`<a class="adm-btn adm-btn--primary" href="${url('admin/missoes', { nova: '1' })}">${icon('add')}<span>${str(micro.nova, 'Nova missão')}</span></a>`
            : html`<a class="adm-btn adm-btn--ghost" href="${url('admin/missoes')}">${icon('backspace')}<span>${str(micro.limpar, 'Limpar filtros')}</span></a>`}
        </div>`
      : html`
        <div class="adm-tablewrap">
          <table class="adm-table adm-table--compact mis-table">
            <thead>
              <tr>
                <th class="tc mis-c-ord">${str(colunas.ordem, '#')}</th>
                <th>${str(colunas.missao, 'Missão')}</th>
                <th class="mis-c-tipo">${str(colunas.tipo, 'Tipo')}</th>
                <th class="mis-c-orig">${str(colunas.origem, 'Origem')}</th>
                <th class="mis-c-dif">${str(colunas.dificuldade, 'Dificuldade')}</th>
                <th class="tr mis-c-nvl">${str(colunas.nivel, 'Nível')}</th>
                <th class="mis-c-rec">${str(colunas.recompensas, 'Recompensas')}</th>
                <th class="tr mis-c-cmp">${str(colunas.ativas, 'Em jogo')}</th>
                <th class="mis-c-est">${str(colunas.estado, 'Estado')}</th>
                <th class="adm-table__acts"><span class="sr-only">${str(colunas.acoes, 'Ações')}</span></th>
              </tr>
            </thead>
            <tbody>${linhas.map(linha)}
            </tbody>
          </table>
        </div>`;

    let pager: SafeHtml = raw('');
    if (!vazio) {
      let botoes: SafeHtml = raw('');
      if (paginas > 1) {
        const lk = (p: number): string => {
          const q: Record<string, string> = {};
          for (const [k, v] of Object.entries({ q: fq, tipo: ftipo, origem: forigem, dificuldade: fdif, estado: festado, ordem: fordem, pagina: String(p) })) {
            if (v !== '') q[k] = v;
          }
          return url('admin/missoes', q);
        };
        const nums: SafeHtml[] = [];
        /* Sem reticências: a lista é de dezenas, não de milhares. */
        for (let p = 1; p <= paginas; p++) {
          nums.push(html`
          <a class="adm-pager__btn${p === pagina ? ' is-active' : ''}" href="${lk(p)}"${p === pagina ? raw(' aria-current="page"') : ''}>${String(p)}</a>`);
        }
        botoes = html`
          <a class="adm-pager__btn${pagina <= 1 ? ' is-disabled' : ''}" href="${lk(Math.max(1, pagina - 1))}" aria-label="Página anterior">${icon('chevron_left')}</a>${nums}
          <a class="adm-pager__btn${pagina >= paginas ? ' is-disabled' : ''}" href="${lk(Math.min(paginas, pagina + 1))}" aria-label="Próxima página">${icon('chevron_right')}</a>`;
      }
      pager = html`
    <div class="adm-card__foot adm-pager">
        <span class="adm-pager__info" data-adm-total>
          ${!filtrosAtivos
            ? sprintf(str(micro.sem_filtro, '%s missões cadastradas'), num(geral))
            : sprintf(str(micro.resultado, '%s de %s missões'), num(total), num(geral))}
        </span>${botoes}
      </div>`;
    }

    /* GET, e não POST: o resultado de uma busca é um endereço. data-adm-preservar: este
       formulário é transplantado para dentro do HTML novo em vez de ser substituído. */
    return html`  <!-- =========================================================================== LISTA -->
  <!--eh:lista-->
  <section class="adm-card mis-list">

    <form class="adm-toolbar" method="get" action="${url('admin/missoes')}"
          data-adm-preservar="filtros">
      <label class="adm-search adm-toolbar__grow">
        ${icon('search')}
        <span class="sr-only">${str(micro.busca, 'Buscar')}</span>
        <input class="adm-input" type="search" name="q" value="${fq}"
               placeholder="${str(micro.busca, 'Buscar')}">
      </label>

      <select class="adm-select mis-filtro" name="tipo" aria-label="Filtrar por tipo">
        <option value="">Todos os tipos</option>
        ${options(obj(vocab.tipos), ftipo)}
      </select>

      <select class="adm-select mis-filtro" name="origem" aria-label="Filtrar por origem">
        <option value="">Todas as origens</option>
        ${options(obj(vocab.origens), forigem)}
      </select>

      <select class="adm-select mis-filtro" name="dificuldade" aria-label="Filtrar por dificuldade">
        <option value="">Todas as dificuldades</option>
        ${options(obj(vocab.dificuldades), fdif)}
      </select>

      <select class="adm-select mis-filtro" name="estado" aria-label="Filtrar por estado">
        ${Object.entries(obj(cromo.estados)).map(([k, rot]) => html`
          <option value="${k}"${k === festado ? raw(' selected') : ''}>${str(rot)}</option>`)}
      </select>

      <select class="adm-select mis-filtro" name="ordem" aria-label="Ordenar">
        ${Object.entries(obj(cromo.ordenacoes)).map(([k, rot]) => html`
          <option value="${k}"${k === fordem ? raw(' selected') : ''}>${str(rot)}</option>`)}
      </select>

      <button class="adm-btn" type="submit">${icon('filter_alt')}<span>Aplicar</span></button>
      ${filtrosAtivos
        ? html`
      <a class="adm-btn adm-btn--ghost" href="${url('admin/missoes')}">${icon('backspace')}<span>${str(micro.limpar, 'Limpar filtros')}</span></a>`
        : ''}
      <a class="adm-btn adm-btn--primary mis-toolbar__nova" href="${url('admin/missoes', { nova: '1', ...contexto })}">
        ${icon('add')}<span>${str(micro.nova, 'Nova missão')}</span>
      </a>
    </form>

    <div class="adm-card__body adm-card__body--flush">${tabela}
    </div>
${pager}
  </section>
  <!--/eh:lista-->
`;
  }

  /** Uma linha da tabela. */
  function linha(l: Dict): SafeHtml {
    const id = int(l.id, 0);
    const ativas = int(l.ativas, 0);
    const premios = (Array.isArray(l.premios) ? l.premios : []) as Dict[];
    const locked = int(l.locked, 0) === 1;
    const sqMin = int(l.squad_min, 1);
    const sqMax = int(l.squad_max, squadMax);
    const giver = str(l.giver);
    const cls = (locked ? 'mis-row--locked' : '') + (id === destaque ? ' mis-row--novo' : '');

    let premiosHtml: SafeHtml;
    if (premios.length === 0) {
      premiosHtml = html`
                      <span class="adm-badge adm-badge--danger" title="${str(micro.sem_recompensa)}">SEM RECOMPENSA</span>`;
    } else {
      const chips = premios.slice(0, 3).map((p) => {
        const pt = premioTxt(p);
        return html`
                          <span class="mis-prem__chip mis-prem__chip--${pt.tom}" title="${pt.titulo}">${pt.texto}</span>`;
      });
      const mais =
        premios.length > 3
          ? html`
                          <span class="mis-prem__mais" title="${premios.slice(3).map((p) => premioTxt(p).texto).join(' · ')}">+${String(premios.length - 3)}</span>`
          : '';
      premiosHtml = html`
                      <span class="mis-prem">${chips}${mais}
                      </span>`;
    }

    /* Com jogador em campo a exclusão é recusada no servidor, então o gesto não existe aqui:
       o motivo vai no title e a saída (bloquear) está a um clique. */
    const excluirBtn =
      ativas > 0
        ? html`
                      <span class="adm-btn adm-btn--sm adm-btn--icon is-disabled"
                            title="${sprintf(str(exTxt.bloqueio), String(ativas))}"
                            aria-disabled="true">${icon('lock')}</span>`
        : html`
                      <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--danger" title="Excluir" aria-label="Excluir ${str(l.name)}"
                         href="${url('admin/missoes', { excluir: String(id), ...contexto })}">${icon('delete')}</a>`;

    return html`
                <tr class="${cls}">
                  <td class="tc num mis-c-ord">${num(int(l.sort_order, 0))}</td>

                  <td>
                    <span class="mis-name" title="${str(l.name) + (giver !== '' ? ' · ' + giver : '')}">
                      <strong>${str(l.name)}</strong>
                      <small>
                        <code class="mis-slug">${str(l.slug)}</code>
                        ${!vazioDe(l.region) ? html` · ${str(l.region)}` : ''}
                        ${!vazioDe(l.act) ? html` · ATO ${str(l.act)}` : ''}
                      </small>
                    </span>
                  </td>

                  <td class="mis-c-tipo">${badge(l.type, obj(vocab.tipos))}</td>
                  <td class="mis-c-orig">${badge(l.origin, obj(vocab.origens))}</td>
                  <td class="mis-c-dif">${pips(l.difficulty)}</td>

                  <td class="tr mis-c-nvl">
                    <span class="mis-num">
                      <span class="num">${l.level_req !== null && l.level_req !== undefined ? num(int(l.level_req)) : '—'}</span>
                      <small class="num" title="${sprintf(str(micro.esquadrao, '%s a %s'), String(sqMin), String(sqMax))}">${sqMin + '–' + sqMax} op</small>
                    </span>
                  </td>

                  <td class="mis-c-rec">${premiosHtml}
                  </td>

                  <td class="tr mis-c-cmp">
                    <span class="mis-campo${ativas > 0 ? ' is-on' : ''}"
                          title="${ativas > 0 ? sprintf(str(micro.em_campo, '%s'), String(ativas)) : str(micro.sem_jogadores)}">
                      <span class="num">${num(ativas)}</span>
                    </span>
                  </td>

                  <td class="mis-c-est">
                    <span class="adm-badge adm-badge--${locked ? 'warn' : 'ok'}">${locked ? 'BLOQUEADA' : 'PUBLICADA'}</span>
                  </td>

                  <td class="adm-table__acts">
                    <a class="adm-btn adm-btn--sm adm-btn--icon" title="Editar" aria-label="Editar ${str(l.name)}"
                       href="${url('admin/missoes', { editar: String(id), ...contexto })}">${icon('edit')}</a>${excluirBtn}
                  </td>
                </tr>`;
  }
}
