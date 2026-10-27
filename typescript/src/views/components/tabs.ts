/**
 * component('tabs') — app/Views/components/tabs.php. Props: items[] ({label, href, active,
 * panel, icon, count, attrs}), active, fill, pills, class, label.
 * Aba com href navega (<a>); aba só com panel troca painel no cliente (<button>).
 */
import { icon } from '../../core/helpers';
import { e, raw, SafeHtml } from '../../core/html';
import type { Dict } from '../../core/view';

export function tabs(p: Dict = {}): SafeHtml {
  const items = (Array.isArray(p.items) ? p.items : []) as Dict[];
  let cls = 'tabs';
  if (p.fill) cls += ' tabs--fill';
  if (p.pills) cls += ' tabs--pills';
  if (p.class) cls += ' ' + p.class;
  const activeKey = String(p.active ?? '');

  let out = `<nav class="${e(cls)}" aria-label="${e(String(p.label ?? 'Abas'))}">\n`;
  for (const it of items) {
    const label = String(it.label ?? '');
    const panel = String(it.panel ?? '');
    const isActive = Boolean(it.active) || (activeKey !== '' && (activeKey === label || activeKey === panel));
    const href = String(it.href ?? '');
    let a = '';
    if (panel !== '') a += ` data-tab="${e(panel)}"`;
    for (const [k, v] of Object.entries((it.attrs as Dict) ?? {})) {
      if (/^[a-zA-Z][\w:-]*$/.test(k)) a += ` ${e(k)}="${e(String(v))}"`;
    }
    let inner = it.icon ? icon(String(it.icon)).value : '';
    inner += e(label);
    if (it.count !== undefined && it.count !== null && it.count !== '') inner += ` <span class="tab__count num">${e(String(it.count))}</span>`;
    out += href !== ''
      ? `  <a class="tab${isActive ? ' is-active' : ''}" href="${e(href)}"${a}${isActive ? ' aria-current="page"' : ''}>${inner}</a>\n`
      : `  <button type="button" class="tab${isActive ? ' is-active' : ''}"${a} aria-selected="${isActive ? 'true' : 'false'}">${inner}</button>\n`;
  }
  return raw(out + '</nav>\n');
}
