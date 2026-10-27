<?php declare(strict_types=1);

/**
 * Tela ADMIN · CRIAR PONTOS NO MAPA (/admin/mapas/pontos).
 *
 * Escopo: $page, $player, $nav, $server, $data, $flash, $csrf. Layout: layouts/admin.php.
 *
 * A view não consulta o banco e não valida nada — tudo chega pronto de
 * App\Controllers\Admin\MapPointsController. O que se decide aqui é APRESENTAÇÃO.
 *
 * TRÊS DECISÕES DE DESENHO
 *
 * 1. O catálogo é uma GRADE DE FICHAS, não uma tabela. O dado mais importante de um ponto é
 *    o desenho dele: numa tabela o ícone viraria uma coluna de 20px ao lado de texto, e
 *    escolher entre "Café" e "Lanchonete" exigiria ler em vez de olhar. Ficha grande mostra o
 *    ícone no tamanho em que ele será reconhecido.
 *
 * 2. O formulário tem PRÉ-VISUALIZAÇÃO ao vivo. Trocar a origem do ícone muda o campo abaixo
 *    e o desenho ao lado na mesma hora (assets/js/admin/admin-mapas-pontos.js). Sem
 *    JavaScript os três campos aparecem juntos e o preview mostra o que está gravado — a
 *    tela continua utilizável, só menos confortável.
 *
 * 3. Excluir é uma VIAGEM (?excluir=N), não um confirm(). A CSP proíbe script inline, e a
 *    consequência precisa estar escrita: quantos marcadores do mundo passam a ser desenhados
 *    pelo tipo bruto.
 */

$cromo   = (array)($data['cromo'] ?? []);
$origens = (array)($cromo['origens'] ?? []);
$glifos  = (array)($cromo['glifos'] ?? []);
$simbolos= (array)($cromo['simbolos'] ?? []);
$vazios  = (array)($cromo['vazios'] ?? []);
$notas   = (array)($cromo['notas'] ?? []);

$modo    = (string)($data['modo'] ?? 'lista');
$grupos  = (array)($data['grupos'] ?? []);
$cats    = (array)($data['categorias'] ?? []);
$cores   = (array)($data['cores'] ?? []);
$kinds   = (array)($data['kinds'] ?? []);
$f       = (array)($data['filtros'] ?? []);
$form    = (array)($data['form'] ?? []);
$erros   = (array)($data['erros'] ?? []);
$alvo    = is_array($data['alvo'] ?? null) ? $data['alvo'] : null;
$excluir = is_array($data['excluir'] ?? null) ? $data['excluir'] : null;
$sistema = $alvo !== null && !empty($alvo['is_system']);

$lnk = static function (array $extra = []) use ($f): string {
    $q = $extra + ['q' => (string)($f['q'] ?? ''), 'cat' => (string)($f['cat'] ?? '')];
    return url('admin/mapas/pontos', array_filter($q, static fn($v): bool => (string)$v !== ''));
};
?>

<?php if ($excluir !== null): ?>
<!-- ============================ CONFIRMAÇÃO DE EXCLUSÃO ============================ -->
<section class="adm-card adm-card--danger">
  <div class="adm-card__head">
    <h2 class="adm-card__title"><?= icon('delete', 'adm-card__ico') ?> Excluir "<?= e((string)$excluir['ponto']['label']) ?>"?</h2>
  </div>
  <div class="adm-card__body">
    <p class="adm-hint">
      <?= e(sprintf((string)($notas['excluir'] ?? '%s'), (string)$excluir['ponto']['kind'])) ?>
    </p>
    <p class="adm-hint">
      <?php if ((int)$excluir['usos'] > 0): ?>
        <strong class="num"><?= (int)$excluir['usos'] ?></strong> marcador(es) no mundo usam este ponto hoje.
      <?php else: ?>
        Nenhum marcador usa este ponto — a exclusão não muda nada no mapa.
      <?php endif ?>
    </p>
  </div>
  <div class="adm-card__foot">
    <a class="adm-btn adm-btn--ghost" href="<?= e($lnk()) ?>">Cancelar</a>
    <form method="post" action="<?= e(url('admin/mapas/pontos/excluir')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$excluir['ponto']['id'] ?>">
      <button class="adm-btn adm-btn--danger" type="submit"><?= icon('delete') ?> Excluir ponto</button>
    </form>
  </div>
</section>

<?php elseif ($modo === 'editor'): ?>
<!-- ================================ FORMULÁRIO ================================ -->
<?php /* Os vetores do mapa, para o preview desenhar sem consultar o servidor a cada tecla.
         Só na página do formulário — a listagem não troca ícone nenhum e não paga por isto. */ ?>
