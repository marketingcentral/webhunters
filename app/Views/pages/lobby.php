<?php declare(strict_types=1);
/**
 * Tela LOBBY (referência 03-lobby-ao-clicar-em-jogar-com-amigos.png).
 *
 * $data: title, subtitle, invite, squad[], empty_slot, leader_tag, chat[], chat_title, chat_ph,
 *        news_title, news_slides[], mission{...}, settings_title, settings[], cta, cta_href, gear_href.
 * Escopo da view: $page, $player, $nav, $server, $data, $flash, $csrf.
 */

$squad    = (array)($data['squad'] ?? []);
$empty    = (array)($data['empty_slot'] ?? []);
$chat     = (array)($data['chat'] ?? []);
$slides   = (array)($data['news_slides'] ?? []);
$mission  = (array)($data['mission'] ?? []);
$settings = (array)($data['settings'] ?? []);

/* --- slides do carrossel de notícias (HTML já renderizado) ------------------ */
$slidesHtml = [];
foreach ($slides as $s) {
    ob_start(); ?>
<a class="news-slide" href="<?= e(url((string)($s['href'] ?? 'noticias'))) ?>">
  <img class="news-slide__img" src="<?= e(media((string)($s['image'] ?? 'news/news-atualizacao.jpg'))) ?>" alt="" loading="lazy" decoding="async">
  <span class="news-slide__shade" aria-hidden="true"></span>
  <span class="news-slide__body">
    <strong class="news-slide__title"><?= e((string)($s['title'] ?? '')) ?></strong>
    <span class="news-slide__desc"><?= e((string)($s['desc'] ?? '')) ?></span>
  </span>
</a>
<?php $slidesHtml[] = (string)ob_get_clean();
}
?>
<div class="lobby">

  <!-- ====================== COLUNA ESQUERDA ====================== -->
  <div class="lobby__left">

    <header class="lobby-head">
      <div class="lobby-head__id">
        <h1 class="page-title"><?= e((string)($data['title'] ?? 'LOBBY')) ?></h1>
        <p class="page-subtitle"><?= e((string)($data['subtitle'] ?? '')) ?></p>
      </div>
      <a class="invite-btn" href="<?= e(url('amigos')) ?>">
        <?= icon('person_add', 'invite-btn__ic') ?><span><?= e((string)($data['invite'] ?? 'CONVIDAR AMIGO')) ?></span>
      </a>
    </header>

    <div class="squad" data-stagger>
      <?php foreach ($squad as $m): ?>
      <article class="op-card<?= !empty($m['leader']) ? ' op-card--leader' : '' ?>" data-boot>
        <?php if (!empty($m['leader'])): ?>
        <span class="op-card__tag"><?= e((string)($data['leader_tag'] ?? 'LÍDER')) ?></span>
        <?php endif; ?>
        <span class="op-card__media">
          <img class="op-card__portrait" src="<?= e(media((string)$m['portrait'])) ?>" alt="" width="896" height="1200" decoding="async">
          <span class="op-card__fade" aria-hidden="true"></span>
        </span>
        <span class="op-card__info">
          <?php component('hex', ['size' => 'md', 'color' => !empty($m['leader']) ? 'lime' : 'muted', 'text' => (string)$m['level'], 'hot' => !empty($m['leader']), 'class' => 'op-card__hex', 'title' => 'Nível ' . (string)$m['level']]) ?>
          <span class="op-card__id">
            <strong class="op-card__name"><?= e((string)$m['name']) ?></strong>
            <span class="op-card__ready"><?= e((string)($m['status'] ?? '')) ?><?= icon('check_circle', 'fill op-card__check') ?></span>
          </span>
        </span>
        <span class="op-card__class">
          <?= icon((string)($m['class_ms'] ?? 'swords'), 'op-card__class-ic') ?>
          <span class="op-card__class-name"><?= e((string)($m['class'] ?? '')) ?></span>
          <?php if (!empty($m['leader'])): ?><?= svg('crown', 'op-card__crown') ?><?php endif; ?>
        </span>
      </article>
      <?php endforeach; ?>

      <a class="op-card op-card--empty" href="<?= e(url('amigos')) ?>" data-boot>
        <span class="op-card__plus"><?= icon('add') ?></span>
        <strong class="op-card__empty-title"><?= e((string)($empty['title'] ?? 'CONVIDAR AMIGO')) ?></strong>
        <small class="op-card__empty-sub"><?= e((string)($empty['desc'] ?? 'Espaço livre')) ?></small>
      </a>
    </div>

    <div class="lobby-bottom">

      <section class="panel lobby-chat">
        <header class="panel__head">
          <h2 class="panel__title"><?= e((string)($data['chat_title'] ?? 'CHAT DO LOBBY')) ?></h2>
        </header>
        <div class="panel__body">
          <ul class="chat scroll-y scroll-y--nomask" id="lobby-chat">
            <?php foreach ($chat as $msg): ?>
            <li class="chat__msg<?= !empty($msg['self']) ? ' is-self' : '' ?>">
              <span class="chat__line"><span class="chat__author"><?= e((string)$msg['author']) ?>:</span> <span class="chat__text"><?= e((string)$msg['text']) ?></span></span>
              <span class="chat__time num"><?= e((string)$msg['time']) ?></span>
            </li>
            <?php endforeach; ?>
          </ul>
          <form class="chat__form" method="post" action="<?= e(url('lobby/chat')) ?>" autocomplete="off">
            <?= csrf_field() ?>
            <input class="chat__input" type="text" name="message" maxlength="200" placeholder="<?= e((string)($data['chat_ph'] ?? 'Digite sua mensagem...')) ?>" aria-label="<?= e((string)($data['chat_ph'] ?? 'Mensagem')) ?>" required>
            <button class="chat__send" type="submit" aria-label="<?= e((string)($data['chat_send'] ?? 'Enviar mensagem')) ?>"><?= icon('send', 'fill') ?></button>
          </form>
        </div>
      </section>

      <section class="panel lobby-news">
        <header class="panel__head">
          <h2 class="panel__title"><?= e((string)($data['news_title'] ?? 'NOTÍCIAS E ATUALIZAÇÕES')) ?></h2>
        </header>
        <div class="panel__body">
          <?php component('carousel', ['slides' => $slidesHtml, 'interval' => 8000, 'dots' => true, 'over' => true, 'fixed' => true, 'class' => 'news-carousel', 'label' => 'Notícias e atualizações']) ?>
        </div>
      </section>

    </div>
  </div>

  <!-- ====================== COLUNA DIREITA ====================== -->
  <aside class="lobby__right">

    <section class="panel mission">
      <header class="panel__head">
        <h2 class="panel__title"><?= e((string)($mission['panel_title'] ?? 'MISSÃO SELECIONADA')) ?></h2>
      </header>
      <div class="panel__body">

        <a class="mission__media" href="<?= e(url((string)($mission['href'] ?? 'missoes'))) ?>">
          <img class="mission__img" src="<?= e(media((string)($mission['image'] ?? 'ui/mission-sinal-perdido.jpg'))) ?>" alt="<?= e((string)($mission['title'] ?? '')) ?>" decoding="async">
          <span class="mission__shade" aria-hidden="true"></span>
          <span class="mission__head">
            <strong class="mission__title"><?= e((string)($mission['title'] ?? '')) ?></strong>
            <span class="mission__sub"><?= e((string)($mission['subtitle'] ?? '')) ?></span>
          </span>
          <span class="mission__stats">
            <?php foreach ((array)($mission['stats'] ?? []) as $st): ?>
            <span class="mstat">
              <span class="mstat__label"><?= e((string)$st['label']) ?></span>
              <span class="mstat__val"><?= e((string)$st['value']) ?></span>
            </span>
            <?php endforeach; ?>
          </span>
        </a>

        <h3 class="mission__section"><?= e((string)($mission['objective_title'] ?? 'OBJETIVO')) ?></h3>
        <p class="mission__objective">
          <?php foreach ((array)($mission['objective_lines'] ?? [(string)($mission['objective'] ?? '')]) as $k => $line): ?>
          <?= $k ? '<br>' : '' ?><?= e((string)$line) ?>
          <?php endforeach; ?>
        </p>

        <hr class="divider mission__rule">

        <h3 class="mission__section"><?= e((string)($mission['rewards_title'] ?? 'RECOMPENSAS')) ?></h3>
        <div class="rewards">
          <?php foreach ((array)($mission['rewards'] ?? []) as $rw): ?>
          <span class="reward reward--<?= e((string)($rw['type'] ?? 'xp')) ?>">
            <span class="reward__ring"><?php if (($rw['icon'] ?? 'xp') === 'xp'): ?><span class="reward__xp">XP</span><?php else: ?><?= svg((string)$rw['icon'], 'reward__ic') ?><?php endif; ?></span>
            <span class="reward__val num"><?= e((string)($rw['prefix'] ?? '')) ?><?= e(num((int)($rw['value'] ?? 0))) ?><?= e((string)($rw['suffix'] ?? '')) ?></span>
          </span>
          <?php endforeach; ?>
          <?php $it = (array)($mission['reward_item'] ?? []); ?>
          <?php if ($it !== []): ?>
          <span class="reward-item">
            <img class="reward-item__img" src="<?= e(media((string)$it['image'])) ?>" alt="" decoding="async">
          </span>
          <span class="reward-item__text">
            <strong class="reward-item__name"><?= e((string)$it['name']) ?></strong>
            <small class="reward-item__desc"><?= e((string)$it['desc']) ?></small>
          </span>
          <?php endif; ?>
        </div>

      </div>
    </section>

    <section class="panel mission-set">
      <header class="panel__head">
        <h2 class="panel__title"><?= e((string)($data['settings_title'] ?? 'CONFIGURAÇÕES DA MISSÃO')) ?></h2>
      </header>
      <div class="panel__body panel__body--tight">
        <?php foreach ($settings as $row): ?>
        <?php $opts = (array)($row['options'] ?? [$row['value']]); ?>
        <div class="set-row stepper<?= !empty($row['icon']) ? ' set-row--icon' : '' ?>" data-key="<?= e((string)$row['key']) ?>" data-options="<?= e(implode('|', array_map('strval', $opts))) ?>" data-value="<?= e((string)$row['value']) ?>">
          <span class="set-row__label"><?= e((string)$row['label']) ?></span>
          <button class="stepper__btn ms set-row__prev" type="button" aria-label="Anterior: <?= e((string)$row['label']) ?>">chevron_left</button>
          <?php if (!empty($row['icon'])): ?>
          <?= icon((string)$row['icon'], 'set-row__ic') ?>
          <?php endif; ?>
          <span class="stepper__val set-row__val"><?= e((string)$row['value']) ?></span>
          <button class="stepper__btn ms set-row__next" type="button" aria-label="Próximo: <?= e((string)$row['label']) ?>">chevron_right</button>
          <input type="hidden" name="<?= e((string)$row['key']) ?>" value="<?= e((string)$row['value']) ?>">
        </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="panel mission-cta" data-boot>
      <a class="btn btn--primary start-btn" href="<?= e(url((string)($data['cta_href'] ?? 'mapa'))) ?>">
        <?= icon('play_arrow', 'fill start-btn__play') ?><span class="start-btn__label"><?= e((string)($data['cta'] ?? 'INICIAR MISSÃO')) ?></span>
      </a>
      <a class="btn start-gear" href="<?= e(url((string)($data['gear_href'] ?? 'configuracoes'))) ?>" aria-label="<?= e((string)($data['gear_label'] ?? 'Configurações')) ?>" data-tip="<?= e((string)($data['gear_label'] ?? 'Configurações')) ?>">
        <?= icon('settings') ?>
      </a>
    </section>

  </aside>
</div>
