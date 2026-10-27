<?php declare(strict_types=1);

/**
 * Tela ADMIN · EDITAR MAPA (/admin/mapas/editor).
 *
 * Escopo: $page, $player, $nav, $server, $data, $flash, $csrf. Layout: layouts/admin.php.
 *
 * TRÊS DECISÕES DE DESENHO
 *
 * 1. TRÊS COLUNAS, e a do meio é a arte. Paleta à esquerda (o que se pode pôr), mapa no
 *    centro (onde se põe), detalhes à direita (o que aquele ponto é). A ordem acompanha o
 *    gesto: escolher, posicionar, ajustar.
 *
 * 2. O MAPA É UMA CAIXA ROLÁVEL, e o zoom muda a LARGURA do palco, não uma transformação.
 *    Com transform, arrastar exigiria reimplementar o deslocamento, os limites e a inércia —
 *    e os marcadores, que estão em %, precisariam de contas inversas a cada gesto. Mudando a
 *    largura, quem desloca é a barra de rolagem do navegador, que já sabe fazer isso melhor
 *    do que qualquer laço de pointermove escrito aqui.
 *
 * 3. O ESTADO VIVE NO JAVASCRIPT, e o servidor recebe o conjunto inteiro num campo só. A
 *    alternativa — um POST por marcador movido — encheria o log de auditoria de uma linha por
 *    arrasto e deixaria o mapa num estado intermediário se a rede caísse no meio da sessão
 *    de edição. Aqui ou salva tudo, ou não salva nada, e o operador sabe quando isso acontece.
 *
 * SEM JAVASCRIPT esta tela não arrasta — e diz isso, apontando para a grade de coordenadas do
 * cadastro do mapa, que continua funcionando. É a única tela do painel que depende de script,
 * porque a tarefa dela é literalmente um gesto.
 */

$cromo   = (array)($data['cromo'] ?? []);
$ajuda   = (array)($cromo['ajuda'] ?? []);
$grupos  = (array)($cromo['grupos'] ?? []);
$rotulos = (array)($cromo['rotulos'] ?? []);
$vazios  = (array)($cromo['vazios'] ?? []);
$notas   = (array)($cromo['notas'] ?? []);

$mapa    = (array)($data['mapa'] ?? []);
$mapas   = (array)($data['mapas'] ?? []);
$arte    = (string)($data['arte'] ?? '');
$paleta  = (array)($data['paleta'] ?? []);
$missoes = (array)($data['missoes'] ?? []);
$marcs   = (array)($data['marcadores'] ?? []);
$cores   = (array)($data['cores'] ?? []);
$kinds   = (array)($data['kinds'] ?? []);
$mapaId  = (int)($mapa['id'] ?? 0);

/* Catálogo achatado e indexado pelo slug: é assim que o editor procura o desenho de um ponto
   quando ele é arrastado. Um array_merge dos grupos daria uma LISTA, e o JavaScript teria de
   varrê-la a cada arrasto. */
$tiposPorSlug = [];
foreach ($paleta as $g) {
    foreach ((array)($g['items'] ?? []) as $t) { $tiposPorSlug[(string)$t['slug']] = $t; }
}

/* O ponto "objetivo de missão" é o molde de tudo que for arrastado da gaveta de missões:
   é ele que diz qual kind gravar e qual desenho usar. Achá-lo aqui evita repetir a regra
   no JavaScript. */
$tipoMissao = 'mission-objective';
foreach ($paleta as $g) {
    foreach ($g['items'] as $t) {
        if ((string)$t['kind'] === 'mission') { $tipoMissao = (string)$t['slug']; break 2; }
    }
}
?>

<?php /* Tudo que o editor precisa saber, num bloco de dados só. JSON num <script type=
         "application/json"> em vez de atributos data-*: são listas aninhadas, e serializá-las
         em atributo significaria escapar HTML dentro de escape de atributo dentro de JSON. */ ?>
