<?php declare(strict_types=1);

/**
 * Tela ADMIN · ITENS (/admin/itens). Layout: layouts/admin.php.
 *
 * Escopo: $page, $player, $nav, $server, $data, $flash, $csrf.
 *
 * A view não consulta o banco e não decide regra: tudo chegou pronto de
 * App\Controllers\Admin\ItemsController (política) e App\Repo\ItemsRepo (SQL). O que existe
 * aqui é desenho e formatação.
 *
 * A tela tem TRÊS estados e eles nunca aparecem ao mesmo tempo em disputa pela atenção:
 *   lista          — o padrão;
 *   lista + form   — ?form=novo | ?form=<id>, o formulário abre ACIMA da lista (#adm-form),
 *                    e a lista continua visível para o operador não perder o contexto;
 *   lista + baixa  — ?excluir=<id>, o painel de confirmação toma o lugar do formulário.
 *
 * Tudo navega por GET com a query string inteira preservada (busca, filtro, ordem, página),
 * porque a tela precisa funcionar com JavaScript desligado e sair inteira no `curl` — é assim
 * que ela é conferida.
 */

use App\Core\Config;

$cromo   = is_array($data['cromo'] ?? null) ? $data['cromo'] : [];
$CATS    = (array)($cromo['categorias'] ?? []);
$RARS    = (array)($cromo['raridades'] ?? []);
$SLOTS   = (array)($cromo['slots'] ?? []);
$MOEDAS  = (array)($cromo['moedas'] ?? []);
$ORDENS  = (array)($cromo['ordenacoes'] ?? []);
$TITULOS = (array)($cromo['titulos'] ?? []);
$VAZIOS  = (array)($cromo['vazios'] ?? []);
$FRASES  = (array)($cromo['frases'] ?? []);

$resumo   = (array)($data['resumo'] ?? []);
$linhas   = (array)($data['linhas'] ?? []);
$total    = (int)($data['total'] ?? 0);
$pagina   = (int)($data['pagina'] ?? 1);
$paginas  = (int)($data['paginas'] ?? 1);
$porPag   = (int)($data['por_pagina'] ?? 20);

$filtros  = (array)($data['filtros'] ?? []);
$temFiltro= (bool)($data['tem_filtro'] ?? false);
$catsDb   = (array)($data['cats_banco'] ?? []);
$rarsDb   = (array)($data['rars_banco'] ?? []);

$edicao   = is_array($data['edicao'] ?? null) ? $data['edicao'] : null;
$erros    = (array)($data['erros'] ?? []);
$baixa    = is_array($data['excluir'] ?? null) ? $data['excluir'] : null;

/* Confirmar exclusão e editar ao mesmo tempo seria pedir duas decisões na mesma tela. A baixa
   é a mais destrutiva das duas, então ela vence e o formulário fica fechado. */
if ($baixa !== null) {
    $edicao = null;
}

/* URL desta tela mantendo a query atual e trocando/soltando o que for pedido. É por causa
   dela que voltar de um formulário cai na mesma página filtrada de onde se saiu. */
$aqui = static function (array $troca = []) use ($filtros): string {
    $q = array_filter(array_replace($filtros, $troca), static fn($v): bool => $v !== null && $v !== '');
    return url('admin/itens', $q);
};

/* Rótulo de um valor de lista fechada. Valor gravado que a tela não conhece aparece CRU, entre
   aspas: é um dado real do catálogo e escondê-lo faria a tabela mentir. */
$rotulo = static fn(array $dic, ?string $v, string $vazio = '—'): string
    => ($v === null || $v === '') ? $vazio : (string)($dic[$v] ?? $v);

/* Existe arte para desenhar? media() já cai de assets/img/ para /media, e aqui a mesma busca
   responde a outra pergunta: se NENHUM dos dois tem o arquivo, a linha ganha um aviso em vez
   de um quadrado quebrado silencioso. */
$temArte = static function (string $caminho): bool {
    static $memo = [];
    $caminho = ltrim(str_replace('\\', '/', $caminho), '/');
    if ($caminho === '' || str_contains($caminho, '..')) {
        return false;
    }
    return $memo[$caminho] ??= is_file(Config::root() . '/media/' . $caminho)
        || is_file(Config::assets() . '/img/' . $caminho);
};

$de  = $total === 0 ? 0 : ($pagina - 1) * $porPag + 1;
$ate = min($pagina * $porPag, $total);
?>

