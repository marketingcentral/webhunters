<?php declare(strict_types=1);
/**
 * Tela RELATÓRIO DE BUG (layout hud, sem referência — âncora visual: 04-settings).
 *
 * $data: title, tag, intro, panel, required, areas[], severities[], form{}, tips[],
 *        system[], recent[], errors{}, values{}.
 * Escopo da view: $page, $player, $nav, $server, $data, $flash, $csrf.
 */

$f      = (array)($data['form'] ?? []);
$errors = (array)($data['errors'] ?? []);
$v      = (array)($data['values'] ?? []);
$areas  = array_values((array)($data['areas'] ?? []));
$sevs   = array_values((array)($data['severities'] ?? []));
$tips   = (array)($data['tips'] ?? []);
$system = (array)($data['system'] ?? []);
$recent = (array)($data['recent'] ?? []);

$detail = is_array($data['detail'] ?? null) ? $data['detail'] : null;
$dl     = (array)($data['detail_labels'] ?? []);

/* O stepper nasce num item vazio ("SELECIONE A ÁREA"): CATEGORIA é obrigatória, então o
   hidden #bug-area começa em branco e o jogador precisa escolher de fato. */
$opts = array_merge([['slug' => '', 'label' => 'SELECIONE A ÁREA', 'icon' => 'category']], $areas);

$areaIdx = 0;
foreach ($opts as $i => $a) {
    if (($v['area'] ?? '') !== '' && ($v['area'] ?? '') === ($a['slug'] ?? '')) { $areaIdx = $i; break; }
}
$area = $opts[$areaIdx];

$sevSel   = (string)($v['severity'] ?? '');
$descMax  = (int)($f['description_max'] ?? 600);
$stepsMax = (int)($f['steps_max'] ?? 3000);
?>
<div class="bug">

  <!-- ====================== COLUNA PRINCIPAL ====================== -->
  <div class="bug__main">

    <header class="bug-head" data-boot>
      <h1 class="page-title"><?= e($detail !== null ? (string)$detail['code'] : (string)($data['title'] ?? 'RELATÓRIO DE BUG')) ?><small><?= e((string)($data['tag'] ?? '')) ?></small></h1>
      <p class="page-subtitle"><?= e($detail !== null ? (string)($dl['intro'] ?? '') : (string)($data['intro'] ?? '')) ?></p>
    </header>

<?php if ($detail !== null): ?>
    <!-- ---------------------------- DETALHE DE UM RELATÓRIO ---------------------------- -->
    <section class="panel bug-detail">
      <header class="panel__head">
        <h2 class="panel__title"><?= icon('bug_report', 'bug-form__ic') ?><?= e((string)($dl['panel'] ?? 'DETALHE DO RELATÓRIO')) ?></h2>
        <span class="chip chip--<?= e((string)$detail['status_cls']) ?> chip--dot"><?= e((string)$detail['status']) ?></span>
      </header>
      <div class="panel__body bug-detail__body" data-stagger>

        <h3 class="bug-detail__title"><?= e((string)$detail['title']) ?></h3>

        <dl class="bug-detail__meta">
          <div class="dt-row">
            <dt><?= e((string)($dl['area'] ?? 'CATEGORIA')) ?></dt>
            <dd><?= icon((string)$detail['area_icon'], 'dt-row__ic') ?><?= e((string)$detail['area_label']) ?></dd>
          </div>
          <div class="dt-row">
            <dt><?= e((string)($dl['severity'] ?? 'GRAVIDADE')) ?></dt>
            <dd><span class="chip chip--<?= e((string)$detail['severity_cls']) ?> chip--dot"><?= e((string)$detail['severity']) ?></span></dd>
          </div>
          <div class="dt-row">
            <dt><?= e((string)($dl['opened'] ?? 'ABERTO EM')) ?></dt>
            <dd class="num"><?= e((string)$detail['opened']) ?></dd>
          </div>
          <div class="dt-row">
            <dt><?= e((string)($dl['closed'] ?? 'ENCERRADO EM')) ?></dt>
            <dd class="<?= $detail['closed'] !== null ? 'num' : 'dt-row__open' ?>"><?= e((string)($detail['closed'] ?? ($dl['open_yet'] ?? 'EM ABERTO'))) ?></dd>
          </div>
          <div class="dt-row">
            <dt><?= e((string)($dl['logs'] ?? 'LOGS DA SESSÃO')) ?></dt>
            <dd><?= icon($detail['attach_raw'] ? 'description' : 'block', 'dt-row__ic') ?><?= e((string)($detail['attach_raw'] ? ($dl['logs_on'] ?? 'ANEXADOS') : ($dl['logs_off'] ?? 'NÃO ANEXADOS'))) ?></dd>
          </div>
        </dl>

        <div class="bug-detail__block">
          <span class="field__label"><?= e((string)($dl['description'] ?? 'DESCRIÇÃO')) ?></span>
          <p class="bug-detail__text<?= $detail['description'] === '' ? ' is-empty' : '' ?>"><?= e((string)($detail['description'] !== '' ? $detail['description'] : ($dl['empty'] ?? '—'))) ?></p>
        </div>

        <div class="bug-detail__block bug-detail__block--steps">
          <span class="field__label"><?= e((string)($dl['steps'] ?? 'PASSOS PARA REPRODUZIR')) ?></span>
          <pre class="bug-detail__steps<?= $detail['steps'] === '' ? ' is-empty' : '' ?>"><?= e((string)($detail['steps'] !== '' ? $detail['steps'] : ($dl['empty'] ?? '—'))) ?></pre>
        </div>

      </div>
      <footer class="bug-form__foot bug-detail__foot">
        <span class="bug-detail__code num"><?= icon('tag', 'bug-attach__fic') ?><?= e((string)$detail['code']) ?></span>
        <div class="bug-actions">
          <a class="btn btn--primary" href="<?= e(url('relatorio-de-bug')) ?>"><?= icon('bug_report', 'fill') ?><?= e((string)($dl['back'] ?? 'NOVO RELATÓRIO')) ?></a>
        </div>
      </footer>
    </section>
