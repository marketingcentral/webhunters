<?php declare(strict_types=1);
/**
 * Tela JOGAR SOLO / MULTIPLAYER (referência 02-jogar-solo-multplayer.png).
 * $data: title, subtitle, intro, modes, banner, maps, mode_icons, prefs, cta, eta,
 *        squad, challenges, reset_in, daily_reward, *_title, *_action.
 *
 * Os brasões dos modos e os glifos dos hexágonos são símbolos SVG locais (defs abaixo):
 * a sprite compartilhada (F2) só tem silhuetas maciças e a referência pede contorno
 * heráldico com detalhe interno. Ids sempre prefixados jg- para não colidir.
 */
$maps       = is_array($data['maps'] ?? null) ? $data['maps'] : [];
$challenges = is_array($data['challenges'] ?? null) ? $data['challenges'] : [];
$modes      = is_array($data['modes'] ?? null) ? $data['modes'] : [];
$prefs      = is_array($data['prefs'] ?? null) ? $data['prefs'] : [];
$banner     = is_array($data['banner'] ?? null) ? $data['banner'] : [];
$squad      = is_array($data['squad'] ?? null) ? $data['squad'] : [];
$reward     = is_array($data['daily_reward'] ?? null) ? $data['daily_reward'] : [];
$modeIcons  = is_array($data['mode_icons'] ?? null) ? $data['mode_icons'] : [];
$resetIn    = (string)($data['reset_in'] ?? '12h 45m');
$members    = is_array($squad['members'] ?? null) ? $squad['members'] : [];
$slots      = max(0, (int)($squad['max'] ?? 4) - count($members));

