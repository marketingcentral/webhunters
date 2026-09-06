<?php declare(strict_types=1);
/**
 * Tela MENSAGENS (sem referência; âncora de estilo 03-lobby).
 * Dados vindos do banco por jogador (App\Repo\MessageRepo, conciliado por user_id).
 *
 * $data: title, subtitle, unread, folders[], active_folder, search_ph, list_title, compose,
 *        compose_href, empty_text, items[] (com thread[]), open, thread_actions[], reply_ph,
 *        reply_send, system_panel{}, maintenance{}.
 * Escopo da view: $page, $player, $nav, $server, $data, $flash, $csrf.
 */

$items    = (array)($data['items'] ?? []);
$folders  = (array)($data['folders'] ?? []);
$sys      = (array)($data['system_panel'] ?? []);
$sysItems = (array)($sys['items'] ?? []);
$maint    = (array)($data['maintenance'] ?? []);
$sysEmpty = (array)($sys['empty'] ?? []);
$noThread = (array)($data['empty_thread'] ?? []);
$me       = (string)($player['name'] ?? 'HUNTER_07');
$activeId = (string)($data['open'] ?? '');
if ($activeId === '') {
    foreach ($items as $it) {
        if (!empty($it['active'])) { $activeId = (string)$it['id']; break; }
    }
}
if ($activeId === '' && $items !== []) { $activeId = (string)$items[0]['id']; }
$unread = (int)($data['unread'] ?? 0);

// contagem já correta no HTML (o JS só recalcula depois de filtrar/buscar)
$actFolder = (string)($data['active_folder'] ?? 'entrada');
$inFolder  = array_values(array_filter($items, static fn(array $i): bool =>
    $actFolder === 'sistema' ? !empty($i['system']) : (string)($i['folder'] ?? 'entrada') === $actFolder));
