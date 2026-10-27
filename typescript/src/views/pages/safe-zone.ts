/**
 * Tela SAFE ZONE (/safe-zone) — app/Views/pages/safe-zone.php. Três colunas: zonas conhecidas
 * à esquerda (+ turno atual), papéis civis no centro, zona selecionada à direita.
 */
import * as GR from '../../core/game-rules';
import { csrfField, icon, url } from '../../core/helpers';
import { html, join, SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { badge } from '../components';
import { btn } from '../components/btn';

const dois = (n: number): string => String(n).padStart(2, '0');

export function safeZone(s: Scope): SafeHtml {
  const d = s.data;
  const zones = (d.zones ?? []) as Dict[];
  const zone: Dict | null = d.zone && typeof d.zone === 'object' ? d.zone : null;
  const roles = (d.roles ?? []) as Dict[];
  const cur: Dict | null = d.current && typeof d.current === 'object' ? d.current : null;
  const op: Dict = d.operational ?? {};
  const labels: Dict = d.labels ?? {};
  const cta: Dict = d.cta ?? {};
  const surv = Boolean(d.survivor);
  const merc = (op.state ?? '') === GR.STATE_MERCENARY;
  const back: Dict = d.back ?? {};
  // No PHP o controller troca `empty` por um bool; ler título/descrição dele dá null (padrão).
  const emptyD: Dict = d.empty && typeof d.empty === 'object' ? d.empty : {};
  const ativa = String(d.active_zone ?? '');
  const L = (k: string, def: string): string => String(labels[k] ?? def);

  const aviso = surv
    ? html`  <p class="sz__notice sz__notice--survivor" role="status" data-boot>
    ${badge({ kind: 'state', value: op })}    <span>${String(d.survivor_notice ?? '')}</span>
  </p>
`
    : merc
      ? html`  <p class="sz__notice" role="status" data-boot>
    ${badge({ kind: 'faction', value: String(op.faction ?? '') })}    <span>${String(d.mercenary_notice ?? '')}</span>
  </p>
`
      : '';

  const listaZonas =
    zones.length === 0
      ? html`          <p class="sz-zones__none">${String(emptyD.title ?? 'NENHUMA SAFE ZONE MAPEADA')}</p>
`
      : html`          <div class="sz-zones__list" data-stagger>
${join(zones.map((z) => {
  const atual = String(z.slug) === ativa;
  return html`            <a class="row sz-zone${atual ? ' is-active' : ''}${z.blocked ? ' is-locked' : ''}"
               href="${url('safe-zone/' + String(z.slug))}"
               ${atual ? html` aria-current="true"` : ''}>
              ${icon(z.gov ? 'shield_person' : 'local_fire_department', 'row__icon sz-zone__ic')}
              <span class="row__text">
                <strong>${String(z.name)}</strong>
                <small>${String(z.region)}</small>
              </span>
              ${badge({ kind: 'base', value: String(z.status), label: String(z.status), class: 'sz-zone__st' })}${z.blocked ? html`              <span class="row__lock" title="${String(z.block_reason)}">${icon('lock', 'fill')}</span>
` : ''}            </a>
`;
}))}          </div>
`;

  const restante = Number(cur?.remaining ?? 0) | 0;
  const turno =
    cur === null
      ? html`          <p class="sz-shift__none">${icon('work_off', 'sz-shift__none-ic')}<span>NENHUM PAPEL ASSUMIDO</span></p>
          <small class="sz-shift__hint">Escolha uma zona e um papel ao lado para abrir um turno.</small>
`
      : html`          <div class="sz-shift__now">
            <span class="sz-shift__ic sz-shift__ic--${String(cur.color)}">${icon(String(cur.ms), 'fill')}</span>
            <span class="sz-shift__txt">
              <strong>${String(cur.label)}</strong>
              <small>${String(zone?.name ?? cur.zone)}</small>
            </span>
          </div>
          <ul class="sz-shift__rows">
            <li><span>${L('ends_in', 'ENCERRA EM')}</span>
              <b class="num" data-shift-countdown="${String(restante)}">${dois(Math.floor(restante / 60))}:${dois(restante % 60)}</b></li>
            <li><span>${L('served', 'ATENDIMENTOS')}</span><b class="num">${String(Number(cur.served) | 0)}</b></li>
          </ul>
          <form class="sz-shift__form" method="post" action="${url('safe-zone/encerrar')}">
            ${csrfField()}
            ${btn({ label: String(cta.leave ?? 'ENCERRAR TURNO'), type: 'submit', variant: 'outline', block: true })}          </form>
`;

  const papel = (r: Dict): SafeHtml => {
    let rodape: SafeHtml;
    if (r.active) {
      rodape = html`              <span class="btn btn--block is-disabled sz-role__cta" aria-disabled="true">${icon('check_circle', 'fill')}<span>${String(cta.active ?? 'EM TURNO')}</span></span>
`;
    } else if (r.can) {
      rodape = html`              <form method="post" action="${url('safe-zone/assumir')}" class="sz-role__form">
                ${csrfField()}
                <input type="hidden" name="zone" value="${String(zone?.slug ?? '')}">
                <input type="hidden" name="role" value="${String(r.key)}">
                ${btn({ label: String(cur !== null ? (cta.switch ?? 'TROCAR PARA ESTE PAPEL') : (cta.assume ?? 'ASSUMIR PAPEL')), type: 'submit', variant: 'primary', block: true, class: 'sz-role__cta' })}              </form>
`;
    } else {
      rodape = html`              <span class="btn btn--block is-disabled sz-role__cta sz-role__cta--off" aria-disabled="true" data-tip="${String(r.reason ?? '')}">
                ${icon('lock')}<span>${String(r.reason ?? '')}</span>
              </span>
`;
    }
    return html`          <article class="sz-role sz-role--${String(r.color)}${r.active ? ' is-active' : ''}${!r.can && !r.active ? ' is-off' : ''}" data-role="${String(r.key)}" data-boot>
            <header class="sz-role__head">
              <span class="sz-role__ic">${icon(String(r.ms), 'fill')}</span>
              <span class="sz-role__id">
                <strong class="sz-role__name">${String(r.label)}</strong>
                <small class="sz-role__sum">${String(r.summary)}</small>
              </span>
${r.active ? html`              <span class="chip chip--lime badge sz-role__tag">${String(cta.active ?? 'EM TURNO')}</span>
` : ''}            </header>

            <p class="sz-role__desc">${String(r.desc)}</p>

            <ul class="sz-role__rows">
              <li><span>${L('gain', 'O QUE RENDE')}</span><b>${String(r.gain)}</b></li>
              <li><span>${L('requires', 'REQUISITO')}</span><b class="sz-role__req">${String(r.req)}</b></li>
            </ul>

            <footer class="sz-role__foot">
${rodape}            </footer>
          </article>
`;
  };

  let detalhe: SafeHtml;
  if (zone === null) {
    detalhe = html`          <p class="sz-zp__none">
            <strong>${String(emptyD.title ?? '')}</strong>
            <small>${String(emptyD.desc ?? '')}</small>
          </p>
`;
  } else {
    const services = (zone.services ?? []) as unknown[];
    const rules = (zone.rules ?? []) as unknown[];
    detalhe = html`          <h3 class="sz-zp__name">${String(zone.name)}</h3>
          <p class="sz-zp__desc">${String(zone.description)}</p>

          <ul class="sz-zp__rows">
            <li><span>${L('region', 'REGIÃO')}</span><b>${String(zone.region)}</b></li>
            <li><span>${L('occupancy', 'MOVIMENTO')}</span>
              <b>${badge({ kind: 'activity', value: String(zone.occupancy) })}</b></li>
            <li><span>${L('capacity', 'CAPACIDADE CIVIL')}</span><b class="num">${String(Number(zone.capacity) | 0)}</b></li>
            <li><span>${L('access', 'SEU ACESSO')}</span>
              <b class="sz-zp__access sz-zp__access--${String(zone.access).toLowerCase()}">${String(zone.access)}</b></li>
          </ul>

          <p class="sz-zp__prot">${icon(zone.gov ? 'shield' : 'local_fire_department', 'sz-zp__prot-ic')}${String(zone.protection)}</p>

${zone.blocked ? html`          <p class="sz-zp__block">${icon('lock', 'sz-zp__block-ic')}${String(zone.block_reason)}</p>
` : ''}
${services.length ? html`          <h4 class="sz-zp__sec">${String(d.services_title ?? 'ESTRUTURAS')}</h4>
          <ul class="sz-zp__svc">
${join(services.map((sv) => html`            <li>${String(sv)}</li>
`))}          </ul>
` : ''}
${rules.length ? html`          <h4 class="sz-zp__sec">${String(d.rules_title ?? 'REGRAS DA ZONA')}</h4>
          <ul class="sz-zp__rules">
${join(rules.map((ru) => html`            <li>${icon('gavel', 'sz-zp__rule-ic')}<span>${String(ru)}</span></li>
`))}          </ul>
` : ''}`;
  }

  return html`<div class="sz">

  <header class="sz__head" data-boot>
    <div class="sz__title">
      <h1 class="page-title">${String(d.title ?? 'SAFE ZONE')}<small>${String(d.title_tag ?? '')}</small></h1>
      <p class="page-subtitle sz__intro">${String(d.subtitle ?? '')}</p>
    </div>
    <div class="sz__me">
      ${badge({ kind: 'state', value: op, level: Number(s.player.level ?? 0) | 0, lg: true })}      <p class="sz__rule">${icon('schedule', 'sz__rule-ic')}${String(d.rule ?? '')}</p>
    </div>
  </header>

${aviso}
  <div class="sz__grid">

    <!-- ====================== ZONAS ====================== -->
    <aside class="sz__left">
      <section class="panel sz-zones" data-boot>
        <header class="panel__head">
          <h2 class="panel__title">${String(d.zones_title ?? 'ZONAS CONHECIDAS')}</h2>
          <span class="panel__meta num">${String(zones.length)}</span>
        </header>
        <div class="panel__body panel__body--tight">
${listaZonas}        </div>
      </section>

      <!-- turno atual -->
      <section class="panel sz-shift${cur !== null ? ' is-on' : ''}" data-boot>
        <header class="panel__head">
          <h2 class="panel__title">${String(d.shift_title ?? 'SEU TURNO')}</h2>
        </header>
        <div class="panel__body">
${turno}        </div>
      </section>
    </aside>

    <!-- ====================== PAPÉIS ====================== -->
    <section class="panel sz-roles">
      <header class="panel__head">
        <h2 class="panel__title">${String(d.roles_title ?? 'PAPÉIS DISPONÍVEIS')}</h2>
        <span class="panel__meta">${String(zone?.name ?? '—')}</span>
      </header>
      <div class="panel__body panel__body--list">
        <div class="sz-roles__grid scroll-y" data-stagger>
${join(roles.map(papel))}        </div>
      </div>
    </section>

    <!-- ====================== ZONA SELECIONADA ====================== -->
    <aside class="sz__right">
      <section class="panel sz-zp" data-boot>
        <header class="panel__head">
          <h2 class="panel__title">${String(d.zone_title ?? 'ZONA SELECIONADA')}</h2>
${zone !== null ? html`          ${badge({ kind: 'base', value: String(zone.status), label: String(zone.status) })}` : ''}        </header>
        <div class="panel__body sz-zp__body">
${detalhe}        </div>
      </section>

      <div class="sz__cta">
        ${btn({ label: String(cta.operate ?? 'VOLTAR À OPERAÇÃO'), href: url('jogar'), variant: 'primary', block: true, chev: true })}        ${btn({ label: String(back.label ?? 'IR PARA O MAPA'), href: url(String(back.href ?? 'mapa')), variant: 'outline', block: true })}      </div>
    </aside>

  </div>
</div>
`;
}
