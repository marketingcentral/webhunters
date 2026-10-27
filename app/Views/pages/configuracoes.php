<?php declare(strict_types=1);
/**
 * Tela CONFIGURAÇÕES (referência 04-settings).
 * $data: title, tabs, active_tab, tab, groups (da aba ativa), difficulty, details.
 * Layout: [categorias] | [grupos de opções] | [painel de detalhe + ações].
 */
$tabs   = $data['tabs'] ?? [];
$aba    = (string)($data['active_tab'] ?? 'jogabilidade');
$tab    = $data['tab'] ?? null;
$groups = $data['groups'] ?? [];

$detail  = $data['detail'] ?? [];
$options = $detail['options'] ?? [];
$note    = (string)($detail['note'] ?? '');
$preset  = '';
foreach ($options as $opt) {
    if (!empty($opt['active'])) { $preset = (string)($opt['slug'] ?? ''); if ($note === '') { $note = (string)($opt['desc'] ?? ''); } }
}
$formAction = url('configuracoes/' . $aba);

/**
 * Glifo dos ladrilhos de preset: família única "explosão" que ganha pontas
 * conforme a intensidade ('burst:N' e 'burst:N:hex'); qualquer outro nome cai
 * no Material Symbols preenchido.
 */
$tileGlyph = static function (string $name): string {
    if (!preg_match('/^burst:(\d{1,2})(:hex)?$/', $name, $m)) {
        return icon($name, 'set-tile__icon fill');
    }
    $spikes = max(3, min(16, (int)$m[1]));
    $caged  = isset($m[2]);
    $r0     = $caged ? 2.8 : 3.6;
    $r1     = $caged ? 6.6 : 9.2;
    $out    = '';
    $n = static fn (float $v): string => number_format($v, 2, '.', '');
    for ($i = 0; $i < $spikes; $i++) {
        $a = (M_PI * 2 / $spikes) * $i - M_PI / 2;
        $out .= '<line x1="' . $n(12 + cos($a) * $r0) . '" y1="' . $n(12 + sin($a) * $r0)
            . '" x2="' . $n(12 + cos($a) * $r1) . '" y2="' . $n(12 + sin($a) * $r1) . '"/>';
    }
    $frame = $caged
        ? '<path d="M12 1.7 21 6.85 21 17.15 12 22.3 3 17.15 3 6.85Z"/><circle cx="12" cy="12" r="1.7"/>'
        : '<circle cx="12" cy="12" r="2.3"/>';
    return '<svg class="ic set-tile__icon set-tile__burst" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . $frame . $out . '</svg>';
};
?>
<form class="set" method="post" action="<?= e($formAction) ?>" data-settings-tab="<?= e($aba) ?>">
  <?php /* Botão padrão do form: o Enter num campo de texto dispara o PRIMEIRO submit do DOM.
           Sem isto o Enter caía no RESTAURAR PADRÕES e apagava as preferências gravadas. */ ?>
  <button type="submit" name="action" value="apply" class="set-default-submit" tabindex="-1" aria-hidden="true"></button>
    <?= csrf_field() ?>

    <!-- ============================ categorias ============================ -->
    <div class="set__left">
        <h1 class="page-title set__title" data-boot><?= e((string)($data['title'] ?? 'CONFIGURAÇÕES')) ?></h1>
        <nav class="set-nav" aria-label="Categorias de configuração" data-stagger>
            <?php foreach ($tabs as $item): ?>
                <?php component('row', [
                    'title'  => (string)$item['label'],
                    'sub'    => (string)$item['desc'],
                    'icon'   => (string)$item['icon'],
                    'href'   => url('configuracoes/' . $item['slug']),
                    'active' => $item['slug'] === $aba,
                    'class'  => 'set-nav__row',
                ]) ?>
            <?php endforeach; ?>
            <span class="set-nav__filler" aria-hidden="true"></span>
        </nav>
    </div>

    <!-- ============================== opções ============================== -->
    <div class="set__mid">
        <h2 class="set__mid-title" data-boot><?= e((string)($tab['label'] ?? '')) ?></h2>
        <section class="panel set-panel">
            <div class="panel__body set-panel__body">
                <div class="scroll-y set-scroll">
                    <?php foreach ($groups as $group): ?>
                        <div class="set-group">
                            <h3 class="set-group__title"><?= e((string)$group['title']) ?></h3>
                            <div class="set-list">
                                <?php foreach (($group['items'] ?? []) as $it): ?>
                                    <?php
                                    $key  = (string)$it['key'];
                                    $type = (string)$it['type'];
                                    ?>
                                    <div class="row row--setting set-row<?= !empty($it['active']) ? ' is-active' : '' ?>">
                                        <span class="row__label"><?= e((string)$it['label']) ?></span>
                                        <span class="row__ctrl">
                                            <?php if ($type === 'toggle'): ?>
                                                <?php component('toggle', ['name' => $key, 'checked' => !empty($it['value'])]) ?>
                                            <?php elseif ($type === 'stepper' || $type === 'select'): ?>
                                                <?php
                                                // A caixa do stepper tem largura fixa: encolhe a fonte
                                                // conforme a opção mais longa para nada ser cortado.
                                                $longest = 0;
                                                foreach ((array)($it['options'] ?? []) as $o) { $longest = max($longest, mb_strlen((string)$o, 'UTF-8')); }
                                                $fit = $longest > 17 ? ' set-stepper--xs' : ($longest > 12 ? ' set-stepper--sm' : '');
                                                ?>
                                                <?php component('stepper', [
                                                    'options' => (array)($it['options'] ?? []),
                                                    'value'   => (string)$it['value'],
                                                    'name'    => $key,
                                                    'class'   => trim($fit),
                                                ]) ?>
                                            <?php elseif ($type === 'range'): ?>
                                                <?php component('range', [
                                                    'name'   => $key,
                                                    'value'  => (float)$it['value'],
                                                    'suffix' => (string)($it['unit'] ?? '%'),
                                                    'max'    => (int)($it['max'] ?? (($it['unit'] ?? '%') === '' ? 120 : 100)),
                                                    'label'  => (string)$it['label'],
                                                ]) ?>
                                            <?php elseif ($type === 'key'): ?>
                                                <button type="button" class="keycap" data-keybind
                                                        aria-label="<?= e('Reatribuir tecla: ' . (string)$it['label']) ?>"><?= e((string)$it['value']) ?></button>
                                                <input type="hidden" name="<?= e($key) ?>" value="<?= e((string)$it['value']) ?>">
                                            <?php else: ?>
                                                <input class="input set-input" type="text" name="<?= e($key) ?>" value="<?= e((string)$it['value']) ?>" aria-label="<?= e((string)$it['label']) ?>">
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </div>

    <!-- ============================= detalhe ============================== -->
    <aside class="panel set-detail">
        <div class="panel__body set-detail__body">
            <h2 class="set-detail__title"><?= e((string)($detail['title'] ?? '')) ?></h2>
            <p class="set-detail__desc"><?= e((string)($detail['desc'] ?? '')) ?></p>

            <?php if (!empty($detail['image'])): ?>
                <figure class="set-detail__media">
                    <img src="<?= e(media((string)$detail['image'])) ?>" alt="" class="set-detail__img"<?= !empty($detail['image_pos']) ? ' style="object-position:' . e((string)$detail['image_pos']) . '"' : '' ?>>
                </figure>
            <?php endif; ?>

            <?php if ($options): ?>
                <div class="set-tiles" data-select-group data-select-input="#set-preset">
                    <?php foreach ($options as $opt): ?>
                        <button type="button" class="btn set-tile<?= !empty($opt['active']) ? ' is-active' : '' ?>"
                                data-select data-value="<?= e((string)$opt['slug']) ?>"
                                data-note="<?= e((string)($opt['desc'] ?? '')) ?>"
                                aria-pressed="<?= !empty($opt['active']) ? 'true' : 'false' ?>">
                            <?= $tileGlyph((string)$opt['icon']) ?>
                            <span class="set-tile__label"><?= e((string)$opt['label']) ?></span>
                        </button>
                    <?php endforeach; ?>
                    <input type="hidden" id="set-preset" name="set_preset" value="<?= e($preset) ?>">
                </div>
                <p class="set-detail__note" data-note-target aria-live="polite"><?= e($note) ?></p>
            <?php endif; ?>

            <?php if (!empty($detail['stats'])): ?>
                <div class="set-stats">
                    <?php foreach ($detail['stats'] as $st): ?>
                        <div class="set-stat">
                            <span class="ms set-stat__icon" aria-hidden="true"><?= e((string)$st['icon']) ?></span>
                            <span class="set-stat__label"><?= e((string)$st['label']) ?></span>
                            <span class="set-stat__val"><?= e((string)$st['value']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="set-actions">
                <button class="btn set-btn set-btn--reset" type="submit" name="action" value="reset">
                    <?= icon('restart_alt') ?><span class="btn__label"><?= e((string)($detail['reset'] ?? 'RESTAURAR PADRÕES')) ?></span>
                </button>
                <button class="btn btn--primary set-btn set-btn--apply" type="submit" name="action" value="apply">
                    <span class="btn__label"><?= e((string)($detail['apply'] ?? 'APLICAR ALTERAÇÕES')) ?></span>
                </button>
            </div>
        </div>
    </aside>
</form>
