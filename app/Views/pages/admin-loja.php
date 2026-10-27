<?php declare(strict_types=1);

/**
 * Tela ADMIN · LOJA (/admin/loja).
 *
 * Escopo: $page, $player, $nav, $server, $data, $flash, $csrf. Layout: layouts/admin.php.
 *
 * A view NÃO consulta o banco e NÃO decide regra: tudo chegou pronto de
 * App\Repo\ShopOfferRepo, via App\Controllers\Admin\ShopController. Aqui só existe
 * apresentação — o chip de moeda, o preço riscado, a seta de ordenação, o paginador.
 *
 * DUAS COISAS QUE ESTA TELA SE RECUSA A FAZER
 *
 * 1. Escrever preço em R$. Tudo aqui é moeda de jogo (créditos, dinheiro, pontos) e cada
 *    número carrega o glifo da sua moeda. O painel principal fala em reais a três cliques
 *    daqui; sem o glifo, "48.500" numa tela e "R$ 6.407,65" na outra convidam à confusão
 *    mais cara que um operador de loja pode cometer.
 *
 * 2. Confiar em JavaScript para confirmar uma exclusão. A confirmação é uma TELA, servida
 *    pelo servidor em ?excluir=<id>, e o POST só apaga com o campo `confirmar`. Um confirm()
 *    de navegador desaparece com o JS desligado e leva a rede de proteção junto.
 *
 * REGRA DE LARGURA: a página nunca rola na horizontal. A tabela tem oito colunas e, quando
 * a janela aperta, quem some primeiro é a coluna que o operador consegue deduzir da vizinha
 * (o preço cheio, ao lado do preço final) — ver assets/css/admin/admin-loja.css.
 */

$txt      = (array)($data['txt'] ?? []);
$titulos  = (array)($txt['titulos'] ?? []);
$rotulos  = (array)($txt['kpis'] ?? []);
$notas    = (array)($txt['kpi_notas'] ?? []);
$abas     = (array)($txt['categorias'] ?? []);
$moedas   = (array)($txt['moedas'] ?? []);
$raridades= (array)($txt['raridades'] ?? []);
$filtroTx = (array)($txt['filtros'] ?? []);
$estadoTx = (array)($txt['estados'] ?? []);
$colunas  = (array)($txt['colunas'] ?? []);
$campos   = (array)($txt['campos'] ?? []);
$ajudas   = (array)($txt['ajudas'] ?? []);
$vazios   = (array)($txt['vazios'] ?? []);
$acoesTx  = (array)($txt['acoes'] ?? []);
$confTx   = (array)($txt['confirmacao'] ?? []);
$micro    = (array)($txt['micro'] ?? []);

$f        = (array)($data['filtros'] ?? []);
$query    = (array)($data['query'] ?? []);
$lista    = (array)($data['lista'] ?? []);
$linhas   = (array)($lista['linhas'] ?? []);
$resumo   = (array)($data['resumo'] ?? []);
$porAba   = (array)($data['por_aba'] ?? []);
$itens    = (array)($data['itens'] ?? []);
$limites  = (array)($data['limites'] ?? []);

$form      = (array)($data['form'] ?? []);
$erros     = (array)($data['form_erros'] ?? []);
$formAberto= !empty($data['form_aberto']);
$emEdicao  = is_array($data['form_edicao'] ?? null) ? $data['form_edicao'] : null;

$aExcluir  = is_array($data['excluir'] ?? null) ? $data['excluir'] : null;
$bloqueio  = $data['excluir_bloqueio'] ?? null;
$compras   = (array)($data['excluir_compras'] ?? ['n' => 0, 'ultima' => 0]);
$recente   = (int)($data['destaque_id'] ?? 0);

$temFiltro = ($f['q'] ?? '') !== '' || ($f['cat'] ?? '') !== ''
          || ($f['moeda'] ?? '') !== '' || ($f['estado'] ?? '') !== '';

/* =========================================================================================
   URLS — todo link e todo `action` levam os filtros atuais junto.

   É a query string que preserva o estado desta tela: os formulários de linha (destaque,
   mover, excluir) não repetem os filtros em <input hidden> porque a tela chega a ter quase
   quarenta formulários e isso seria a maior parte do HTML. Request lê $_GET em qualquer
   método, então o POST enxerga os mesmos filtros do GET.
   ========================================================================================= */

/* Chave com valor vazio é REMOVIDA, não escrita: é assim que o chip "Todas" apaga a
   categoria do filtro sem deixar um `?cat=` pendurado na barra de endereço. */
$urlLista = static function (array $extra = []) use ($query): string {
    $q = $extra + $query;
    return url('admin/loja', array_filter($q, static fn($v): bool => (string)$v !== ''));
};
$acaoSalvar = url('admin/loja/salvar', $query);
$acaoApagar = url('admin/loja/excluir', $query);

/* =========================================================================================
   FORMATADORES
   ========================================================================================= */