/** Ids permitidos dos símbolos locais (nada vindo do mock entra cru no href). */
$crests = ['jg-quick', 'jg-team', 'jg-dom', 'jg-zone', 'jg-hunt', 'jg-hx-skull', 'jg-hx-shield', 'jg-hx-target'];
$crest  = static function (?string $id, string $fallback, array $ok): string {
    $id = (string)$id;
    return in_array($id, $ok, true) ? $id : $fallback;
};
?>
<svg class="jogar-defs" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <!-- caveira + ossos cruzados (PARTIDA RÁPIDA / banner) -->
    <symbol id="jg-quick" viewBox="0 0 24 24">
      <g fill="currentColor">
        <g transform="rotate(22 12 19)">
          <rect x="4.7" y="18.2" width="14.6" height="1.5" rx=".75"/>
          <circle cx="5.2" cy="18.1" r="1"/><circle cx="5.2" cy="19.8" r="1"/>
          <circle cx="18.8" cy="18.1" r="1"/><circle cx="18.8" cy="19.8" r="1"/>
        </g>
        <g transform="rotate(-22 12 19)">
          <rect x="4.7" y="18.2" width="14.6" height="1.5" rx=".75"/>
          <circle cx="5.2" cy="18.1" r="1"/><circle cx="5.2" cy="19.8" r="1"/>
          <circle cx="18.8" cy="18.1" r="1"/><circle cx="18.8" cy="19.8" r="1"/>
        </g>
      </g>
      <path fill="currentColor" fill-rule="evenodd" d="M12 1.6c-4.2 0-7.5 3.1-7.5 7.1 0 2.4 1 4.3 2.6 5.6v1.5c0 .6.5 1.1 1.1 1.1h7.6c.6 0 1.1-.5 1.1-1.1v-1.5c1.6-1.3 2.6-3.2 2.6-5.6 0-4-3.3-7.1-7.5-7.1ZM8.6 6.9a2.1 2.1 0 1 0 0 4.2 2.1 2.1 0 0 0 0-4.2Zm6.8 0a2.1 2.1 0 1 0 0 4.2 2.1 2.1 0 0 0 0-4.2ZM12 11.1l-1.1 2.1h2.2L12 11.1Zm-1.4 3.5h.85v3h-.85Zm2 0h.85v3h-.85Z"/>
    </symbol>

    <!-- três operadores (MATA-MATA EM EQUIPE) -->
    <symbol id="jg-team" viewBox="0 0 24 24">
      <g fill="currentColor" opacity=".5">
        <circle cx="4.4" cy="9.9" r="2.3"/>
        <path d="M4.4 13c-2 0-3.6 1.5-3.6 3.4V19h7.2v-2.6c0-1.9-1.6-3.4-3.6-3.4Z"/>
        <circle cx="19.6" cy="9.9" r="2.3"/>
        <path d="M19.6 13c2 0 3.6 1.5 3.6 3.4V19H16v-2.6c0-1.9 1.6-3.4 3.6-3.4Z"/>
      </g>
      <g fill="currentColor">
        <circle cx="12" cy="7.6" r="3.3"/>
        <path d="M12 11.8c-2.7 0-4.9 2-4.9 4.5V19h9.8v-2.7c0-2.5-2.2-4.5-4.9-4.5Z"/>
      </g>
    </symbol>

    <!-- brasão: escudo vazado + estandarte (DOMINAÇÃO) -->
    <symbol id="jg-dom" viewBox="0 0 24 24">
      <path fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" d="M12 2.3 20.4 5.2v6.4c0 4.7-3.4 8.7-8.4 10.3-5-1.6-8.4-5.6-8.4-10.3V5.2Z"/>
      <path fill="currentColor" d="M9.1 6.5h1.3v11H9.1z"/>
      <path fill="currentColor" d="M11.1 6.9h5.6l-1.6 2.3 1.6 2.3h-5.6z"/>
    </symbol>

    <!-- brasão: escudo vazado + zona disputada (ZONA DE CONFLITO) -->
    <symbol id="jg-zone" viewBox="0 0 24 24">
      <path fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" d="M12 2.3 20.4 5.2v6.4c0 4.7-3.4 8.7-8.4 10.3-5-1.6-8.4-5.6-8.4-10.3V5.2Z"/>
      <path fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" d="m7.4 8.4 3 3.1-3 3.1M16.6 8.4l-3 3.1 3 3.1"/>
      <path fill="currentColor" d="M11.4 5.9h1.2v11.2h-1.2z" opacity=".55"/>
    </symbol>

    <!-- brasão: escudo vazado + retículo (CAÇA) -->
    <symbol id="jg-hunt" viewBox="0 0 24 24">
      <path fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" d="M12 2.3 20.4 5.2v6.4c0 4.7-3.4 8.7-8.4 10.3-5-1.6-8.4-5.6-8.4-10.3V5.2Z"/>
      <circle cx="12" cy="11.2" r="3.7" fill="none" stroke="currentColor" stroke-width="1.4"/>
      <path fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" d="M12 5.6v2.1M12 14.7v2.1M6.4 11.2h2.1M15.5 11.2h2.1"/>
      <circle cx="12" cy="11.2" r="1.2" fill="currentColor"/>
    </symbol>

    <!-- glifos compactos dos hexágonos dos cards de mapa -->
    <symbol id="jg-hx-skull" viewBox="0 0 24 24">
      <path fill="currentColor" fill-rule="evenodd" d="M12 1.9c-4.6 0-8.2 3.4-8.2 7.8 0 2.7 1.1 4.8 2.9 6.2v1.9c0 .7.6 1.3 1.3 1.3h8c.7 0 1.3-.6 1.3-1.3v-1.9c1.8-1.4 2.9-3.5 2.9-6.2 0-4.4-3.6-7.8-8.2-7.8ZM8.2 7.8a2.3 2.3 0 1 0 0 4.6 2.3 2.3 0 0 0 0-4.6Zm7.6 0a2.3 2.3 0 1 0 0 4.6 2.3 2.3 0 0 0 0-4.6ZM12 12.5l-1.2 2.3h2.4L12 12.5Zm-1.55 3.7h.9v3.2h-.9Zm2.2 0h.9v3.2h-.9Z"/>
      <path fill="currentColor" d="M6.6 20.1h10.8v1.5H6.6z" opacity=".5"/>
    </symbol>
    <symbol id="jg-hx-shield" viewBox="0 0 24 24">
      <path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" d="M12 1.9 21 5v6.8c0 5-3.7 9.3-9 11-5.3-1.7-9-6-9-11V5Z"/>
      <path fill="currentColor" d="M11.1 6.2h1.7v11.4h-1.7z"/>
      <path fill="currentColor" d="M13.5 6.7h4.6l-1.5 2.2 1.5 2.2h-4.6z"/>
    </symbol>
    <symbol id="jg-hx-target" viewBox="0 0 24 24">
      <circle cx="12" cy="12" r="7.4" fill="none" stroke="currentColor" stroke-width="1.8"/>
      <circle cx="12" cy="12" r="2.4" fill="currentColor"/>
      <path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M12 1.8v3.4M12 18.8v3.4M1.8 12h3.4M18.8 12h3.4"/>
    </symbol>
  </defs>
