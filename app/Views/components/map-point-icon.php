<?php declare(strict_types=1);

/**
 * Ícone de um PONTO DO MAPA, venha ele de onde vier.
 *
 * props:
 *   icon   string  valor cru de `map_point_types.icon` — "glyph:fuel" | "symbol:local_cafe" | "image:uploads/mapas/x.webp"
 *   color  string  cor do mapa (white|lime|cyan|amber|gold|red|purple|muted)
 *   size   string  sm | md | lg
 *   class  string  classes extras
 *
 * POR QUE UM COMPONENTE, E NÃO TRÊS TRECHOS DE VIEW
 * O mesmo ícone é desenhado em cinco lugares: a ficha do catálogo, a pré-visualização do
 * formulário, a paleta do editor, o marcador dentro do editor e o mapa do jogador. Se cada
 * um resolvesse o prefixo por conta própria, bastaria um deles esquecer o caso `image` para
 * uma tela mostrar um quadrado vazio onde a outra mostra a arte — e ninguém descobriria até
 * alguém cadastrar um ponto com arte enviada.
 *
 * As três origens ocupam a MESMA caixa de propósito: o que faz uma paleta legível é todo
 * ícone ter o mesmo peso, não cada um ter o seu tamanho natural.
 */

$icon  = (string)($icon ?? 'symbol:place');
$cor   = preg_replace('/[^a-z]/', '', strtolower((string)($color ?? 'white'))) ?: 'white';
$size  = in_array(($size ?? 'md'), ['sm', 'md', 'lg'], true) ? (string)$size : 'md';
$extra = trim((string)($class ?? ''));

[$origem, $valor] = \App\Repo\MapPointsRepo::icone($icon);

$cls = 'mpt-ic mpt-ic--' . $size . ' mpt-ic--' . $cor . ($extra !== '' ? ' ' . $extra : '');
?>
<span class="<?= e($cls) ?>" data-icon="<?= e($icon) ?>">
<?php if ($origem === 'image'): ?>
  <img src="<?= e(media($valor)) ?>" alt="" loading="lazy" decoding="async">
<?php elseif ($origem === 'glyph'): ?>
  <?php $g = (array)data_mock('map_glyphs'); ?>
  <?php if (isset($g[$valor])): ?>
    <?php /* conteúdo confiável: a tabela é do próprio projeto, nunca de requisição */ ?>
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="currentColor"><?= $g[$valor] ?></svg>
  <?php else: ?>
    <?php /* vetor removido da tabela depois que alguém já o escolheu: mostra a falta em vez
             de desenhar nada, senão o ponto sumiria da paleta sem explicação */ ?>
    <?= icon('help', 'mpt-ic__faltando') ?>
  <?php endif ?>
<?php else: ?>
  <?= icon($valor) ?>
<?php endif ?>
</span>