/** Chip de preço na cor da moeda, com o mesmo glifo que o jogador vê na loja. */
$chipMoeda = static function (int $valor, string $moeda, string $extra = '') use ($moedas): string {
    $m = (array)($moedas[$moeda] ?? $moedas['credits'] ?? []);
    return '<span class="loja-preco loja-preco--' . e((string)($m['tom'] ?? 'lime'))
        . ($extra !== '' ? ' ' . e($extra) : '') . '" title="' . e((string)($m['label'] ?? $moeda)) . '">'
        . svg((string)($m['svg'] ?? 'coin-credit'), 'loja-preco__coin')
        . '<span class="num">' . e(num($valor)) . '</span></span>';
};

/** Nome curto da moeda (CRD/DIN/PTS) — cabe onde o chip inteiro não caberia. */
$moedaCurta = static fn(string $m): string => (string)(($moedas[$m]['curto'] ?? strtoupper($m)));

/**
 * Selo de raridade — só quando ela NÃO é a comum.
 *
 * Nove em cada dez itens do catálogo são comuns: com o selo em todas as linhas, a coluna
 * repetia "COMUM" vinte e seis vezes e o único item raro da página não saltava aos olhos.
 * A ausência do selo passa a significar "comum", e o que aparece significa alguma coisa.
 */
$seloRaridade = static function (string $r) use ($raridades): string {
    if ($r === '' || $r === 'comum') {
        return '';
    }
    $meta = (array)($raridades[$r] ?? ['label' => ucfirst($r), 'tom' => 'muted']);
    return '<span class="adm-badge adm-badge--' . e((string)$meta['tom']) . ' loja-rar">'
        . e((string)$meta['label']) . '</span>';
};

/** Rótulo da aba; categoria antiga que não é mais aba aparece com o slug cru, sem inventar nome. */
$rotuloAba = static fn(string $slug): string => (string)($abas[$slug] ?? $slug);

/**
 * Cabeçalho ordenável. O clique inverte a direção da coluna já ativa e volta a ASC ao trocar
 * de coluna — e a seta desenhada é a que está VALENDO, não a que o clique vai produzir.
 */
$th = static function (string $chave, string $rotulo, string $classe = '') use ($f, $urlLista): string {
    $ativo = (string)($f['ord'] ?? 'ordem') === $chave;
    $dir   = $ativo && (string)($f['dir'] ?? 'asc') === 'asc' ? 'desc' : 'asc';
    $href  = $urlLista(['ord' => $chave, 'dir' => $dir, 'pag' => '1']);
    $seta  = $ativo ? icon((string)($f['dir'] ?? 'asc') === 'asc' ? 'arrow_upward' : 'arrow_downward', 'loja-th__seta') : '';
    return '<th class="' . e(trim($classe . ($ativo ? ' is-sorted' : ''))) . '"'
        . ($ativo ? ' aria-sort="' . ((string)($f['dir'] ?? 'asc') === 'asc' ? 'ascending' : 'descending') . '"' : '')
        . '><a class="loja-th" href="' . e($href) . '">' . e($rotulo) . $seta . '</a></th>';
};

/** Mensagem de erro de um campo, já no formato que o CSS do painel espera. */
$erro = static function (string $campo) use ($erros): string {
    if (!isset($erros[$campo])) {
        return '';
    }
    return '<span class="adm-error">' . icon('error') . '<span>' . e((string)$erros[$campo]) . '</span></span>';
};

/** ' is-invalid' no .adm-field do campo que falhou. */
$invalido = static fn(string $campo): string => isset($erros[$campo]) ? ' is-invalid' : '';

/**
 * Botão de uma ação rápida de linha (destaque, subir, descer).
 *
 * Cada um é um FORMULÁRIO de verdade, com CSRF: escrita por GET seria clicável por um <img>
 * numa página de terceiros e recarregável pelo histórico do navegador.
 */
$botaoAcao = static function (array $campos, string $icone, string $titulo, string $classe = '', bool $ligado = false) use ($acaoSalvar): string {
    $ocultos = '';
    foreach ($campos as $k => $v) {
        $ocultos .= '<input type="hidden" name="' . e($k) . '" value="' . e((string)$v) . '">';
    }
    return '<form class="loja-acao" method="post" action="' . e($acaoSalvar) . '">'
        . csrf_field() . $ocultos
        . '<button class="adm-btn adm-btn--sm adm-btn--icon ' . e($classe) . ($ligado ? ' is-on' : '')
        . '" type="submit" title="' . e($titulo) . '" aria-label="' . e($titulo) . '">'
        . icon($icone) . '</button></form>';
};

/* =========================================================================================
   FAIXA DE INDICADORES — os mesmos números da topbar, da mesma consulta
   ========================================================================================= */

$semNada = (int)($resumo['ofertas'] ?? 0) === 0;

