/**
 * component('row') — app/Views/components/row.php (linha de lista). Mesmas props:
 * title, sub, icon, svg, img, img_sq, hex, meta, meta_class, chev, lock, active, href, button,
 * size, flat, value, ctrl (HTML pronto), tip, class, attrs.
 */
import { img, svg } from '../../core/helpers';
import { e, raw, SafeHtml } from '../../core/html';
import type { Dict } from '../../core/view';
import { hex } from './index';

export function row(p: Dict = {}): SafeHtml {
  let cls = 'row';
  if (['lg', 'sm'].includes(p.size)) cls += ' row--' + p.size;
  if (p.flat) cls += ' row--flat';
  if (p.active) cls += ' is-active';
  if (p.lock) cls += ' is-locked';
  if (p.class) cls += ' ' + p.class;
  let attrs = '';
  if (p.value !== undefined && p.value !== null) attrs += ` data-value="${e(String(p.value))}"`;
  if (p.tip) attrs += ` data-tip="${e(String(p.tip))}"`;
  if (p.active) attrs += ' aria-current="true"';
  for (const [k, v] of Object.entries((p.attrs as Dict) ?? {})) {
    if (/^[a-zA-Z][\w:-]*$/.test(k)) attrs += ` ${e(k)}="${e(String(v))}"`;
  }
  const tag = p.href ? 'a' : p.button ? 'button' : 'div';
  if (tag === 'a') attrs += ` href="${e(String(p.href))}"`;
  if (tag === 'button') attrs += ' type="button"';

  let out = `<${tag} class="${e(cls)}"${attrs}>\n`;
  if (p.hex && typeof p.hex === 'object') out += `  <span class="row__icon">${hex(p.hex as Dict).value}</span>\n`;
  else if (p.img) out += `  <img class="row__img${p.img_sq ? ' row__img--sq' : ''}" src="${e(img(String(p.img)))}" alt="" loading="lazy">\n`;
  else if (p.svg) out += `  ${svg(String(p.svg), 'row__icon').value}\n`;
  else if (p.icon) out += `  <span class="row__icon ms" aria-hidden="true">${e(String(p.icon))}</span>\n`;
  out += `  <span class="row__text"><strong>${e(String(p.title ?? ''))}</strong>${p.sub ? `<small>${e(String(p.sub))}</small>` : ''}</span>\n`;
  if (p.ctrl) out += `  <span class="row__ctrl">${String(p.ctrl)}</span>\n`;
  else if (p.meta !== undefined && p.meta !== null && p.meta !== '') {
    out += `  <span class="row__meta num${p.meta_class ? ' ' + e(String(p.meta_class)) : ''}">${e(String(p.meta))}</span>\n`;
  }
  if (p.lock) out += '  <span class="ms row__lock" aria-hidden="true">lock</span>\n';
  else if (p.chev) out += '  <span class="ms row__chev" aria-hidden="true">chevron_right</span>\n';
  return raw(out + `</${tag}>\n`);
}
