/**
 * Tela BASE DE CAMPO (/base) — app/Views/pages/base.php (PRD v3 §28). P0: estado vazio honesto
 * + módulos como prévia. Quando player_bases existir (P1), `base` vem preenchida.
 */
import { icon, url } from '../../core/helpers';
import { html, join, SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { badge } from '../components';
import { btn } from '../components/btn';

export function base(s: Scope): SafeHtml {
  const d = s.data;
  const labels: Dict = d.labels ?? {};
  const empty: Dict = d.empty ?? {};
  const modules = (d.modules ?? []) as Dict[];
  const terms = (d.terms ?? []) as Dict[];
  const b: Dict | null = d.base && typeof d.base === 'object' ? d.base : null;
  const op: Dict = d.operational ?? {};
  const L = (k: string, def: string): string => String(labels[k] ?? def);
  const ativos = b !== null ? ((Array.isArray(b.modules) ? b.modules : []) as unknown[]).map((m) => String(m).toLowerCase()) : [];

  const principal =
    b === null
      ? html`      <div class="fb-empty">
        <span class="fb-empty__ic" aria-hidden="true">${icon('home_work')}</span>
        <h2 class="fb-empty__title">${String(empty.title ?? 'NENHUMA BASE DE CAMPO ATIVA')}</h2>
        <p class="fb-empty__desc">${String(empty.desc ?? '')}</p>
${d.is_survivor ? html`        <p class="fb-empty__surv">${badge({ kind: 'state', value: op })}<span>${String(d.survivor_note ?? '')}</span></p>
` : ''}        <div class="fb-empty__actions">
          ${btn({ label: String(empty.cta ?? 'VER MAPA'), href: url(String(empty.href ?? 'mapa')), variant: 'primary', chev: true })}          ${btn({ label: String(empty.cta2 ?? 'STASH CENTRAL'), href: url(String(empty.href2 ?? 'inventario')), variant: 'outline' })}        </div>
        <small class="fb-empty__note">${String(d.p1_note ?? '')}</small>
      </div>
`
      : html`      <header class="panel__head">
        <h2 class="panel__title">${String(b.name ?? 'BASE DE CAMPO')}</h2>
        ${badge({ kind: 'base', value: String(b.status ?? 'OPERACIONAL') })}      </header>
      <ul class="fb-rows">
        <li><span>${L('region', 'REGIÃO')}</span><b>${String(b.region_name ?? '—')}</b></li>
        <li><span>${L('security', 'SEGURANÇA')}</span><b>${String(b.security ?? '—')}</b></li>
        <li><span>${L('storage', 'ARMAZENAMENTO')}</span><b class="num">${String(b.storage_used ?? 0)} / ${String(b.storage_total ?? 0)}</b></li>
        <li><span>${L('vehicles', 'VEÍCULOS')}</span><b class="num">${String(b.vehicles ?? 0)}</b></li>
        <li><span>${L('exposure', 'EXPOSIÇÃO')}</span><b>${String(b.exposure ?? 'MÉDIA')}</b></li>
        <li><span>${L('human', 'ATIVIDADE HUMANA PRÓXIMA')}</span><b>${String(b.human_activity ?? 'NÃO DETECTADA')}</b></li>
      </ul>
`;

  return html`<div class="fb">

  <header class="fb__head" data-boot>
    <div>
      <h1 class="page-title">${String(d.title ?? 'BASE DE CAMPO')}<span class="page-title__tag">${String(d.subtitle ?? '')}</span></h1>
      <p class="page-subtitle fb__intro">${String(d.intro ?? '')}</p>
    </div>
    ${badge({ kind: 'state', value: op, level: Number(s.player.level ?? 0) | 0, lg: true })}  </header>

  <div class="fb__grid">

    <!-- painel principal -->
    <section class="panel fb-main" data-boot>
${principal}    </section>

    <!-- módulos -->
    <section class="panel fb-mods" data-boot>
      <header class="panel__head"><h2 class="panel__title">${String(d.modules_title ?? 'MÓDULOS')}</h2></header>
      <ul class="fb-mods__list" data-stagger>
${join(modules.map((m) => {
  const on = ativos.includes(String(m.slug).toLowerCase());
  return html`        <li class="fb-mod${on ? ' is-on' : ''}" data-boot>
          <span class="fb-mod__ic" aria-hidden="true">${icon(String(m.icon))}</span>
          <span class="fb-mod__txt"><strong>${String(m.label)}</strong><small>${String(m.desc)}</small></span>
          <span class="fb-mod__st">${on ? 'ATIVO' : 'NÃO CONSTRUÍDO'}</span>
        </li>
`;
}))}      </ul>
    </section>

    <!-- glossário curto: o que é o quê -->
    <section class="panel fb-terms" data-boot>
      <header class="panel__head"><h2 class="panel__title">${String(d.terms_title ?? 'NÃO CONFUNDIR')}</h2></header>
      <ul class="fb-terms__list">
${join(terms.map((t) => html`        <li><b>${String(t.k)}</b><span>${String(t.v)}</span></li>
`))}      </ul>
    </section>

  </div>
</div>
`;
}