<?php /* ============================================================ resumo do catálogo */ ?>
<section class="adm-grid adm-grid--4 itens-kpis" aria-label="<?= e((string)($TITULOS['resumo'] ?? 'Resumo')) ?>">
  <div class="adm-kpi">
    <span class="adm-kpi__ico"><?= icon('inventory_2') ?></span>
    <span class="adm-kpi__txt">
      <span class="adm-kpi__label">Itens no catálogo</span>
      <span class="adm-kpi__value num"><?= e(num((int)($resumo['itens'] ?? 0))) ?></span>
      <span class="adm-meta"><?= e(num((int)($resumo['categorias'] ?? 0))) ?> categoria(s) em uso</span>
    </span>
  </div>
  <div class="adm-kpi adm-kpi--cyan">
    <span class="adm-kpi__ico"><?= icon('backpack') ?></span>
    <span class="adm-kpi__txt">
      <span class="adm-kpi__label">Unidades com jogadores</span>
      <span class="adm-kpi__value num"><?= e(num((int)($resumo['instancias'] ?? 0))) ?></span>
      <span class="adm-meta">em <?= e(num((int)($resumo['donos'] ?? 0))) ?> inventário(s)</span>
    </span>
  </div>
  <div class="adm-kpi adm-kpi--gold">
    <span class="adm-kpi__ico"><?= icon('storefront') ?></span>
    <span class="adm-kpi__txt">
      <span class="adm-kpi__label">Ofertas na loja</span>
      <span class="adm-kpi__value num"><?= e(num((int)($resumo['ofertas'] ?? 0))) ?></span>
      <span class="adm-meta">linhas de `shop_offers` apontando para itens</span>
    </span>
  </div>
  <div class="adm-kpi adm-kpi--amber">
    <span class="adm-kpi__ico"><?= icon('do_not_disturb_on') ?></span>
    <span class="adm-kpi__txt">
      <span class="adm-kpi__label">Sem nenhum dono</span>
      <span class="adm-kpi__value num"><?= e(num((int)($resumo['orfaos'] ?? 0))) ?></span>
      <?php /* Não é defeito: é o catálogo que ainda não chegou a jogador nenhum — e é
               exatamente esse conjunto que pode ser excluído sem estrago. */ ?>
      <span class="adm-meta">itens que ninguém possui</span>
    </span>
  </div>
</section>

<?php /* ==================================================== confirmação de exclusão */ ?>
<?php if ($baixa !== null): ?>
  <?php
    $item = (array)$baixa['item'];
    $usos = (array)$baixa['usos'];
    $bloqueado = (int)($usos['total'] ?? 0) > 0;
    /* Cada referência com o EFEITO real da exclusão: CASCADE apaga junto, SET NULL deixa a
       missão premiando nada. Números diferentes, estragos diferentes — a tela diz qual é qual. */
    $refs = [
        ['n' => (int)$usos['instancias'], 'ico' => 'backpack',   'txt' => 'unidade(s) no inventário de ' . num((int)$usos['jogadores']) . ' jogador(es)', 'efeito' => 'seriam apagadas em cascata'],
        ['n' => (int)$usos['ofertas'],    'ico' => 'storefront', 'txt' => 'oferta(s) na loja',            'efeito' => 'seriam apagadas em cascata'],
        ['n' => (int)$usos['missoes'],    'ico' => 'flag',       'txt' => 'recompensa(s) de missão',      'efeito' => 'ficariam sem item, premiando nada'],
        ['n' => (int)$usos['conquistas'], 'ico' => 'military_tech', 'txt' => 'recompensa(s) de conquista','efeito' => 'ficariam sem item, premiando nada'],
    ];
  ?>
  <section class="adm-card itens-baixa<?= $bloqueado ? ' is-bloqueada' : '' ?>" id="adm-form">
    <div class="adm-card__head">
      <h2 class="adm-card__title">
        <?= e((string)($TITULOS['excluir'] ?? 'Excluir item')) ?>
        <small><?= e((string)$item['name']) ?> · <?= e((string)$item['slug']) ?></small>
      </h2>
      <div class="adm-card__tools">
        <a class="adm-btn adm-btn--ghost adm-btn--sm" href="<?= e($aqui(['excluir' => null])) ?>"><?= icon('close') ?><span>Cancelar</span></a>
      </div>
    </div>
    <div class="adm-card__body itens-baixa__body">
      <?php if ($bloqueado): ?>
        <p class="itens-baixa__lead">
          <?= icon('block', 'itens-baixa__ico') ?>
          <span>Este item <strong>não pode ser excluído</strong> enquanto for referenciado. Apagar a
          linha do catálogo levaria junto o que está listado abaixo — sem aviso e sem volta.</span>
        </p>
        <ul class="itens-baixa__refs">
          <?php foreach ($refs as $r): ?>
            <?php if ($r['n'] <= 0) { continue; } ?>
            <li>
              <?= icon((string)$r['ico']) ?>
              <b class="num"><?= e(num($r['n'])) ?></b>
              <span><?= e((string)$r['txt']) ?> — <em><?= e((string)$r['efeito']) ?></em></span>
            </li>
          <?php endforeach ?>
        </ul>
        <p class="adm-hint">
          Para tirar o item de circulação sem destruir posse, prefira editá-lo (por exemplo,
          zerar o preço e removê-lo das ofertas) — a exclusão é para catálogo que nunca chegou
          a jogador nenhum.
        </p>
        <div class="adm-btns">
          <a class="adm-btn" href="<?= e($aqui(['excluir' => null, 'form' => (string)$item['id']])) ?>#adm-form"><?= icon('edit') ?><span>Editar este item</span></a>
        </div>
      <?php else: ?>
        <p class="itens-baixa__lead">
          <?= icon('warning', 'itens-baixa__ico') ?>
          <span>Nada referencia <strong><?= e((string)$item['name']) ?></strong> — nenhum inventário,
          nenhuma oferta e nenhuma recompensa. A exclusão é definitiva e não tem desfazer.</span>
        </p>
        <dl class="adm-dl itens-baixa__ficha">
          <dt>Slug</dt><dd><code><?= e((string)$item['slug']) ?></code></dd>
          <dt>Categoria</dt><dd><?= e($rotulo($CATS, (string)$item['category'])) ?></dd>
          <dt>Raridade</dt><dd><?= e((string)(($RARS[(string)$item['rarity']]['label'] ?? $item['rarity']))) ?></dd>
          <dt>Preço</dt><dd class="num"><?= e(num((int)$item['base_price'])) ?> <?= e((string)(($MOEDAS[(string)$item['currency']]['curto'] ?? $item['currency']))) ?></dd>
        </dl>
        <form class="adm-btns" method="post" action="<?= e(url('admin/itens/excluir')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= e((string)$item['id']) ?>">
          <?php foreach (['q', 'cat', 'rar', 'ord', 'p'] as $k): ?>
            <?php if (($filtros[$k] ?? '') !== ''): ?>
              <input type="hidden" name="f_<?= e($k) ?>" value="<?= e((string)$filtros[$k]) ?>">
            <?php endif ?>
          <?php endforeach ?>
          <button class="adm-btn adm-btn--danger" type="submit"><?= icon('delete_forever') ?><span>Excluir definitivamente</span></button>
          <a class="adm-btn adm-btn--ghost" href="<?= e($aqui(['excluir' => null])) ?>">Cancelar</a>
        </form>
      <?php endif ?>
    </div>
  </section>
