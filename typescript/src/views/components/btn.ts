/**
 * component('btn') — app/Views/components/btn.php. Mesmas props:
 * label, href, type, variant, size, icon, svg, chev, block, active, disabled, badge, tip,
 * class, id, name, value, form, attrs. Com href (e sem disabled) vira <a>, senão <button>.
 */
import { icon, svg } from '../../core/helpers';
import { e, raw, SafeHtml } from '../../core/html';
import type { Dict } from '../../core/view';

const VARIANTES = ['primary', 'gold', 'ghost', 'outline', 'lime', 'danger'];
const TAMANHOS = ['lg', 'sm', 'xs'];

/** empty() do PHP: null, '', '0', 0, false e [] contam como vazio. */
function vazio(v: unknown): boolean {
  return v === undefined || v === null || v === false || v === '' || v === '0' || v === 0 || (Array.isArray(v) && v.length === 0);
}

export function btn(p: Dict = {}): SafeHtml {
  let cls = 'btn';
  if (VARIANTES.includes(p.variant)) cls += ' btn--' + p.variant;
  if (TAMANHOS.includes(p.size)) cls += ' btn--' + p.size;
  if (!vazio(p.block)) cls += ' btn--block';
  if (!vazio(p.active)) cls += ' is-active';
  if (vazio(p.label) && (!vazio(p.icon) || !vazio(p.svg))) cls += ' btn--icon';
  if (!vazio(p.class)) cls += ' ' + p.class;

  let attrs = '';
  for (const k of ['id', 'name', 'value', 'form']) {
    if (p[k] !== undefined && p[k] !== null && p[k] !== '') attrs += ` ${k}="${e(String(p[k]))}"`;
  }
  if (!vazio(p.tip)) attrs += ` data-tip="${e(String(p.tip))}"`;
  for (const [k, v] of Object.entries((p.attrs as Dict) ?? {})) {
    if (/^[a-zA-Z][\w:-]*$/.test(k)) attrs += ' ' + e(k) + (v === true ? '' : `="${e(String(v))}"`);
  }

  const isLink = !vazio(p.href) && vazio(p.disabled);
  let type = String(p.type ?? 'button');
  if (!['button', 'submit', 'reset'].includes(type)) type = 'button';

  let inner = '';
  if (!vazio(p.svg)) inner += svg(String(p.svg)).value;
  else if (!vazio(p.icon)) inner += icon(String(p.icon)).value;
  if (!vazio(p.label)) inner += `<span class="btn__label">${e(String(p.label))}</span>`;
  if (p.badge !== undefined && p.badge !== '' && p.badge !== null) inner += `<span class="btn__badge num">${e(String(p.badge))}</span>`;
  if (!vazio(p.chev)) inner += icon('chevron_right', 'btn__chev').value;

  if (isLink) return raw(`<a class="${e(cls)}" href="${e(String(p.href))}"${attrs}>${inner}</a>\n`);
  return raw(`<button class="${e(cls)}" type="${e(type)}"${!vazio(p.disabled) ? ' disabled' : ''}${attrs}>${inner}</button>\n`);
}
