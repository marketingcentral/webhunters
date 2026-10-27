/**
 * component('range') — app/Views/components/range.php. Mesmas props:
 * name, id, min, max, step, value, suffix, dec, wide, show, label, class, attrs.
 * O hud.js atualiza o valor e a variável --v (preenchimento do trilho).
 */
import { num } from '../../core/helpers';
import { e, raw, SafeHtml } from '../../core/html';
import type { Dict } from '../../core/view';

export function range(p: Dict = {}): SafeHtml {
  const min = Number(p.min ?? 0) || 0;
  const max = Number(p.max ?? 100) || 0;
  const step = Number(p.step ?? 1) || 0;
  const value = Number(p.value ?? 50) || 0;
  const suffix = String(p.suffix ?? '%');
  const dec = Number(p.dec ?? 0) | 0;
  const pct = max > min ? ((value - min) / (max - min)) * 100 : 0;
  // inteiro sem casas: "60"; senão number_format(n, max(dec,2), '.', '') sem zeros à direita
  const fmt = (n: number): string =>
    n === Math.floor(n) && dec === 0 ? String(Math.trunc(n)) : n.toFixed(Math.max(dec, 2)).replace(/0+$/, '').replace(/\.$/, '');
  let cls = 'range';
  if (p.wide) cls += ' range--wide';
  if (p.class) cls += ' ' + p.class;
  let attrs = ` data-suffix="${e(suffix)}"` + (dec ? ` data-dec="${dec}"` : '');
  if (p.name) attrs += ` name="${e(String(p.name))}"`;
  if (p.id) attrs += ` id="${e(String(p.id))}"`;
  if (p.label) attrs += ` aria-label="${e(String(p.label))}"`;
  for (const [k, v] of Object.entries((p.attrs as Dict) ?? {})) {
    if (/^[a-zA-Z][\w:-]*$/.test(k)) attrs += ` ${e(k)}="${e(String(v))}"`;
  }
  let out = `<span class="range-wrap${p.wide ? ' w-full' : ''}">\n`;
  out += `  <input type="range" class="${e(cls)}" min="${e(fmt(min))}" max="${e(fmt(max))}" step="${e(fmt(step))}" value="${e(fmt(value))}" style="--v:${e(pct.toFixed(2))}%"${attrs}>\n`;
  if (p.show ?? true) out += `  <span class="range__val num">${e(num(value, dec))}${e(suffix)}</span>\n`;
  return raw(out + '</span>\n');
}
