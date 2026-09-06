<?php declare(strict_types=1);
/**
 * HUD IN-GAME — overlay transparente.
 *
 * Por enquanto só o bloco de armas no canto inferior direito: bandoleira e coldre, cada
 * uma com a munição do carregador instalado e a reserva do mesmo calibre.
 *
 * O servidor pinta o estado inicial (vindo do inventário no banco) e
 * assets/js/pages/hud.js atualiza em tempo real com o que a Unreal manda.
 *
 * $data: guns[] (slot, label, name, image, caliber, current, capacity, has_mag, reserve)
 */
$guns = (array)($data['guns'] ?? []);
?>
<div class="ov" data-hud
     data-endpoint-ammo="<?= e(url('hud/municao')) ?>"
     data-endpoint-data="<?= e(url('hud/dados')) ?>">

  <section class="ov-guns" aria-label="Armamento">
<?php if ($guns === []): ?>
    <p class="ov-guns__empty ov-shadow">Nenhuma arma equipada.</p>
<?php endif; ?>
<?php foreach ($guns as $g): ?>
<?php
  $cur = (int)($g['current'] ?? 0);
  $cap = max(1, (int)($g['capacity'] ?? 1));
  $pct = (int)round($cur / $cap * 100);
?>
    <article class="ov-gun<?= empty($g['has_mag']) ? ' is-empty' : '' ?>"
             data-gun="<?= e((string)$g['slot']) ?>"
             data-capacity="<?= e((string)($g['capacity'] ?? 0)) ?>">
      <div class="ov-gun__info">
        <span class="ov-gun__slot ov-shadow"><?= e((string)$g['label']) ?></span>
        <strong class="ov-gun__name ov-shadow"><?= e((string)$g['name']) ?></strong>
      </div>

<?php if (!empty($g['image'])): ?>
      <img class="ov-gun__art" src="<?= e(media((string)$g['image'])) ?>" alt="" draggable="false">
<?php endif; ?>

      <div class="ov-gun__ammo">
<?php if (!empty($g['has_mag'])): ?>
        <span class="ov-gun__cur num ov-shadow" data-ammo-cur><?= e(str_pad((string)$cur, 3, '0', STR_PAD_LEFT)) ?></span>
        <span class="ov-gun__sep" aria-hidden="true">/</span>
        <span class="ov-gun__res num ov-shadow" data-ammo-res><?= e(str_pad((string)(int)($g['reserve'] ?? 0), 3, '0', STR_PAD_LEFT)) ?></span>
<?php else: ?>
        <span class="ov-gun__nomag ov-shadow">SEM CARREGADOR</span>
<?php endif; ?>
      </div>

      <span class="ov-gun__bar" aria-hidden="true"><i style="--v:<?= e((string)$pct) ?>%"></i></span>
      <span class="ov-gun__cal ov-shadow"><?= e((string)($g['caliber'] ?? '')) ?></span>
    </article>
<?php endforeach; ?>
  </section>
</div>