$kpis = [
    [
        'chave' => 'ofertas', 'icone' => 'sell', 'tom' => 'lime',
        'valor' => num((int)($resumo['ofertas'] ?? 0)),
        'sub'   => (int)($resumo['itens'] ?? 0) > 0 ? 'de ' . num((int)$resumo['itens']) . ' itens' : null,
    ],
    [
        'chave' => 'destaque', 'icone' => 'star', 'tom' => 'gold',
        'valor' => num((int)($resumo['destaque'] ?? 0)),
        'sub'   => null,
    ],
    [
        'chave' => 'promo', 'icone' => 'percent', 'tom' => (int)($resumo['promo'] ?? 0) > 0 ? 'amber' : 'cyan',
        'valor' => num((int)($resumo['promo'] ?? 0)),
        'sub'   => (int)($resumo['desconto_medio'] ?? 0) > 0 ? 'méd. ' . (int)$resumo['desconto_medio'] . '%' : null,
    ],
    [
        'chave' => 'catalogo', 'icone' => 'inventory_2', 'tom' => 'purple',
        'valor' => num((int)($resumo['itens_sem_oferta'] ?? 0)),
        'sub'   => null,
    ],
];
?>
<div class="loja">

  <!-- ================================================================= INDICADORES -->
  <section class="loja-kpis" aria-label="Resumo da loja">
    <?php foreach ($kpis as $k): ?>
      <article class="adm-kpi adm-kpi--<?= e((string)$k['tom']) ?>">
        <span class="adm-kpi__ico"><?= icon((string)$k['icone']) ?></span>
        <span class="adm-kpi__txt">
          <span class="adm-kpi__label" title="<?= e((string)($notas[$k['chave']] ?? '')) ?>">
            <?= e((string)($rotulos[$k['chave']] ?? '')) ?>
          </span>
          <span class="adm-kpi__value num">
            <?= e((string)$k['valor']) ?>
            <?php if ($k['sub'] !== null): ?><small><?= e((string)$k['sub']) ?></small><?php endif ?>
          </span>
        </span>
      </article>
    <?php endforeach ?>
    <p class="loja-kpis__nota"><?= icon('info') ?><span><?= e((string)($micro['moeda_nota'] ?? '')) ?></span></p>
  </section>

  <!-- ============================================================== CONFIRMAR EXCLUSÃO -->
  <?php if ($aExcluir !== null): ?>
    <?php $temBloqueio = is_string($bloqueio) && $bloqueio !== ''; ?>
    <section class="adm-card loja-conf<?= $temBloqueio ? ' loja-conf--bloqueada' : '' ?>" aria-labelledby="loja-conf-t">
      <div class="adm-card__head">
        <h2 class="adm-card__title" id="loja-conf-t">
          <?= icon($temBloqueio ? 'block' : 'delete_forever') ?>
          <?= e((string)($titulos['exclusao'] ?? 'Excluir oferta')) ?>
        </h2>
      </div>
      <div class="adm-card__body loja-conf__body">

        <div class="loja-conf__alvo">
          <?php if ((string)$aExcluir['item_imagem'] !== ''): ?>
            <img class="loja-thumb" src="<?= e(media((string)$aExcluir['item_imagem'])) ?>" alt=""
                 width="56" height="56" loading="lazy" decoding="async">
          <?php else: ?>
            <span class="loja-thumb loja-thumb--ph"><?= icon('inventory_2') ?></span>
          <?php endif ?>
          <span class="loja-conf__id">
            <strong><?= e((string)$aExcluir['item_nome']) ?></strong>
            <small>
              <?= e($rotuloAba((string)$aExcluir['category'])) ?>
              · <?= e($moedaCurta((string)$aExcluir['currency'])) ?>
              <?= e(num((int)$aExcluir['preco_final'])) ?>
              · oferta #<?= e((string)$aExcluir['id']) ?>
            </small>
          </span>
        </div>

        <?php if ($temBloqueio): ?>
          <p class="loja-conf__bloqueio"><?= icon('report') ?><span><?= e((string)$bloqueio) ?></span></p>
        <?php else: ?>
          <p class="loja-conf__pergunta"><?= e((string)($confTx['pergunta'] ?? '')) ?></p>
          <p class="loja-conf__efeito"><?= e((string)($confTx['efeito'] ?? '')) ?></p>
          <p class="loja-conf__hist">
            <?= icon('receipt_long') ?>
            <span><?= (int)($compras['n'] ?? 0) > 0
                ? e(sprintf((string)($confTx['historico'] ?? '%s compras'), num((int)$compras['n'])))
                : e((string)($confTx['sem_historico'] ?? '')) ?></span>
          </p>
        <?php endif ?>

        <div class="adm-form__actions loja-conf__acoes">
          <a class="adm-btn" href="<?= e($urlLista()) ?>"><?= icon('arrow_back') ?><span><?= e((string)($acoesTx['cancelar'] ?? 'Cancelar')) ?></span></a>
          <?php if (!$temBloqueio): ?>
            <form method="post" action="<?= e($acaoApagar) ?>" class="adm-spacer">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= e((string)$aExcluir['id']) ?>">
              <input type="hidden" name="confirmar" value="sim">
              <button class="adm-btn adm-btn--danger" type="submit">
                <?= icon('delete_forever') ?><span><?= e((string)($acoesTx['confirmar'] ?? 'Excluir')) ?></span>
              </button>
            </form>
          <?php endif ?>
        </div>
      </div>
    </section>
  <?php endif ?>

  <!-- ==================================================================== FORMULÁRIO -->
  <?php if ($formAberto): ?>
    <section class="adm-card loja-form" aria-labelledby="loja-form-t">
      <div class="adm-card__head">
        <h2 class="adm-card__title" id="loja-form-t">
          <?= icon($emEdicao !== null ? 'edit' : 'add_shopping_cart') ?>
          <?= e((string)($titulos[$emEdicao !== null ? 'formulario_editar' : 'formulario_novo'] ?? 'Oferta')) ?>
          <?php if ($emEdicao !== null): ?><small>#<?= e((string)$emEdicao['id']) ?> · <?= e((string)$emEdicao['item_nome']) ?></small><?php endif ?>
        </h2>
        <div class="adm-card__tools">
          <a class="adm-card__link" href="<?= e($urlLista()) ?>"><?= e((string)($acoesTx['cancelar'] ?? 'Cancelar')) ?><?= icon('close') ?></a>
        </div>
      </div>

      <form class="adm-card__body adm-form" method="post" action="<?= e($acaoSalvar) ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="formulario">
        <input type="hidden" name="id" value="<?= e((string)($form['id'] ?? 0)) ?>">

        <?php if ($erros !== []): ?>
          <p class="loja-form__aviso"><?= icon('error') ?><span><?= e((string)($txt['erros']['geral'] ?? '')) ?></span></p>
        <?php endif ?>

        <div class="adm-form__row">
          <div class="adm-field<?= $invalido('item_id') ?>">
            <label class="adm-label" for="of-item"><?= e((string)($campos['item'] ?? 'Item')) ?><span class="adm-label__req">*</span></label>
            <?php if ($itens === []): ?>
              <p class="adm-hint"><?= e((string)($vazios['sem_item'] ?? '')) ?></p>
            <?php else: ?>
              <select class="adm-select" id="of-item" name="item_id" required>
                <option value=""><?= e('— escolha o item —') ?></option>
                <?php
                  /* Agrupado pela categoria do CATÁLOGO (items.category), que é a taxonomia
                     do item — e não pela aba da loja, que é decisão desta tela. São coisas
                     diferentes de propósito: uma munição pode ser vendida em PACOTES. */
                  $grupoAtual = null;
                  foreach ($itens as $it):
                      if ($grupoAtual !== $it['categoria']):
                          if ($grupoAtual !== null): ?></optgroup><?php endif;
                          $grupoAtual = $it['categoria']; ?>
                          <optgroup label="<?= e(mb_strtoupper((string)$it['categoria'])) ?>">
                      <?php endif; ?>
                      <option value="<?= e((string)$it['id']) ?>"<?= (int)($form['item_id'] ?? 0) === (int)$it['id'] ? ' selected' : '' ?>>
                        <?= e((string)$it['nome']) ?>
                        <?php if ((int)$it['preco_base'] > 0): ?>
                          — <?= e(num((int)$it['preco_base'])) ?> <?= e($moedaCurta((string)$it['moeda_base'])) ?>
                        <?php endif ?>
                        <?php if ((int)$it['ofertas'] > 0): ?>
                          · já em <?= e((string)$it['ofertas']) ?> oferta<?= (int)$it['ofertas'] > 1 ? 's' : '' ?>
                        <?php endif ?>
                      </option>
                  <?php endforeach; ?>
                  <?php if ($grupoAtual !== null): ?></optgroup><?php endif ?>
              </select>
            <?php endif ?>
            <?= $erro('item_id') ?>
            <p class="adm-hint"><?= e((string)($ajudas['item'] ?? '')) ?></p>
          </div>

          <div class="adm-field<?= $invalido('category') ?>">
            <label class="adm-label" for="of-cat"><?= e((string)($campos['categoria'] ?? 'Categoria')) ?><span class="adm-label__req">*</span></label>
            <select class="adm-select" id="of-cat" name="category" required>
              <option value=""><?= e('— escolha a aba —') ?></option>
              <?php foreach ($abas as $slug => $rot): ?>
                <option value="<?= e((string)$slug) ?>"<?= (string)($form['category'] ?? '') === (string)$slug ? ' selected' : '' ?>>
                  <?= e((string)$rot) ?> (<?= e((string)($porAba[$slug] ?? 0)) ?>)
                </option>
              <?php endforeach ?>
            </select>
            <?= $erro('category') ?>
            <p class="adm-hint"><?= e((string)($ajudas['categoria'] ?? '')) ?></p>
          </div>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field<?= $invalido('price') ?>">
            <label class="adm-label" for="of-preco"><?= e((string)($campos['preco'] ?? 'Preço')) ?></label>
            <input class="adm-input num" id="of-preco" name="price" type="text" inputmode="numeric"
                   value="<?= e((string)($form['price'] ?? '')) ?>" placeholder="0"
                   maxlength="9" autocomplete="off">
            <?= $erro('price') ?>
            <p class="adm-hint"><?= e((string)($ajudas['preco'] ?? '')) ?></p>
          </div>

          <div class="adm-field<?= $invalido('currency') ?>">
            <label class="adm-label" for="of-moeda"><?= e((string)($campos['moeda'] ?? 'Moeda')) ?><span class="adm-label__req">*</span></label>
            <select class="adm-select" id="of-moeda" name="currency" required>
              <?php foreach ($moedas as $chave => $m): ?>
                <option value="<?= e((string)$chave) ?>"<?= (string)($form['currency'] ?? '') === (string)$chave ? ' selected' : '' ?>>
                  <?= e((string)$m['label']) ?> (<?= e((string)$m['curto']) ?>)
                </option>
              <?php endforeach ?>
            </select>
            <?= $erro('currency') ?>
            <p class="adm-hint"><?= e((string)($ajudas['moeda'] ?? '')) ?></p>
          </div>

          <div class="adm-field<?= $invalido('discount') ?>">
            <label class="adm-label" for="of-desc"><?= e((string)($campos['desconto'] ?? 'Desconto')) ?></label>
            <input class="adm-input num" id="of-desc" name="discount" type="text" inputmode="numeric"
                   value="<?= e((string)($form['discount'] ?? '')) ?>" placeholder="0"
                   maxlength="2" autocomplete="off">
            <?= $erro('discount') ?>
            <p class="adm-hint"><?= e((string)($ajudas['desconto'] ?? '')) ?></p>
          </div>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field<?= $invalido('tag') ?>">
            <label class="adm-label" for="of-tag"><?= e((string)($campos['tag'] ?? 'Etiqueta')) ?></label>
            <input class="adm-input" id="of-tag" name="tag" type="text"
                   value="<?= e((string)($form['tag'] ?? '')) ?>" placeholder="NOVO"
                   maxlength="<?= e((string)($limites['tag'] ?? 16)) ?>" autocomplete="off">
            <?= $erro('tag') ?>
            <p class="adm-hint"><?= e((string)($ajudas['tag'] ?? '')) ?></p>
          </div>

          <div class="adm-field<?= $invalido('sort_order') ?>">
            <label class="adm-label" for="of-ordem"><?= e((string)($campos['ordem'] ?? 'Ordem')) ?></label>
            <input class="adm-input num" id="of-ordem" name="sort_order" type="text" inputmode="numeric"
                   value="<?= e((string)($form['sort_order'] ?? '')) ?>" placeholder="fim da aba"
                   maxlength="5" autocomplete="off">
            <?= $erro('sort_order') ?>
            <p class="adm-hint"><?= e((string)($ajudas['ordem'] ?? '')) ?></p>
          </div>

          <div class="adm-field">
            <span class="adm-label"><?= e((string)($campos['destaque'] ?? 'Destaque')) ?></span>
            <label class="adm-switch loja-form__switch">
              <input class="adm-switch__in" type="checkbox" name="featured" value="1"
                     <?= !empty($form['featured']) ? 'checked' : '' ?>>
              <span class="adm-switch__ui" aria-hidden="true"></span>
              <span><?= e(!empty($form['featured']) ? 'Aparece em DESTAQUES' : 'Fora de DESTAQUES') ?></span>
            </label>
            <p class="adm-hint"><?= e((string)($ajudas['destaque'] ?? '')) ?></p>
          </div>
        </div>

        <div class="adm-form__actions">
          <button class="adm-btn adm-btn--primary" type="submit">
            <?= icon('save') ?><span><?= e((string)($acoesTx['salvar'] ?? 'Salvar')) ?></span>
          </button>
          <a class="adm-btn adm-btn--ghost" href="<?= e($urlLista()) ?>"><?= e((string)($acoesTx['cancelar'] ?? 'Cancelar')) ?></a>
        </div>
      </form>
    </section>
  <?php endif ?>

  <!-- ========================================================================= LISTA -->
  <?php /* Região da lista (App\Core\Fragmento): o pedaço que volta do servidor quando o
           filtro é aplicado sem recarregar a página. */ ?>
  <!--eh:lista-->
  <section class="adm-card loja-lista">
    <div class="adm-card__head">
      <h2 class="adm-card__title">
        <?= e((string)($titulos['lista'] ?? 'Ofertas publicadas')) ?>
        <small data-adm-total><?= e(sprintf(
            (string)($micro['total'] ?? '%s de %s'),
            num(count($linhas)),
            num((int)($lista['total'] ?? 0))
        )) ?></small>
      </h2>
      <div class="adm-card__tools">
        <?php if (!$formAberto && $aExcluir === null): ?>
          <a class="adm-btn adm-btn--primary adm-btn--sm" href="<?= e($urlLista(['novo' => '1'])) ?>">
            <?= icon('add') ?><span><?= e((string)($acoesTx['nova'] ?? 'Nova oferta')) ?></span>
          </a>
        <?php endif ?>
      </div>
    </div>

    <!-- barra de busca e filtros (GET: o estado da lista é a URL, e a URL é compartilhável) -->
    <?php /* data-adm-preservar: transplantado para dentro do HTML novo em vez de
             substituído — é onde está o cursor de quem digita. */ ?>
    <form class="adm-toolbar loja-bar" method="get" action="<?= e(url('admin/loja')) ?>"
          data-adm-preservar="filtros">
      <label class="adm-search adm-toolbar__grow">
        <span class="sr-only"><?= e((string)($filtroTx['busca'] ?? 'Buscar')) ?></span>
        <?= icon('search') ?>
        <input class="adm-input" type="search" name="q" value="<?= e((string)($f['q'] ?? '')) ?>"
               placeholder="<?= e((string)($filtroTx['busca'] ?? '')) ?>" maxlength="60">
      </label>

      <label class="loja-bar__sel">
        <span class="sr-only"><?= e((string)($filtroTx['moeda'] ?? 'Moeda')) ?></span>
        <select class="adm-select" name="moeda">
          <option value=""><?= e((string)($filtroTx['moeda'] ?? 'Todas as moedas')) ?></option>
          <?php foreach ($moedas as $chave => $m): ?>
            <option value="<?= e((string)$chave) ?>"<?= (string)($f['moeda'] ?? '') === (string)$chave ? ' selected' : '' ?>>
              <?= e((string)$m['label']) ?>
            </option>
          <?php endforeach ?>
        </select>
      </label>

      <label class="loja-bar__sel">
        <span class="sr-only"><?= e((string)($filtroTx['estado'] ?? 'Estado')) ?></span>
        <select class="adm-select" name="estado">
          <option value=""><?= e((string)($filtroTx['estado'] ?? 'Qualquer estado')) ?></option>
          <?php foreach ($estadoTx as $chave => $rot): ?>
            <option value="<?= e((string)$chave) ?>"<?= (string)($f['estado'] ?? '') === (string)$chave ? ' selected' : '' ?>>
              <?= e((string)$rot) ?>
            </option>
          <?php endforeach ?>
        </select>
      </label>

      <?php /* A categoria viaja escondida no formulário de busca: ela é escolhida nos chips
               abaixo, e perdê-la ao digitar na busca faria o filtro parecer instável. */ ?>
      <input type="hidden" name="cat" value="<?= e((string)($f['cat'] ?? '')) ?>">
      <?php if ((string)($f['ord'] ?? 'ordem') !== 'ordem' || (string)($f['dir'] ?? 'asc') !== 'asc'): ?>
        <input type="hidden" name="ord" value="<?= e((string)$f['ord']) ?>">
        <input type="hidden" name="dir" value="<?= e((string)$f['dir']) ?>">
      <?php endif ?>

      <button class="adm-btn adm-btn--sm" type="submit"><?= icon('filter_alt') ?><span>Filtrar</span></button>
      <?php if ($temFiltro): ?>
        <a class="adm-btn adm-btn--ghost adm-btn--sm" href="<?= e(url('admin/loja')) ?>">
          <?= icon('filter_alt_off') ?><span><?= e((string)($filtroTx['limpar'] ?? 'Limpar')) ?></span>
        </a>
      <?php endif ?>
    </form>

    <?php /* Chips de aba: filtram E mostram a distribuição. Uma aba com UMA oferta ganha o
             cadeado — é a que não pode ser esvaziada; uma aba acima dos nichos da vitrine
             ganha o aviso âmbar. Os dois fatos só existem aqui, e o operador precisa deles
             antes de excluir ou de publicar, não depois. */ ?>
    <nav class="loja-abas" aria-label="Filtrar por aba da loja">
      <a class="loja-aba<?= (string)($f['cat'] ?? '') === '' ? ' is-active' : '' ?>"
         href="<?= e($urlLista(['cat' => '', 'pag' => '1'])) ?>">
        <span>Todas</span><span class="loja-aba__n num"><?= e(num((int)($resumo['ofertas'] ?? 0))) ?></span>
      </a>
      <?php foreach ($abas as $slug => $rot): ?>
        <?php
          $n      = (int)($porAba[$slug] ?? 0);
          $nichos = (int)($limites['nichos'] ?? 12);
          $marca  = match (true) {
              $n === 0  => ['vazia',   'lock_open',  'Aba sem nenhuma oferta: a vitrine do jogo mostra doze nichos vazios.'],
              $n === 1  => ['travada', 'lock',       'Única oferta da aba — ela não pode ser excluída sem esvaziar a vitrine.'],
              $n > $nichos => ['cheia', 'warning',   sprintf((string)($micro['aba_cheia'] ?? ''), mb_strtoupper((string)$rot), (string)$n)],
              default   => ['', '', ''],
          };
        ?>
        <a class="loja-aba<?= (string)($f['cat'] ?? '') === (string)$slug ? ' is-active' : '' ?><?= $marca[0] !== '' ? ' loja-aba--' . e($marca[0]) : '' ?>"
           href="<?= e($urlLista(['cat' => (string)$slug, 'pag' => '1'])) ?>"
           <?= $marca[2] !== '' ? 'title="' . e($marca[2]) . '"' : '' ?>>
          <span><?= e((string)$rot) ?></span>
          <span class="loja-aba__n num"><?= e(num($n)) ?></span>
          <?php if ($marca[1] !== ''): ?><?= icon($marca[1], 'loja-aba__ico') ?><?php endif ?>
        </a>
      <?php endforeach ?>
    </nav>

    <div class="adm-card__body adm-card__body--flush">
      <?php if ($linhas === []): ?>
        <div class="adm-empty">
          <?= icon($semNada ? 'storefront' : 'search_off') ?>
          <p class="adm-empty__title"><?= e($semNada ? 'Vitrine vazia' : 'Nenhuma oferta neste filtro') ?></p>
          <p class="adm-empty__txt"><?= e((string)($vazios[$semNada ? 'sem_oferta' : 'sem_filtro'] ?? '')) ?></p>
          <?php if ($semNada): ?>
            <a class="adm-btn adm-btn--primary" href="<?= e(url('admin/loja', ['novo' => '1'])) ?>">
              <?= icon('add') ?><span><?= e((string)($acoesTx['nova'] ?? 'Nova oferta')) ?></span>
            </a>
          <?php else: ?>
            <a class="adm-btn" href="<?= e(url('admin/loja')) ?>">
              <?= icon('filter_alt_off') ?><span><?= e((string)($filtroTx['limpar'] ?? 'Limpar filtros')) ?></span>
            </a>
          <?php endif ?>
        </div>
      <?php else: ?>
        <div class="adm-tablewrap">
          <table class="adm-table loja-tabela">
            <thead>
              <tr>
                <?= $th('ordem', (string)($colunas['ordem'] ?? 'Ordem'), 'loja-col-ordem') ?>
                <?= $th('item', (string)($colunas['item'] ?? 'Item')) ?>
                <?= $th('categoria', (string)($colunas['categoria'] ?? 'Categoria')) ?>
                <?= $th('desconto', (string)($colunas['desconto'] ?? 'Desc.'), 'tr loja-col-desc') ?>
                <?php /* UMA coluna de preço, não duas. O preço cheio só existe quando há
                         desconto (uma linha em vinte e seis) e, em coluna própria, ele virava
                         vinte e cinco travessões alinhados — uma coluna inteira sem informação.
                         Ele passa a morar riscado ACIMA do preço que vale, onde a comparação
                         é imediata e ele só aparece quando tem o que dizer. */ ?>
                <?= $th('preco', (string)($colunas['final'] ?? 'Preço final'), 'tr loja-col-preco') ?>
                <?= $th('destaque', (string)($colunas['destaque'] ?? 'Destaque'), 'tc loja-col-dest') ?>
                <th class="adm-table__acts"><span class="sr-only">Ações</span></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($linhas as $i => $l): ?>
                <?php
                  $primeira = $i === 0;
                  $ultima   = $i === count($linhas) - 1;
                  /* As setas só são desabilitadas nas pontas da PÁGINA quando a lista está na
                     ordem natural da vitrine: em qualquer outra ordenação, "acima" na tela não
                     é "acima" na aba, e a seta mentiria sobre o efeito do clique. */
                  $ordemNatural = (string)($f['ord'] ?? 'ordem') === 'ordem' && (string)($f['dir'] ?? 'asc') === 'asc';
                  $temDesconto  = (int)($l['discount'] ?? 0) > 0;
                ?>
                <tr class="<?= (int)$l['id'] === $recente ? 'is-recente ' : '' ?><?= $l['featured'] ? 'is-destaque' : '' ?>">

                  <td class="loja-col-ordem">
                    <div class="loja-ordem">
                      <?php /* O número é o `sort_order` gravado, não a posição contada: é ele
                               que o formulário edita, e mostrar 1,2,3 aqui faria o operador
                               digitar 3 num campo que espera 24. A posição real está na ordem
                               das linhas, que é o que o olho lê primeiro. */ ?>
                      <span class="loja-ordem__n num" title="<?= e((string)($micro['ordem_val'] ?? '')) ?>"><?= e((string)$l['sort_order']) ?></span>
                      <span class="loja-ordem__setas">
                        <?php if ($ordemNatural && $primeira): ?>
                          <span class="adm-btn adm-btn--sm adm-btn--icon is-disabled" aria-hidden="true"><?= icon('keyboard_arrow_up') ?></span>
                        <?php else: ?>
                          <?= $botaoAcao(['acao' => 'mover', 'id' => $l['id'], 'dir' => 'cima'], 'keyboard_arrow_up', (string)($acoesTx['subir'] ?? 'Subir')) ?>
                        <?php endif ?>
                        <?php if ($ordemNatural && $ultima): ?>
                          <span class="adm-btn adm-btn--sm adm-btn--icon is-disabled" aria-hidden="true"><?= icon('keyboard_arrow_down') ?></span>
                        <?php else: ?>
                          <?= $botaoAcao(['acao' => 'mover', 'id' => $l['id'], 'dir' => 'baixo'], 'keyboard_arrow_down', (string)($acoesTx['descer'] ?? 'Descer')) ?>
                        <?php endif ?>
                      </span>
                    </div>
                  </td>

                  <td>
                    <span class="loja-item">
                      <?php if ((string)$l['item_imagem'] !== ''): ?>
                        <img class="loja-thumb" src="<?= e(media((string)$l['item_imagem'])) ?>" alt=""
                             width="36" height="36" loading="lazy" decoding="async">
                      <?php else: ?>
                        <span class="loja-thumb loja-thumb--ph"><?= icon('inventory_2') ?></span>
                      <?php endif ?>
                      <span class="loja-item__txt">
                        <strong title="<?= e((string)$l['item_nome']) ?>"><?= e((string)$l['item_nome']) ?></strong>
                        <small><?= e((string)$l['item_slug']) ?><?php if ((string)$l['tag'] !== ''): ?>
                          <span class="loja-tag"><?= e((string)$l['tag']) ?></span>
                        <?php endif ?></small>
                      </span>
                    </span>
                  </td>

                  <td>
                    <span class="loja-cat"><?= e($rotuloAba((string)$l['category'])) ?></span>
                    <?= $seloRaridade((string)$l['item_raridade']) ?>
                  </td>

                  <td class="tr loja-col-desc">
                    <?php if ($temDesconto): ?>
                      <span class="adm-badge adm-badge--warn adm-badge--nodot num">−<?= e((string)$l['discount']) ?>%</span>
                    <?php else: ?>
                      <span class="is-void" title="<?= e((string)($micro['sem_desconto'] ?? '')) ?>">—</span>
                    <?php endif ?>
                  </td>

                  <td class="tr loja-col-preco">
                    <?php if ($temDesconto): ?>
                      <s class="loja-cheio num" title="Preço sem o desconto"><?= e(num((int)$l['price'])) ?></s>
                    <?php endif ?>
                    <?= $chipMoeda((int)$l['preco_final'], (string)$l['currency']) ?>
                  </td>

                  <td class="tc loja-col-dest">
                    <?= $botaoAcao(
                          ['acao' => 'destaque', 'id' => $l['id']],
                          $l['featured'] ? 'star' : 'star_outline',
                          (string)($acoesTx[$l['featured'] ? 'tirar' : 'destacar'] ?? 'Destaque'),
                          'loja-star',
                          (bool)$l['featured']
                        ) ?>
                  </td>

                  <td class="adm-table__acts">
                    <span class="adm-btns loja-acoes">
                      <a class="adm-btn adm-btn--sm adm-btn--icon" href="<?= e($urlLista(['editar' => (string)$l['id']])) ?>"
                         title="<?= e((string)($acoesTx['editar'] ?? 'Editar')) ?>"
                         aria-label="<?= e((string)($acoesTx['editar'] ?? 'Editar') . ': ' . $l['item_nome']) ?>"><?= icon('edit') ?></a>
                      <a class="adm-btn adm-btn--sm adm-btn--icon loja-del" href="<?= e($urlLista(['excluir' => (string)$l['id']])) ?>"
                         title="<?= e((string)($acoesTx['excluir'] ?? 'Excluir')) ?>"
                         aria-label="<?= e((string)($acoesTx['excluir'] ?? 'Excluir') . ': ' . $l['item_nome']) ?>"><?= icon('delete') ?></a>
                    </span>
                  </td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
      <?php endif ?>
    </div>

    <?php /* Paginador: só existe quando há mais de uma página. Um paginador com "1" sozinho
             é moldura de nada e rouba a última linha da tabela em 1366×768. */ ?>
    <?php if ((int)($lista['paginas'] ?? 1) > 1): ?>
      <?php
        $pag    = (int)$lista['pagina'];
        $total  = (int)$lista['paginas'];
        /* Janela de páginas: primeira, última e duas vizinhas da atual. Vinte botões numa
           lista de trezentas ofertas não é navegação, é uma segunda barra de rolagem. */
        $mostrar = [1, $total, $pag, $pag - 1, $pag + 1];
        $mostrar = array_values(array_unique(array_filter($mostrar, static fn(int $p): bool => $p >= 1 && $p <= $total)));
        sort($mostrar);
      ?>
      <nav class="adm-card__foot adm-pager" aria-label="Paginação das ofertas">
        <span class="adm-pager__info"><?= e(sprintf((string)($micro['pagina'] ?? '%s / %s'), num($pag), num($total))) ?></span>

        <a class="adm-pager__btn<?= $pag <= 1 ? ' is-disabled' : '' ?>"
           href="<?= e($urlLista(['pag' => (string)max(1, $pag - 1)])) ?>"
           aria-label="Página anterior"><?= icon('chevron_left') ?></a>

        <?php $anterior = 0; ?>
        <?php foreach ($mostrar as $p): ?>
          <?php if ($anterior > 0 && $p > $anterior + 1): ?><span class="adm-pager__gap">…</span><?php endif ?>
          <a class="adm-pager__btn<?= $p === $pag ? ' is-active' : '' ?>"
             href="<?= e($urlLista(['pag' => (string)$p])) ?>"
             <?= $p === $pag ? 'aria-current="page"' : '' ?>><?= e((string)$p) ?></a>
          <?php $anterior = $p; ?>
        <?php endforeach ?>

        <a class="adm-pager__btn<?= $pag >= $total ? ' is-disabled' : '' ?>"
           href="<?= e($urlLista(['pag' => (string)min($total, $pag + 1)])) ?>"
           aria-label="Próxima página"><?= icon('chevron_right') ?></a>
      </nav>
    <?php endif ?>
  </section>
  <!--/eh:lista-->
</div>
