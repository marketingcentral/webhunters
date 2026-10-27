/**
 * Tela CONFIGURAÇÕES — app/Views/pages/configuracoes.php (referência 04-settings).
 * $data: title, tabs, active_tab, tab, groups (da aba ativa), detail.
 * Layout: [categorias] | [grupos de opções] | [painel de detalhe + ações].
 */
import { csrfField, icon, media, url } from '../../core/helpers';
import { e, html, join, raw, SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { range } from '../components/range';
import { row } from '../components/row';
import { stepper } from '../components/stepper';
import { toggle } from '../components/toggle';

/**
 * Glifo dos ladrilhos de preset: família única "explosão" que ganha pontas conforme a
 * intensidade ('burst:N' e 'burst:N:hex'); qualquer outro nome cai no Material Symbols preenchido.
 */
function tileGlyph(name: string): SafeHtml {
  const m = /^burst:(\d{1,2})(:hex)?$/.exec(name);
  if (!m) return icon(name, 'set-tile__icon fill');
  const spikes = Math.max(3, Math.min(16, Number(m[1])));
  const caged = m[2] !== undefined;
  const r0 = caged ? 2.8 : 3.6;
  const r1 = caged ? 6.6 : 9.2;
  const n = (v: number): string => v.toFixed(2);
  let out = '';
  for (let i = 0; i < spikes; i++) {
    const a = ((Math.PI * 2) / spikes) * i - Math.PI / 2;
    out +=
      `<line x1="${n(12 + Math.cos(a) * r0)}" y1="${n(12 + Math.sin(a) * r0)}"` +
      ` x2="${n(12 + Math.cos(a) * r1)}" y2="${n(12 + Math.sin(a) * r1)}"/>`;
  }
  const frame = caged
    ? '<path d="M12 1.7 21 6.85 21 17.15 12 22.3 3 17.15 3 6.85Z"/><circle cx="12" cy="12" r="1.7"/>'
    : '<circle cx="12" cy="12" r="2.3"/>';
  return raw(
    '<svg class="ic set-tile__icon set-tile__burst" viewBox="0 0 24 24" fill="none" stroke="currentColor"' +
      ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
      frame + out + '</svg>',
  );
}

/** O controle de cada opção, conforme o tipo. */
function controle(it: Dict): SafeHtml {
  const key = String(it.key);
  const type = String(it.type);
  if (type === 'toggle') return toggle({ name: key, checked: Boolean(it.value) });
  if (type === 'stepper' || type === 'select') {
    // A caixa do stepper tem largura fixa: encolhe a fonte conforme a opção mais longa.
    let longest = 0;
    for (const o of (it.options ?? []) as unknown[]) longest = Math.max(longest, Array.from(String(o)).length);
    const fit = longest > 17 ? ' set-stepper--xs' : longest > 12 ? ' set-stepper--sm' : '';
    return stepper({ options: it.options ?? [], value: String(it.value), name: key, class: fit.trim() });
  }
  if (type === 'range') {
    return range({
      name: key,
      value: Number(it.value) || 0,
      suffix: String(it.unit ?? '%'),
      max: Number(it.max ?? ((it.unit ?? '%') === '' ? 120 : 100)) | 0,
      label: String(it.label),
    });
  }
  if (type === 'key') {
    return html`<button type="button" class="keycap" data-keybind
                                                        aria-label="${'Reatribuir tecla: ' + String(it.label)}">${String(it.value)}</button>
                                                <input type="hidden" name="${key}" value="${String(it.value)}">`;
  }
  return html`<input class="input set-input" type="text" name="${key}" value="${String(it.value)}" aria-label="${String(it.label)}">`;
}

export function configuracoes(s: Scope): SafeHtml {
  const d = s.data;
  const tabs = (d.tabs ?? []) as Dict[];
  const aba = String(d.active_tab ?? 'jogabilidade');
  const tab: Dict | null = d.tab ?? null;
  const groups = (d.groups ?? []) as Dict[];

  const detail: Dict = d.detail ?? {};
  const options = (detail.options ?? []) as Dict[];
  let note = String(detail.note ?? '');
  let preset = '';
  for (const opt of options) {
    if (opt.active) {
      preset = String(opt.slug ?? '');
      if (note === '') note = String(opt.desc ?? '');
    }
  }
  const formAction = url('configuracoes/' + aba);
  const stats = (detail.stats ?? []) as Dict[];

  return html`<form class="set" method="post" action="${formAction}" data-settings-tab="${aba}">
  <button type="submit" name="action" value="apply" class="set-default-submit" tabindex="-1" aria-hidden="true"></button>
    ${csrfField()}

    <!-- ============================ categorias ============================ -->
    <div class="set__left">
        <h1 class="page-title set__title" data-boot>${String(d.title ?? 'CONFIGURAÇÕES')}</h1>
        <nav class="set-nav" aria-label="Categorias de configuração" data-stagger>
${join(
  tabs.map(
    (item) => html`                ${row({
      title: String(item.label),
      sub: String(item.desc),
      icon: String(item.icon),
      href: url('configuracoes/' + String(item.slug)),
      active: item.slug === aba,
      class: 'set-nav__row',
    })}`,
  ),
)}            <span class="set-nav__filler" aria-hidden="true"></span>
        </nav>
    </div>

    <!-- ============================== opções ============================== -->
    <div class="set__mid">
        <h2 class="set__mid-title" data-boot>${String(tab?.label ?? '')}</h2>
        <section class="panel set-panel">
            <div class="panel__body set-panel__body">
                <div class="scroll-y set-scroll">
${join(
  groups.map(
    (group) => html`                        <div class="set-group">
                            <h3 class="set-group__title">${String(group.title)}</h3>
                            <div class="set-list">
${join(
  ((group.items ?? []) as Dict[]).map(
    (it) => html`                                    <div class="row row--setting set-row${it.active ? ' is-active' : ''}">
                                        <span class="row__label">${String(it.label)}</span>
                                        <span class="row__ctrl">
                                            ${controle(it)}
                                        </span>
                                    </div>
`,
  ),
)}                            </div>
                        </div>
`,
  ),
)}                </div>
            </div>
        </section>
    </div>

    <!-- ============================= detalhe ============================== -->
    <aside class="panel set-detail">
        <div class="panel__body set-detail__body">
            <h2 class="set-detail__title">${String(detail.title ?? '')}</h2>
            <p class="set-detail__desc">${String(detail.desc ?? '')}</p>

${detail.image
  ? html`                <figure class="set-detail__media">
                    <img src="${media(String(detail.image))}" alt="" class="set-detail__img"${detail.image_pos ? raw(` style="object-position:${e(String(detail.image_pos))}"`) : ''}>
                </figure>
`
  : ''}
${options.length > 0
  ? html`                <div class="set-tiles" data-select-group data-select-input="#set-preset">
${join(
  options.map(
    (opt) => html`                        <button type="button" class="btn set-tile${opt.active ? ' is-active' : ''}"
                                data-select data-value="${String(opt.slug)}"
                                data-note="${String(opt.desc ?? '')}"
                                aria-pressed="${opt.active ? 'true' : 'false'}">
                            ${tileGlyph(String(opt.icon))}
                            <span class="set-tile__label">${String(opt.label)}</span>
                        </button>
`,
  ),
)}                    <input type="hidden" id="set-preset" name="set_preset" value="${preset}">
                </div>
                <p class="set-detail__note" data-note-target aria-live="polite">${note}</p>
`
  : ''}
${stats.length > 0
  ? html`                <div class="set-stats">
${join(
  stats.map(
    (st) => html`                        <div class="set-stat">
                            <span class="ms set-stat__icon" aria-hidden="true">${String(st.icon)}</span>
                            <span class="set-stat__label">${String(st.label)}</span>
                            <span class="set-stat__val">${String(st.value)}</span>
                        </div>
`,
  ),
)}                </div>
`
  : ''}
            <div class="set-actions">
                <button class="btn set-btn set-btn--reset" type="submit" name="action" value="reset">
                    ${icon('restart_alt')}<span class="btn__label">${String(detail.reset ?? 'RESTAURAR PADRÕES')}</span>
                </button>
                <button class="btn btn--primary set-btn set-btn--apply" type="submit" name="action" value="apply">
                    <span class="btn__label">${String(detail.apply ?? 'APLICAR ALTERAÇÕES')}</span>
                </button>
            </div>
        </div>
    </aside>
</form>
`;
}
