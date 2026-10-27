/**
 * component('stepper') — app/Views/components/stepper.php. Mesmas props:
 * options, value, name, loop, boxed, class, attrs.
 * O hud.js cicla as opções (data-options), atualiza .stepper__val e o input escondido.
 */
import { e, raw, SafeHtml } from '../../core/html';
import type { Dict } from '../../core/view';

export function stepper(p: Dict = {}): SafeHtml {
  const options = (Array.isArray(p.options) ? p.options : []).map((o: unknown) => String(o));
  const value = String(p.value ?? options[0] ?? '');
  let cls = 'stepper';
  if (p.boxed) cls += ' stepper--boxed';
  if (p.class) cls += ' ' + p.class;
  let attrs = ` data-options="${e(options.join('|'))}" data-value="${e(value)}"`;
  if (p.loop !== undefined && p.loop !== null && !p.loop) attrs += ' data-loop="false"';
  for (const [k, v] of Object.entries((p.attrs as Dict) ?? {})) {
    if (/^[a-zA-Z][\w:-]*$/.test(k)) attrs += ` ${e(k)}="${e(String(v))}"`;
  }
  let out = `<div class="${e(cls)}"${attrs}>\n`;
  out += '  <button class="stepper__btn ms" type="button" aria-label="Anterior">chevron_left</button>\n';
  out += `  <span class="stepper__val">${e(value)}</span>\n`;
  out += '  <button class="stepper__btn ms" type="button" aria-label="Próximo">chevron_right</button>\n';
  if (p.name) out += `  <input type="hidden" name="${e(String(p.name))}" value="${e(value)}">\n`;
  return raw(out + '</div>\n');
}