</svg>

<div class="layout-3 jogar">

  <!-- ================= COLUNA ESQUERDA ================= -->
  <div class="col jogar-left">

    <header class="jogar-head" data-boot>
      <h1 class="page-title"><?= e((string)($data['title'] ?? 'JOGAR SOLO')) ?><span class="page-title__tag"><?= e((string)($data['subtitle'] ?? '')) ?></span></h1>
      <p class="page-subtitle"><?= e((string)($data['intro'] ?? '')) ?></p>
    </header>

    <section class="panel jogar-modes panel--grow" data-boot>
      <div class="panel__body">
        <h2 class="panel__section"><?= e((string)($data['modes_title'] ?? 'MODO DE JOGO')) ?></h2>
        <div class="list jogar-modes__list" data-select-group data-stagger>
<?php foreach ($modes as $m): ?>
<?php
  $locked = !empty($m['locked']);
  $act    = !empty($m['active']);
  $sym    = $crest((string)($m['icon'] ?? ''), 'jg-quick', $crests);
  $tag    = $locked ? 'div' : 'button';
?>
          <<?= $tag ?> class="row jogar-mode<?= $act ? ' is-active' : '' ?><?= $locked ? ' is-locked' : '' ?>" data-value="<?= e((string)($m['slug'] ?? '')) ?>"<?php if ($locked): ?> data-tip="Modo bloqueado"<?php else: ?> type="button" data-select aria-pressed="<?= $act ? 'true' : 'false' ?>"<?php endif; ?>>
            <span class="row__icon jogar-mode__crest" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24"><use href="#<?= e($sym) ?>"></use></svg></span>
            <span class="row__text"><strong><?= e((string)($m['title'] ?? '')) ?></strong><small><?= e((string)($m['desc'] ?? '')) ?></small></span>
<?php if ($locked): ?>
            <span class="ms row__lock" aria-hidden="true">lock</span>
<?php else: ?>
            <span class="ms row__chev" aria-hidden="true">chevron_right</span>
<?php endif; ?>
          </<?= $tag ?>>
<?php endforeach; ?>
        </div>
      </div>
    </section>

    <section class="panel jogar-prefs" data-boot>
      <div class="panel__body">
        <h2 class="panel__section"><?= e((string)($data['prefs_title'] ?? 'PREFERÊNCIAS DE PARTIDA')) ?></h2>
        <div class="pref-list">
<?php foreach ($prefs as $p): ?>
          <div class="pref">
            <span class="pref__label"><?= e((string)($p['label'] ?? '')) ?></span>
<?php if (($p['type'] ?? '') === 'toggle'): ?>
            <?php component('toggle', ['name' => 'pref_' . (string)($p['key'] ?? ''), 'checked' => !empty($p['value']), 'on' => 'Ativado', 'off' => 'Desativado', 'lime' => true, 'class' => 'pref__toggle']) ?>
<?php else: ?>
            <?php component('stepper', ['options' => (array)($p['options'] ?? [(string)($p['text'] ?? '')]), 'value' => (string)($p['text'] ?? ''), 'name' => 'pref_' . (string)($p['key'] ?? ''), 'class' => 'pref__stepper']) ?>
<?php endif; ?>
          </div>
