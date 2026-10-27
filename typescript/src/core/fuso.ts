/**
 * Relógio de parede de America/Sao_Paulo — o date()/strtotime() do PHP com
 * date_default_timezone_set('America/Sao_Paulo') (app/bootstrap.php).
 *
 * Usado onde o PHP gravava e formatava DATETIME no horário local (mensagens, chat do lobby,
 * janelas de manutenção). O Brasil não tem horário de verão desde 2019, então o deslocamento
 * é fixo em -03:00 — não depende de Intl/tzdata no Worker.
 */
const OFFSET_MS = -3 * 3600 * 1000;

const pad = (n: number): string => String(n).padStart(2, '0');

/** Partes da data local (UTC-3) de um instante em ms. */
function partes(ms: number): { Y: number; m: number; d: number; H: number; i: number; s: number } {
  const t = new Date(ms + OFFSET_MS);
  return {
    Y: t.getUTCFullYear(), m: t.getUTCMonth() + 1, d: t.getUTCDate(),
    H: t.getUTCHours(), i: t.getUTCMinutes(), s: t.getUTCSeconds(),
  };
}

/** time() em segundos. */
export function agoraTs(): number {
  return Math.floor(Date.now() / 1000);
}

/** strtotime('Y-m-d H:i:s' local) -> segundos; NaN quando não dá para ler. */
export function strtotime(dt: string): number {
  const m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/.exec(dt.trim());
  if (!m) return NaN;
  const utc = Date.UTC(+m[1]!, +m[2]! - 1, +m[3]!, +(m[4] ?? 0), +(m[5] ?? 0), +(m[6] ?? 0));
  return Math.floor((utc - OFFSET_MS) / 1000);
}

/** date(formato, ts) com os códigos que o app usa: Y m d H i s. */
export function date(formato: string, ts: number = agoraTs()): string {
  const p = partes(ts * 1000);
  const map: Record<string, string> = {
    Y: String(p.Y), m: pad(p.m), d: pad(p.d), H: pad(p.H), i: pad(p.i), s: pad(p.s),
  };
  return formato.replace(/[YmdHis]/g, (c) => map[c]!);
}