<?php endif ?>

<?php /* ============================================================== formulário */ ?>
<?php if ($edicao !== null): ?>
  <?php
    $novo = (int)($edicao['id'] ?? 0) <= 0;
    $v    = static fn(string $campo, string $padrao = ''): string
        => ($edicao[$campo] ?? null) === null ? $padrao : (string)$edicao[$campo];

    /* Um campo do formulário: rótulo, controle, erro do servidor e dica. Está aqui como
       closure — e não repetido 24 vezes — para que campo com erro e campo sem erro sejam
       impossíveis de desenhar diferente por descuido. */
    $campo = static function (string $nome, string $rot, string $ctrl, string $dica = '', bool $req = false) use ($erros): void {
        $erro = (string)($erros[$nome] ?? '');
        echo '<div class="adm-field' . ($erro !== '' ? ' is-invalid' : '') . '">';
        echo '<label class="adm-label" for="f-' . e($nome) . '">' . e($rot)
           . ($req ? '<span class="adm-label__req" aria-hidden="true">*</span>' : '') . '</label>';
        echo $ctrl;
        if ($erro !== '') {
            echo '<p class="adm-error" id="e-' . e($nome) . '">' . icon('error') . '<span>' . e($erro) . '</span></p>';
        } elseif ($dica !== '') {
            echo '<p class="adm-hint">' . e($dica) . '</p>';
        }
        echo '</div>';
    };

    /* Atributos comuns a todo controle: id previsível, aria-invalid e o vínculo com a
       mensagem de erro — é o que faz o leitor de tela anunciar a recusa junto com o campo. */
    $attrs = static function (string $nome) use ($erros): string {
        $err = isset($erros[$nome]);
        return ' id="f-' . e($nome) . '" name="' . e($nome) . '"'
             . ($err ? ' aria-invalid="true" aria-describedby="e-' . e($nome) . '"' : '');
    };

    /* Campo numérico ganha a fonte tabular (.num) e inputmode: número que muda de largura a
       cada dígito é ilegível numa coluna, inclusive dentro do formulário. */
    $texto = static fn(string $n, array $o = []): string
        => '<input class="adm-input' . (!empty($o['num']) ? ' num' : '') . '"' . $attrs($n)
         . ' type="' . e($o['type'] ?? 'text') . '"'
         . ' value="' . e($v($n, (string)($o['padrao'] ?? ''))) . '"'
         . (isset($o['max']) ? ' maxlength="' . (int)$o['max'] . '"' : '')
         . (isset($o['min_n']) ? ' min="' . e((string)$o['min_n']) . '"' : '')
         . (isset($o['max_n']) ? ' max="' . e((string)$o['max_n']) . '"' : '')
         . (isset($o['ph']) ? ' placeholder="' . e((string)$o['ph']) . '"' : '')
         . (!empty($o['num']) ? ' inputmode="numeric"' : '')
         . '>';

    $lista = static function (string $n, array $opcoes, bool $vazio, string $rotVazio = '— nenhum —') use ($attrs, $v): string {
        $atual = $v($n);
        $out = '<select class="adm-select"' . $attrs($n) . '>';
        if ($vazio) {
            $out .= '<option value="">' . e($rotVazio) . '</option>';
        }
        foreach ($opcoes as $chave => $rot) {
            $sel = (string)$chave === $atual ? ' selected' : '';
            $out .= '<option value="' . e((string)$chave) . '"' . $sel . '>' . e((string)$rot) . '</option>';
        }
        // Valor legado gravado no banco que não está na lista: entra como opção própria, senão
        // salvar sem tocar no campo trocaria calado o valor do item pelo primeiro da lista.
        if ($atual !== '' && !array_key_exists($atual, $opcoes)) {
            $out .= '<option value="' . e($atual) . '" selected>' . e($atual) . ' (valor atual)</option>';
        }
        return $out . '</select>';
    };

    /* Categorias e raridades oferecidas = as conhecidas + as que já existem no banco. */
    $catsOpc = $CATS;
    foreach (array_keys($catsDb) as $c) { $catsOpc[$c] ??= $c; }
    $rarsOpc = [];
    foreach ($RARS as $k => $meta) { $rarsOpc[$k] = (string)$meta['label']; }
    foreach (array_keys($rarsDb) as $r) { $rarsOpc[$r] ??= $r; }
    $moedasOpc = [];
    foreach ($MOEDAS as $k => $meta) { $moedasOpc[$k] = (string)$meta['label']; }
  ?>
  <section class="adm-card itens-form" id="adm-form">
    <div class="adm-card__head">
      <h2 class="adm-card__title">
        <?= e((string)($TITULOS[$novo ? 'novo' : 'editar'] ?? 'Item')) ?>
        <?php if (!$novo): ?><small>#<?= e((string)$edicao['id']) ?> · <?= e($v('name')) ?></small><?php endif ?>
      </h2>
      <div class="adm-card__tools">
        <a class="adm-btn adm-btn--ghost adm-btn--sm" href="<?= e($aqui(['form' => null])) ?>"><?= icon('close') ?><span>Fechar</span></a>
      </div>
    </div>

    <?php if ($erros !== []): ?>
      <p class="itens-form__alerta" role="alert">
        <?= icon('error') ?>
        <span><?= e(count($erros) === 1 ? 'Um campo foi recusado pelo servidor.' : count($erros) . ' campos foram recusados pelo servidor.') ?>
        Nada foi gravado — corrija o que está marcado e salve de novo.</span>
      </p>
    <?php endif ?>

    <form class="adm-card__body adm-form" method="post" action="<?= e(url('admin/itens/salvar')) ?>" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= e((string)($edicao['id'] ?? 0)) ?>">
      <?php /* O filtro em que o operador estava viaja junto e volta com ele depois de salvar. */ ?>
      <?php foreach (['q', 'cat', 'rar', 'ord', 'p'] as $k): ?>
        <?php if (($filtros[$k] ?? '') !== ''): ?>
          <input type="hidden" name="f_<?= e($k) ?>" value="<?= e((string)$filtros[$k]) ?>">
        <?php endif ?>
      <?php endforeach ?>

      <fieldset class="itens-form__bloco">
        <legend class="itens-form__legenda"><?= icon('badge') ?><span>Identificação</span></legend>
        <div class="adm-form__row adm-form__row--3">
          <?php $campo('slug', 'Slug', $texto('slug', ['max' => 64, 'ph' => 'ak103']), (string)($FRASES['slug_regra'] ?? ''), true) ?>
          <?php $campo('name', 'Nome', $texto('name', ['max' => 120, 'ph' => 'AK-103']), '', true) ?>
          <?php $campo('short_name', 'Nome curto', $texto('short_name', ['max' => 32]), 'Usado onde não cabe o nome inteiro (grade do inventário).') ?>
        </div>
        <div class="adm-form__row">
          <?php $campo('image', 'Imagem', $texto('image', ['max' => 160, 'ph' => 'items/ak103.png']), 'Caminho relativo dentro de /media.', true) ?>
          <?php $campo('description', 'Descrição', '<textarea class="adm-textarea"' . $attrs('description') . ' maxlength="400" rows="3">' . e($v('description')) . '</textarea>', 'Até 400 caracteres. Aparece na ficha do item.') ?>
        </div>
      </fieldset>

      <fieldset class="itens-form__bloco">
        <legend class="itens-form__legenda"><?= icon('category') ?><span>Classificação</span></legend>
        <div class="adm-form__row adm-form__row--3">
          <?php $campo('category', 'Categoria', $lista('category', $catsOpc, false), '', true) ?>
          <?php $campo('subtype', 'Subtipo', $texto('subtype', ['max' => 32, 'ph' => 'assault_rifle']), 'Minúsculas e sublinhado. Livre — é o recorte fino dentro da categoria.') ?>
          <?php $campo('slot_type', 'Slot de equipamento', $lista('slot_type', $SLOTS, true, '— não equipável —'), 'Em branco: o item só ocupa espaço na grade.') ?>
        </div>
        <div class="adm-form__row adm-form__row--3">
          <?php $campo('rarity', 'Raridade', $lista('rarity', $rarsOpc, false), '', true) ?>
          <?php $campo('grid_w', 'Largura na grade', $texto('grid_w', ['type' => 'number', 'min_n' => 1, 'max_n' => 20, 'num' => true, 'padrao' => '1']), 'Células ocupadas na horizontal.', true) ?>
          <?php $campo('grid_h', 'Altura na grade', $texto('grid_h', ['type' => 'number', 'min_n' => 1, 'max_n' => 20, 'num' => true, 'padrao' => '1']), 'Células ocupadas na vertical.', true) ?>
        </div>
      </fieldset>

      <fieldset class="itens-form__bloco">
        <legend class="itens-form__legenda"><?= icon('scale') ?><span>Física e durabilidade</span></legend>
        <div class="adm-form__row adm-form__row--3">
          <?php $campo('weight_kg', 'Peso (kg)', $texto('weight_kg', ['num' => true, 'ph' => '3,600', 'padrao' => '0.000']), 'Até três casas. Vírgula ou ponto.', true) ?>
          <?php $campo('durability_max', 'Durabilidade máxima', $texto('durability_max', ['type' => 'number', 'min_n' => 1, 'max_n' => 65535, 'num' => true]), 'Só para peças que se desgastam (capacete, colete, arma).') ?>
          <?php $campo('ammo_caliber', 'Calibre', $texto('ammo_caliber', ['max' => 24, 'ph' => '7.62x39']), 'Arma, carregador ou munição.') ?>
        </div>
        <div class="adm-form__row adm-form__row--3">
          <?php $campo('grid_cols', 'Grade interna — colunas', $texto('grid_cols', ['type' => 'number', 'min_n' => 1, 'max_n' => 255, 'num' => true]), 'Só contêineres (rig, mochila).') ?>
          <?php $campo('grid_rows', 'Grade interna — linhas', $texto('grid_rows', ['type' => 'number', 'min_n' => 1, 'max_n' => 255, 'num' => true]), 'Preencha as duas, ou nenhuma.') ?>
          <?php $campo('mag_capacity', 'Capacidade do carregador', $texto('mag_capacity', ['type' => 'number', 'min_n' => 1, 'max_n' => 65535, 'num' => true]), 'Quantos cartuchos o carregador leva.') ?>
        </div>
      </fieldset>

      <fieldset class="itens-form__bloco">
        <legend class="itens-form__legenda"><?= icon('payments') ?><span>Economia</span></legend>
        <div class="adm-form__row adm-form__row--3">
          <?php $campo('base_price', 'Preço base', $texto('base_price', ['type' => 'number', 'min_n' => 0, 'num' => true, 'padrao' => '0']), (string)($FRASES['preco_unid'] ?? ''), true) ?>
          <?php $campo('currency', 'Moeda', $lista('currency', $moedasOpc, false), '', true) ?>
          <?php $campo('max_stack', 'Pilha máxima', $texto('max_stack', ['type' => 'number', 'min_n' => 1, 'max_n' => 65535, 'num' => true, 'padrao' => '1']), 'Empilhável exige pelo menos 2. Não empilhável é gravado como 1.', true) ?>
        </div>
        <div class="adm-form__row">
          <div class="adm-field">
            <span class="adm-label">Empilhamento</span>
            <label class="adm-switch">
              <input class="adm-switch__in" type="checkbox" name="stackable" value="1"<?= (int)$v('stackable', '0') === 1 ? ' checked' : '' ?>>
              <span class="adm-switch__ui"></span>
              <span>Várias unidades ocupam a mesma célula</span>
            </label>
          </div>
        </div>
      </fieldset>

      <fieldset class="itens-form__bloco">
        <legend class="itens-form__legenda"><?= icon('data_object') ?><span>Estrutura (JSON)</span></legend>
        <div class="adm-form__row adm-form__row--3">
          <?php $campo('attach_slots', 'Bocais de acessório', '<textarea class="adm-textarea itens-form__json"' . $attrs('attach_slots') . ' rows="4" spellcheck="false" placeholder=\'[{"key":"sight","label":"MIRA","accepts":["sight"]}]\'>' . e($v('attach_slots')) . '</textarea>', 'Array JSON. Em branco grava NULL.') ?>
          <?php $campo('fits_calibers', 'Calibres aceitos', '<textarea class="adm-textarea itens-form__json"' . $attrs('fits_calibers') . ' rows="4" spellcheck="false" placeholder=\'["7.62x39"]\'>' . e($v('fits_calibers')) . '</textarea>', 'Array JSON. Em branco grava NULL.') ?>
          <?php $campo('meta', 'Meta', '<textarea class="adm-textarea itens-form__json"' . $attrs('meta') . ' rows="4" spellcheck="false" placeholder=\'{"efeito":"cura","valor":25}\'>' . e($v('meta')) . '</textarea>', 'Campo livre do item. JSON, ou em branco.') ?>
        </div>
      </fieldset>

      <div class="adm-form__actions">
        <button class="adm-btn adm-btn--primary" type="submit"><?= icon('save') ?><span><?= $novo ? 'Cadastrar item' : 'Salvar alterações' ?></span></button>
        <a class="adm-btn adm-btn--ghost" href="<?= e($aqui(['form' => null])) ?>">Cancelar</a>
        <?php if (!$novo): ?>
          <a class="adm-btn adm-btn--danger adm-spacer" href="<?= e($aqui(['form' => null, 'excluir' => (string)$edicao['id']])) ?>#adm-form"><?= icon('delete') ?><span>Excluir…</span></a>
        <?php endif ?>
      </div>
    </form>
  </section>