$folderNew = count(array_filter($inFolder, static fn(array $i): bool => !empty($i['unread'])));
?>
<div class="msgs">

  <header class="msgs-head" data-boot>
    <div class="msgs-head__id">
      <h1 class="page-title"><?= e((string)($data['title'] ?? 'MENSAGENS')) ?><small class="msgs-head__unread"><span class="num" data-count="<?= e((string)$unread) ?>" data-dur="700"><?= e(num($unread)) ?></span> <span data-unread-label><?= $unread === 1 ? 'NÃO LIDA' : 'NÃO LIDAS' ?></span></small></h1>
      <p class="page-subtitle"><?= e((string)($data['subtitle'] ?? '')) ?></p>
    </div>
    <a class="compose-btn" href="<?= e(url((string)($data['compose_href'] ?? 'amigos'))) ?>">
      <?= icon('edit_square', 'compose-btn__ic') ?><span><?= e((string)($data['compose'] ?? 'NOVA MENSAGEM')) ?></span>
    </a>
  </header>

  <div class="msgs-grid">

    <!-- ====================== LISTA DE CONVERSAS ====================== -->
    <section class="panel conv-panel">
      <header class="panel__head">
        <h2 class="panel__title"><?= e((string)($data['list_title'] ?? 'CONVERSAS')) ?></h2>
        <span class="panel__meta"><span class="num" data-conv-count><?= e(num(count($inFolder))) ?></span> NA PASTA · <span class="num lime" data-conv-new><?= e(num($folderNew)) ?></span> <span data-conv-new-label><?= $folderNew === 1 ? 'NOVA' : 'NOVAS' ?></span></span>
      </header>
      <div class="panel__body conv-panel__body">

        <div class="conv-search">
          <?= icon('search', 'conv-search__ic') ?>
          <input class="conv-search__input" type="search" placeholder="<?= e((string)($data['search_ph'] ?? 'Buscar...')) ?>" aria-label="<?= e((string)($data['search_ph'] ?? 'Buscar')) ?>" autocomplete="off" data-conv-search>
        </div>

        <nav class="tabs conv-tabs" data-conv-tabs aria-label="Pastas">
          <?php foreach ($folders as $f): ?>
          <?php $fOn = ($f['slug'] ?? '') === ($data['active_folder'] ?? 'entrada'); ?>
          <button class="tab conv-tab<?= $fOn ? ' is-active' : '' ?>" type="button" data-folder="<?= e((string)$f['slug']) ?>" aria-selected="<?= $fOn ? 'true' : 'false' ?>">
            <span class="conv-tab__label"><?= e((string)$f['label']) ?></span>
            <span class="conv-tab__count num"><?= e(num((int)$f['count'])) ?></span>
          </button>
          <?php endforeach; ?>
        </nav>

        <?php /* pasta sem nenhuma linha visível: a lista sai do fluxo para o vazio ficar centrado */ ?>
        <ul class="conv-list scroll-y" data-conv-list data-stagger<?= $inFolder === [] ? ' hidden' : '' ?>>
          <?php foreach ($items as $i => $it): $id = (string)$it['id']; ?>
          <li class="conv-item" data-boot>
            <button class="conv<?= $id === $activeId ? ' is-active' : '' ?><?= !empty($it['unread']) ? ' is-unread' : '' ?><?= !empty($it['system']) ? ' conv--system' : '' ?>"
                    type="button"
                    data-conv="<?= e($id) ?>"
                    data-folder="<?= e((string)($it['folder'] ?? 'entrada')) ?>"
                    data-system="<?= !empty($it['system']) ? '1' : '0' ?>"
                    data-search="<?= e(mb_strtolower((string)$it['from'] . ' ' . (string)$it['subject'] . ' ' . (string)($it['preview'] ?? ''))) ?>"
                    aria-pressed="<?= $id === $activeId ? 'true' : 'false' ?>">
              <span class="conv__avatar conv__avatar--<?= e((string)($it['color'] ?? 'lime')) ?>">
                <?php if (!empty($it['avatar'])): ?>
                <img class="conv__img" src="<?= e(media((string)$it['avatar'])) ?>" alt="" width="896" height="1200" loading="lazy" decoding="async">
                <?php else: ?>
                <?= icon((string)($it['icon'] ?? 'mail'), 'conv__ic') ?>
                <?php endif; ?>
                <?php if (!empty($it['level'])): ?>
                <span class="conv__lvl num"><?= e((string)$it['level']) ?></span>
                <?php endif; ?>
              </span>
              <span class="conv__body">
                <span class="conv__top">
                  <strong class="conv__from"><?= e((string)$it['from']) ?></strong>
                  <span class="conv__time num"><?= e((string)$it['time']) ?></span>
                </span>
                <span class="conv__subject"><?= e((string)$it['subject']) ?></span>
                <span class="conv__preview"><?= e((string)($it['preview'] ?? '')) ?></span>
              </span>
              <span class="conv__dot" aria-hidden="true"></span>
            </button>
          </li>
          <?php endforeach; ?>
        </ul>

        <p class="conv-empty" data-conv-empty
           data-empty-folder="<?= e((string)($data['empty_text'] ?? 'Nenhuma conversa.')) ?>"
           data-empty-search="<?= e((string)($data['empty_search'] ?? 'Nenhuma conversa corresponde à busca.')) ?>"<?= $inFolder === [] ? '' : ' hidden' ?>>
          <?= icon('mark_email_unread', 'conv-empty__ic') ?>
          <span data-empty-text><?= e((string)($data['empty_text'] ?? 'Nenhuma conversa.')) ?></span>
        </p>

      </div>
    </section>

    <!-- ====================== CONVERSA ABERTA ====================== -->
    <section class="panel thread-panel panel--flush">
      <?php if ($items === []): ?>
      <?php /* caixa vazia: nada de retângulo oco — estado vazio com saída para AMIGOS */ ?>
      <div class="thread-empty" data-boot>
        <span class="thread-empty__ic"><?= icon((string)($noThread['icon'] ?? 'forum')) ?></span>
        <strong class="thread-empty__title"><?= e((string)($noThread['title'] ?? 'NENHUMA CONVERSA AINDA')) ?></strong>
        <p class="thread-empty__text"><?= e((string)($noThread['text'] ?? '')) ?></p>
        <a class="btn btn--outline btn--sm thread-empty__cta" href="<?= e(url((string)($noThread['href'] ?? 'amigos'))) ?>"><?= e((string)($noThread['cta'] ?? 'PROCURAR OPERADORES')) ?><?= icon('chevron_right', 'btn__chev') ?></a>
      </div>
      <?php else: ?>
      <div class="thread-stack">
        <?php foreach ($items as $it): $id = (string)$it['id']; $on = $id === $activeId; ?>
        <article class="thread" data-thread="<?= e($id) ?>"<?= $on ? '' : ' hidden' ?>>

          <header class="thread__head">
            <span class="thread__avatar thread__avatar--<?= e((string)($it['color'] ?? 'lime')) ?>">
              <?php if (!empty($it['avatar'])): ?>
              <img class="thread__img" src="<?= e(media((string)$it['avatar'])) ?>" alt="" width="896" height="1200" loading="lazy" decoding="async">
              <?php else: ?>
              <?= icon((string)($it['icon'] ?? 'mail'), 'thread__ic') ?>
              <?php endif; ?>
            </span>
            <span class="thread__id">
              <strong class="thread__from"><?= e((string)$it['from']) ?>
                <?php if (!empty($it['tag'])): ?><?php component('chip', ['label' => (string)$it['tag'], 'color' => 'gold', 'class' => 'thread__tag']) ?><?php endif; ?>
              </strong>
              <span class="thread__meta">
                <?php if (!empty($it['level'])): ?><span class="thread__lvl num">NÍVEL <?= e((string)$it['level']) ?></span><span class="thread__sep" aria-hidden="true"></span><?php endif; ?>
                <?= icon((string)($it['class_ms'] ?? 'shield'), 'thread__class-ic') ?><span class="thread__class"><?= e((string)($it['class'] ?? '')) ?></span>
                <span class="thread__sep" aria-hidden="true"></span>
                <span class="thread__status thread__status--<?= e((string)($it['status_kind'] ?? 'offline')) ?>"><?= e((string)($it['status'] ?? '')) ?></span>
              </span>
            </span>
            <span class="thread__actions">
              <?php foreach ((array)($data['thread_actions'] ?? []) as $act): ?>
              <a class="thread__act" href="<?= e(url((string)$act['href'])) ?>" aria-label="<?= e((string)$act['label']) ?>" data-tip="<?= e((string)$act['label']) ?>"><?= icon((string)$act['icon']) ?></a>
              <?php endforeach; ?>
            </span>
          </header>

          <div class="thread__scroll scroll-y" data-thread-scroll>
            <p class="thread__subject"><span class="thread__subject-label">ASSUNTO</span><span class="thread__subject-text"><?= e((string)$it['subject']) ?></span><span class="thread__subject-date num"><?= e((string)($it['date'] ?? $it['time'])) ?></span></p>
            <ul class="chat">
              <?php foreach ((array)($it['thread'] ?? []) as $k => $m): ?>
              <?php if (!empty($m['sep'])): ?>
              <li class="chat__sep"><span><?= e((string)$m['sep']) ?></span></li>
              <?php endif; ?>
              <li class="chat__msg<?= !empty($m['self']) || ($m['author'] ?? '') === 'self' ? ' is-self' : '' ?>" style="--m:<?= e((string)$k) ?>">
                <span class="chat__line">
                  <span class="chat__author chat__author--<?= e((string)($m['color'] ?? 'lime')) ?>"><?= e(($m['author'] ?? '') === 'self' ? $me : (string)$m['author']) ?>:</span>
                  <span class="chat__text"><?= e((string)$m['text']) ?></span>
                </span>
                <span class="chat__time num"><?= e((string)$m['time']) ?></span>
              </li>
              <?php endforeach; ?>
            </ul>
          </div>

        </article>
        <?php endforeach; ?>
      </div>

      <form class="thread__form" method="post" action="<?= e(url('mensagens/responder')) ?>" data-reply-form>
        <?= csrf_field() ?>
        <input type="hidden" name="conversa" value="<?= e($activeId) ?>" data-reply-conv>
        <input class="thread__input" type="text" name="mensagem" maxlength="200" placeholder="<?= e((string)($data['reply_ph'] ?? 'Escreva uma resposta...')) ?>" aria-label="<?= e((string)($data['reply_ph'] ?? 'Resposta')) ?>" autocomplete="off" required data-reply-input>
        <button class="thread__send" type="submit" aria-label="<?= e((string)($data['reply_send'] ?? 'Enviar resposta')) ?>" data-reply-send><?= icon('send', 'fill') ?></button>
        <p class="thread__hint" data-reply-hint role="status" aria-live="polite" hidden>Digite uma mensagem antes de enviar.</p>
      </form>
      <?php endif; ?>
    </section>

    <!-- ====================== SISTEMA ====================== -->
    <aside class="msgs-side">

      <section class="panel sys-panel">
        <header class="panel__head">
          <h2 class="panel__title"><?= e((string)($sys['title'] ?? 'MENSAGENS DO SISTEMA')) ?></h2>
          <?php $sa = (array)($sys['action'] ?? []); if ($sa !== []): ?>
          <a class="panel__action" href="<?= e(url((string)$sa['href'])) ?>"><?= e((string)$sa['label']) ?><?= icon((string)($sa['icon'] ?? 'chevron_right')) ?></a>
          <?php endif; ?>
        </header>
        <div class="panel__body sys-panel__body">
          <?php if ($sysItems === []): ?>
          <div class="sys-empty" data-boot>
            <span class="sys-empty__ic"><?= icon((string)($sysEmpty['icon'] ?? 'inbox')) ?></span>
            <strong class="sys-empty__title"><?= e((string)($sysEmpty['title'] ?? 'SEM AVISOS')) ?></strong>
            <p class="sys-empty__text"><?= e((string)($sysEmpty['text'] ?? '')) ?></p>
            <a class="btn btn--outline btn--sm sys-empty__cta" href="<?= e(url((string)($sysEmpty['href'] ?? 'noticias'))) ?>"><?= e((string)($sysEmpty['cta'] ?? 'VER NOTÍCIAS')) ?><?= icon('chevron_right', 'btn__chev') ?></a>
          </div>
          <?php else: ?>
          <ul class="sys-list scroll-y scroll-y--nomask" data-stagger>
            <?php foreach ($sysItems as $s): ?>
            <li class="sys<?= !empty($s['fresh']) ? ' is-fresh' : '' ?> sys--<?= e((string)($s['color'] ?? 'lime')) ?>" data-boot>
              <span class="sys__icon"><?= icon((string)$s['icon']) ?></span>
              <span class="sys__body">
                <strong class="sys__title"><?= e((string)$s['title']) ?></strong>
                <span class="sys__text"><?= e((string)$s['text']) ?></span>
                <span class="sys__foot">
                  <span class="sys__time num"><?= e((string)$s['time']) ?></span>
                  <a class="sys__cta" href="<?= e(url((string)$s['href'])) ?>"><?= e((string)$s['cta']) ?><?= icon('chevron_right', 'sys__chev') ?></a>
                </span>
              </span>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
        </div>
      </section>

      <section class="panel maint" data-boot>
        <header class="panel__head">
          <h2 class="panel__title"><?= icon('schedule') ?><?= e((string)($maint['title'] ?? 'PRÓXIMA MANUTENÇÃO')) ?></h2>
        </header>
        <div class="panel__body maint__body">
          <p class="maint__clock num" data-countdown="<?= e((string)((int)($maint['seconds'] ?? 0))) ?>" aria-live="off">--:--:--</p>
          <p class="maint__label"><?= e((string)($maint['label'] ?? '')) ?></p>
          <p class="maint__note"><?= e((string)($maint['note'] ?? '')) ?></p>
          <a class="btn btn--outline btn--sm maint__cta" href="<?= e(url((string)($maint['href'] ?? 'noticias'))) ?>"><?= e((string)($maint['cta'] ?? 'LER AVISO')) ?><?= icon('chevron_right', 'btn__chev') ?></a>
        </div>
      </section>

    </aside>
  </div>
</div>