<?php else: ?>
    <form class="panel bug-form" method="post" action="<?= e(url('relatorio-de-bug')) ?>" novalidate autocomplete="off">
      <?= csrf_field() ?>

      <header class="panel__head">
        <h2 class="panel__title"><?= icon('bug_report', 'bug-form__ic') ?><?= e((string)($data['panel'] ?? 'NOVO RELATÓRIO')) ?></h2>
        <span class="panel__meta bug-form__req"><span class="bug-req">*</span> <?= e((string)($data['required'] ?? 'CAMPOS OBRIGATÓRIOS')) ?></span>
      </header>

      <div class="panel__body bug-form__body" data-stagger>

        <!-- linha 1: título + categoria ------------------------------------ -->
        <div class="bug-row" data-boot>
          <div class="field bug-field<?= isset($errors['title']) ? ' is-invalid' : '' ?>">
            <label class="field__head" for="bug-title">
              <span class="field__label"><?= e((string)($f['title_label'] ?? 'TÍTULO')) ?> <span class="bug-req">*</span></span>
              <span class="field__count num" data-count-for="bug-title" data-max="<?= e((string)($f['title_max'] ?? 120)) ?>">0/<?= e((string)($f['title_max'] ?? 120)) ?></span>
            </label>
            <input class="input bug-input" type="text" id="bug-title" name="title" maxlength="<?= e((string)($f['title_max'] ?? 120)) ?>"
                   value="<?= e((string)($v['title'] ?? '')) ?>" placeholder="<?= e((string)($f['title_ph'] ?? '')) ?>">
            <?php if (isset($errors['title'])): ?><small class="field__error"><?= icon('warning') ?><?= e((string)$errors['title']) ?></small><?php endif; ?>
          </div>

          <div class="field bug-field bug-field--cat<?= isset($errors['area']) ? ' is-invalid' : '' ?>">
            <span class="field__head">
              <span class="field__label"><?= e((string)($f['area_label'] ?? 'CATEGORIA')) ?> <span class="bug-req">*</span></span>
              <span class="field__count num" id="bug-cat-idx" data-total="<?= e(num(count($areas))) ?>"><?= $areaIdx === 0 ? '—' : e(num($areaIdx)) ?>/<?= e(num(count($areas))) ?></span>
            </span>
            <div class="stepper stepper--boxed bug-stepper<?= $areaIdx === 0 ? ' is-empty' : '' ?>" id="bug-cat"
                 data-options="<?= e(implode('|', array_map(static fn(array $a): string => (string)$a['label'], $opts))) ?>"
                 data-slugs="<?= e(implode('|', array_map(static fn(array $a): string => (string)$a['slug'], $opts))) ?>"
                 data-icons="<?= e(implode('|', array_map(static fn(array $a): string => (string)($a['icon'] ?? 'help'), $opts))) ?>"
                 data-value="<?= e((string)$area['label']) ?>">
              <button class="stepper__btn ms" type="button" aria-label="Categoria anterior">chevron_left</button>
              <span class="bug-stepper__face">
                <?= icon((string)($area['icon'] ?? 'help'), 'bug-stepper__ic') ?>
                <span class="stepper__val"><?= e((string)$area['label']) ?></span>
              </span>
              <button class="stepper__btn ms" type="button" aria-label="Próxima categoria">chevron_right</button>
            </div>
            <input type="hidden" name="area" id="bug-area" value="<?= e((string)$area['slug']) ?>">
            <?php if (isset($errors['area'])): ?><small class="field__error"><?= icon('warning') ?><?= e((string)$errors['area']) ?></small><?php endif; ?>
          </div>
        </div>

        <!-- linha 2: gravidade --------------------------------------------- -->
        <div class="field bug-field bug-field--sev<?= isset($errors['severity']) ? ' is-invalid' : '' ?>" data-boot>
          <span class="field__head">
            <span class="field__label"><?= e((string)($f['severity_label'] ?? 'GRAVIDADE')) ?> <span class="bug-req">*</span></span>
          </span>
          <div class="sev" role="group" aria-label="<?= e((string)($f['severity_label'] ?? 'Gravidade')) ?>" data-select-group data-select-input="#bug-sev">
            <?php foreach ($sevs as $s): ?>
            <button class="sev__chip sev__chip--<?= e((string)($s['cls'] ?? 'muted')) ?><?= $sevSel === $s['slug'] ? ' is-active' : '' ?>"
                    type="button" data-select data-value="<?= e((string)$s['slug']) ?>"
                    aria-pressed="<?= $sevSel === $s['slug'] ? 'true' : 'false' ?>">
              <span class="sev__ic"><?= icon((string)($s['icon'] ?? 'warning')) ?></span>
              <span class="sev__text">
                <strong class="sev__label"><?= e((string)$s['label']) ?></strong>
                <small class="sev__desc"><?= e((string)$s['desc']) ?></small>
              </span>
              <span class="sev__check ms" aria-hidden="true">check</span>
            </button>
            <?php endforeach; ?>
            <input type="hidden" name="severity" id="bug-sev" value="<?= e($sevSel) ?>">
          </div>
          <?php if (isset($errors['severity'])): ?><small class="field__error"><?= icon('warning') ?><?= e((string)$errors['severity']) ?></small><?php endif; ?>
        </div>

        <!-- linha 3: descrição --------------------------------------------- -->
        <div class="field bug-field bug-field--desc" data-boot>
          <label class="field__head" for="bug-desc">
            <span class="field__label"><?= e((string)($f['description_label'] ?? 'DESCRIÇÃO')) ?></span>
            <span class="field__count num" data-count-for="bug-desc" data-max="<?= e((string)$descMax) ?>">0/<?= e((string)$descMax) ?></span>
          </label>
          <textarea class="input bug-textarea" id="bug-desc" name="description" maxlength="<?= e((string)$descMax) ?>"
                    placeholder="<?= e((string)($f['description_ph'] ?? '')) ?>"><?= e((string)($v['description'] ?? '')) ?></textarea>
        </div>

        <!-- linha 4: passos para reproduzir --------------------------------- -->
        <div class="field bug-field bug-field--steps<?= isset($errors['steps']) ? ' is-invalid' : '' ?>" data-boot>
          <label class="field__head" for="bug-steps">
            <span class="field__label"><?= e((string)($f['steps_label'] ?? 'PASSOS PARA REPRODUZIR')) ?> <span class="bug-req">*</span></span>
            <span class="field__count num" data-count-for="bug-steps" data-max="<?= e((string)$stepsMax) ?>">0/<?= e((string)$stepsMax) ?></span>
          </label>
          <textarea class="input bug-textarea bug-textarea--mono" id="bug-steps" name="steps" maxlength="<?= e((string)$stepsMax) ?>"
                    placeholder="<?= e((string)($f['steps_ph'] ?? '')) ?>"><?= e((string)($v['steps'] ?? '')) ?></textarea>
          <?php if (isset($errors['steps'])): ?><small class="field__error"><?= icon('warning') ?><?= e((string)$errors['steps']) ?></small><?php endif; ?>
        </div>

      </div>

      <footer class="bug-form__foot">
        <label class="toggle bug-attach" data-on="<?= e((string)($f['attach_on'] ?? 'ANEXADO')) ?>" data-off="<?= e((string)($f['attach_off'] ?? 'NÃO ANEXAR')) ?>">
          <input type="checkbox" name="attach_logs" value="1"<?= ($data['attach'] ?? true) ? ' checked' : '' ?>>
          <span class="toggle__track"></span>
          <span class="toggle__label"><?= e((string)(($data['attach'] ?? true) ? ($f['attach_on'] ?? 'ANEXADO') : ($f['attach_off'] ?? 'NÃO ANEXAR'))) ?></span>
        </label>
        <span class="bug-attach__text">
          <strong class="bug-attach__title"><?= e((string)($f['attach_label'] ?? 'ANEXAR LOGS DA SESSÃO')) ?></strong>
          <small class="bug-attach__file num"><?= icon('description', 'bug-attach__fic') ?><?= e((string)($f['attach_file'] ?? '')) ?></small>
        </span>
        <div class="bug-actions">
          <a class="btn btn--ghost" href="<?= e(url('relatorio-de-bug')) ?>"><?= icon('undo') ?><?= e((string)($f['reset'] ?? 'LIMPAR')) ?></a>
          <button class="btn btn--primary bug-submit" type="submit">
            <?= icon('send', 'fill') ?><?= e((string)($f['submit'] ?? 'ENVIAR RELATÓRIO')) ?>
          </button>
        </div>
      </footer>
    </form>
