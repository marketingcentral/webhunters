/**
 * component('toggle') — app/Views/components/toggle.php. Mesmas props:
 * name, id, checked, on, off, label, lime, right, sm, disabled, class, attrs.
 * O hud.js troca o texto do rótulo (on/off) ao mudar.
 */
import { e, raw, SafeHtml } from '../../core/html';
import type { Dict } from '../../core/view';

export function toggle(p: Dict = {}): SafeHtml {
  const on = String(p.on ?? 'ATIVADO');
  const off = String(p.off ?? 'DESATIVADO');
  const checked = Boolean(p.checked);
  let cls = 'toggle';
  if (p.lime) cls += ' toggle--lime';
  if (p.right) cls += ' toggle--label-right';
  if (p.sm) cls += ' toggle--sm';
  if (p.class) cls += ' ' + p.class;
  let attrs = ` data-on="${e(on)}" data-off="${e(off)}"`;
  for (const [k, v] of Object.entries((p.attrs as Dict) ?? {})) {
    if (/^[a-zA-Z][\w:-]*$/.test(k)) attrs += ` ${e(k)}="${e(String(v))}"`;
  }
  let out = `<label class="${e(cls)}"${attrs}>\n`;
  out +=
    '  <input type="checkbox" value="1"' +
    (p.name ? ` name="${e(String(p.name))}"` : '') +
    (p.id ? ` id="${e(String(p.id))}"` : '') +
    (checked ? ' checked' : '') +
    (p.disabled ? ' disabled' : '') +
    ` role="switch" aria-checked="${checked ? 'true' : 'false'}">\n`;
  out += '  <span class="toggle__track" aria-hidden="true"></span>\n';
  // `$p['label'] ?? true`: só some quando vier falso de propósito
  if (p.label ?? true) out += `  <span class="toggle__label">${e(checked ? on : off)}</span>\n`;
  return raw(out + '</label>\n');
}
