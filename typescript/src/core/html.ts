/**
 * Saída HTML segura — o equivalente do `<?= e($x) ?>` dos templates PHP.
 *
 * Todo valor interpolado em html`` é escapado, a não ser que já seja SafeHtml (saída de
 * outro template, de raw() ou de um helper como icon()). Arrays são concatenados item a
 * item com a mesma regra; null/undefined/false viram texto vazio — o mesmo que e() fazia
 * com null e bool no PHP.
 */
export class SafeHtml {
  constructor(public readonly value: string) {}
  toString(): string {
    return this.value;
  }
}

const MAPA: Record<string, string> = {
  '&': '&amp;',
  '<': '&lt;',
  '>': '&gt;',
  '"': '&quot;',
  "'": '&apos;', // ENT_HTML5, igual ao PHP
};

/** htmlspecialchars(ENT_QUOTES | ENT_HTML5). Arrays/objetos viram vazio, como no PHP. */
export function e(v: unknown): string {
  if (v === null || v === undefined || v === false) return '';
  if (v === true) return '1';
  if (v instanceof SafeHtml) return v.value;
  if (typeof v === 'object') return '';
  return String(v).replace(/[&<>"']/g, (c) => MAPA[c]!);
}

function render(v: unknown): string {
  if (v instanceof SafeHtml) return v.value;
  if (Array.isArray(v)) return v.map(render).join('');
  return e(v);
}

export function html(strings: TemplateStringsArray, ...values: unknown[]): SafeHtml {
  let out = strings[0] ?? '';
  for (let i = 0; i < values.length; i++) {
    out += render(values[i]) + (strings[i + 1] ?? '');
  }
  return new SafeHtml(out);
}

/** Marca uma string como HTML já pronto (vem de arquivo de dados confiável, SVG do catálogo...). */
export function raw(s: string | null | undefined): SafeHtml {
  return new SafeHtml(s ?? '');
}

/** Concatena fragmentos (o `foreach` dos templates). */
export function join(parts: Iterable<unknown>, sep = ''): SafeHtml {
  return new SafeHtml(Array.from(parts, render).join(sep));
}

/** Liga um atributo booleano/condicional: when(cond, html`...`). */
export function when(cond: unknown, a: () => unknown, b?: () => unknown): SafeHtml {
  const v = cond ? a() : b ? b() : '';
  return v instanceof SafeHtml ? v : new SafeHtml(render(v));
}