<?php endif; ?>
  </div>

  <!-- ====================== COLUNA DIREITA ====================== -->
  <aside class="bug__side" data-stagger>

    <section class="panel bug-tips" data-boot>
      <header class="panel__head">
        <h2 class="panel__title"><?= icon('lightbulb', 'bug-tips__ic') ?><?= e((string)($data['tips_title'] ?? 'DICAS')) ?></h2>
      </header>
      <div class="panel__body">
        <ul class="bug-tip-list">
          <?php foreach ($tips as $t): ?>
          <li class="bug-tip">
            <span class="bug-tip__ic"><?= icon((string)($t['icon'] ?? 'check_circle')) ?></span>
            <span class="bug-tip__body">
              <strong class="bug-tip__title"><?= e((string)($t['title'] ?? '')) ?></strong>
              <small class="bug-tip__text"><?= e((string)($t['text'] ?? '')) ?></small>
            </span>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>

    <section class="panel bug-sys" data-boot>
      <header class="panel__head">
        <h2 class="panel__title"><?= icon('memory', 'bug-sys__ic') ?><?= e((string)($data['system_title'] ?? 'DADOS DA SESSÃO')) ?></h2>
        <span class="panel__meta bug-sys__auto"><?= icon('lock') ?>AUTOMÁTICO</span>
      </header>
      <div class="panel__body panel__body--tight">
        <?php foreach ($system as $row): ?>
        <div class="sys-row">
          <?= icon((string)($row['icon'] ?? 'terminal'), 'sys-row__ic') ?>
          <span class="sys-row__label"><?= e((string)$row['label']) ?></span>
          <span class="sys-row__val num"><?= e((string)$row['value']) ?></span>
        </div>
        <?php endforeach; ?>
        <div class="sys-row sys-row--clock">
          <?= icon('schedule', 'sys-row__ic') ?>
          <span class="sys-row__label"><?= e((string)($data['clock_label'] ?? 'HORA DO REGISTRO')) ?></span>
          <span class="sys-row__val num" id="bug-clock" data-clock>--:--:--</span>
        </div>
      </div>
    </section>

    <section class="panel bug-recent" data-boot>
      <header class="panel__head">
        <h2 class="panel__title"><?= icon('history', 'bug-recent__ic') ?><?= e((string)($data['recent_title'] ?? 'SEUS RELATÓRIOS')) ?></h2>
        <?php if ($recent !== []): ?>
        <span class="panel__meta bug-recent__count num"><?= e(num(count($recent))) ?> <?= e((string)($data['recent_count'] ?? 'NO HISTÓRICO')) ?></span>
        <?php endif; ?>
      </header>
      <div class="panel__body panel__body--tight">
        <?php if ($recent === []): ?>
        <p class="bug-recent__empty"><?= icon('inbox', 'bug-recent__eic') ?><?= e((string)($data['recent_empty'] ?? 'Nenhum relatório enviado ainda.')) ?></p>
        <?php else: ?>
        <ul class="rep-list scroll-y">
          <?php foreach ($recent as $r): $isCur = $detail !== null && (string)$detail['code'] === (string)$r['id']; ?>
          <li>
            <a class="rep<?= $isCur ? ' is-current' : '' ?>" href="<?= e(url('relatorio-de-bug/' . mb_strtolower((string)$r['id']))) ?>"<?= $isCur ? ' aria-current="page"' : '' ?>>
              <span class="rep__id num"><?= e((string)$r['id']) ?></span>
              <span class="rep__title"><?= e((string)$r['title']) ?></span>
              <span class="rep__date num"><?= e((string)$r['date']) ?></span>
              <span class="chip chip--<?= e((string)($r['cls'] ?? 'muted')) ?> chip--dot rep__status"><?= e((string)$r['status']) ?></span>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
      <footer class="bug-recent__foot">
        <span class="bug-recent__label"><?= e((string)($data['recent_foot'] ?? '')) ?></span>
        <span class="bug-recent__sla num"><?= e((string)($data['recent_sla'] ?? '')) ?></span>
      </footer>
    </section>

  </aside>
</div>
