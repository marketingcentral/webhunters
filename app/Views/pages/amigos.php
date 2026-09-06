<?php declare(strict_types=1);
/**
 * Tela AMIGOS (sem referência; âncoras de estilo: 03-lobby).
 *
 * $data: title, subtitle, online, total, tab, search_ph, search_label, tabs[],
 *        items_online[], items_offline[], pending[], status_labels{}, actions{},
 *        add{}, squad{}, empty{}
 * As listas chegam prontas do FriendsController (App\Repo\FriendsRepo, por user_id).
 * Escopo da view: $page, $player, $nav, $server, $data, $flash, $csrf.
 */

$online  = (array)($data['items_online'] ?? []);
$offline = (array)($data['items_offline'] ?? []);
$pending = (array)($data['pending'] ?? []);
$tabs    = (array)($data['tabs'] ?? []);
$labels  = (array)($data['status_labels'] ?? []);
$act     = (array)($data['actions'] ?? []);
$addBox  = (array)($data['add'] ?? []);
$squad   = (array)($data['squad'] ?? []);
$empty   = (array)($data['empty'] ?? []);
$active  = (string)($data['tab'] ?? 'online');

$tabItems = [];
foreach ($tabs as $t) {
    $slug = (string)($t['slug'] ?? '');
    $tabItems[] = [
        'label'  => (string)($t['label'] ?? ''),
        'panel'  => $slug,
        'count'  => (string)($t['count'] ?? '0'),
        'active' => $slug === $active,
    ];
}

/** Linha de vazio de uma lista: mostra o texto da aba, ou o da busca quando o filtro está ativo. */
$emptyRow = static function (string $slug, array $empty, bool $show): void { ?>
    <li class="flist__empty" data-empty-list="<?= e((string)($empty[$slug] ?? '')) ?>"
        data-empty-search="<?= e((string)($empty['search'] ?? '')) ?>"<?= $show ? '' : ' hidden' ?>>
      <?= e((string)($empty[$slug] ?? '')) ?>
    </li>
<?php };