<?php endforeach; ?>
        </div>
      </div>
    </section>

    <section class="panel jogar-cta" data-boot>
      <div class="panel__body">
        <?php component('btn', ['label' => (string)($data['cta'] ?? 'BUSCAR PARTIDA'), 'href' => url('lobby'), 'variant' => 'primary', 'size' => 'lg', 'block' => true, 'chev' => true, 'class' => 'jogar-cta__btn', 'attrs' => ['data-ue-play' => '']]) ?>
        <p class="jogar-eta"><?= icon('schedule') ?><span><?= e((string)($data['eta_label'] ?? 'TEMPO ESTIMADO:')) ?></span> <span class="num"><?= e((string)($data['eta'] ?? '')) ?></span></p>
      </div>
    </section>
  </div>

  <!-- ================= COLUNA CENTRAL ================= -->
  <div class="col jogar-mid">

    <section class="jogar-banner" data-boot>
      <img class="jogar-banner__img" src="<?= e(media((string)($banner['image'] ?? 'bg/jogar-banner.jpg'))) ?>" alt="">
      <div class="jogar-banner__shade" aria-hidden="true"></div>
      <div class="jogar-banner__body">
        <svg class="jg-hex jogar-banner__hex" viewBox="0 0 52 60" aria-hidden="true" focusable="false">
          <polygon class="jg-hex__frame" points="26,1.4 50.6,15.7 50.6,44.3 26,58.6 1.4,44.3 1.4,15.7"/>
          <g class="jg-hex__dots"><circle cx="5.4" cy="18.2" r="1"/><circle cx="46.6" cy="18.2" r="1"/><circle cx="5.4" cy="41.8" r="1"/><circle cx="46.6" cy="41.8" r="1"/></g>
          <use href="#jg-quick" x="10.5" y="15.5" width="31" height="31"></use>
        </svg>
        <div class="jogar-banner__text">
          <h2 class="jogar-banner__title"><?= e((string)($banner['title'] ?? '')) ?></h2>
          <p class="jogar-banner__desc"><?= e((string)($banner['desc'] ?? '')) ?></p>
        </div>
      </div>
    </section>

    <section class="panel jogar-maps panel--grow" data-boot>
      <div class="panel__body">
        <h2 class="panel__section"><?= e((string)($data['maps_title'] ?? 'SELEÇÃO DE MAPAS')) ?></h2>
        <div class="map-grid" data-select-group>
<?php foreach (array_slice($maps, 0, 8) as $mp): ?>
<?php
  $mode = (string)($mp['mode'] ?? '');
  $sel  = !empty($mp['selected']);
  $sym  = $crest((string)($modeIcons[$mode] ?? ''), 'jg-hx-skull', $crests);
?>
          <article class="card map-card<?= $sel ? ' is-selected' : '' ?>" data-value="<?= e((string)($mp['slug'] ?? '')) ?>" data-ue-level="<?= e((string)($mp['ue_level'] ?? '')) ?>"<?= $sel ? ' aria-selected="true"' : '' ?>>
            <div class="card__media">
              <img src="<?= e(media((string)($mp['image'] ?? ''))) ?>" alt="<?= e((string)($mp['name'] ?? '')) ?>" loading="lazy">
            </div>
            <div class="card__body card__body--row">
              <svg class="jg-hex map-card__hex" viewBox="0 0 52 60" aria-hidden="true" focusable="false">
                <polygon class="jg-hex__frame" points="26,1.4 50.6,15.7 50.6,44.3 26,58.6 1.4,44.3 1.4,15.7"/>
                <use href="#<?= e($sym) ?>" x="11" y="15.5" width="30" height="30"></use>
              </svg>
              <div class="grid min-0 map-card__lines">
                <h3 class="card__title"><?= e((string)($mp['name'] ?? '')) ?></h3>
                <span class="card__sub"><?= e($mode) ?></span>
                <span class="card__meta"><?= e((string)($mp['players'] ?? '')) ?></span>
              </div>
            </div>
            <span class="map-card__check" aria-hidden="true"><?= icon('check') ?></span>
          </article>
<?php endforeach; ?>
        </div>
        <div class="jogar-maps__foot">
          <?php component('btn', ['label' => (string)($data['maps_action'] ?? 'VER TODOS OS MAPAS'), 'href' => url('mapas'), 'variant' => 'outline', 'class' => 'jogar-maps__all']) ?>
        </div>
      </div>
    </section>
  </div>

  <!-- ================= COLUNA DIREITA ================= -->
  <div class="col jogar-right">

    <section class="panel jogar-squad" data-boot>
      <header class="panel__head">
        <h2 class="panel__title"><?= e((string)($squad['title'] ?? 'ESQUADRÃO')) ?></h2>
        <span class="panel__meta num"><?= e(num((int)($squad['count'] ?? count($members)))) ?> / <?= e(num((int)($squad['max'] ?? 4))) ?></span>
      </header>
      <div class="panel__body">
<?php foreach ($members as $mb): ?>
<?php
  $self  = !empty($mb['self']);
  $mName = $self ? (string)($player['name'] ?? $mb['name'] ?? '') : (string)($mb['name'] ?? '');
  $mLvl  = $self ? (int)($player['level'] ?? $mb['level'] ?? 0) : (int)($mb['level'] ?? 0);
  $mImg  = $self ? (string)($player['avatar'] ?? $mb['avatar'] ?? '') : (string)($mb['avatar'] ?? '');
