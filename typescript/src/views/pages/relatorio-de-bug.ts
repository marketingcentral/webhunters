/**
 * Tela RELATÓRIO DE BUG (app/Views/pages/relatorio-de-bug.php). Layout hud; âncora: 04-settings.
 *
 * data: title, tag, intro, panel, required, areas[], severities[], form{}, tips[],
 *       system[], recent[], errors{}, values{}, attach, detail (null no formulário).
 */
import { csrfField, icon, num, url } from '../../core/helpers';
import { html, join, raw, type SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';

const obj = (v: unknown): Dict => (v && typeof v === 'object' ? (v as Dict) : {});
const lista = (v: unknown): Dict[] => (Array.isArray(v) ? (v as Dict[]) : v && typeof v === 'object' ? (Object.values(v) as Dict[]) : []);

function detalhe(detail: Dict, dl: Dict): SafeHtml {
  const fechado = detail.closed !== null && detail.closed !== undefined;
  return html`    <!-- ---------------------------- DETALHE DE UM RELATÓRIO ---------------------------- -->
    <section class="panel bug-detail">
      <header class="panel__head">
        <h2 class="panel__title">${icon('bug_report', 'bug-form__ic')}${String(dl.panel ?? 'DETALHE DO RELATÓRIO')}</h2>
        <span class="chip chip--${String(detail.status_cls)} chip--dot">${String(detail.status)}</span>
      </header>
      <div class="panel__body bug-detail__body" data-stagger>

        <h3 class="bug-detail__title">${String(detail.title)}</h3>

        <dl class="bug-detail__meta">
          <div class="dt-row">
            <dt>${String(dl.area ?? 'CATEGORIA')}</dt>
            <dd>${icon(String(detail.area_icon), 'dt-row__ic')}${String(detail.area_label)}</dd>
          </div>
          <div class="dt-row">
            <dt>${String(dl.severity ?? 'GRAVIDADE')}</dt>
            <dd><span class="chip chip--${String(detail.severity_cls)} chip--dot">${String(detail.severity)}</span></dd>
          </div>
          <div class="dt-row">
            <dt>${String(dl.opened ?? 'ABERTO EM')}</dt>
            <dd class="num">${String(detail.opened)}</dd>
          </div>
          <div class="dt-row">
            <dt>${String(dl.closed ?? 'ENCERRADO EM')}</dt>
            <dd class="${fechado ? 'num' : 'dt-row__open'}">${String(fechado ? detail.closed : (dl.open_yet ?? 'EM ABERTO'))}</dd>
          </div>
          <div class="dt-row">
            <dt>${String(dl.logs ?? 'LOGS DA SESSÃO')}</dt>
            <dd>${icon(detail.attach_raw ? 'description' : 'block', 'dt-row__ic')}${String(detail.attach_raw ? (dl.logs_on ?? 'ANEXADOS') : (dl.logs_off ?? 'NÃO ANEXADOS'))}</dd>
          </div>
        </dl>

        <div class="bug-detail__block">
          <span class="field__label">${String(dl.description ?? 'DESCRIÇÃO')}</span>
          <p class="bug-detail__text${detail.description === '' ? ' is-empty' : ''}">${String(detail.description !== '' ? detail.description : (dl.empty ?? '—'))}</p>
        </div>

        <div class="bug-detail__block bug-detail__block--steps">
          <span class="field__label">${String(dl.steps ?? 'PASSOS PARA REPRODUZIR')}</span>
          <pre class="bug-detail__steps${detail.steps === '' ? ' is-empty' : ''}">${String(detail.steps !== '' ? detail.steps : (dl.empty ?? '—'))}</pre>
        </div>

      </div>
      <footer class="bug-form__foot bug-detail__foot">
        <span class="bug-detail__code num">${icon('tag', 'bug-attach__fic')}${String(detail.code)}</span>
        <div class="bug-actions">
          <a class="btn btn--primary" href="${url('relatorio-de-bug')}">${icon('bug_report', 'fill')}${String(dl.back ?? 'NOVO RELATÓRIO')}</a>
        </div>
      </footer>
    </section>
`;
}

function formulario(d: Dict): SafeHtml {
  const f = obj(d.form);
  const errors = obj(d.errors);
  const v = obj(d.values);
  const areas = lista(d.areas);
  const sevs = lista(d.severities);

  /* O stepper nasce num item vazio ("SELECIONE A ÁREA"): CATEGORIA é obrigatória, então o
     hidden #bug-area começa em branco e o jogador precisa escolher de fato. */
  const opts: Dict[] = [{ slug: '', label: 'SELECIONE A ÁREA', icon: 'category' }, ...areas];
  let areaIdx = 0;
  const vArea = String(v.area ?? '');
  for (let i = 0; i < opts.length; i++) {
    if (vArea !== '' && vArea === String(opts[i]!.slug ?? '')) {
      areaIdx = i;
      break;
    }
  }
  const area = opts[areaIdx]!;
  const sevSel = String(v.severity ?? '');
  const descMax = Number(f.description_max ?? 600) | 0;
  const stepsMax = Number(f.steps_max ?? 3000) | 0;
  const titleMax = String(f.title_max ?? 120);
  const attach = d.attach ?? true;

  const err = (k: string): SafeHtml =>
    k in errors ? html`<small class="field__error">${icon('warning')}${String(errors[k])}</small>` : raw('');

  return html`    <form class="panel bug-form" method="post" action="${url('relatorio-de-bug')}" novalidate autocomplete="off">
      ${csrfField()}

      <header class="panel__head">
        <h2 class="panel__title">${icon('bug_report', 'bug-form__ic')}${String(d.panel ?? 'NOVO RELATÓRIO')}</h2>
        <span class="panel__meta bug-form__req"><span class="bug-req">*</span> ${String(d.required ?? 'CAMPOS OBRIGATÓRIOS')}</span>
      </header>

      <div class="panel__body bug-form__body" data-stagger>

        <!-- linha 1: título + categoria ------------------------------------ -->
        <div class="bug-row" data-boot>
          <div class="field bug-field${'title' in errors ? ' is-invalid' : ''}">
            <label class="field__head" for="bug-title">
              <span class="field__label">${String(f.title_label ?? 'TÍTULO')} <span class="bug-req">*</span></span>
              <span class="field__count num" data-count-for="bug-title" data-max="${titleMax}">0/${titleMax}</span>
            </label>
            <input class="input bug-input" type="text" id="bug-title" name="title" maxlength="${titleMax}"
                   value="${String(v.title ?? '')}" placeholder="${String(f.title_ph ?? '')}">
            ${err('title')}
          </div>

          <div class="field bug-field bug-field--cat${'area' in errors ? ' is-invalid' : ''}">
            <span class="field__head">
              <span class="field__label">${String(f.area_label ?? 'CATEGORIA')} <span class="bug-req">*</span></span>
              <span class="field__count num" id="bug-cat-idx" data-total="${num(areas.length)}">${areaIdx === 0 ? raw('—') : num(areaIdx)}/${num(areas.length)}</span>
            </span>
            <div class="stepper stepper--boxed bug-stepper${areaIdx === 0 ? ' is-empty' : ''}" id="bug-cat"
                 data-options="${opts.map((a) => String(a.label)).join('|')}"
                 data-slugs="${opts.map((a) => String(a.slug)).join('|')}"
                 data-icons="${opts.map((a) => String(a.icon ?? 'help')).join('|')}"
                 data-value="${String(area.label)}">
              <button class="stepper__btn ms" type="button" aria-label="Categoria anterior">chevron_left</button>
              <span class="bug-stepper__face">
                ${icon(String(area.icon ?? 'help'), 'bug-stepper__ic')}
                <span class="stepper__val">${String(area.label)}</span>
              </span>
              <button class="stepper__btn ms" type="button" aria-label="Próxima categoria">chevron_right</button>
            </div>
            <input type="hidden" name="area" id="bug-area" value="${String(area.slug)}">
            ${err('area')}
          </div>
        </div>

        <!-- linha 2: gravidade --------------------------------------------- -->
        <div class="field bug-field bug-field--sev${'severity' in errors ? ' is-invalid' : ''}" data-boot>
          <span class="field__head">
            <span class="field__label">${String(f.severity_label ?? 'GRAVIDADE')} <span class="bug-req">*</span></span>
          </span>
          <div class="sev" role="group" aria-label="${String(f.severity_label ?? 'Gravidade')}" data-select-group data-select-input="#bug-sev">
${join(sevs.map((sv) => {
    const on = sevSel === sv.slug;
    return html`            <button class="sev__chip sev__chip--${String(sv.cls ?? 'muted')}${on ? ' is-active' : ''}"
                    type="button" data-select data-value="${String(sv.slug)}"
                    aria-pressed="${on ? 'true' : 'false'}">
              <span class="sev__ic">${icon(String(sv.icon ?? 'warning'))}</span>
              <span class="sev__text">
                <strong class="sev__label">${String(sv.label)}</strong>
                <small class="sev__desc">${String(sv.desc)}</small>
              </span>
              <span class="sev__check ms" aria-hidden="true">check</span>
            </button>
`;
  }))}            <input type="hidden" name="severity" id="bug-sev" value="${sevSel}">
          </div>
          ${err('severity')}
        </div>

        <!-- linha 3: descrição --------------------------------------------- -->
        <div class="field bug-field bug-field--desc" data-boot>
          <label class="field__head" for="bug-desc">
            <span class="field__label">${String(f.description_label ?? 'DESCRIÇÃO')}</span>
            <span class="field__count num" data-count-for="bug-desc" data-max="${String(descMax)}">0/${String(descMax)}</span>
          </label>
          <textarea class="input bug-textarea" id="bug-desc" name="description" maxlength="${String(descMax)}"
                    placeholder="${String(f.description_ph ?? '')}">${String(v.description ?? '')}</textarea>
        </div>

        <!-- linha 4: passos para reproduzir --------------------------------- -->
        <div class="field bug-field bug-field--steps${'steps' in errors ? ' is-invalid' : ''}" data-boot>
          <label class="field__head" for="bug-steps">
            <span class="field__label">${String(f.steps_label ?? 'PASSOS PARA REPRODUZIR')} <span class="bug-req">*</span></span>
            <span class="field__count num" data-count-for="bug-steps" data-max="${String(stepsMax)}">0/${String(stepsMax)}</span>
          </label>
          <textarea class="input bug-textarea bug-textarea--mono" id="bug-steps" name="steps" maxlength="${String(stepsMax)}"
                    placeholder="${String(f.steps_ph ?? '')}">${String(v.steps ?? '')}</textarea>
          ${err('steps')}
        </div>

      </div>

      <footer class="bug-form__foot">
        <label class="toggle bug-attach" data-on="${String(f.attach_on ?? 'ANEXADO')}" data-off="${String(f.attach_off ?? 'NÃO ANEXAR')}">
          <input type="checkbox" name="attach_logs" value="1"${attach ? raw(' checked') : ''}>
          <span class="toggle__track"></span>
          <span class="toggle__label">${String(attach ? (f.attach_on ?? 'ANEXADO') : (f.attach_off ?? 'NÃO ANEXAR'))}</span>
        </label>
        <span class="bug-attach__text">
          <strong class="bug-attach__title">${String(f.attach_label ?? 'ANEXAR LOGS DA SESSÃO')}</strong>
          <small class="bug-attach__file num">${icon('description', 'bug-attach__fic')}${String(f.attach_file ?? '')}</small>
        </span>
        <div class="bug-actions">
          <a class="btn btn--ghost" href="${url('relatorio-de-bug')}">${icon('undo')}${String(f.reset ?? 'LIMPAR')}</a>
          <button class="btn btn--primary bug-submit" type="submit">
            ${icon('send', 'fill')}${String(f.submit ?? 'ENVIAR RELATÓRIO')}          </button>
        </div>
      </footer>
    </form>
`;
}

export function relatorioDeBug(s: Scope): SafeHtml {
  const d = s.data;
  const tips = lista(d.tips);
  const system = lista(d.system);
  const recent = lista(d.recent);
  const detail = d.detail && typeof d.detail === 'object' && !Array.isArray(d.detail) ? (d.detail as Dict) : null;
  const dl = obj(d.detail_labels);

  const relatorios = recent.length === 0
    ? html`        <p class="bug-recent__empty">${icon('inbox', 'bug-recent__eic')}${String(d.recent_empty ?? 'Nenhum relatório enviado ainda.')}</p>
`
    : html`        <ul class="rep-list scroll-y">
${join(recent.map((r) => {
    const atual = detail !== null && String(detail.code) === String(r.id);
    return html`          <li>
            <a class="rep${atual ? ' is-current' : ''}" href="${url('relatorio-de-bug/' + String(r.id).toLowerCase())}"${atual ? raw(' aria-current="page"') : ''}>
              <span class="rep__id num">${String(r.id)}</span>
              <span class="rep__title">${String(r.title)}</span>
              <span class="rep__date num">${String(r.date)}</span>
              <span class="chip chip--${String(r.cls ?? 'muted')} chip--dot rep__status">${String(r.status)}</span>
            </a>
          </li>
`;
  }))}        </ul>
`;

  return html`<div class="bug">

  <!-- ====================== COLUNA PRINCIPAL ====================== -->
  <div class="bug__main">

    <header class="bug-head" data-boot>
      <h1 class="page-title">${detail !== null ? String(detail.code) : String(d.title ?? 'RELATÓRIO DE BUG')}<small>${String(d.tag ?? '')}</small></h1>
      <p class="page-subtitle">${detail !== null ? String(dl.intro ?? '') : String(d.intro ?? '')}</p>
    </header>

${detail !== null ? detalhe(detail, dl) : formulario(d)}  </div>

  <!-- ====================== COLUNA DIREITA ====================== -->
  <aside class="bug__side" data-stagger>

    <section class="panel bug-tips" data-boot>
      <header class="panel__head">
        <h2 class="panel__title">${icon('lightbulb', 'bug-tips__ic')}${String(d.tips_title ?? 'DICAS')}</h2>
      </header>
      <div class="panel__body">
        <ul class="bug-tip-list">
${join(tips.map((t) => html`          <li class="bug-tip">
            <span class="bug-tip__ic">${icon(String(t.icon ?? 'check_circle'))}</span>
            <span class="bug-tip__body">
              <strong class="bug-tip__title">${String(t.title ?? '')}</strong>
              <small class="bug-tip__text">${String(t.text ?? '')}</small>
            </span>
          </li>
`))}        </ul>
      </div>
    </section>

    <section class="panel bug-sys" data-boot>
      <header class="panel__head">
        <h2 class="panel__title">${icon('memory', 'bug-sys__ic')}${String(d.system_title ?? 'DADOS DA SESSÃO')}</h2>
        <span class="panel__meta bug-sys__auto">${icon('lock')}AUTOMÁTICO</span>
      </header>
      <div class="panel__body panel__body--tight">
${join(system.map((row) => html`        <div class="sys-row">
          ${icon(String(row.icon ?? 'terminal'), 'sys-row__ic')}
          <span class="sys-row__label">${String(row.label)}</span>
          <span class="sys-row__val num">${String(row.value)}</span>
        </div>
`))}        <div class="sys-row sys-row--clock">
          ${icon('schedule', 'sys-row__ic')}
          <span class="sys-row__label">${String(d.clock_label ?? 'HORA DO REGISTRO')}</span>
          <span class="sys-row__val num" id="bug-clock" data-clock>--:--:--</span>
        </div>
      </div>
    </section>

    <section class="panel bug-recent" data-boot>
      <header class="panel__head">
        <h2 class="panel__title">${icon('history', 'bug-recent__ic')}${String(d.recent_title ?? 'SEUS RELATÓRIOS')}</h2>
${recent.length ? html`        <span class="panel__meta bug-recent__count num">${num(recent.length)} ${String(d.recent_count ?? 'NO HISTÓRICO')}</span>
` : ''}      </header>
      <div class="panel__body panel__body--tight">
${relatorios}      </div>
      <footer class="bug-recent__foot">
        <span class="bug-recent__label">${String(d.recent_foot ?? '')}</span>
        <span class="bug-recent__sla num">${String(d.recent_sla ?? '')}</span>
      </footer>
    </section>

  </aside>
</div>
`;
}