/** Uma linha da lista de amigos. */
$friendRow = static function (array $f, array $labels, array $act, bool $dim = false): void {
    $status = (string)($f['status'] ?? 'offline');
    ?>
  <li class="frow frow--<?= e($status) ?><?= $dim ? ' frow--dim' : '' ?>" data-boot data-name="<?= e(mb_strtolower((string)$f['name'])) ?>">
    <span class="frow__thumb">
      <img class="frow__portrait" src="<?= e(media((string)$f['avatar'])) ?>" alt="" width="896" height="1200" loading="lazy" decoding="async">
      <span class="frow__thumb-fade" aria-hidden="true"></span>
    </span>
    <?php component('hex', ['size' => 'sm', 'text' => (string)$f['level'], 'color' => $status === 'offline' ? 'muted' : 'lime', 'class' => 'frow__hex', 'title' => 'Nível ' . (string)$f['level']]) ?>
    <span class="frow__id">
      <strong class="frow__name ellipsis"><?= e((string)$f['name']) ?></strong>
      <span class="frow__class">
        <?= svg((string)($f['class_icon'] ?? 'class-assault'), 'frow__class-ic') ?><?= e((string)($f['class'] ?? '')) ?>
      </span>
    </span>
    <span class="frow__state">
      <span class="frow__status"><i class="fdot" aria-hidden="true"></i><?= e((string)($labels[$status] ?? '')) ?></span>
      <span class="frow__activity ellipsis"><?= e((string)($f['activity'] ?? '')) ?></span>
    </span>
    <span class="frow__meta num"><?= e((string)($f['meta'] ?? '')) ?></span>
    <span class="frow__actions">
      <?php if ($status !== 'offline'): ?>
      <a class="fbtn fbtn--go" href="<?= e(url('lobby')) ?>"><?= icon('person_add', 'fbtn__ic') ?><span><?= e((string)($act['invite'] ?? 'CONVIDAR')) ?></span></a>
      <?php endif; ?>
      <a class="fbtn" href="<?= e(url('mensagens')) ?>"><?= icon('mail', 'fbtn__ic') ?><span><?= e((string)($act['message'] ?? 'MENSAGEM')) ?></span></a>
    </span>
  </li>
<?php };
?>
<div class="friends" data-tabs-scope>

  <!-- ====================== COLUNA ESQUERDA ====================== -->
  <section class="friends__main">

    <header class="friends-head">
      <div class="friends-head__id">
        <h1 class="page-title"><?= e((string)($data['title'] ?? 'AMIGOS')) ?><small class="num"><?= e(num((int)($data['online'] ?? 0))) ?>/<?= e(num((int)($data['total'] ?? 0))) ?></small></h1>
        <p class="page-subtitle"><?= e((string)($data['subtitle'] ?? '')) ?></p>
      </div>
      <div class="friends-head__tools">
        <span class="fsearch">
          <?= icon('search', 'fsearch__ic') ?>
          <input class="fsearch__input" type="search" id="friends-search" autocomplete="off"
                 placeholder="<?= e((string)($data['search_ph'] ?? 'Buscar...')) ?>"
                 aria-label="<?= e((string)($data['search_label'] ?? 'Buscar amigo')) ?>">
        </span>
        <a class="fbtn fbtn--tool" href="<?= e(url('mensagens')) ?>">
          <?= icon('mail', 'fbtn__ic') ?><span>MENSAGENS</span>
          <?php if ((int)($player['unread_mail'] ?? 0) > 0): ?><i class="fbtn__badge num"><?= e((string)(int)$player['unread_mail']) ?></i><?php endif; ?>
        </a>
      </div>
    </header>

    <?php component('tabs', ['items' => $tabItems, 'class' => 'friends-tabs', 'label' => 'Filtros da lista de amigos']) ?>

    <section class="panel friends-panel">
      <div class="panel__body panel__body--tight friends-panel__body">

        <ul class="flist scroll-y" data-tab-panel="online" data-stagger<?= $active === 'online' ? '' : ' hidden' ?>>
          <?php foreach ($online as $f): ?><?php $friendRow($f, $labels, $act); ?><?php endforeach; ?>
          <?php $emptyRow('online', $empty, $online === []); ?>
        </ul>

        <ul class="flist scroll-y" data-tab-panel="offline" data-stagger<?= $active === 'offline' ? '' : ' hidden' ?>>
          <?php foreach ($offline as $f): ?><?php $friendRow($f, $labels, $act, true); ?><?php endforeach; ?>
          <?php $emptyRow('offline', $empty, $offline === []); ?>
        </ul>

        <ul class="flist scroll-y" data-tab-panel="pedidos" data-stagger<?= $active === 'pedidos' ? '' : ' hidden' ?>>
          <?php foreach ($pending as $p): $in = ($p['direction'] ?? 'in') === 'in'; ?>
          <li class="frow frow--pending<?= $in ? ' frow--in' : ' frow--out' ?>" data-boot data-name="<?= e(mb_strtolower((string)$p['name'])) ?>">
            <span class="frow__thumb">
              <img class="frow__portrait" src="<?= e(media((string)$p['avatar'])) ?>" alt="" width="896" height="1200" loading="lazy" decoding="async">
              <span class="frow__thumb-fade" aria-hidden="true"></span>
            </span>
            <?php component('hex', ['size' => 'sm', 'text' => (string)$p['level'], 'color' => 'muted', 'class' => 'frow__hex', 'title' => 'Nível ' . (string)$p['level']]) ?>
            <span class="frow__id">
              <strong class="frow__name ellipsis"><?= e((string)$p['name']) ?></strong>
              <span class="frow__class"><?= svg((string)($p['class_icon'] ?? 'class-assault'), 'frow__class-ic') ?><?= e((string)($p['class'] ?? '')) ?></span>
            </span>
            <span class="frow__state">
              <span class="frow__status"><i class="fdot" aria-hidden="true"></i><?= $in ? 'PEDIDO RECEBIDO' : 'CONVITE ENVIADO' ?></span>
              <span class="frow__activity ellipsis"><?= e((string)($p['activity'] ?? '')) ?></span>
            </span>
            <span class="frow__meta num"><?= e((string)($p['meta'] ?? '')) ?></span>
            <form class="frow__actions" method="post" action="<?= e(url('amigos')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="jogador" value="<?= e((string)$p['name']) ?>">
              <?php if ($in): ?>
              <button class="fbtn fbtn--go" type="submit" name="acao" value="aceitar"><?= icon('check_circle', 'fbtn__ic') ?><span><?= e((string)($act['accept'] ?? 'ACEITAR')) ?></span></button>
              <button class="fbtn fbtn--deny" type="submit" name="acao" value="recusar"><?= icon('close', 'fbtn__ic') ?><span><?= e((string)($act['decline'] ?? 'RECUSAR')) ?></span></button>
              <?php else: ?>
              <button class="fbtn fbtn--deny" type="submit" name="acao" value="cancelar"><?= icon('undo', 'fbtn__ic') ?><span><?= e((string)($act['cancel'] ?? 'CANCELAR')) ?></span></button>
              <?php endif; ?>
            </form>
          </li>
          <?php endforeach; ?>
          <?php $emptyRow('pedidos', $empty, $pending === []); ?>
        </ul>

      </div>
    </section>
  </section>

  <!-- ====================== COLUNA DIREITA ====================== -->
  <aside class="friends__side">

    <section class="panel panel--hot fadd" data-boot>
      <header class="panel__head">
        <h2 class="panel__title"><?= icon('person_add', 'panel__title-ic') ?><?= e((string)($addBox['title'] ?? 'ADICIONAR AMIGO')) ?></h2>
      </header>
      <div class="panel__body">
        <form class="form fadd__form" method="post" action="<?= e(url('amigos')) ?>" autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="acao" value="convidar">
          <div class="field field--icon">
            <label class="field__label" for="add-friend"><?= e((string)($addBox['label'] ?? '')) ?></label>
            <input class="input" type="text" id="add-friend" name="jogador" maxlength="40"
                   placeholder="<?= e((string)($addBox['placeholder'] ?? '')) ?>" required>
            <?= icon('badge') ?>
          </div>
          <button class="btn btn--primary btn--block fadd__submit" type="submit">
            <?= icon('send', 'fill') ?><span><?= e((string)($addBox['submit'] ?? 'ENVIAR CONVITE')) ?></span>
          </button>
          <p class="field__hint fadd__hint"><?= e((string)($addBox['hint'] ?? '')) ?></p>
        </form>
        <hr class="divider fadd__rule">
        <div class="fadd__id">
          <span class="fadd__id-label"><?= e((string)($addBox['id_label'] ?? 'SEU ID')) ?></span>
          <span class="fadd__id-val num"><?= e((string)($player['name'] ?? '')) ?><?= e((string)($addBox['id_suffix'] ?? '')) ?></span>
        </div>
      </div>
    </section>

    <section class="panel fsquad panel--grow" data-boot>
      <header class="panel__head">
        <h2 class="panel__title"><?= icon('group', 'panel__title-ic') ?><?= e((string)($squad['title'] ?? 'ESQUADRÃO RECENTE')) ?></h2>
        <a class="panel__action" href="<?= e(url('lobby')) ?>"><?= e((string)($squad['action'] ?? 'ABRIR LOBBY')) ?><?= icon('chevron_right') ?></a>
      </header>
      <div class="panel__body panel__body--tight">
        <ul class="squad-list" data-stagger>
          <?php foreach ((array)($squad['items'] ?? []) as $s): ?>
          <li class="srow" data-boot>
            <span class="srow__thumb">
              <img class="srow__portrait" src="<?= e(media((string)$s['avatar'])) ?>" alt="" width="896" height="1200" loading="lazy" decoding="async">
              <span class="srow__thumb-fade" aria-hidden="true"></span>
            </span>
            <span class="srow__id">
              <strong class="srow__name ellipsis"><?= e((string)$s['name']) ?></strong>
              <span class="srow__map ellipsis"><?= e((string)$s['map']) ?></span>
            </span>
            <span class="srow__right">
              <span class="srow__result<?= empty($s['ok']) ? ' is-ko' : '' ?>"><?= e((string)$s['result']) ?></span>
              <span class="srow__when num"><?= e((string)$s['when']) ?></span>
            </span>
            <a class="srow__add" href="<?= e(url('lobby')) ?>" data-tip="Convidar <?= e((string)$s['name']) ?>" aria-label="Convidar <?= e((string)$s['name']) ?>"><?= icon('person_add') ?></a>
          </li>
          <?php endforeach; ?>
          <?php if ((array)($squad['items'] ?? []) === []): ?>
          <li class="srow srow--empty">Nenhuma partida em esquadrão ainda.</li>
          <?php endif; ?>
        </ul>
      </div>
    </section>

  </aside>
</div>