?>
        <a class="squad-member" href="<?= e(url('perfil')) ?>">
          <span class="squad-member__pic"><img class="squad-member__avatar" src="<?= e(media($mImg)) ?>" alt=""></span>
          <span class="squad-member__text">
            <strong><?= e($mName) ?></strong>
            <small>Nível <span class="num"><?= e(num($mLvl)) ?></span><?php if (!empty($mb['leader'])): ?><?= svg('crown', 'squad-member__crown') ?><?php endif; ?></small>
          </span>
          <span class="dot<?= empty($mb['online']) ? ' dot--off' : '' ?> squad-member__dot" aria-hidden="true"></span>
        </a>
<?php endforeach; ?>
<?php if ($slots > 0): ?>
        <div class="squad-slots">
          <a class="squad-slot" href="<?= e(url('amigos')) ?>" data-tip="<?= e((string)($squad['add_label'] ?? 'CONVIDAR JOGADOR')) ?>" aria-label="<?= e((string)($squad['add_label'] ?? 'CONVIDAR JOGADOR')) ?>"><?= icon('add') ?></a>
        </div>
<?php endif; ?>
      </div>
    </section>

    <section class="panel jogar-challenges panel--grow" data-boot>
      <header class="panel__head">
        <h2 class="panel__title"><?= e((string)($data['challenges_title'] ?? 'DESAFIOS DIÁRIOS')) ?></h2>
        <span class="panel__meta"><?= e((string)($data['challenges_reset'] ?? 'REINICIA EM:')) ?> <span class="num" data-countdown="<?= e($resetIn) ?>"><?= e($resetIn) ?></span></span>
      </header>
      <div class="panel__body">
        <ul class="chal-list">
<?php foreach ($challenges as $ch): ?>
<?php
  $cur = (int)($ch['cur'] ?? 0);
  $max = max(1, (int)($ch['max'] ?? 1));
  $xp  = (int)($ch['xp'] ?? 0);
  $hot = $xp >= 5000;
?>
          <li class="chal">
            <div class="chal__main">
              <div class="chal__head">
                <span class="chal__label"><?= e((string)($ch['label'] ?? '')) ?></span>
                <span class="chal__count num"><?= e(num($cur)) ?> / <?= e(num($max)) ?></span>
              </div>
              <?php component('progress', ['value' => $cur, 'max' => $max, 'size' => 'thin', 'anim' => true, 'flat' => true, 'class' => 'chal__bar']) ?>
            </div>
            <span class="xp-chip<?= $hot ? ' xp-chip--purple' : '' ?>">
              <span class="xp-chip__badge">XP</span>
              <span class="xp-chip__val num"><?= e((string)$xp) ?></span>
            </span>
          </li>
<?php endforeach; ?>
        </ul>
      </div>
      <footer class="panel__foot">
        <a class="panel__action panel__action--lime chal-all" href="<?= e(url('desafios')) ?>"><?= e((string)($data['challenges_action'] ?? 'VER TODOS OS DESAFIOS')) ?></a>
      </footer>
    </section>

    <section class="panel jogar-reward" data-boot>
      <div class="panel__body">
        <h2 class="panel__section reward__title"><?= e((string)($reward['title'] ?? 'RECOMPENSA DIÁRIA')) ?></h2>
        <p class="reward__next"><?= e((string)($reward['next_label'] ?? '')) ?> <span class="num lime" data-countdown="<?= e((string)($reward['next_in'] ?? '')) ?>"><?= e((string)($reward['next_in'] ?? '')) ?></span></p>
        <div class="reward__item">
          <span class="reward__thumb"><img src="<?= e(media((string)($reward['image'] ?? 'items/supply-crate.png'))) ?>" alt="<?= e((string)($reward['name'] ?? '')) ?>"></span>
          <span class="reward__text">
            <strong><?= e((string)($reward['name'] ?? '')) ?></strong>
            <small><?= e((string)($reward['desc'] ?? '')) ?></small>
          </span>
        </div>
        <?php component('btn', ['label' => (string)($reward['cta'] ?? 'RESGATAR RECOMPENSA'), 'type' => 'button', 'variant' => 'lime', 'block' => true, 'class' => 'reward__btn', 'attrs' => ['data-claim' => '']]) ?>
      </div>
    </section>
  </div>
</div>
