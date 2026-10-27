/**
 * Tela FACÇÕES (/faccoes) — app/Views/pages/faccoes.php (PRD v3 §27). Dois cards (AEGIS,
 * FRONTIER); assinar/romper abre o <dialog> de confirmação do faccoes.js, e o formulário só é
 * enviado com confirm=1 — sem isso o servidor recusa (422).
 */
import * as GR from '../../core/game-rules';
import { asset, csrfField, icon, num, url } from '../../core/helpers';
import { html, join, SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { badge } from '../components';
import { btn } from '../components/btn';

/** date('H:i:s', strtotime($x)) — a hora do próprio DATETIME gravado. */
function hora(s: string): string {
  const m = /(\d{2}:\d{2}:\d{2})/.exec(s);
  return m ? m[1]! : '00:00:00';
}

export function faccoes(s: Scope): SafeHtml {
  const d = s.data;
  const labels: Dict = d.labels ?? {};
  const factions = (d.factions ?? []) as Dict[];
  const op: Dict = d.operational ?? {};
  const surv = (op.state ?? '') === GR.STATE_SURVIVOR;
  const back: Dict = d.back ?? {};
  const L = (k: string, def: string): string => String(labels[k] ?? def);

  const card = (f: Dict): SafeHtml => {
    const slug = String(f.slug);
    const color = String(f.color);
    const cta = String(f.cta_key ?? 'sign');
    const conf: Dict = f.confirm ?? {};
    const locked = f.relation_key === 'locked';
    const inst = (f.installations ?? []) as string[];

    let acao: SafeHtml;
    if (f.can_sign) {
      acao = html`        <form class="fac-card__form" method="post" action="${url('faccoes/assinar')}" data-needs-confirm
              data-confirm-title="${String(conf.title ?? '')}" data-confirm-body="${String(conf.body ?? '')}"
              data-confirm-ok="${String(conf.ok ?? '')}" data-confirm-cancel="${String(conf.cancel ?? 'CANCELAR')}">
          ${csrfField()}
          <input type="hidden" name="faction" value="${slug}">
          <input type="hidden" name="confirm" value="0" data-confirm-field>
          ${btn({ label: String(f.cta), type: 'submit', variant: cta === 'switch' ? 'danger' : 'primary', block: true, class: 'fac-card__cta' })}        </form>
`;
    } else if (f.active) {
      acao = html`        <form class="fac-card__form" method="post" action="${url('faccoes/romper')}" data-needs-confirm
              data-confirm-title="ENCERRAR CONTRATO?" data-confirm-body="Você volta a operar sob o Hunter Command. A reputação com a ${String(f.label)} é mantida. Para assinar com outra facção é preciso encerrar este contrato antes."
              data-confirm-ok="ENCERRAR CONTRATO" data-confirm-cancel="CANCELAR">
          ${csrfField()}
          <input type="hidden" name="confirm" value="0" data-confirm-field>
          ${btn({ label: String((d.cta ?? {}).release ?? 'ENCERRAR CONTRATO'), type: 'submit', variant: 'outline', block: true, class: 'fac-card__cta' })}        </form>
`;
    } else {
      acao = html`        ${btn({ label: String(f.cta), type: 'button', variant: 'ghost', block: true, class: 'fac-card__cta', attrs: { disabled: 'disabled', 'aria-disabled': 'true' } })}`;
    }

    return html`    <section class="panel fac-card fac-card--${color}${f.active ? ' is-active' : ''}${locked ? ' is-locked' : ''}" data-boot aria-labelledby="fac-${slug}">
      <header class="fac-card__head">
        <div class="fac-card__title">
          <h2 id="fac-${slug}" class="fac-card__name">${String(f.label)}</h2>
          <p class="fac-card__tag">${String(f.tagline)}</p>
        </div>
        <span class="fac-card__emblem" aria-hidden="true">
          <picture>
            <source srcset="${asset('img/emblemas/' + slug + '.webp')}" type="image/webp">
            <img src="${asset('img/emblemas/' + slug + '.png')}" alt="" loading="lazy" decoding="async">
          </picture>
        </span>
      </header>

      <ul class="fac-card__rows">
        <li class="frow">
          <span class="frow__k">${L('reputation', 'REPUTAÇÃO')}</span>
          <span class="frow__v num frow__v--${color}">${num(Number(f.reputation) | 0)}</span>
        </li>
        <li class="frow">
          <span class="frow__k">${L('tier', 'FAIXA')}</span>
          <span class="frow__v">${String(f.tier)}<small>${String(f.next_tier)}</small></span>
        </li>
        <li class="frow">
          <span class="frow__k">${L('relation', 'RELAÇÃO')}</span>
          <span class="frow__v frow__v--rel-${String(f.relation_key)}">${String(f.relation)}
${f.locked_until ? html`            <small class="num" data-countdown-to="${String(f.locked_until)}">${L('locked_until', 'BLOQUEADO')} ${hora(String(f.locked_until))}</small>
` : ''}          </span>
        </li>
        <li class="frow">
          <span class="frow__k">${L('contracts', 'CONTRATOS DISPONÍVEIS')}</span>
          <span class="frow__v num">${num(Number(f.contracts) | 0)}</span>
        </li>
        <li class="frow frow--wide">
          <span class="frow__k">${L('installations', 'INSTALAÇÕES CONHECIDAS')}</span>
          <span class="frow__v frow__v--list">
${inst.length === 0 ? html`            <em>NENHUMA MAPEADA</em>
` : join(inst.map((i) => html`            <span>${String(i)}</span>
`))}          </span>
        </li>
      </ul>

      <h3 class="fac-card__sec">${L('benefits', 'BENEFÍCIOS')}</h3>
      <ul class="fac-card__ben">
        <li><b>${L('identity', 'IDENTIDADE')}</b><span>${String(f.identity)}</span></li>
        <li><b>${L('economy', 'ECONOMIA')}</b><span>${String(f.economy)}</span></li>
        <li><b>${L('missions', 'MISSÕES')}</b><span>${String(f.missions)}</span></li>
        <li><b>${L('npc', 'APOIO EM CAMPO')}</b><span>${String(f.npc)}</span></li>
      </ul>

      <footer class="fac-card__foot">
        <span class="fac-card__status">${L('status', 'STATUS DO CONTRATO')}
          ${badge({
            kind: locked ? 'discipline' : 'base',
            value: f.active ? 'OPERACIONAL' : locked ? 'PUNIDO' : 'ISOLADA',
            label: f.active ? 'ATIVO' : locked ? 'BLOQUEADO' : 'SEM CONTRATO',
          })}        </span>
${acao}      </footer>
    </section>
`;
  };

  return html`<div class="fac">

  <header class="fac__head" data-boot>
    <div>
      <h1 class="page-title">${String(d.title ?? 'FACÇÕES')}<span class="page-title__tag">${String(d.subtitle ?? '')}</span></h1>
      <p class="page-subtitle fac__intro">${String(d.intro ?? '')}</p>
    </div>
    <div class="fac__me">
      ${badge({ kind: 'state', value: op, level: Number(s.player.level ?? 0) | 0, lg: true })}      <p class="fac__rule">${icon('gavel', 'fac__rule-ic')}${String(d.rule ?? '')}</p>
    </div>
  </header>

${surv ? html`  <p class="fac__notice" role="status" data-boot>
    ${badge({ kind: 'state', value: op })}    <span>${String(d.survivor_notice ?? '')}</span>
  </p>
` : ''}
  <div class="fac__grid">
${join(factions.map(card))}  </div>

  <p class="fac__back" data-boot>
    ${btn({ label: String(back.label ?? 'VER CONTRATOS'), href: url(String(back.href ?? 'missoes')), variant: 'outline', chev: true })}  </p>

  <!-- confirmação obrigatória (PRD §7.2) — texto e botão vêm do formulário que a abriu -->
  <dialog class="fac-modal" data-fac-modal>
    <form method="dialog" class="fac-modal__box">
      <h2 class="fac-modal__title" data-modal-title>ROMPER CONTRATO?</h2>
      <p class="fac-modal__body" data-modal-body></p>
      <div class="fac-modal__actions">
        <button class="btn btn--outline" type="button" value="cancel" data-modal-cancel>CANCELAR</button>
        <button class="btn btn--danger" type="button" value="ok" data-modal-ok>ROMPER E ASSINAR</button>
      </div>
    </form>
  </dialog>
</div>
`;
}