<script type="application/json" data-mpt-glifos><?= json_encode((array)data_mock('map_glyphs'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>

<form class="adm-card mpt-form" method="post" action="<?= e(url('admin/mapas/pontos/salvar')) ?>"
      data-mpt-form data-mpt-media="<?= e(\App\Core\Config::baseUrl() . 'media/') ?>">
  <?= csrf_field() ?>
  <?php if ($alvo !== null): ?><input type="hidden" name="id" value="<?= (int)$alvo['id'] ?>"><?php endif ?>
  <?php if ($alvo === null): ?><input type="hidden" name="novo" value="1"><?php endif ?>

  <div class="adm-card__head">
    <h2 class="adm-card__title">
      <?= icon('add_location_alt', 'adm-card__ico') ?>
      <?= $alvo !== null ? 'Editar ponto' : 'Novo ponto' ?>
    </h2>
    <?php if ($sistema): ?>
      <span class="adm-badge adm-badge--muted" title="<?= e((string)($notas['sistema'] ?? '')) ?>">Ponto do jogo</span>
    <?php endif ?>
  </div>

  <div class="adm-card__body mpt-form__body">

    <!-- coluna: pré-visualização -->
    <aside class="mpt-preview">
      <span class="mpt-preview__box" data-mpt-preview>
        <?php component('map-point-icon', [
            'icon'  => (string)($form['icon_origem'] ?? 'symbol') . ':' . (string)($form['icon_valor'] ?? 'place'),
            'color' => (string)($form['color'] ?? 'white'),
            'size'  => 'lg',
        ]) ?>
      </span>
      <strong class="mpt-preview__name" data-mpt-preview-name><?= e((string)($form['label'] ?? '') ?: 'SEM NOME') ?></strong>
      <span class="mpt-preview__hint">É assim que o ponto aparece na paleta e no mapa.</span>
    </aside>

    <!-- coluna: campos -->
    <div class="mpt-fields">

      <div class="adm-form__row adm-form__row--2">
        <label class="adm-field">
          <span class="adm-label">Nome<span class="adm-label__req">*</span></span>
          <input class="adm-input<?= isset($erros['label']) ? ' is-invalid' : '' ?>" type="text" name="label"
                 value="<?= e((string)($form['label'] ?? '')) ?>" maxlength="60" required data-mpt-label>
          <?php if (isset($erros['label'])): ?><span class="adm-hint adm-hint--err"><?= e($erros['label']) ?></span><?php endif ?>
        </label>
        <label class="adm-field">
          <span class="adm-label">Chave</span>
          <input class="adm-input<?= isset($erros['slug']) ? ' is-invalid' : '' ?>" type="text" name="slug"
                 value="<?= e((string)($form['slug'] ?? '')) ?>" maxlength="48"
                 placeholder="gerada a partir do nome"<?= $sistema ? ' readonly' : '' ?>>
          <span class="adm-hint<?= isset($erros['slug']) ? ' adm-hint--err' : '' ?>">
            <?= isset($erros['slug']) ? e($erros['slug']) : 'É o que os marcadores guardam. Deixe em branco para gerar.' ?>
          </span>
        </label>
      </div>

      <div class="adm-form__row adm-form__row--2">
        <label class="adm-field">
          <span class="adm-label">Categoria<span class="adm-label__req">*</span></span>
          <select class="adm-select<?= isset($erros['category']) ? ' is-invalid' : '' ?>" name="category">
            <?php foreach ($cats as $k => $rot): ?>
              <option value="<?= e((string)$k) ?>"<?= (string)($form['category'] ?? '') === (string)$k ? ' selected' : '' ?>><?= e((string)$rot) ?></option>
            <?php endforeach ?>
          </select>
          <span class="adm-hint<?= isset($erros['category']) ? ' adm-hint--err' : '' ?>">
            <?= isset($erros['category']) ? e($erros['category']) : 'A gaveta em que este ponto aparece na paleta do editor.' ?>
          </span>
        </label>
        <label class="adm-field">
          <span class="adm-label">Comportamento no jogo<span class="adm-label__req">*</span></span>
          <select class="adm-select<?= isset($erros['kind']) ? ' is-invalid' : '' ?>" name="kind">
            <?php foreach ($kinds as $k => $rot): ?>
              <option value="<?= e((string)$k) ?>"<?= (string)($form['kind'] ?? '') === (string)$k ? ' selected' : '' ?>><?= e((string)$rot) ?></option>
            <?php endforeach ?>
          </select>
          <span class="adm-hint<?= isset($erros['kind']) ? ' adm-hint--err' : '' ?>">
            <?= isset($erros['kind']) ? e($erros['kind']) : 'Decide a camada do filtro e as regras (extração bloqueada, base que pulsa…).' ?>
          </span>
        </label>
      </div>

      <!-- ícone: origem + valor. O JS troca o campo da direita conforme a origem; sem JS os
           três aparecem e o operador preenche o que a origem escolhida usa. -->
      <div class="adm-form__row adm-form__row--2">
        <label class="adm-field">
          <span class="adm-label">Origem do ícone</span>
          <select class="adm-select" name="icon_origem" data-mpt-origem>
            <?php foreach ($origens as $k => $o): ?>
              <option value="<?= e((string)$k) ?>"<?= (string)($form['icon_origem'] ?? '') === (string)$k ? ' selected' : '' ?>><?= e((string)$o['label']) ?></option>
            <?php endforeach ?>
          </select>
          <span class="adm-hint" data-mpt-origem-hint><?= e((string)($origens[(string)($form['icon_origem'] ?? 'symbol')]['hint'] ?? '')) ?></span>
        </label>

        <div class="adm-field mpt-icon">
          <span class="adm-label">Ícone<span class="adm-label__req">*</span></span>

          <?php /* vetor do jogo */ ?>
          <select class="adm-select mpt-icon__campo" name="icon_glyph" data-mpt-campo="glyph">
            <?php foreach ($glifos as $k => $rot): ?>
              <option value="<?= e((string)$k) ?>"<?= (string)($form['icon_valor'] ?? '') === (string)$k ? ' selected' : '' ?>><?= e((string)$rot) ?></option>
            <?php endforeach ?>
          </select>

          <?php /* Material Symbol: texto livre com sugestões — a fonte tem centenas de nomes
                   e uma lista fechada obrigaria a mexer no código a cada ícone novo */ ?>
          <input class="adm-input mpt-icon__campo" type="text" list="mpt-simbolos" placeholder="ex.: local_cafe"
                 name="icon_symbol" data-mpt-campo="symbol"
                 value="<?= (string)($form['icon_origem'] ?? '') === 'symbol' ? e((string)($form['icon_valor'] ?? '')) : '' ?>">
          <datalist id="mpt-simbolos">
            <?php foreach ($simbolos as $s): ?><option value="<?= e((string)$s) ?>"></option><?php endforeach ?>
          </datalist>

          <?php /* imagem: o MESMO componente de upload das outras telas do painel — campo de
                   texto com prévia e botão de envio para media/uploads/mapas/. Reaproveitar
                   evita um segundo jeito de subir arquivo, com outra validação e outro erro. */ ?>
          <div class="mpt-icon__campo" data-mpt-campo="image">
            <?php component('admin-upload', [
                'id'       => 'mpt-icon-image',
                'name'     => 'icon_image',
                'entidade' => 'mapas',
                'mono'     => true,
                'valor'    => (string)($form['icon_origem'] ?? '') === 'image' ? (string)($form['icon_valor'] ?? '') : '',
                'placeholder' => 'uploads/mapas/arquivo.webp',
            ]) ?>
          </div>

          <?php /* Os TRÊS campos têm nome próprio e o servidor lê o que corresponde à origem
                   escolhida. É o que mantém a tela inteira sem JavaScript: sem script os três
                   ficam visíveis e o operador preenche o da origem que selecionou; com script,
                   dois se escondem. Um campo oculto único, preenchido por JS, teria deixado
                   quem está sem script sem NENHUMA forma de trocar o ícone. */ ?>
          <span class="adm-hint<?= isset($erros['icon_valor']) ? ' adm-hint--err' : '' ?>">
            <?= isset($erros['icon_valor']) ? e($erros['icon_valor']) : 'O desenho que este ponto mostra no mapa.' ?>
          </span>
        </div>
      </div>

      <div class="adm-form__row adm-form__row--3">
        <label class="adm-field">
          <span class="adm-label">Cor</span>
          <select class="adm-select<?= isset($erros['color']) ? ' is-invalid' : '' ?>" name="color" data-mpt-cor>
            <?php foreach ($cores as $c): ?>
              <option value="<?= e((string)$c) ?>"<?= (string)($form['color'] ?? '') === (string)$c ? ' selected' : '' ?>><?= e(mb_strtoupper((string)$c)) ?></option>
            <?php endforeach ?>
          </select>
          <?php if (isset($erros['color'])): ?><span class="adm-hint adm-hint--err"><?= e($erros['color']) ?></span><?php endif ?>
        </label>
        <label class="adm-field">
          <span class="adm-label">Camada do filtro</span>
          <input class="adm-input" type="text" name="layer" maxlength="24"
                 value="<?= e((string)($form['layer'] ?? '')) ?>" placeholder="a do comportamento">
          <span class="adm-hint">Em branco: usa a camada do comportamento escolhido acima.</span>
        </label>
        <label class="adm-field">
          <span class="adm-label">Ordem na paleta</span>
          <input class="adm-input num" type="number" name="sort_order" min="0" max="9999"
                 value="<?= (int)($form['sort_order'] ?? 0) ?>">
        </label>
      </div>

      <div class="adm-form__row">
        <label class="adm-field">
          <span class="adm-label">Descrição</span>
          <textarea class="adm-textarea" name="description" rows="2" maxlength="255"
                    placeholder="Para que serve este ponto — aparece como dica na paleta."><?= e((string)($form['description'] ?? '')) ?></textarea>
        </label>
      </div>

      <div class="adm-form__row">
        <label class="adm-check">
          <input type="checkbox" name="show_label" value="1"<?= !empty($form['show_label']) ? ' checked' : '' ?>>
          <span>Mostrar o nome ao lado do ícone no mapa do jogador</span>
        </label>
      </div>

    </div>
  </div>

  <div class="adm-card__foot">
    <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/mapas/pontos')) ?>">Cancelar</a>
    <?php if ($alvo !== null && !$sistema): ?>
      <a class="adm-btn adm-btn--danger adm-btn--sm" href="<?= e($lnk(['excluir' => (string)$alvo['id']])) ?>"><?= icon('delete') ?> Excluir</a>
    <?php endif ?>
    <span class="adm-spacer"></span>
    <button class="adm-btn adm-btn--primary" type="submit"><?= icon('save') ?> Salvar ponto</button>
  </div>
</form>

<?php else: ?>
<!-- ================================ CATÁLOGO ================================ -->
<!--eh:lista-->
<section class="adm-card">
  <div class="adm-card__head">
    <h2 class="adm-card__title"><?= icon('category', 'adm-card__ico') ?> Catálogo de pontos</h2>
    <div class="adm-card__tools">
      <form class="adm-toolbar" method="get" action="<?= e(url('admin/mapas/pontos')) ?>" data-adm-preservar="filtros">
        <label class="adm-search adm-toolbar__grow">
          <?= icon('search') ?>
          <input class="adm-input" type="search" name="q" value="<?= e((string)($f['q'] ?? '')) ?>"
                 placeholder="Buscar ponto, chave ou comportamento" maxlength="60">
        </label>
        <select class="adm-select" name="cat">
          <option value="">Todas as categorias</option>
          <?php foreach ($cats as $k => $rot): ?>
            <option value="<?= e((string)$k) ?>"<?= (string)($f['cat'] ?? '') === (string)$k ? ' selected' : '' ?>><?= e((string)$rot) ?></option>
          <?php endforeach ?>
        </select>
        <noscript><button class="adm-btn adm-btn--sm" type="submit">Filtrar</button></noscript>
      </form>
    </div>
  </div>

  <div class="adm-card__body">
    <p class="adm-hint mpt-intro"><?= e((string)($cromo['intro'] ?? '')) ?></p>

    <?php if ($grupos === []): ?>
      <div class="adm-empty">
        <span class="adm-empty__ic"><?= icon('location_off') ?></span>
        <p class="adm-empty__title"><?= e((string)($vazios[!empty($data['filtrando']) ? 'filtro' : 'catalogo'] ?? '')) ?></p>
        <?php if (!empty($data['filtrando'])): ?>
          <a class="adm-btn adm-btn--ghost adm-btn--sm" href="<?= e(url('admin/mapas/pontos')) ?>">Limpar filtros</a>
        <?php endif ?>
      </div>
    <?php endif ?>

    <?php foreach ($grupos as $g): ?>
    <section class="mpt-group">
      <h3 class="mpt-group__title">
        <?= e((string)$g['label']) ?>
        <span class="mpt-group__n num"><?= count($g['items']) ?></span>
      </h3>
      <div class="mpt-grid">
        <?php foreach ($g['items'] as $t): ?>
        <a class="mpt-card<?= !empty($t['is_system']) ? ' is-system' : '' ?>"
           href="<?= e($lnk(['id' => (string)$t['id']])) ?>"
           title="<?= e((string)($t['description'] ?? '')) ?>">
          <?php component('map-point-icon', ['icon' => (string)$t['icon'], 'color' => (string)$t['color'], 'size' => 'md']) ?>
          <span class="mpt-card__txt">
            <strong class="mpt-card__name"><?= e((string)$t['label']) ?></strong>
            <small class="mpt-card__kind"><?= e((string)$t['kind']) ?></small>
          </span>
          <span class="mpt-card__use num<?= (int)$t['usos'] === 0 ? ' is-zero' : '' ?>"
                title="<?= e((int)$t['usos'] > 0 ? (string)($notas['uso'] ?? '') : (string)($notas['semuso'] ?? '')) ?>">
            <?= (int)$t['usos'] ?>
          </span>
          <?php if (!empty($t['is_system'])): ?>
            <span class="mpt-card__lock" title="<?= e((string)($notas['sistema'] ?? '')) ?>"><?= icon('lock') ?></span>
          <?php endif ?>
        </a>
        <?php endforeach ?>
      </div>
    </section>
    <?php endforeach ?>
  </div>
</section>
<!--/eh:lista-->
<?php endif ?>
