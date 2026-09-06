<?php declare(strict_types=1);
/**
 * Tela SUPORTE (sem referência; âncora de estilo: 04-settings).
 *
 * Colunas: [FAQ + canais] | [abertura de chamado] | [status dos servidores].
 * $data: title, tag, intro, categories, form, ticket_meta, success, faq, contacts,
 *        status, maintenance, tickets, errors, values.
 * Escopo da view: $page, $player, $nav, $server, $data, $flash, $csrf.
 */

$f       = (array)($data['form'] ?? []);
$errors  = (array)($data['errors'] ?? []);
$values  = (array)($data['values'] ?? []);
$cats    = (array)($data['categories'] ?? []);
$faq     = (array)($data['faq'] ?? []);
$status  = (array)($data['status'] ?? []);
$maint   = (array)($data['maintenance'] ?? []);
$success = (array)($data['success'] ?? []);
$sent    = !empty($flash['ok']);

$labels = array_map(static fn (array $c): string => (string)$c['label'], $cats);
$slugs  = array_map(static fn (array $c): string => (string)$c['slug'], $cats);
$slas   = array_map(static fn (array $c): string => (string)($c['sla'] ?? ''), $cats);

$idx = array_search((string)($values['category'] ?? ''), $slugs, true);
$idx = is_int($idx) ? $idx : 0;
$curLabel = $labels[$idx] ?? '';
$curSlug  = $slugs[$idx] ?? '';
$curSla   = $slas[$idx] ?? '';

$msgVal   = (string)($values['message'] ?? '');
$msgMax   = (int)($f['message_max'] ?? 2000);
$prios    = array_map('strval', (array)($f['priorities'] ?? ['NORMAL']));

