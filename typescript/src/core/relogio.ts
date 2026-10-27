/**
 * Relógio no fuso do PHP (date_default_timezone_set('America/Sao_Paulo') em app/bootstrap.php).
 *
 * O Worker roda em UTC; as telas que o PHP calculava com DateTime/strtotime no fuso de
 * São Paulo (prazos de desafios, chaves de período, agenda de notícias) usam isto para dar
 * o mesmo resultado. O Brasil não tem horário de verão desde 2019: o deslocamento é fixo
 * em -03:00, então não é preciso tabela de fuso (Intl) para converter nos dois sentidos.
 */

const OFFSET_MS = -3 * 3600 * 1000;
const SUFIXO = '-03:00';

/** "Relógio de parede" de SP como Date cujos campos UTC são a hora local de SP. */
export function paredeSp(ms = Date.now()): Date {
  return new Date(ms + OFFSET_MS);
}

/** Converte um relógio de parede de SP (campos UTC) de volta para o instante real em ms. */
export function instanteDe(parede: Date): number {
  return parede.getTime() - OFFSET_MS;
}

/**
 * DATETIME do banco ('YYYY-MM-DD HH:MM:SS', gravado no fuso de SP pelo MySQL) -> ms.
 * Aceita também só a data. Valor inválido devolve NaN.
 */
export function lerDataSp(valor: string): number {
  const m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/.exec(valor.trim());
  if (!m) return NaN;
  const parede = Date.UTC(+m[1]!, +m[2]! - 1, +m[3]!, +(m[4] ?? 0), +(m[5] ?? 0), +(m[6] ?? 0));
  return parede - OFFSET_MS;
}

const p2 = (n: number): string => String(n).padStart(2, '0');

/** date('Y-m-d') em SP. */
export function ymd(ms = Date.now()): string {
  const d = paredeSp(ms);
  return `${d.getUTCFullYear()}-${p2(d.getUTCMonth() + 1)}-${p2(d.getUTCDate())}`;
}

/** date('d/m/Y', strtotime($valor)) para um DATETIME/DATE do banco. '' se inválido. */
export function dmy(valor: string): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(valor.trim());
  return m ? `${m[3]}/${m[2]}/${m[1]}` : '';
}

/** date(DATE_ATOM, ms) em SP: 2026-10-29T21:00:00-03:00. */
export function atom(ms: number): string {
  const d = paredeSp(ms);
  return (
    `${d.getUTCFullYear()}-${p2(d.getUTCMonth() + 1)}-${p2(d.getUTCDate())}` +
    `T${p2(d.getUTCHours())}:${p2(d.getUTCMinutes())}:${p2(d.getUTCSeconds())}${SUFIXO}`
  );
}

/** gmdate('c', ms): 2026-11-01T22:36:37+00:00. */
export function atomUtc(ms: number): string {
  return new Date(Math.floor(ms / 1000) * 1000).toISOString().replace('.000Z', '+00:00');
}

/** Meia-noite (SP) de hoje + `dias` dias, como instante em ms. */
export function meiaNoite(dias = 0, ms = Date.now()): number {
  const d = paredeSp(ms);
  return instanteDe(new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate() + dias)));
}

/** modify('monday next week midnight'): segunda-feira da próxima semana ISO, 00:00 de SP. */
export function segundaQueVem(ms = Date.now()): number {
  const dow = (paredeSp(ms).getUTCDay() + 6) % 7; // 0 = segunda ... 6 = domingo
  return meiaNoite(7 - dow, ms);
}

/** strtotime('next tuesday 03:00') etc.: próximo dia da semana (estritamente depois de hoje) às hh:mm. */
export function proximoDiaSemana(diaSemana: number, hora: number, minuto = 0, ms = Date.now()): number {
  const hoje = paredeSp(ms).getUTCDay();
  let delta = (diaSemana - hoje + 7) % 7;
  if (delta === 0) delta = 7;
  return meiaNoite(delta, ms) + (hora * 60 + minuto) * 60000;
}

/** format('o-\WW'): ano ISO + semana ISO, ex. 2026-W44. */
export function semanaIso(ms = Date.now()): string {
  const d = paredeSp(ms);
  const t = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate()));
  const dow = (t.getUTCDay() + 6) % 7;
  t.setUTCDate(t.getUTCDate() - dow + 3); // quinta-feira da mesma semana decide o ano ISO
  const ano = t.getUTCFullYear();
  const semana = 1 + Math.floor((t.getTime() - Date.UTC(ano, 0, 1)) / 86400000 / 7);
  return `${ano}-W${p2(semana)}`;
}
