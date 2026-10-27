/**
 * Tela SAÚDE — app/Views/pages/saude.php (referência 06-saude.png).
 * $data: overview_title, body_image, vitals[], body_title, body[], body_action, tabs[], active_tab,
 *        healing_title, healing[], exams_title, exams[], conditions_title, conditions[],
 *        status_groups[], details_title, details{health,regen}, history_title, history[], history_action.
 */
import { icon, media, num, url } from '../../core/helpers';
import { html, join, raw, SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { hex, progress } from '../components/index';

/**
 * Pictogramas do ESTADO CORPORAL — desenhados aqui porque as ligaduras do
 * Material Symbols (capacete de perfil / estômago em traço fino) não leem
 * como capacete, colete e estômago no tamanho usado pela referência.
 */
const PART_PATHS: Record<string, string> = {
  head: 'M12 2.2C7.4 2.2 4 5.7 4 10.4v6.1c0 1.9 1.2 3.3 2.9 3.3h1.5l.6-3h6.1l.6 3H17c1.7 0 3-1.4 3-3.3v-6.1c0-4.7-3.4-8.2-8-8.2Zm-5.7 7h11.4c.2.7.3 1.4.3 2.2v1.4H6v-1.4c0-.8.1-1.5.3-2.2Z',
  thorax: 'M12 2.3c-1.4 0-2.5.5-3.3 1L5 5.5C4 6.1 3.4 7.1 3.4 8.2v3.3h2.9v8.9c0 .6.5 1.1 1.1 1.1h9.2c.6 0 1.1-.5 1.1-1.1v-8.9h2.9V8.2c0-1.1-.6-2.1-1.6-2.7L15.3 3.3c-.8-.5-1.9-1-3.3-1Zm-.6 6.4h1.2v10.6h-1.2Z',
  stomach: 'M8.6 2.2c-.8 0-1.5.7-1.5 1.5v4C5.2 8.8 4 10.8 4 13.1c0 3.9 3.2 7.1 7.1 7.1 2.8 0 5.3-1.6 6.4-4.1.3-.8 0-1.7-.8-2-.8-.3-1.7 0-2 .8-.6 1.4-2 2.3-3.6 2.3-2.3 0-4.1-1.8-4.1-4.1 0-1.6.9-2.9 2.2-3.6.5-.3.8-.8.8-1.3V5.2h3.6c.8 0 1.5-.7 1.5-1.5s-.7-1.5-1.5-1.5H8.6Z',
};

function partIcon(name: string): SafeHtml {
  const d = PART_PATHS[name] ?? '';
  if (d === '') return icon(name);
  return raw(
    '<svg class="ic bpart__svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
      `<path fill="currentColor" fill-rule="evenodd" d="${d}"/></svg>`,
  );
}

/** is_numeric() do PHP para os valores da aba STATUS (ex.: "12/8" não é). */
function isNumeric(v: unknown): boolean {
  if (typeof v === 'number') return Number.isFinite(v);
  return typeof v === 'string' && /^\s*[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?\s*$/.test(v);
}

export function saude(s: Scope): SafeHtml {
  const data = s.data;
  const vitals = (data.vitals ?? []) as Dict[];
  const parts = (data.body ?? []) as Dict[];
  const healing = (data.healing ?? []) as Dict[];
  const exams = (data.exams ?? []) as Dict[];
  const conditions = (data.conditions ?? []) as Dict[];
  const groups = (data.status_groups ?? []) as Dict[];
  const history = (data.history ?? []) as Dict[];
  const details: Dict = data.details ?? {};
  const hp: Dict = details.health ?? {};
  const regen: Dict = details.regen ?? {};
  const activeTab = String(data.active_tab ?? 'tratamento');

  const colA = parts.filter((p) => (p.col ?? 'a') === 'a');
  const colB = parts.filter((p) => (p.col ?? 'a') === 'b');

  const bodyAct: Dict = data.body_action ?? {};
  const histAct: Dict = data.history_action ?? {};
  const bodyHref = url(String(bodyAct.href ?? 'saude'), (bodyAct.query ?? {}) as Record<string, string>);
  const histHref = url(String(histAct.href ?? 'perfil'));

  const vital = (v: Dict): SafeHtml => {
    const cls = String(v.cls ?? 'lime');
    const dec = Number(v.dec ?? 0) | 0;
    const tem = v.max !== undefined && v.max !== null;
    return html`          <li class="vital vital--${cls}" data-vital="${String(v.key)}">
            <span class="vital__ic" aria-hidden="true">${icon(String(v.icon), 'fill')}</span>
            <span class="vital__head">
              <span class="vital__label">${String(v.label)}</span>
              <span class="vital__val num" data-vital-val>${num(Number(v.value), dec)}${tem
                ? html`<small>/${num(Number(v.max))}</small>`
                : v.unit ? html`<small class="unit">${String(v.unit)}</small>` : ''}</span>
            </span>
            ${progress({ pct: Number(v.pct ?? 0), color: cls === 'lime' ? '' : cls, anim: true, class: 'vital__bar' })}
            <span class="vital__cap">${String(v.status)}</span>
          </li>
`;
  };

  const grupo = (g: Dict): SafeHtml => {
    const itens = (g.items ?? []) as Dict[];
    let corpo: SafeHtml;
    if ((g.kind ?? '') === 'tiles') {
      corpo = html`        <div class="stiles" data-stagger>
${join(itens.map((it) => {
  const u = String(it.unit ?? '');
  return html`          <div class="stile stile--${String(it.cls ?? 'lime')}" data-boot>
            <span class="stile__ic" aria-hidden="true">${icon(String(it.icon), 'fill')}</span>
            <span class="stile__label">${String(it.label)}</span>
            <span class="stile__val num">${isNumeric(it.value) ? num(Number(it.value), Number(it.dec ?? 0) | 0) : String(it.value)}${it.unit ? html`<small${u.trimStart().startsWith('°') ? raw(' class="unit"') : ''}>${u}</small>` : ''}</span>
            <span class="stile__cap">${String(it.caption)}</span>
          </div>
`;
}))}        </div>
`;
    } else if ((g.kind ?? '') === 'bars') {
      corpo = html`        <div class="sbars" data-stagger>
${join(itens.map((it) => html`          <div class="sbar sbar--${String(it.cls ?? 'lime')}" data-boot>
            <span class="sbar__label">${String(it.label)}</span>
            <span class="sbar__val num">${num(Number(it.value))}<small>%</small></span>
            ${progress({ value: Number(it.value), max: Number(it.max), color: (it.cls ?? 'lime') === 'lime' ? '' : String(it.cls), anim: true, class: 'sbar__bar' })}
            <span class="sbar__cap">${String(it.caption)}</span>
          </div>
`))}        </div>
`;
    } else {
      corpo = html`        <div class="conds" data-stagger>
${join(itens.map((it) => html`          <div class="cond cond--eff cond--${String(it.cls ?? 'lime')}" data-boot>
            <span class="cond__ic" aria-hidden="true">${icon(String(it.icon), 'fill')}</span>
            <span class="cond__text">
              <strong class="cond__title">${String(it.title)}</strong>
              <small class="cond__desc">${String(it.desc)}</small>
            </span>
            <span class="cond__meta num" data-countdown="${String(it.left)}">${String(it.meta)}</span>
          </div>
`))}        </div>
`;
    }
    return html`        <h2 class="sd-sec">${String(g.title)}</h2>

${corpo}`;
  };

  return html`<div class="saude">

  <!-- ====================== VISÃO GERAL (esquerda) ====================== -->
  <section class="panel sd-ov" data-boot>
    <header class="panel__head">
      <h1 class="panel__title">${String(data.overview_title ?? 'VISÃO GERAL')}</h1>
    </header>
    <div class="panel__body">

      <div class="sd-ov__top">
        <figure class="sd-figure">
          <img class="sd-figure__img" src="${media(String(data.body_image ?? 'ui/body-wireframe-clean.png'))}"
               alt="${String(data.body_image_alt ?? '')}" width="200" height="453" decoding="async">
          <span class="sd-figure__scan" aria-hidden="true"></span>
        </figure>

        <ul class="vitals" data-stagger>
${join(vitals.map(vital))}        </ul>
      </div>

      <hr class="divider sd-ov__rule">

      <h2 class="sd-sec sd-sec--plain">${String(data.body_title ?? 'ESTADO CORPORAL')}</h2>

      <div class="bodyparts" data-stagger>
${join(([['a', colA], ['b', colB]] as Array<[string, Dict[]]>).map(([key, col]) => html`        <div class="bodyparts__col bodyparts__col--${key}">
${join(col.map((p) => html`          <div class="bpart${p.icon ? ' bpart--icon' : ''}" data-boot>
${p.icon ? html`            <span class="bpart__ic" aria-hidden="true">${partIcon(String(p.icon))}</span>
` : ''}            <span class="bpart__label">${String(p.label)}</span>
            <span class="bpart__val num">${num(Number(p.cur))}<small>/${num(Number(p.max))}</small></span>
            ${progress({ value: Number(p.cur), max: Number(p.max), anim: true, class: 'bpart__bar' })}
          </div>
`))}        </div>
`))}      </div>

      <a class="keybtn" href="${bodyHref}" id="sd-body-detail">
        <span class="keybtn__key num" aria-hidden="true">${String(bodyAct.key ?? 'X')}</span>
        <span class="keybtn__label">${String(bodyAct.label ?? 'VER DETALHES DO CORPO')}</span>
      </a>

    </div>
  </section>

  <!-- ====================== TRATAMENTO / STATUS (centro) ====================== -->
  <section class="panel sd-mid" data-boot data-tabs-scope>
    <nav class="tabs sd-tabs" aria-label="Seções da saúde">
${join(((data.tabs ?? []) as Dict[]).map((t) => {
  const slug = String(t.slug);
  const on = slug === activeTab;
  return html`      <a class="tab${on ? ' is-active' : ''}" href="${url('saude', { aba: slug })}" data-tab="${slug}"${on ? raw(' aria-current="page"') : ''}>${String(t.label)}</a>
`;
}))}    </nav>

    <div class="panel__body">

      <!-- ---------- aba TRATAMENTO ---------- -->
      <div class="sd-pane" data-tab-panel="tratamento"${activeTab === 'tratamento' ? '' : raw(' hidden')}>

        <h2 class="sd-sec">${String(data.healing_title ?? 'ITENS DE CURA')}</h2>
        <div class="mcards" data-stagger>
${join(healing.map((h) => html`          <article class="mcard mcard--heal" data-boot data-heal="${String(h.heal)}" data-name="${String(h.name)}" data-img="${media(String(h.image))}">
            <h3 class="mcard__name">${String(h.name)}</h3>
            <div class="mcard__media">
              <img class="mcard__img" src="${media(String(h.image))}" alt="" loading="lazy" decoding="async">
            </div>
            <p class="mcard__count num"><span data-count-cur>${String(h.count)}</span>/${String(h.total)}</p>
            <p class="mcard__desc">${String(h.desc)}</p>
            <button class="mcard__btn" type="button" data-use aria-label="${String(h.cta) + ' ' + String(h.name)}">${String(h.cta)}</button>
          </article>
`))}        </div>

        <h2 class="sd-sec">${String(data.exams_title ?? 'EXAMES MÉDICOS')}</h2>
        <div class="mcards" data-stagger>
${join(exams.map((x) => html`          <article class="mcard mcard--exam" data-boot data-result="${String(x.result)}" data-running="${String(data.exam_running ?? 'ANALISANDO')}">
            <h3 class="mcard__name">${String(x.name)}</h3>
            <div class="mcard__media">
              <img class="mcard__img" src="${media(String(x.image))}" alt="" loading="lazy" decoding="async">
              <span class="mcard__scan" aria-hidden="true"></span>
            </div>
            <p class="mcard__desc">${String(x.desc)}</p>
            <button class="mcard__btn" type="button" data-exam aria-label="${String(x.cta) + ' ' + String(x.name)}"><span class="mcard__btn-lbl">${String(x.cta)}</span></button>
          </article>
`))}        </div>

        <h2 class="sd-sec">${String(data.conditions_title ?? 'DOENÇAS E CONDIÇÕES')}</h2>
        <div class="conds" data-stagger>
${join(conditions.map((c) => html`          <div class="cond cond--${String(c.level ?? 'ok')}" data-boot>
            <span class="cond__ic" aria-hidden="true">${icon(String(c.icon))}</span>
            <span class="cond__text">
              <strong class="cond__title">${String(c.title)}</strong>
              <small class="cond__desc">${String(c.desc)}</small>
            </span>
          </div>
`))}        </div>

      </div>

      <!-- ---------- aba STATUS ---------- -->
      <div class="sd-pane" data-tab-panel="status"${activeTab === 'status' ? '' : raw(' hidden')}>
${join(groups.map(grupo))}      </div>

    </div>
  </section>

  <!-- ====================== DETALHES (direita) ====================== -->
  <aside class="panel sd-det" data-boot>
    <header class="panel__head">
      <h2 class="panel__title">${String(data.details_title ?? 'DETALHES')}</h2>
    </header>
    <div class="panel__body">

      <div class="sd-hero">
        <span class="sd-hero__badge">
          ${hex({ size: 'xl', color: 'lime', class: 'sd-hero__hex' })}
          <svg class="sd-hero__cross" viewBox="0 0 48 48" aria-hidden="true" focusable="false">
            <path class="sd-hero__cross-fill" d="M18.6 5h10.8c.9 0 1.6.7 1.6 1.6v10.8h10.8c.9 0 1.6.7 1.6 1.6v10.8c0 .9-.7 1.6-1.6 1.6H31v10.8c0 .9-.7 1.6-1.6 1.6H18.6c-.9 0-1.6-.7-1.6-1.6V31.4H6.2c-.9 0-1.6-.7-1.6-1.6V19c0-.9.7-1.6 1.6-1.6H17V6.6c0-.9.7-1.6 1.6-1.6Z"/>
            <path class="sd-hero__cross-cut" d="M23.2 12.6h1.6v25h-1.6z"/>
            <path class="sd-hero__cross-cut" d="M24 14.4c-3.1 1.4-3.1 4.6 0 6s3.1 4.6 0 6-3.1 4.6 0 6" fill="none" stroke-width="1.5" stroke-linecap="round"/>
            <path class="sd-hero__cross-cut" d="M24 13.7c-2.2-1.8-4.4-2.3-6.5-1.4 1.8 1.7 4 2.4 6.5 2.2Zm0 0c2.2-1.8 4.4-2.3 6.5-1.4-1.8 1.7-4 2.4-6.5 2.2Z"/>
          </svg>
        </span>
        <div class="sd-hero__id">
          <span class="sd-hero__label">${String(hp.label ?? 'SAÚDE')}</span>
          <span class="sd-hero__val num"><span class="lime" id="sd-hp">${num(Number(hp.value ?? 100))}</span><small>/${num(Number(hp.max ?? 100))}</small></span>
        </div>
      </div>

      <p class="sd-hero__desc">
        ${join(((hp.desc_lines ?? []) as unknown[]).map((line, k) => html`${k ? raw('<br>') : ''}${String(line)}`))}
      </p>

      <hr class="divider">

      <div class="sd-regen">
        <span class="sd-regen__ring" aria-hidden="true">${icon(String(regen.icon ?? 'monitor_heart'), 'fill')}</span>
        <span class="sd-regen__label">${String(regen.label ?? 'REGENERAÇÃO')}</span>
        <span class="sd-regen__val num">${num(Number(regen.value ?? 0), 1)}${String(regen.unit ?? '/s')}</span>
        <span class="sd-regen__desc">${String(regen.desc ?? '')}</span>
      </div>

      <hr class="divider">

      <h2 class="sd-sec sd-sec--plain">${String(data.history_title ?? 'ÚLTIMOS TRATAMENTOS')}</h2>

      <ul class="hist" id="sd-hist" data-stagger>
${join(history.map((h) => html`        <li class="hist__row" data-boot>
          <img class="hist__img" src="${media(String(h.image))}" alt="" loading="lazy" decoding="async">
          <span class="hist__text">
            <strong class="hist__name">${String(h.name)}</strong>
            <small class="hist__time num">${String(h.time)}</small>
          </span>
          <span class="hist__eff num">${String(h.effect)}</span>
        </li>
`))}      </ul>

      <a class="keybtn" href="${histHref}" id="sd-history">
        <span class="keybtn__key num" aria-hidden="true">${String(histAct.key ?? 'H')}</span>
        <span class="keybtn__label">${String(histAct.label ?? 'HISTÓRICO COMPLETO')}</span>
      </a>

    </div>
  </aside>

</div>
`;
}