// prioridade: mantém a escolha do jogador quando o POST volta com erro
$curPrio = (string)($values['priority'] ?? '');
if (!in_array($curPrio, $prios, true)) {
    $curPrio = (string)($f['priority'] ?? ($prios[0] ?? 'NORMAL'));
}
$prioIdx = array_search($curPrio, $prios, true);
$prioIdx = is_int($prioIdx) ? $prioIdx : 0;
$logsOn  = !array_key_exists('logs', $values) || !empty($values['logs']);
$tickets = (array)($data['tickets'] ?? []);
?>
<div class="sup">

  <!-- ====================== CABEÇALHOS DE COLUNA ====================== -->
  <header class="sup-head" data-boot>
    <h1 class="page-title"><?= e((string)($data['title'] ?? 'SUPORTE')) ?><small><?= e((string)($data['tag'] ?? '')) ?></small></h1>
    <p class="page-subtitle"><?= e((string)($data['intro'] ?? '')) ?></p>
  </header>

  <h2 class="sup-col-title" data-boot><?= e((string)($f['panel_title'] ?? 'ABRIR CHAMADO')) ?></h2>

  <p class="sup-live" data-boot>
    <span class="dot sup-live__dot" aria-hidden="true"></span>
    <span class="sup-live__label"><?= e((string)($status['updated'] ?? 'ATUALIZADO HÁ')) ?></span>
    <span class="num sup-live__val" data-elapsed>00:00</span>
  </p>

  <!-- ========================= COLUNA ESQUERDA ======================== -->
  <div class="sup__left">

    <section class="panel sup-faq">
      <header class="panel__head">
        <h2 class="panel__title"><?= e((string)($data['faq_title'] ?? 'PERGUNTAS FREQUENTES')) ?></h2>
        <span class="panel__meta num"><?= e(num(count($faq))) ?></span>
      </header>
      <div class="panel__body sup-faq__body">
        <div class="faq" data-accordion data-stagger>
          <?php foreach ($faq as $i => $item): ?>
          <article class="faq-item<?= $i === 0 ? ' is-open' : '' ?>">
            <button class="faq-item__q" type="button" aria-expanded="<?= $i === 0 ? 'true' : 'false' ?>" aria-controls="faq-<?= e((string)$i) ?>">
              <span class="faq-item__idx num"><?= e(str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT)) ?></span>
              <span class="faq-item__text"><?= e((string)$item['q']) ?></span>
              <span class="ms faq-item__chev" aria-hidden="true">expand_more</span>
            </button>
            <div class="faq-item__a" id="faq-<?= e((string)$i) ?>" role="region">
              <p class="faq-item__p"><?= e((string)$item['a']) ?></p>
            </div>
          </article>
          <?php endforeach; ?>
        </div>
      </div>
      <footer class="panel__foot sup-faq__foot">
        <span class="sup-faq__note"><?= e((string)($data['faq_foot'] ?? '')) ?></span>
        <a class="link sup-faq__link" href="<?= e(url('relatorio-de-bug')) ?>"><?= e((string)($f['bug_label'] ?? 'RELATÓRIO DE BUG')) ?><?= icon('chevron_right') ?></a>
      </footer>
    </section>

    <section class="panel sup-channels">
      <header class="panel__head">
        <h2 class="panel__title"><?= e((string)($data['contacts_title'] ?? 'CANAIS DE ATENDIMENTO')) ?></h2>
      </header>
      <div class="panel__body sup-channels__body" data-stagger>
        <?php foreach ((array)($data['contacts'] ?? []) as $c): ?>
        <?php $tag = !empty($c['href']) ? 'a' : 'div'; ?>
        <<?= $tag ?> class="chan"<?= !empty($c['href']) ? ' href="' . e(url((string)$c['href'])) . '"' : '' ?>>
          <span class="chan__ic"><?= icon((string)($c['icon'] ?? 'headset_mic')) ?></span>
          <span class="chan__text">
            <strong class="chan__label"><?= e((string)$c['label']) ?></strong>
            <small class="chan__value"><?= e((string)$c['value']) ?></small>
          </span>
          <span class="chan__meta chan__meta--<?= e((string)($c['tone'] ?? 'muted')) ?>"><?= e((string)($c['meta'] ?? '')) ?></span>
        </<?= $tag ?>>
        <?php endforeach; ?>
      </div>
    </section>

  </div>

  <!-- ========================== COLUNA CENTRAL ======================== -->
  <div class="sup__mid">
    <section class="panel sup-ticket">

      <?php if ($sent): ?>
      <div class="panel__body sup-done">
        <span class="sup-done__ring" aria-hidden="true"><?= icon('check_circle', 'fill') ?></span>
        <h3 class="sup-done__title"><?= e((string)($success['title'] ?? 'CHAMADO REGISTRADO')) ?></h3>
        <p class="sup-done__msg"><?= e((string)$flash['ok']) ?></p>
        <p class="sup-done__desc"><?= e((string)($success['desc'] ?? '')) ?></p>
        <ul class="sup-done__steps">
          <?php foreach ((array)($success['steps'] ?? []) as $st): ?>
          <li class="sup-done__step"><?= icon('check', 'sup-done__check') ?><span><?= e((string)$st) ?></span></li>
          <?php endforeach; ?>
        </ul>
        <div class="sup-done__actions">
          <a class="btn btn--outline" href="<?= e(url((string)($success['secondary']['href'] ?? 'suporte'))) ?>"><?= icon('add') ?><?= e((string)($success['secondary']['label'] ?? 'ABRIR OUTRO CHAMADO')) ?></a>
          <a class="btn btn--primary" href="<?= e(url((string)($success['primary']['href'] ?? 'mensagens'))) ?>"><?= icon('mail') ?><?= e((string)($success['primary']['label'] ?? 'IR PARA MENSAGENS')) ?><?= icon('chevron_right', 'btn__chev') ?></a>
        </div>
      </div>

      <?php else: ?>
      <form class="panel__body sup-form" method="post" action="<?= e(url('suporte')) ?>" novalidate>
        <?= csrf_field() ?>

        <div class="sup-meta">
          <span class="sup-meta__item">
            <span class="sup-meta__label">OPERADOR</span>
            <span class="sup-meta__val"><?= e((string)($player['name'] ?? 'HUNTER')) ?></span>
          </span>
          <?php foreach ((array)($data['ticket_meta'] ?? []) as $m): ?>
          <span class="sup-meta__item">
            <span class="sup-meta__label"><?= e((string)$m['label']) ?></span>
            <span class="sup-meta__val num"><?= e((string)$m['value']) ?></span>
          </span>
          <?php endforeach; ?>
        </div>

        <div class="sup-field">
          <span class="sup-field__label"><?= e((string)($f['subject_label'] ?? 'ASSUNTO')) ?></span>
          <div class="stepper sup-stepper" data-options="<?= e(implode('|', $labels)) ?>" data-value="<?= e($curLabel) ?>"
               data-slugs="<?= e(implode('|', $slugs)) ?>" data-slas="<?= e(implode('|', $slas)) ?>">
            <button class="stepper__btn ms" type="button" aria-label="Assunto anterior">chevron_left</button>
            <span class="stepper__val"><?= e($curLabel) ?></span>
            <button class="stepper__btn ms" type="button" aria-label="Próximo assunto">chevron_right</button>
            <input type="hidden" name="subject" value="<?= e($curLabel) ?>">
          </div>
          <span class="sup-field__aside">
            <span class="sup-field__aside-label"><?= e((string)($f['sla_label'] ?? 'RESPOSTA ESTIMADA')) ?></span>
            <span class="sup-field__aside-val num" data-sla><?= e($curSla) ?></span>
          </span>
          <input type="hidden" name="category" value="<?= e($curSlug) ?>" data-category>
          <?php if (!empty($errors['category']) || !empty($errors['subject'])): ?>
          <small class="field__error"><?= icon('warning') ?><?= e((string)($errors['category'] ?? $errors['subject'])) ?></small>
          <?php endif; ?>
        </div>

        <div class="sup-field">
          <span class="sup-field__label"><?= e((string)($f['priority_label'] ?? 'PRIORIDADE')) ?></span>
          <div class="stepper sup-stepper" data-options="<?= e(implode('|', $prios)) ?>" data-value="<?= e($curPrio) ?>">
            <button class="stepper__btn ms" type="button" aria-label="Prioridade anterior">chevron_left</button>
            <span class="stepper__val"><?= e($curPrio) ?></span>
            <button class="stepper__btn ms" type="button" aria-label="Próxima prioridade">chevron_right</button>
            <input type="hidden" name="priority" value="<?= e($curPrio) ?>">
          </div>
          <span class="sup-prio" data-prio-level="<?= e((string)($prioIdx + 1)) ?>" aria-hidden="true">
            <?php foreach ($prios as $k => $_p): ?><span class="sup-prio__seg"></span><?php endforeach; ?>
          </span>
        </div>

        <div class="sup-field sup-field--area<?= !empty($errors['message']) ? ' is-invalid' : '' ?>">
          <span class="sup-field__label"><?= e((string)($f['message_label'] ?? 'DESCRIÇÃO')) ?></span>
          <span class="sup-field__count num"><span data-count-out><?= e(num(mb_strlen($msgVal))) ?></span> / <?= e(num($msgMax)) ?></span>
          <textarea class="input sup-textarea" name="message" maxlength="<?= e((string)$msgMax) ?>" data-counter
                    placeholder="<?= e((string)($f['message_ph'] ?? '')) ?>" aria-label="<?= e((string)($f['message_label'] ?? 'Descrição')) ?>"><?= e($msgVal) ?></textarea>
          <?php if (!empty($errors['message'])): ?>
          <small class="field__error"><?= icon('warning') ?><?= e((string)$errors['message']) ?></small>
          <?php else: ?>
          <small class="field__hint"><?= e((string)($f['message_hint'] ?? '')) ?></small>
          <?php endif; ?>
        </div>

        <div class="sup-related">
          <span class="sup-related__label"><?= e((string)($data['related_title'] ?? 'ARTIGOS RELACIONADOS')) ?></span>
          <?php foreach ((array)($data['related'] ?? []) as $rel): ?>
          <a class="sup-related__chip" href="<?= e(url((string)$rel['href'])) ?>"><?= icon('article', 'sup-related__ic') ?><?= e((string)$rel['label']) ?></a>
          <?php endforeach; ?>
        </div>

        <label class="sup-attach">
          <input type="checkbox" name="logs" value="1"<?= $logsOn ? ' checked' : '' ?>>
          <span class="sup-attach__box" aria-hidden="true"><?= icon('check') ?></span>
          <span class="sup-attach__text"><?= e((string)($f['attach_label'] ?? '')) ?></span>
          <span class="sup-attach__file num">MATCH_LOG_4412.TXT</span>
        </label>

        <div class="sup-form__actions">
          <a class="btn btn--outline sup-form__bug" href="<?= e(url('relatorio-de-bug')) ?>"><?= icon('bug_report') ?><?= e((string)($f['bug_label'] ?? 'RELATÓRIO DE BUG')) ?></a>
          <button class="btn btn--primary sup-form__send" type="submit"><?= icon('send', 'fill') ?><span class="sup-form__send-label"><?= e((string)($f['submit'] ?? 'ENVIAR')) ?></span></button>
        </div>
      </form>
      <?php endif; ?>

    </section>
  </div>

  <!-- ========================== COLUNA DIREITA ======================== -->
  <aside class="sup__right">

    <section class="panel sup-status">
      <header class="panel__head">
        <h2 class="panel__title"><?= e((string)($status['label'] ?? 'STATUS DOS SERVIDORES')) ?></h2>
        <span class="chip chip--<?= !empty($status['ok']) ? 'lime' : 'amber' ?> chip--dot sup-status__badge"><?= e((string)($status['badge'] ?? 'OPERACIONAL')) ?></span>
      </header>
      <div class="panel__body sup-status__body">
        <ul class="srv" data-stagger>
          <?php foreach ((array)($status['regions'] ?? []) as $r): ?>
          <li class="srv__row">
            <span class="dot srv__dot" aria-hidden="true"></span>
            <span class="srv__text">
              <strong class="srv__name"><?= e((string)$r['label']) ?></strong>
              <small class="srv__sub"><span class="num"><?= e((string)($r['players'] ?? '')) ?></span> operadores</small>
            </span>
            <span class="srv__ping num" data-ping="<?= e((string)(int)$r['ping']) ?>"><span data-ping-out><?= e((string)(int)$r['ping']) ?></span><small>ms</small></span>
          </li>
          <?php endforeach; ?>
        </ul>

        <h3 class="sup-status__section"><?= e((string)($status['services_title'] ?? 'SERVIÇOS')) ?></h3>
        <ul class="svc">
          <?php foreach ((array)($status['services'] ?? []) as $s): ?>
          <?php $warn = ($s['state'] ?? 'ok') !== 'ok'; ?>
          <li class="svc__tile<?= $warn ? ' is-warn' : '' ?>">
            <span class="svc__head">
              <span class="dot svc__dot<?= $warn ? ' dot--amber' : '' ?>" aria-hidden="true"></span>
              <span class="svc__name"><?= e((string)$s['label']) ?></span>
            </span>
            <span class="svc__state"><?= e((string)($s['short'] ?? $s['value'])) ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>

    <?php if ($maint !== []): ?>
    <section class="panel sup-maint">
      <div class="panel__body sup-maint__body">
        <span class="sup-maint__ic"><?= icon('build') ?></span>
        <div class="sup-maint__text">
          <strong class="sup-maint__title"><?= e((string)($maint['title'] ?? 'MANUTENÇÃO PROGRAMADA')) ?></strong>
          <small class="sup-maint__desc"><?= e((string)($maint['service'] ?? '')) ?> — <?= e((string)($maint['desc'] ?? '')) ?></small>
        </div>
        <span class="sup-maint__timer">
          <span class="sup-maint__label"><?= e((string)($maint['label'] ?? 'INÍCIO EM')) ?></span>
          <span class="num sup-maint__val" data-countdown="<?= e((string)(int)($maint['starts_in'] ?? 0)) ?>">--:--:--</span>
        </span>
      </div>
    </section>
    <?php endif; ?>

    <section class="panel sup-tickets">
      <header class="panel__head">
        <h2 class="panel__title"><?= e((string)($data['tickets_title'] ?? 'MEUS CHAMADOS')) ?></h2>
        <a class="panel__action" href="<?= e(url('mensagens')) ?>">VER TODOS<?= icon('chevron_right') ?></a>
      </header>
      <div class="panel__body sup-tickets__body" data-stagger>
        <?php foreach ($tickets as $t): ?>
        <a class="tkt" href="<?= e(url('mensagens')) ?>">
          <span class="tkt__id num"><?= e((string)$t['id']) ?></span>
          <span class="tkt__subject"><?= e((string)$t['subject']) ?></span>
          <span class="tkt__date num"><?= e((string)$t['date']) ?></span>
          <span class="tkt__state tkt__state--<?= e((string)($t['tone'] ?? 'muted')) ?>"><?= e((string)$t['state']) ?></span>
        </a>
        <?php endforeach; ?>
        <?php if ($tickets === []): ?>
        <p class="sup-tickets__empty"><?= icon('inbox') ?><span>Você ainda não abriu nenhum chamado.</span></p>
        <?php endif; ?>
      </div>
    </section>

  </aside>
</div>