<script type="application/json" data-mape-dados><?= json_encode([
    'mapaId'     => $mapaId,
    'marcadores' => $marcs,
    'glifos'     => (array)($data['glifos'] ?? []),
    'tipos'      => $tiposPorSlug,
    'tipoMissao' => $tipoMissao,
    'rotulos'    => $rotulos,
    'max'        => (int)($data['max'] ?? 400),
    'opacidade'  => (float)($data['opacidade'] ?? .65),
    'media'      => \App\Core\Config::baseUrl() . 'media/',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>

<noscript>
  <p class="adm-hint adm-hint--err mape-nojs"><?= e((string)($notas['semjs'] ?? '')) ?></p>
</noscript>

<section class="adm-card mape" data-mape>

  <!-- ============================== BARRA DE FERRAMENTAS ============================== -->
  <div class="adm-card__head mape__bar">
    <form class="mape__pick" method="get" action="<?= e(url('admin/mapas/editor')) ?>">
      <label class="adm-field adm-field--inline">
        <span class="adm-label">Mapa</span>
        <select class="adm-select" name="mapa" data-mape-mapa>
          <?php foreach ($mapas as $m): ?>
            <option value="<?= (int)$m['id'] ?>"<?= (int)$m['id'] === $mapaId ? ' selected' : '' ?>>
              <?= e((string)$m['name']) ?> · <?= (int)$m['marcadores'] ?> ponto(s)
            </option>
          <?php endforeach ?>
        </select>
      </label>
      <button class="adm-btn adm-btn--sm" type="submit">Abrir</button>
    </form>

    <div class="mape__tools">
      <div class="mape__zoom" role="group" aria-label="Zoom do mapa">
        <button class="adm-btn adm-btn--icon adm-btn--sm" type="button" data-mape-zoom="-" aria-label="Diminuir zoom"><?= icon('remove') ?></button>
        <span class="mape__zoom-v num" data-mape-zoom-v>100%</span>
        <button class="adm-btn adm-btn--icon adm-btn--sm" type="button" data-mape-zoom="+" aria-label="Aumentar zoom"><?= icon('add') ?></button>
        <button class="adm-btn adm-btn--icon adm-btn--sm" type="button" data-mape-zoom="fit" aria-label="Ajustar à tela"><?= icon('fit_screen') ?></button>
      </div>

      <?php /* Opacidade da ARTE, não dos marcadores: é o terreno que recua para o texto da
               HUD ser lido por cima dele. O operador regula olhando para o resultado, com os
               pontos no lugar — por isso o controle mora aqui, e não no cadastro do mapa. */ ?>
      <label class="mape__op" title="Opacidade da arte no mapa do jogador">
        <?= icon('opacity', 'mape__op-ic') ?>
        <input type="range" min="15" max="100" step="1"
               value="<?= (int)round((float)($data['opacidade'] ?? .65) * 100) ?>" data-mape-opacidade>
        <span class="mape__op-v num" data-mape-opacidade-v><?= (int)round((float)($data['opacidade'] ?? .65) * 100) ?>%</span>
      </label>

      <label class="adm-check mape__check">
        <?php /* desligado por padrão: 43 rótulos ao mesmo tempo escondem a própria ilha.
                 Passar o mouse num marcador já revela o nome dele. */ ?>
        <input type="checkbox" data-mape-nomes>
        <span>Nomes</span>
      </label>

      <button class="adm-btn adm-btn--ghost adm-btn--sm" type="button" data-mape-desfazer disabled>
        <?= icon('undo') ?><span>Desfazer</span>
      </button>

      <span class="mape__estado" data-mape-estado role="status" aria-live="polite"></span>

      <form method="post" action="<?= e(url('admin/mapas/editor/salvar')) ?>" data-mape-form>
        <?= csrf_field() ?>
        <input type="hidden" name="mapa_id" value="<?= $mapaId ?>">
        <?php /* preenchido pelo JS no submit — é o conjunto inteiro de marcadores */ ?>
        <input type="hidden" name="marcadores" value="[]" data-mape-payload>
        <input type="hidden" name="opacidade" value="<?= e((string)round((float)($data['opacidade'] ?? .65), 2)) ?>" data-mape-op-payload>
        <button class="adm-btn adm-btn--primary adm-btn--sm" type="submit" data-mape-salvar>
          <?= icon('save') ?><span>Salvar mapa</span>
        </button>
      </form>
    </div>
  </div>

  <div class="mape__body">

    <!-- ================================== PALETA ================================== -->
    <aside class="mape__palette" aria-label="Paleta de pontos">
      <label class="adm-search mape__search">
        <?= icon('search') ?>
        <input class="adm-input" type="search" placeholder="Buscar na paleta" data-mape-busca>
      </label>

      <?php /* Missões primeiro: é a gaveta que o operador abre para fixar o que ele acabou de
               cadastrar em /admin/missoes. */ ?>
      <details class="mape__group" open>
        <summary class="mape__group-t">
          <?= e((string)($grupos['missoes'] ?? 'Missões')) ?>
          <span class="mape__group-n num"><?= count($missoes) ?></span>
        </summary>
        <div class="mape__items">
          <?php if ($missoes === []): ?>
            <p class="adm-hint mape__empty"><?= e((string)($vazios['missoes'] ?? '')) ?></p>
          <?php endif ?>
          <?php foreach ($missoes as $ms): ?>
          <button class="mape__chip" type="button" draggable="true"
                  data-mape-chip data-tipo="<?= e($tipoMissao) ?>" data-missao="<?= (int)$ms['id'] ?>"
                  data-nome="<?= e((string)$ms['name']) ?>"
                  data-busca="<?= e(mb_strtolower((string)$ms['name'] . ' ' . $ms['region'] . ' ' . $ms['giver'])) ?>"
                  title="<?= e((string)($cromo['missao_dica'] ?? '')) ?>">
            <?php component('map-point-icon', ['icon' => 'glyph:mission', 'color' => 'gold', 'size' => 'sm']) ?>
            <span class="mape__chip-txt">
              <strong><?= e((string)$ms['name']) ?></strong>
              <small><?= e((string)($ms['region'] ?: $ms['giver'] ?: $ms['type'])) ?></small>
            </span>
          </button>
          <?php endforeach ?>
        </div>
      </details>

      <?php foreach ($paleta as $g): ?>
      <details class="mape__group"<?= $g['key'] === 'operacional' ? ' open' : '' ?>>
        <summary class="mape__group-t">
          <?= e((string)$g['label']) ?>
          <span class="mape__group-n num"><?= count($g['items']) ?></span>
        </summary>
        <div class="mape__items">
          <?php foreach ($g['items'] as $t): ?>
          <button class="mape__chip" type="button" draggable="true"
                  data-mape-chip data-tipo="<?= e((string)$t['slug']) ?>"
                  data-nome="<?= e((string)$t['label']) ?>"
                  data-busca="<?= e(mb_strtolower((string)$t['label'] . ' ' . $t['slug'] . ' ' . $t['kind'])) ?>"
                  title="<?= e((string)($t['description'] ?? '')) ?>">
            <?php component('map-point-icon', ['icon' => (string)$t['icon'], 'color' => (string)$t['color'], 'size' => 'sm']) ?>
            <span class="mape__chip-txt">
              <strong><?= e((string)$t['label']) ?></strong>
              <small><?= e((string)$t['kind']) ?></small>
            </span>
          </button>
          <?php endforeach ?>
        </div>
      </details>
      <?php endforeach ?>

      <p class="mape__empty mape__empty--busca" data-mape-vazio hidden><?= e((string)($vazios['busca'] ?? '')) ?></p>
    </aside>

    <!-- =================================== PALCO =================================== -->
    <div class="mape__stagewrap">
      <div class="mape__stage scroll-y" data-mape-stage>
        <div class="mape__canvas" data-mape-canvas style="--mape-art-opacity:<?= e((string)round((float)($data['opacidade'] ?? .65), 2)) ?>">
          <img class="mape__art" src="<?= e(media($arte)) ?>" alt="<?= e((string)($mapa['name'] ?? '')) ?>" draggable="false" decoding="async">
          <?php /* Os marcadores são desenhados pelo JS a partir do bloco de dados acima. Um
                   mapa vazio mostra a dica no lugar deles. */ ?>
          <div class="mape__markers" data-mape-layer></div>
        </div>
      </div>
      <p class="mape__help">
        <?= icon('drag_pan', 'mape__help-ic') ?>
        <?= e((string)($ajuda['arrastar'] ?? '')) ?>
        <?= e((string)($ajuda['mover'] ?? '')) ?>
        <?= e((string)($ajuda['desfazer'] ?? '')) ?>
      </p>
    </div>

    <!-- ================================= DETALHES ================================= -->
    <aside class="mape__side" aria-label="Detalhes do marcador">

      <div class="mape__none" data-mape-vazio-sel>
        <span class="mape__none-ic"><?= icon('ads_click') ?></span>
        <p class="adm-hint"><?= e((string)($marcs === [] ? ($vazios['mapa'] ?? '') : ($ajuda['mover'] ?? ''))) ?></p>
      </div>

      <div class="mape__props" data-mape-props hidden>
        <label class="adm-field">
          <span class="adm-label">Nome</span>
          <input class="adm-input" type="text" maxlength="80" data-mape-p="name">
        </label>

        <div class="adm-form__row adm-form__row--2">
          <label class="adm-field">
            <span class="adm-label">Cor</span>
            <select class="adm-select" data-mape-p="color">
              <?php foreach ($cores as $c): ?><option value="<?= e((string)$c) ?>"><?= e(mb_strtoupper((string)$c)) ?></option><?php endforeach ?>
            </select>
          </label>
          <label class="adm-field">
            <span class="adm-label">Nome no mapa</span>
            <select class="adm-select" data-mape-p="label">
              <?php foreach ($rotulos as $k => $rot): ?><option value="<?= e((string)$k) ?>"><?= e((string)$rot) ?></option><?php endforeach ?>
            </select>
          </label>
        </div>

        <?php /* Ícone DESTE marcador. A lista é o catálogo inteiro — inclusive os pontos cujo
                 desenho é um SVG ou PNG enviado —, então trocar aqui é emprestar qualquer ícone
                 já cadastrado para um ponto específico, sem criar um tipo novo para um ponto só.
                 "Herdar" devolve o marcador ao desenho do seu tipo. */ ?>
        <label class="adm-field">
          <span class="adm-label">Ícone</span>
          <select class="adm-select" data-mape-p="icon">
            <option value="">Herdar do ponto da paleta</option>
            <?php foreach ($paleta as $g): ?>
              <optgroup label="<?= e((string)$g['label']) ?>">
                <?php foreach ($g['items'] as $t): ?>
                  <option value="<?= e((string)$t['icon']) ?>"><?= e((string)$t['label']) ?></option>
                <?php endforeach ?>
              </optgroup>
            <?php endforeach ?>
          </select>
          <span class="adm-hint">
            Para desenhar um ícone que ainda não existe, crie o ponto em
            <a href="<?= e(url('admin/mapas/pontos', ['novo' => '1'])) ?>">Criar pontos</a> — lá se envia SVG, PNG ou WebP.
          </span>
        </label>

        <label class="adm-field">
          <span class="adm-label">Comportamento no jogo</span>
          <select class="adm-select" data-mape-p="kind">
            <?php foreach ($kinds as $k => $rot): ?><option value="<?= e((string)$k) ?>"><?= e((string)$rot) ?></option><?php endforeach ?>
          </select>
          <span class="adm-hint">Vem do ponto da paleta. Mudar aqui vale só para este marcador.</span>
        </label>

        <div class="adm-form__row adm-form__row--3">
          <label class="adm-field">
            <span class="adm-label">X</span>
            <input class="adm-input num" type="number" step="0.0001" min="0" max="1" data-mape-p="x">
          </label>
          <label class="adm-field">
            <span class="adm-label">Y</span>
            <input class="adm-input num" type="number" step="0.0001" min="0" max="1" data-mape-p="y">
          </label>
          <label class="adm-field">
            <span class="adm-label">Raio %</span>
            <input class="adm-input num" type="number" step="0.1" min="0" max="40" data-mape-p="radius">
          </label>
        </div>
        <p class="adm-hint"><?= e((string)($notas['raio'] ?? '')) ?></p>

        <p class="adm-hint mape__extras" data-mape-extras hidden></p>

        <div class="mape__props-foot">
          <button class="adm-btn adm-btn--ghost adm-btn--sm" type="button" data-mape-duplicar><?= icon('content_copy') ?><span>Duplicar</span></button>
          <button class="adm-btn adm-btn--danger adm-btn--sm" type="button" data-mape-remover><?= icon('delete') ?><span>Remover</span></button>
        </div>
      </div>
    </aside>

  </div>
</section>