<?php endif ?>

<?php /* ================================================================== listagem */ ?>
<?php /* Região da lista (App\Core\Fragmento): o pedaço que volta do servidor quando o
         filtro é aplicado sem recarregar a página. Os KPIs acima contam o catálogo
         inteiro e por isso ficam de fora. */ ?>
<!--eh:lista-->
<section class="adm-card itens-lista">
  <div class="adm-card__head">
    <h2 class="adm-card__title">
      <?= e((string)($TITULOS['lista'] ?? 'Catálogo de itens')) ?>
      <small data-adm-total>
        <?php if ($total === 0): ?>
          nenhum resultado
        <?php else: ?>
          <?= e(num($de)) ?>–<?= e(num($ate)) ?> de <?= e(num($total)) ?><?= $temFiltro ? ' (filtrado)' : '' ?>
        <?php endif ?>
      </small>
    </h2>
  </div>

  <?php /* Barra de filtros: um único GET. Sem JavaScript, sem submit automático — o operador
           monta a consulta e aperta uma vez. A página volta para 1 porque o resultado é outro. */ ?>
  <?php /* data-adm-preservar: transplantado para dentro do HTML novo em vez de substituído
           — é onde está o cursor de quem digita. */ ?>
  <form class="adm-toolbar" method="get" action="<?= e(url('admin/itens')) ?>" role="search"
        data-adm-preservar="filtros">
    <div class="adm-toolbar__grow adm-search">
      <?= icon('search') ?>
      <label class="sr-only" for="f-busca">Buscar por nome, slug ou nome curto</label>
      <input class="adm-input" id="f-busca" type="search" name="q" maxlength="64"
             value="<?= e((string)($filtros['q'] ?? '')) ?>" placeholder="Nome, slug ou nome curto…">
    </div>

    <label class="sr-only" for="f-cat">Categoria</label>
    <select class="adm-select itens-toolbar__sel" id="f-cat" name="cat">
      <option value="">Todas as categorias</option>
      <?php
        $catsFiltro = $CATS;
        foreach (array_keys($catsDb) as $c) { $catsFiltro[$c] ??= $c; }
      ?>
      <?php foreach ($catsFiltro as $chave => $rot): ?>
        <?php $n = (int)($catsDb[$chave] ?? 0); ?>
        <?php /* Categoria sem nenhum item aparece desabilitada: escolher só para ver "nenhum
                 resultado" é um caminho que a tela já sabe que não leva a lugar nenhum. */ ?>
        <option value="<?= e((string)$chave) ?>"<?= (string)($filtros['cat'] ?? '') === (string)$chave ? ' selected' : '' ?><?= $n === 0 ? ' disabled' : '' ?>>
          <?= e((string)$rot) ?><?= $n > 0 ? ' (' . num($n) . ')' : ' — vazia' ?>
        </option>
      <?php endforeach ?>
    </select>

    <label class="sr-only" for="f-rar">Raridade</label>
    <select class="adm-select itens-toolbar__sel" id="f-rar" name="rar">
      <option value="">Todas as raridades</option>
      <?php
        $rarsFiltro = [];
        foreach ($RARS as $k => $meta) { $rarsFiltro[$k] = (string)$meta['label']; }
        foreach (array_keys($rarsDb) as $r) { $rarsFiltro[$r] ??= $r; }
      ?>
      <?php foreach ($rarsFiltro as $chave => $rot): ?>
        <?php $n = (int)($rarsDb[$chave] ?? 0); ?>
        <option value="<?= e((string)$chave) ?>"<?= (string)($filtros['rar'] ?? '') === (string)$chave ? ' selected' : '' ?><?= $n === 0 ? ' disabled' : '' ?>>
          <?= e((string)$rot) ?><?= $n > 0 ? ' (' . num($n) . ')' : ' — vazia' ?>
        </option>
      <?php endforeach ?>
    </select>

    <label class="sr-only" for="f-ord">Ordenar por</label>
    <select class="adm-select itens-toolbar__sel" id="f-ord" name="ord">
      <?php foreach ($ORDENS as $chave => $rot): ?>
        <option value="<?= e((string)$chave) ?>"<?= (string)($filtros['ord'] ?? 'nome') === (string)$chave ? ' selected' : '' ?>><?= e((string)$rot) ?></option>
      <?php endforeach ?>
    </select>

    <button class="adm-btn" type="submit"><?= icon('filter_alt') ?><span>Filtrar</span></button>
    <?php if ($temFiltro): ?>
      <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/itens')) ?>"><?= icon('filter_alt_off') ?><span>Limpar</span></a>
    <?php endif ?>
  </form>

  <?php if ($linhas === []): ?>
    <?php $vazio = (array)($VAZIOS[$temFiltro ? 'filtro' : 'catalogo'] ?? []); ?>
    <div class="adm-empty">
      <?= icon($temFiltro ? 'search_off' : 'inventory_2') ?>
      <h3 class="adm-empty__title"><?= e((string)($vazio['titulo'] ?? 'Nada aqui')) ?></h3>
      <p class="adm-empty__txt"><?= e((string)($vazio['txt'] ?? '')) ?></p>
      <?php if ($temFiltro): ?>
        <a class="adm-btn" href="<?= e(url('admin/itens')) ?>"><?= icon('filter_alt_off') ?><span>Limpar filtros</span></a>
      <?php else: ?>
        <a class="adm-btn adm-btn--primary" href="<?= e($aqui(['form' => 'novo'])) ?>#adm-form"><?= icon('add') ?><span>Cadastrar o primeiro item</span></a>
      <?php endif ?>
    </div>
  <?php else: ?>
    <div class="adm-tablewrap">
      <table class="adm-table itens-tabela">
        <caption class="sr-only">Catálogo de itens — <?= e(num($total)) ?> registro(s)</caption>
        <thead>
          <tr>
            <th scope="col">Item</th>
            <th scope="col">Categoria</th>
            <th scope="col">Raridade</th>
            <th class="tc" scope="col">Grade</th>
            <th class="tr" scope="col">Peso</th>
            <th class="tr" scope="col">Preço</th>
            <th class="tr" scope="col" title="<?= e((string)($FRASES['uso_col'] ?? '')) ?>">Em inventários</th>
            <th class="adm-table__acts" scope="col"><span class="sr-only">Ações</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($linhas as $l): ?>
            <?php
              $id     = (int)$l['id'];
              $rar    = (string)$l['rarity'];
              $tomRar = (string)(($RARS[$rar]['tom'] ?? 'muted'));
              $rotRar = (string)(($RARS[$rar]['label'] ?? $rar));
              $arte   = $temArte((string)$l['image']);
              $inst   = (int)$l['inst'];
              $jog    = (int)$l['jogadores'];
              $moeda  = (string)(($MOEDAS[(string)$l['currency']]['curto'] ?? $l['currency']));
              $emEdicao = $edicao !== null && (int)($edicao['id'] ?? 0) === $id;
            ?>
            <tr<?= $emEdicao ? ' class="is-editando"' : '' ?>>
              <td>
                <div class="itens-cel">
                  <?php if ($arte): ?>
                    <img class="itens-cel__art" src="<?= e(media((string)$l['image'])) ?>" alt=""
                         width="40" height="40" loading="lazy" decoding="async">
                  <?php else: ?>
                    <span class="itens-cel__art itens-cel__art--falta"
                          title="<?= e((string)($FRASES['imagem_falta'] ?? '')) ?>"><?= icon('image_not_supported') ?></span>
                  <?php endif ?>
                  <span class="itens-cel__txt">
                    <strong title="<?= e((string)$l['name']) ?>"><?= e((string)$l['name']) ?></strong>
                    <small title="<?= e((string)$l['slug']) ?>"><code><?= e((string)$l['slug']) ?></code><?php if (($l['short_name'] ?? '') !== ''): ?> · <?= e((string)$l['short_name']) ?><?php endif ?></small>
                  </span>
                </div>
              </td>
              <?php
                /* Subtipo + slot numa linha só. A coluna é estreita e o texto trunca com
                   reticências, então o valor inteiro vai para o `title`: truncar sem deixar
                   como ler o resto é esconder dado, não economizar espaço. */
                $sub = [];
                if (($l['subtype'] ?? '') !== '')   { $sub[] = (string)$l['subtype']; }
                if (($l['slot_type'] ?? '') !== '') { $sub[] = $rotulo($SLOTS, (string)$l['slot_type']); }
                $subTxt = $sub === [] ? '—' : implode(' · ', $sub);
              ?>
              <td>
                <span class="itens-cat" title="<?= e($rotulo($CATS, (string)$l['category'])) ?>"><?= e($rotulo($CATS, (string)$l['category'])) ?></span>
                <small class="adm-meta itens-cat__sub" title="<?= e($subTxt) ?>"><?= e($subTxt) ?></small>
              </td>
              <td><span class="adm-badge adm-badge--<?= e($tomRar) ?>"><?= e($rotRar) ?></span></td>
              <td class="tc num itens-grade">
                <?= e((string)(int)$l['grid_w']) ?>×<?= e((string)(int)$l['grid_h']) ?>
                <?php if ((int)$l['stackable'] === 1): ?>
                  <small title="Empilhável até <?= e(num((int)$l['max_stack'])) ?> por célula">×<?= e(num((int)$l['max_stack'])) ?></small>
                <?php endif ?>
              </td>
              <td class="tr num"><?= e(num((float)$l['weight_kg'], 3)) ?><small> kg</small></td>
              <td class="tr num"><?= e(num((int)$l['base_price'])) ?><small> <?= e($moeda) ?></small></td>
              <td class="tr num itens-posse<?= $inst > 0 ? ' is-usado' : '' ?>">
                <?php if ($inst === 0): ?>
                  <span class="itens-posse__zero" title="Ninguém possui este item — ele pode ser excluído.">0</span>
                <?php else: ?>
                  <?= e(num($jog)) ?><small> jog.</small>
                  <span class="itens-posse__inst" title="<?= e(num($inst)) ?> unidade(s) no total"><?= e(num($inst)) ?> un.</span>
                <?php endif ?>
              </td>
              <td class="adm-table__acts">
                <a class="adm-btn adm-btn--sm adm-btn--icon" href="<?= e($aqui(['form' => (string)$id, 'excluir' => null])) ?>#adm-form"
                   title="Editar <?= e((string)$l['name']) ?>" aria-label="Editar <?= e((string)$l['name']) ?>"><?= icon('edit') ?></a>
                <?php /* Excluir nunca apaga direto: leva para a confirmação, que mostra os
                         números medidos e bloqueia quando o item é referenciado. */ ?>
                <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--danger" href="<?= e($aqui(['excluir' => (string)$id, 'form' => null])) ?>#adm-form"
                   title="Excluir <?= e((string)$l['name']) ?>" aria-label="Excluir <?= e((string)$l['name']) ?>"><?= icon('delete') ?></a>
              </td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>

    <?php if ($paginas > 1): ?>
      <div class="adm-card__foot">
        <nav class="adm-pager" aria-label="Paginação do catálogo">
          <span class="adm-pager__info">Página <?= e(num($pagina)) ?> de <?= e(num($paginas)) ?></span>

          <a class="adm-pager__btn<?= $pagina <= 1 ? ' is-disabled' : '' ?>"
             href="<?= e($aqui(['p' => $pagina > 1 ? (string)($pagina - 1) : null])) ?>"
             <?= $pagina <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?> aria-label="Página anterior"><?= icon('chevron_left') ?></a>

          <?php
            /* Janela de 5 páginas em volta da atual, sempre com a primeira e a última à vista:
               com 40 páginas, listar todas transformaria o rodapé numa segunda tabela. */
            $ini = max(1, min($pagina - 2, $paginas - 4));
            $fim = min($paginas, max($pagina + 2, 5));
          ?>
          <?php if ($ini > 1): ?>
            <a class="adm-pager__btn" href="<?= e($aqui(['p' => null])) ?>">1</a>
            <?php if ($ini > 2): ?><span class="adm-pager__gap">…</span><?php endif ?>
          <?php endif ?>

          <?php for ($n = $ini; $n <= $fim; $n++): ?>
            <a class="adm-pager__btn<?= $n === $pagina ? ' is-active' : '' ?>"
               href="<?= e($aqui(['p' => $n > 1 ? (string)$n : null])) ?>"
               <?= $n === $pagina ? 'aria-current="page"' : '' ?>><?= e((string)$n) ?></a>
          <?php endfor ?>

          <?php if ($fim < $paginas): ?>
            <?php if ($fim < $paginas - 1): ?><span class="adm-pager__gap">…</span><?php endif ?>
            <a class="adm-pager__btn" href="<?= e($aqui(['p' => (string)$paginas])) ?>"><?= e((string)$paginas) ?></a>
          <?php endif ?>

          <a class="adm-pager__btn<?= $pagina >= $paginas ? ' is-disabled' : '' ?>"
             href="<?= e($aqui(['p' => (string)min($paginas, $pagina + 1)])) ?>"
             <?= $pagina >= $paginas ? 'aria-disabled="true" tabindex="-1"' : '' ?> aria-label="Próxima página"><?= icon('chevron_right') ?></a>
        </nav>
      </div>
    <?php endif ?>
  <?php endif ?>
</section>
<!--/eh:lista-->
