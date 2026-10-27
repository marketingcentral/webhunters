<?php declare(strict_types=1);

/**
 * Tela ADMIN · EVENTOS (/admin/eventos).
 *
 * Escopo: $page, $player, $nav, $server, $data, $flash, $csrf. Layout: layouts/admin.php.
 *
 * A view não consulta o banco e não valida nada: a página inteira chega pronta de
 * App\Controllers\Admin\EventsController, que por sua vez só conversa com App\Repo\EventsRepo.
 * O que existe aqui é ESCOLHA DE APRESENTAÇÃO — qual coluna cabe, qual estado ganha cor, o
 * que vira `title` em vez de virar uma segunda linha.
 *
 * Três decisões que explicam o desenho:
 *
 * 1. TUDO É LINK OU FORMULÁRIO. A CSP do projeto (app/Core/Security.php) só libera <script>
 *    com nonce, então não existe `onclick="confirm(...)"` aqui. Excluir é uma VIAGEM: a
 *    lixeira leva a `?excluir=<id>`, que desenha um cartão dizendo o que some, se algo
 *    impede, e só então oferece o POST. Confirmação sem JavaScript e com endereço próprio.
 *
 * 2. NÚMERO À DIREITA, EM FONTE TABULAR (classe .num de components.css). Alvo, XP, créditos
 *    e ordem são comparados de relance entre linhas; centralizados ou em fonte proporcional
 *    eles deixam de formar coluna e a comparação morre.
 *
 * 3. ESTADO TEM COR E TEXTO. Nenhuma informação desta tela depende só de matiz: rascunho é
 *    "RASCUNHO" escrito, aviso global é "GLOBAL" escrito, desafio destacado tem o ícone e o
 *    `title`. A cor é reforço, nunca o único portador.
 */

$cromo   = (array)($data['cromo'] ?? []);
$abas    = (array)($cromo['abas'] ?? []);
$escopos = (array)($cromo['escopos'] ?? []);
$cores   = (array)($cromo['cores'] ?? []);
$vazios  = (array)($cromo['vazios'] ?? []);
$notas   = (array)($cromo['notas'] ?? []);

$aba     = (string)($data['aba'] ?? 'desafios');
$f       = (array)($data['filtros'] ?? []);
$resumo  = (array)($data['resumo'] ?? []);
$lista   = (array)($data['lista'] ?? ['linhas' => [], 'total' => 0, 'paginas' => 1, 'pagina' => 1]);
$linhas  = (array)($lista['linhas'] ?? []);
$total   = (int)($lista['total'] ?? 0);
$paginas = (int)($lista['paginas'] ?? 1);
$atual   = (int)($lista['pagina'] ?? 1);

$form    = is_array($data['form'] ?? null) ? $data['form'] : null;
$erros   = (array)($data['erros'] ?? []);
$excluir = is_array($data['excluir'] ?? null) ? $data['excluir'] : null;
$volta   = (string)($data['volta'] ?? url('admin/eventos'));

$hoje = date('Y-m-d');

/* Algum filtro está ligado? É o que separa "catálogo vazio" de "filtro apertado demais" —
   as duas telas vazias mais parecidas do mundo e as duas com solução oposta. */
$filtrando = ($f['q'] ?? '') !== '' || ($f['escopo'] ?? '') !== ''
          || ($f['estado'] ?? '') !== '' || ($f['cat'] ?? '') !== '' || ($f['destino'] ?? '') !== '';

/* =========================================================================================
   MONTADORES DE LINK — todo link desta tela preserva o estado da listagem
   ========================================================================================= */

/** Base da query: o que precisa sobreviver a qualquer clique. */
$base = array_filter([
    'aba'     => $aba,
    'q'       => (string)($f['q'] ?? ''),
    'escopo'  => (string)($f['escopo'] ?? ''),
    'estado'  => (string)($f['estado'] ?? ''),
    'cat'     => (string)($f['cat'] ?? ''),
    'destino' => (string)($f['destino'] ?? ''),
    'ord'     => (string)($f['ord'] ?? ''),
    'dir'     => (string)($f['ord'] ?? '') !== '' ? (string)($f['dir'] ?? 'ASC') : '',
    'p'       => $atual > 1 ? (string)$atual : '',
], static fn(string $v): bool => $v !== '');

/** url('admin/eventos', base + $extra); chave com valor '' é REMOVIDA da query. */
$lnk = static function (array $extra = []) use ($base): string {
    $q = $extra + $base;
    return url('admin/eventos', array_filter($q, static fn($v): bool => (string)$v !== ''));
};

/**
 * Cabeçalho ordenável. Clicar na coluna que já ordena INVERTE a direção; clicar noutra
 * começa ascendente. A seta só aparece na coluna ativa — seta em toda coluna vira ruído.
 */
$th = static function (string $chave, string $rotulo, string $classe = '') use ($f, $lnk): string {
    $ativa = (string)($f['ord'] ?? '') === $chave;
    $dir   = $ativa && (string)($f['dir'] ?? 'ASC') === 'ASC' ? 'DESC' : 'ASC';
    $href  = $lnk(['ord' => $chave, 'dir' => $dir, 'p' => '']);
    $seta  = $ativa ? icon((string)($f['dir'] ?? 'ASC') === 'DESC' ? 'arrow_downward' : 'arrow_upward', 'ev-sort__ic') : '';
    return '<th class="' . e($classe) . '"><a class="ev-sort' . ($ativa ? ' is-on' : '') . '" href="'
        . e($href) . '">' . e($rotulo) . $seta . '</a></th>';
};

/* Valor de um campo do formulário: o que o operador digitou (POST recusado) ou o que está no
   banco (edição), ou o padrão (criação). Um só ponto de leitura para os quatro formulários. */
$valores = (array)($form['valores'] ?? []);
$val = static function (string $campo, string $padrao = '') use ($valores): string {
    $v = $valores[$campo] ?? null;
    return $v === null ? $padrao : (string)$v;
};
$marcado = static function (string $campo) use ($valores, $form): bool {
    // Numa criação (sem valores) o padrão é desmarcado; num POST recusado, a caixa que não
    // veio no corpo é desmarcada mesmo — checkbox não enviado é checkbox desligado.
    return isset($valores[$campo]) && (string)$valores[$campo] !== '' && (string)$valores[$campo] !== '0';
};
$erro = static function (string $campo) use ($erros): string {
    return isset($erros[$campo])
        ? '<span class="adm-error">' . icon('error') . e((string)$erros[$campo]) . '</span>'
        : '';
};
$invalido = static fn(string $campo): string => isset($erros[$campo]) ? ' is-invalid' : '';

/** "12/05/2026" a partir de "2026-05-12"; vazio vira travessão. */
$dataBr = static function (?string $iso): string {
    $iso = trim((string)$iso);
    if ($iso === '') { return '—'; }
    $d = substr($iso, 0, 10);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1
        ? substr($d, 8, 2) . '/' . substr($d, 5, 2) . '/' . substr($d, 0, 4)
        : $d;
};
?>

<?php /* ---------------------------------------------------------------- abas + contagem */ ?>
<nav class="ev-tabs" aria-label="Catálogos de evento">
  <?php foreach ($abas as $slug => $meta): ?>
    <?php
      $on    = $slug === $aba;
      $conta = (int)($resumo[(string)($meta['conta'] ?? '')] ?? 0);
    ?>
    <?php /* data-adm-recarrega: trocar de aba não é filtrar. Muda a tabela, as colunas, o
             formulário e o painel de bônus de uma vez — é tela nova, e recarregar é o
             honesto. Sem isto o atualizador de lista trocaria só a tabela e deixaria estas
             abas apontando para a aba anterior. */ ?>
    <a class="ev-tab<?= $on ? ' is-on' : '' ?>" href="<?= e(url('admin/eventos', ['aba' => $slug])) ?>"
       data-adm-recarrega <?= $on ? 'aria-current="page"' : '' ?>>
      <?= icon((string)($meta['icon'] ?? 'circle'), 'ev-tab__ic') ?>
      <span class="ev-tab__txt"><?= e((string)($meta['label'] ?? $slug)) ?></span>
      <span class="ev-tab__n num"><?= e(num($conta)) ?></span>
    </a>
  <?php endforeach ?>
</nav>
<p class="ev-sub"><?= e((string)($abas[$aba]['sub'] ?? '')) ?></p>

<?php /* ------------------------------------------------------- confirmação de exclusão */ ?>
<?php if ($excluir !== null): ?>
  <?php $bloqueado = ($excluir['impedimento'] ?? null) !== null; ?>
  <section class="adm-card ev-confirm<?= $bloqueado ? ' ev-confirm--blocked' : '' ?>">
    <header class="adm-card__head">
      <?= icon($bloqueado ? 'block' : 'delete_forever', 'ev-confirm__ic') ?>
      <h2 class="adm-card__title">
        <?= $bloqueado ? 'Não dá para excluir' : 'Confirmar exclusão' ?>
        <small><?= e((string)$excluir['detalhe']) ?></small>
      </h2>
    </header>
    <div class="adm-card__body">
      <p class="ev-confirm__alvo"><?= e((string)$excluir['titulo']) ?></p>
      <?php if ($bloqueado): ?>
        <p class="ev-confirm__txt"><?= e((string)$excluir['impedimento']) ?></p>
      <?php else: ?>
        <p class="ev-confirm__txt">
          A remoção é definitiva e não tem desfazer nesta tela. Fica registrada na trilha de
          auditoria com o seu nome, e o registro some do jogo no próximo carregamento.
        </p>
      <?php endif ?>
    </div>
    <footer class="adm-card__foot">
      <?php if (!$bloqueado): ?>
        <form method="post" action="<?= e(url('admin/eventos/excluir')) ?>" class="ev-confirm__form">
          <?= csrf_field() ?>
          <input type="hidden" name="tipo" value="<?= e((string)$excluir['tipo']) ?>">
          <input type="hidden" name="id" value="<?= e((string)$excluir['id']) ?>">
          <input type="hidden" name="_volta" value="<?= e($volta) ?>">
          <button class="adm-btn adm-btn--danger" type="submit"><?= icon('delete') ?>Excluir definitivamente</button>
        </form>
      <?php endif ?>
      <a class="adm-btn adm-btn--ghost" href="<?= e($volta) ?>"><?= $bloqueado ? 'Voltar à lista' : 'Cancelar' ?></a>
    </footer>
  </section>
<?php endif ?>

<?php /* ------------------------------------------------------------------- formulários */ ?>
<?php if ($form !== null): ?>
  <?php
    $tipo    = (string)($form['tipo'] ?? '');
    $idForm  = (string)($form['id'] ?? '');
    $editando= $idForm !== '';
    $titulos = [
        'desafio' => ['Novo desafio', 'Editar desafio'],
        'bonus'   => ['Novo bônus de escopo', 'Editar bônus de escopo'],
        'noticia' => ['Nova notícia', 'Editar notícia'],
        'aviso'   => ['Novo aviso do sistema', 'Editar aviso do sistema'],
    ];
  ?>
  <section class="adm-card ev-form" id="form">
    <header class="adm-card__head">
      <h2 class="adm-card__title">
        <?= e($titulos[$tipo][$editando ? 1 : 0] ?? 'Registro') ?>
        <?php if ($editando): ?><small>#<?= e($idForm) ?></small><?php endif ?>
      </h2>
      <?php if ($erros !== []): ?>
        <span class="adm-badge adm-badge--danger adm-card__tools"><?= e(num(count($erros))) ?> campo(s) para corrigir</span>
      <?php endif ?>
    </header>

    <form class="adm-form adm-card__body" method="post" action="<?= e(url('admin/eventos/salvar')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
      <input type="hidden" name="id" value="<?= e($idForm) ?>">
      <input type="hidden" name="_volta" value="<?= e($volta) ?>">

      <?php /* ---------------------------------------------------------------- desafio */ ?>
      <?php if ($tipo === 'desafio'): ?>
        <div class="adm-form__row">
          <div class="adm-field<?= $invalido('label') ?>">
            <label class="adm-label" for="c-label">Título do desafio<span class="adm-label__req">*</span></label>
            <input class="adm-input" id="c-label" name="label" maxlength="160" required
                   value="<?= e($val('label')) ?>" placeholder="Elimine 20 inimigos">
            <?= $erro('label') ?>
          </div>
          <div class="adm-field<?= $invalido('slug') ?>">
            <label class="adm-label" for="c-slug">Identificador<span class="adm-label__req">*</span></label>
            <input class="adm-input" id="c-slug" name="slug" maxlength="64" required
                   value="<?= e($val('slug')) ?>" placeholder="elimine-20">
            <?= $erro('slug') ?: '<span class="adm-hint">É por ele que o jogo encontra o desafio. Trocar depois zera a ligação com o progresso salvo.</span>' ?>
          </div>
        </div>

        <div class="adm-field<?= $invalido('description') ?>">
          <label class="adm-label" for="c-desc">Descrição</label>
          <input class="adm-input" id="c-desc" name="description" maxlength="400" value="<?= e($val('description')) ?>">
          <?= $erro('description') ?>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field<?= $invalido('scope') ?>">
            <label class="adm-label" for="c-scope">Escopo<span class="adm-label__req">*</span></label>
            <select class="adm-select" id="c-scope" name="scope">
              <?php foreach ($escopos as $k => $rot): ?>
                <option value="<?= e($k) ?>"<?= $val('scope', 'diarios') === $k ? ' selected' : '' ?>><?= e($rot) ?></option>
              <?php endforeach ?>
            </select>
            <?= $erro('scope') ?>
          </div>
          <div class="adm-field<?= $invalido('season_id') ?>">
            <label class="adm-label" for="c-season">Temporada</label>
            <select class="adm-select" id="c-season" name="season_id">
              <option value="0">— só para escopo Temporada —</option>
              <?php foreach ((array)($data['temporadas'] ?? []) as $t): ?>
                <option value="<?= e((string)$t['id']) ?>"<?= $val('season_id') === (string)$t['id'] ? ' selected' : '' ?>>
                  <?= e((string)$t['tag'] . ' · ' . (string)$t['pass_name']) ?><?= (int)$t['is_current'] === 1 ? ' (atual)' : '' ?>
                </option>
              <?php endforeach ?>
            </select>
            <?= $erro('season_id') ?>
          </div>
          <div class="adm-field<?= $invalido('icon') ?>">
            <label class="adm-label" for="c-icon">Ícone</label>
            <input class="adm-input" id="c-icon" name="icon" maxlength="48" value="<?= e($val('icon')) ?>" placeholder="military_tech">
            <?= $erro('icon') ?: '<span class="adm-hint">Nome do Material Symbols.</span>' ?>
          </div>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field<?= $invalido('target') ?>">
            <label class="adm-label" for="c-target">Alvo<span class="adm-label__req">*</span></label>
            <input class="adm-input num" id="c-target" name="target" inputmode="numeric" value="<?= e($val('target', '1')) ?>">
            <?= $erro('target') ?: '<span class="adm-hint">Quantas vezes precisa acontecer.</span>' ?>
          </div>
          <div class="adm-field<?= $invalido('xp') ?>">
            <label class="adm-label" for="c-xp">XP</label>
            <input class="adm-input num" id="c-xp" name="xp" inputmode="numeric" value="<?= e($val('xp', '0')) ?>">
            <?= $erro('xp') ?>
          </div>
          <div class="adm-field<?= $invalido('credits') ?>">
            <label class="adm-label" for="c-cred">Créditos</label>
            <input class="adm-input num" id="c-cred" name="credits" inputmode="numeric" value="<?= e($val('credits', '0')) ?>">
            <?= $erro('credits') ?>
          </div>
        </div>

        <div class="adm-form__row">
          <div class="adm-field<?= $invalido('sort_order') ?>">
            <label class="adm-label" for="c-ord">Ordem na aba</label>
            <input class="adm-input num" id="c-ord" name="sort_order" inputmode="numeric" value="<?= e($val('sort_order', '0')) ?>">
            <?= $erro('sort_order') ?: '<span class="adm-hint">Menor aparece primeiro.</span>' ?>
          </div>
          <div class="adm-field">
            <span class="adm-label">Destaque</span>
            <label class="adm-switch">
              <input class="adm-switch__in" type="checkbox" name="highlight" value="1"<?= $marcado('highlight') ? ' checked' : '' ?>>
              <span class="adm-switch__ui"></span>
              <span>Linha dourada — o desafio “quente” da aba</span>
            </label>
          </div>
        </div>
      <?php endif ?>

      <?php /* ------------------------------------------------------------------ bônus */ ?>
      <?php if ($tipo === 'bonus'): ?>
        <div class="adm-form__row">
          <div class="adm-field<?= $invalido('scope') ?>">
            <label class="adm-label" for="b-scope">Escopo<span class="adm-label__req">*</span></label>
            <?php if ($editando): ?>
              <input class="adm-input" id="b-scope" value="<?= e((string)($escopos[$idForm] ?? $idForm)) ?>" disabled>
              <span class="adm-hint">O escopo é a chave do bônus e não muda — existe um por aba do jogo.</span>
            <?php elseif (($data['escopos_livres'] ?? []) === []): ?>
              <?php /* Os três escopos já têm bônus e a chave primária é o escopo: não há o que
                       criar. Um <select> vazio deixaria o operador tentar e levar um erro. */ ?>
              <input class="adm-input" id="b-scope" value="nenhum escopo livre" disabled>
              <span class="adm-hint">Os três escopos já têm bônus — só dá para editar os que existem.</span>
            <?php else: ?>
              <select class="adm-select" id="b-scope" name="scope">
                <?php foreach ((array)($data['escopos_livres'] ?? []) as $k): ?>
                  <option value="<?= e($k) ?>"<?= $val('scope') === $k ? ' selected' : '' ?>><?= e((string)($escopos[$k] ?? $k)) ?></option>
                <?php endforeach ?>
              </select>
              <?= $erro('scope') ?>
            <?php endif ?>
          </div>
          <div class="adm-field<?= $invalido('label') ?>">
            <label class="adm-label" for="b-label">Tarja<span class="adm-label__req">*</span></label>
            <input class="adm-input" id="b-label" name="label" maxlength="40" required
                   value="<?= e($val('label')) ?>" placeholder="BÔNUS DIÁRIO">
            <?= $erro('label') ?>
          </div>
        </div>
        <div class="adm-form__row">
          <div class="adm-field<?= $invalido('title') ?>">
            <label class="adm-label" for="b-title">Título<span class="adm-label__req">*</span></label>
            <input class="adm-input" id="b-title" name="title" maxlength="80" required
                   value="<?= e($val('title')) ?>" placeholder="CAIXA DE SUPRIMENTOS">
            <?= $erro('title') ?>
          </div>
          <div class="adm-field<?= $invalido('image') ?>">
            <label class="adm-label" for="b-img">Imagem</label>
            <?php /* Campo de caminho + envio de arquivo (components/admin-upload.php).
                     Mesmo name="image": nada muda no controller nem no banco. */ ?>
            <?php component('admin-upload', [
                'id'          => 'b-img',
                'name'        => 'image',
                'entidade'    => 'eventos',
                'valor'       => $val('image'),
                'maxlength'   => 160,
                'placeholder' => 'items/supply-crate.png',
                'invalido'    => $invalido('image') !== '',
            ]) ?>
            <?= $erro('image') ?: '<span class="adm-hint">Caminho relativo dentro de /media.</span>' ?>
          </div>
        </div>
        <div class="adm-field<?= $invalido('description') ?>">
          <label class="adm-label" for="b-desc">Descrição</label>
          <input class="adm-input" id="b-desc" name="description" maxlength="400" value="<?= e($val('description')) ?>">
          <?= $erro('description') ?>
        </div>
        <div class="adm-form__row">
          <div class="adm-field<?= $invalido('xp') ?>">
            <label class="adm-label" for="b-xp">XP</label>
            <input class="adm-input num" id="b-xp" name="xp" inputmode="numeric" value="<?= e($val('xp', '0')) ?>">
            <?= $erro('xp') ?>
          </div>
          <div class="adm-field<?= $invalido('credits') ?>">
            <label class="adm-label" for="b-cred">Créditos</label>
            <input class="adm-input num" id="b-cred" name="credits" inputmode="numeric" value="<?= e($val('credits', '0')) ?>">
            <?= $erro('credits') ?>
          </div>
        </div>
      <?php endif ?>

      <?php /* ---------------------------------------------------------------- notícia */ ?>
      <?php if ($tipo === 'noticia'): ?>
        <div class="adm-form__row">
          <div class="adm-field<?= $invalido('title') ?>">
            <label class="adm-label" for="n-title">Título<span class="adm-label__req">*</span></label>
            <input class="adm-input" id="n-title" name="title" maxlength="160" required value="<?= e($val('title')) ?>">
            <?= $erro('title') ?>
          </div>
          <div class="adm-field<?= $invalido('slug') ?>">
            <label class="adm-label" for="n-slug">Identificador<span class="adm-label__req">*</span></label>
            <input class="adm-input" id="n-slug" name="slug" maxlength="80" required value="<?= e($val('slug')) ?>">
            <?= $erro('slug') ?: '<span class="adm-hint">As janelas de manutenção referenciam a notícia por este texto.</span>' ?>
          </div>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field<?= $invalido('category') ?>">
            <label class="adm-label" for="n-cat">Categoria</label>
            <input class="adm-input" id="n-cat" name="category" maxlength="24" list="ev-cats" value="<?= e($val('category')) ?>">
            <datalist id="ev-cats">
              <?php foreach ((array)($data['categorias'] ?? []) as $c): ?>
                <option value="<?= e($c) ?>"></option>
              <?php endforeach ?>
            </datalist>
            <?= $erro('category') ?>
          </div>
          <div class="adm-field<?= $invalido('published') ?>">
            <label class="adm-label" for="n-pub">Publicação</label>
            <input class="adm-input" id="n-pub" name="published" type="date" value="<?= e(substr($val('published'), 0, 10)) ?>">
            <?= $erro('published') ?: '<span class="adm-hint">Em branco = rascunho (não sai da gaveta).</span>' ?>
          </div>
          <div class="adm-field<?= $invalido('sort_order') ?>">
            <label class="adm-label" for="n-ord">Ordem no mural</label>
            <input class="adm-input num" id="n-ord" name="sort_order" inputmode="numeric" value="<?= e($val('sort_order', '0')) ?>">
            <?= $erro('sort_order') ?>
          </div>
        </div>

        <div class="adm-form__row">
          <div class="adm-field<?= $invalido('excerpt') ?>">
            <label class="adm-label" for="n-exc">Resumo</label>
            <input class="adm-input" id="n-exc" name="excerpt" maxlength="400" value="<?= e($val('excerpt')) ?>">
            <?= $erro('excerpt') ?: '<span class="adm-hint">A chamada do card no mural.</span>' ?>
          </div>
          <div class="adm-field<?= $invalido('image') ?>">
            <label class="adm-label" for="n-img">Imagem</label>
            <?php /* A notícia tem pasta própria: a capa do mural não é arte de evento, e
                     misturar as duas em media/uploads/eventos/ deixaria a pasta ilegível. */ ?>
            <?php component('admin-upload', [
                'id'          => 'n-img',
                'name'        => 'image',
                'entidade'    => 'noticias',
                'valor'       => $val('image'),
                'maxlength'   => 160,
                'placeholder' => 'news/patch-8-8-1.jpg',
                'invalido'    => $invalido('image') !== '',
            ]) ?>
            <?= $erro('image') ?>
          </div>
        </div>

        <div class="adm-field<?= $invalido('body') ?>">
          <label class="adm-label" for="n-body">Corpo</label>
          <textarea class="adm-textarea" id="n-body" name="body" rows="8"><?= e($val('body')) ?></textarea>
          <?= $erro('body') ?: '<span class="adm-hint">' . e((string)($notas['corpo_noticia'] ?? '')) . '</span>' ?>
        </div>
      <?php endif ?>

      <?php /* ------------------------------------------------------------------ aviso */ ?>
      <?php if ($tipo === 'aviso'): ?>
        <?php $destino = $val('destino', 'global'); ?>
        <div class="adm-form__row">
          <div class="adm-field<?= $invalido('destino') ?>">
            <span class="adm-label">Destino<span class="adm-label__req">*</span></span>
            <div class="ev-radios">
              <label class="adm-check">
                <input type="radio" name="destino" value="global"<?= $destino !== 'jogador' ? ' checked' : '' ?>>
                <span>Global — todos os jogadores</span>
              </label>
              <label class="adm-check">
                <input type="radio" name="destino" value="jogador"<?= $destino === 'jogador' ? ' checked' : '' ?>>
                <span>Um jogador</span>
              </label>
            </div>
            <?= $erro('destino') ?>
          </div>
          <div class="adm-field<?= $invalido('destinatario') ?>">
            <label class="adm-label" for="a-dest">Jogador</label>
            <input class="adm-input" id="a-dest" name="destinatario" list="ev-jogadores"
                   value="<?= e($val('destinatario')) ?>" placeholder="HUNTER_07">
            <?php $sug = (array)($data['jogadores'] ?? []); ?>
            <datalist id="ev-jogadores">
              <?php foreach ($sug as $u): ?><option value="<?= e($u) ?>"></option><?php endforeach ?>
            </datalist>
            <?= $erro('destinatario') ?: '<span class="adm-hint">Só é lido quando o destino é “um jogador”.</span>' ?>
          </div>
        </div>

        <p class="ev-nota"><?= icon('info', 'ev-nota__ic') ?><span><?= e((string)($notas['aviso_global'] ?? '')) ?></span></p>

        <div class="adm-form__row">
          <div class="adm-field<?= $invalido('title') ?>">
            <label class="adm-label" for="a-title">Título<span class="adm-label__req">*</span></label>
            <input class="adm-input" id="a-title" name="title" maxlength="120" required value="<?= e($val('title')) ?>">
            <?= $erro('title') ?>
          </div>
          <div class="adm-field<?= $invalido('body') ?>">
            <label class="adm-label" for="a-body">Texto<span class="adm-label__req">*</span></label>
            <input class="adm-input" id="a-body" name="body" maxlength="400" required value="<?= e($val('body')) ?>">
            <?= $erro('body') ?>
          </div>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field<?= $invalido('color') ?>">
            <label class="adm-label" for="a-color">Cor<span class="adm-label__req">*</span></label>
            <select class="adm-select" id="a-color" name="color">
              <?php foreach ($cores as $k => $meta): ?>
                <option value="<?= e($k) ?>"<?= $val('color', 'lime') === $k ? ' selected' : '' ?>><?= e((string)$meta['label']) ?></option>
              <?php endforeach ?>
            </select>
            <?= $erro('color') ?: '<span class="adm-hint">' . e((string)($cores[$val('color', 'lime')]['hint'] ?? '')) . '</span>' ?>
          </div>
          <div class="adm-field<?= $invalido('icon') ?>">
            <label class="adm-label" for="a-icon">Ícone<span class="adm-label__req">*</span></label>
            <input class="adm-input" id="a-icon" name="icon" maxlength="48" required value="<?= e($val('icon', 'info')) ?>">
            <?= $erro('icon') ?>
          </div>
          <div class="adm-field<?= $invalido('sort_order') ?>">
            <label class="adm-label" for="a-ord">Ordem</label>
            <input class="adm-input num" id="a-ord" name="sort_order" inputmode="numeric" value="<?= e($val('sort_order', '0')) ?>">
            <?= $erro('sort_order') ?>
          </div>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field<?= $invalido('cta_label') ?>">
            <label class="adm-label" for="a-cta">Rótulo do botão</label>
            <input class="adm-input" id="a-cta" name="cta_label" maxlength="40" value="<?= e($val('cta_label')) ?>" placeholder="RESGATAR">
            <?= $erro('cta_label') ?>
          </div>
          <div class="adm-field<?= $invalido('cta_href') ?>">
            <label class="adm-label" for="a-href">Destino do botão</label>
            <input class="adm-input" id="a-href" name="cta_href" maxlength="120" value="<?= e($val('cta_href')) ?>" placeholder="loja">
            <?= $erro('cta_href') ?: '<span class="adm-hint">Rótulo e destino andam juntos: ou os dois, ou nenhum.</span>' ?>
          </div>
          <div class="adm-field">
            <span class="adm-label">Marcador</span>
            <label class="adm-switch">
              <input class="adm-switch__in" type="checkbox" name="fresh" value="1"<?= $marcado('fresh') ? ' checked' : '' ?>>
              <span class="adm-switch__ui"></span>
              <span>Marcar como novo</span>
            </label>
          </div>
        </div>
      <?php endif ?>

      <div class="adm-form__actions">
        <button class="adm-btn adm-btn--primary" type="submit"><?= icon('save') ?><?= $editando ? 'Salvar alterações' : 'Criar' ?></button>
        <a class="adm-btn adm-btn--ghost" href="<?= e($volta) ?>">Cancelar</a>
        <?php if ($editando && $tipo !== 'bonus'): ?>
          <a class="adm-btn adm-btn--danger adm-spacer"
             href="<?= e($lnk(['excluir' => $idForm, 'tipo' => $tipo, 'form' => '', 'id' => ''])) ?>">
            <?= icon('delete') ?>Excluir
          </a>
        <?php endif ?>
      </div>
    </form>
  </section>
<?php endif ?>

<?php /* ------------------------------------------------------------- listagem da aba */ ?>
<?php /* Região da lista (App\Core\Fragmento): o pedaço que volta do servidor quando o
         filtro é aplicado sem recarregar a página. */ ?>
<!--eh:lista-->
<section class="adm-card ev-list">
  <?php /* data-adm-preservar: transplantado para dentro do HTML novo em vez de substituído
           — é onde está o cursor de quem digita. O campo oculto `aba` acompanha a URL. */ ?>
  <form class="adm-toolbar" method="get" action="<?= e(url('admin/eventos')) ?>"
        data-adm-preservar="filtros">
    <input type="hidden" name="aba" value="<?= e($aba) ?>">
    <div class="adm-toolbar__grow">
      <div class="adm-search">
        <?= icon('search') ?>
        <input class="adm-input" type="search" name="q" maxlength="80" value="<?= e((string)($f['q'] ?? '')) ?>"
               placeholder="<?= e($aba === 'desafios' ? 'Buscar por título, identificador ou descrição' : ($aba === 'noticias' ? 'Buscar por título, identificador ou resumo' : 'Buscar por título, texto ou jogador')) ?>"
               aria-label="Buscar">
      </div>
    </div>

    <?php if ($aba === 'desafios'): ?>
      <select class="adm-select ev-filtro" name="escopo" aria-label="Filtrar por escopo">
        <option value="">Todos os escopos</option>
        <?php foreach ($escopos as $k => $rot): ?>
          <option value="<?= e($k) ?>"<?= (string)($f['escopo'] ?? '') === $k ? ' selected' : '' ?>><?= e($rot) ?></option>
        <?php endforeach ?>
        <option value="destaque"<?= (string)($f['escopo'] ?? '') === 'destaque' ? ' selected' : '' ?>>Só destaques</option>
      </select>
    <?php elseif ($aba === 'noticias'): ?>
      <select class="adm-select ev-filtro" name="estado" aria-label="Filtrar por estado">
        <option value="">Todos os estados</option>
        <option value="publicada"<?= (string)($f['estado'] ?? '') === 'publicada' ? ' selected' : '' ?>>Publicadas</option>
        <option value="rascunho"<?= (string)($f['estado'] ?? '') === 'rascunho' ? ' selected' : '' ?>>Rascunhos</option>
        <option value="futura"<?= (string)($f['estado'] ?? '') === 'futura' ? ' selected' : '' ?>>Com data futura</option>
      </select>
      <select class="adm-select ev-filtro" name="cat" aria-label="Filtrar por categoria">
        <option value="">Todas as categorias</option>
        <?php foreach ((array)($data['categorias'] ?? []) as $c): ?>
          <option value="<?= e($c) ?>"<?= (string)($f['cat'] ?? '') === $c ? ' selected' : '' ?>><?= e($c) ?></option>
        <?php endforeach ?>
      </select>
    <?php else: ?>
      <select class="adm-select ev-filtro" name="destino" aria-label="Filtrar por destino">
        <option value="">Todos os destinos</option>
        <option value="global"<?= (string)($f['destino'] ?? '') === 'global' ? ' selected' : '' ?>>Só globais</option>
        <option value="jogador"<?= (string)($f['destino'] ?? '') === 'jogador' ? ' selected' : '' ?>>Só de jogador</option>
        <option value="novo"<?= (string)($f['destino'] ?? '') === 'novo' ? ' selected' : '' ?>>Marcados como novos</option>
      </select>
    <?php endif ?>

    <?php /* A ordenação escolhida por cabeçalho precisa sobreviver ao envio da busca. */ ?>
    <?php if ((string)($f['ord'] ?? '') !== ''): ?>
      <input type="hidden" name="ord" value="<?= e((string)$f['ord']) ?>">
      <input type="hidden" name="dir" value="<?= e((string)($f['dir'] ?? 'ASC')) ?>">
    <?php endif ?>

    <button class="adm-btn" type="submit"><?= icon('filter_alt') ?>Filtrar</button>
    <?php if ($filtrando): ?>
      <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/eventos', ['aba' => $aba])) ?>"><?= icon('close') ?>Limpar</a>
    <?php endif ?>
  </form>

  <?php if ($linhas === []): ?>
    <?php $v = (array)($vazios[$filtrando ? 'filtro' : $aba] ?? []); ?>
    <div class="adm-empty">
      <?= icon($filtrando ? 'filter_alt_off' : 'inbox') ?>
      <h2 class="adm-empty__title"><?= e((string)($v['titulo'] ?? 'Nada aqui')) ?></h2>
      <p class="adm-empty__txt"><?= e((string)($v['txt'] ?? '')) ?></p>
      <?php if ($filtrando): ?>
        <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/eventos', ['aba' => $aba])) ?>">Limpar filtros</a>
      <?php endif ?>
    </div>

  <?php else: ?>
    <div class="adm-tablewrap">
      <table class="adm-table">

        <?php /* -------------------------------------------------------- desafios */ ?>
        <?php if ($aba === 'desafios'): ?>
          <thead>
            <tr>
              <th>Escopo</th>
              <?= $th('nome', 'Desafio') ?>
              <?= $th('alvo', 'Alvo', 'tr') ?>
              <?= $th('xp', 'XP', 'tr') ?>
              <?= $th('cred', 'Créditos', 'tr') ?>
              <?= $th('jog', 'Jogadores', 'tr') ?>
              <?= $th('ordem', 'Ordem', 'tr') ?>
              <th class="adm-table__acts"><span class="ev-sr">Ações</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($linhas as $r): ?>
              <?php $emUso = (int)$r['jogadores'] > 0; ?>
              <tr>
                <td>
                  <span class="adm-badge adm-badge--<?= e((string)$r['scope'] === 'temporada' ? 'purple' : ((string)$r['scope'] === 'semanais' ? 'info' : 'ok')) ?>">
                    <?= e((string)($escopos[(string)$r['scope']] ?? $r['scope'])) ?>
                  </span>
                  <?php if ($r['temporada'] !== null): ?>
                    <small class="ev-dim"><?= e((string)$r['temporada']) ?></small>
                  <?php endif ?>
                </td>
                <td class="ev-cel-nome">
                  <span class="ev-nome">
                    <?php if ((int)$r['highlight'] === 1): ?>
                      <span class="ev-hot" title="Desafio em destaque — linha dourada na tela do jogo"><?= icon('local_fire_department') ?></span>
                    <?php endif ?>
                    <strong><?= e((string)$r['label']) ?></strong>
                  </span>
                  <small class="ev-dim"><?= e((string)$r['slug']) ?><?= $r['description'] !== null ? ' · ' . e(mb_strimwidth((string)$r['description'], 0, 64, '…')) : '' ?></small>
                </td>
                <td class="tr num"><?= e(num((int)$r['target'])) ?></td>
                <td class="tr num"><?= e(num((int)$r['xp'])) ?></td>
                <td class="tr num"><?= e(num((int)$r['credits'])) ?></td>
                <td class="tr num<?= $emUso ? ' ev-uso' : '' ?>"
                    title="<?= e($emUso ? (string)$r['jogadores'] . ' jogador(es) com progresso · ' . (string)$r['concluidos'] . ' concluíram' : 'Ninguém começou este desafio') ?>">
                  <?= e(num((int)$r['jogadores'])) ?>
                </td>
                <td class="tr num"><?= e(num((int)$r['sort_order'])) ?></td>
                <td class="adm-table__acts">
                  <a class="adm-btn adm-btn--sm adm-btn--icon" title="Editar"
                     href="<?= e($lnk(['form' => 'desafio', 'id' => (string)$r['id']])) ?>"><?= icon('edit') ?></a>
                  <?php if ($emUso): ?>
                    <span class="adm-btn adm-btn--sm adm-btn--icon is-disabled"
                          title="Não pode ser excluído: <?= e((string)$r['jogadores']) ?> jogador(es) já têm progresso gravado"><?= icon('lock') ?></span>
                  <?php else: ?>
                    <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--danger" title="Excluir"
                       href="<?= e($lnk(['excluir' => (string)$r['id'], 'tipo' => 'desafio'])) ?>"><?= icon('delete') ?></a>
                  <?php endif ?>
                </td>
              </tr>
            <?php endforeach ?>
          </tbody>
        <?php endif ?>

        <?php /* -------------------------------------------------------- notícias */ ?>
        <?php if ($aba === 'noticias'): ?>
          <thead>
            <tr>
              <th>Estado</th>
              <?= $th('data', 'Publicação', 'tr') ?>
              <?= $th('nome', 'Notícia') ?>
              <?= $th('cat', 'Categoria') ?>
              <?= $th('ordem', 'Ordem', 'tr') ?>
              <th class="adm-table__acts"><span class="ev-sr">Ações</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($linhas as $r): ?>
              <?php
                $pub    = (string)($r['published'] ?? '');
                $futura = $pub !== '' && $pub > $hoje;
                $presa  = (int)$r['janelas'] > 0;
              ?>
              <tr>
                <td>
                  <?php if ($pub === ''): ?>
                    <span class="adm-badge adm-badge--warn">Rascunho</span>
                  <?php else: ?>
                    <span class="adm-badge adm-badge--ok">Publicada</span>
                  <?php endif ?>
                </td>
                <td class="tr num<?= $futura ? ' ev-futura' : '' ?>"
                    title="<?= e($futura ? (string)($notas['noticia_futura'] ?? '') : '') ?>">
                  <?= e($dataBr($pub)) ?><?= $futura ? ' ' . icon('schedule', 'ev-futura__ic') : '' ?>
                </td>
                <td class="ev-cel-nome">
                  <span class="ev-nome"><strong><?= e((string)$r['title']) ?></strong></span>
                  <small class="ev-dim">
                    <?= e((string)$r['slug']) ?>
                    · <?= e(num((int)$r['tamanho_corpo'])) ?> car.
                    <?php if ($presa): ?>
                      · <span class="ev-preso" title="Referenciada por manutenção — não pode ser excluída"><?= e(num((int)$r['janelas'])) ?> janela(s) de manutenção</span>
                    <?php endif ?>
                  </small>
                </td>
                <td><?= $r['category'] !== null && $r['category'] !== '' ? '<span class="adm-badge adm-badge--nodot">' . e((string)$r['category']) . '</span>' : '<span class="ev-dim">—</span>' ?></td>
                <td class="tr num"><?= e(num((int)$r['sort_order'])) ?></td>
                <td class="adm-table__acts">
                  <a class="adm-btn adm-btn--sm adm-btn--icon" title="Editar"
                     href="<?= e($lnk(['form' => 'noticia', 'id' => (string)$r['id']])) ?>"><?= icon('edit') ?></a>
                  <?php if ($presa): ?>
                    <span class="adm-btn adm-btn--sm adm-btn--icon is-disabled"
                          title="Não pode ser excluída: <?= e((string)$r['janelas']) ?> janela(s) de manutenção apontam para este identificador"><?= icon('lock') ?></span>
                  <?php else: ?>
                    <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--danger" title="Excluir"
                       href="<?= e($lnk(['excluir' => (string)$r['id'], 'tipo' => 'noticia'])) ?>"><?= icon('delete') ?></a>
                  <?php endif ?>
                </td>
              </tr>
            <?php endforeach ?>
          </tbody>
        <?php endif ?>

        <?php /* ---------------------------------------------------------- avisos */ ?>
        <?php if ($aba === 'avisos'): ?>
          <thead>
            <tr>
              <?= $th('dest', 'Destino') ?>
              <?= $th('nome', 'Aviso') ?>
              <th>Botão</th>
              <?= $th('criado', 'Criado', 'tr') ?>
              <?= $th('ordem', 'Ordem', 'tr') ?>
              <th class="adm-table__acts"><span class="ev-sr">Ações</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($linhas as $r): ?>
              <?php $global = $r['user_id'] === null; ?>
              <tr>
                <td>
                  <?php if ($global): ?>
                    <span class="adm-badge adm-badge--info" title="<?= e((string)($notas['aviso_global'] ?? '')) ?>">Global</span>
                  <?php else: ?>
                    <span class="adm-badge adm-badge--nodot"><?= icon('person') ?><?= e((string)($r['username'] ?? '#' . $r['user_id'])) ?></span>
                  <?php endif ?>
                </td>
                <td class="ev-cel-nome">
                  <span class="ev-nome">
                    <span class="ev-tinta ev-tinta--<?= e((string)$r['color']) ?>" title="<?= e((string)($cores[(string)$r['color']]['label'] ?? $r['color'])) ?>"><?= icon((string)$r['icon']) ?></span>
                    <strong><?= e((string)$r['title']) ?></strong>
                    <?php if ((int)$r['fresh'] === 1): ?><span class="adm-badge adm-badge--solid ev-novo">Novo</span><?php endif ?>
                  </span>
                  <small class="ev-dim"><?= e(mb_strimwidth((string)$r['body'], 0, 88, '…')) ?></small>
                </td>
                <td>
                  <?php if (($r['cta_label'] ?? '') !== ''): ?>
                    <span class="ev-cta"><?= e((string)$r['cta_label']) ?><small><?= e((string)$r['cta_href']) ?></small></span>
                  <?php else: ?>
                    <span class="ev-dim">—</span>
                  <?php endif ?>
                </td>
                <td class="tr num"><?= e($dataBr((string)$r['created_at'])) ?></td>
                <td class="tr num"><?= e(num((int)$r['sort_order'])) ?></td>
                <td class="adm-table__acts">
                  <a class="adm-btn adm-btn--sm adm-btn--icon" title="Editar"
                     href="<?= e($lnk(['form' => 'aviso', 'id' => (string)$r['id']])) ?>"><?= icon('edit') ?></a>
                  <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--danger" title="Excluir"
                     href="<?= e($lnk(['excluir' => (string)$r['id'], 'tipo' => 'aviso'])) ?>"><?= icon('delete') ?></a>
                </td>
              </tr>
            <?php endforeach ?>
          </tbody>
        <?php endif ?>

      </table>
    </div>

    <?php /* ----------------------------------------------------------- paginação */ ?>
    <footer class="adm-card__foot">
      <nav class="adm-pager" aria-label="Paginação">
        <span class="adm-pager__info" data-adm-total>
          <?= e(num(count($linhas))) ?> de <?= e(num($total)) ?> registro(s)<?= $filtrando ? ' com os filtros atuais' : '' ?>
          <?= $paginas > 1 ? ' · página ' . e(num($atual)) . ' de ' . e(num($paginas)) : '' ?>
        </span>
        <?php if ($paginas > 1): ?>
          <a class="adm-pager__btn<?= $atual <= 1 ? ' is-disabled' : '' ?>" href="<?= e($lnk(['p' => (string)max(1, $atual - 1)])) ?>"
             aria-label="Página anterior"><?= icon('chevron_left') ?></a>
          <?php
            /* Janela de páginas em volta da atual: com 40 páginas, imprimir as 40 empurraria
               o rodapé para duas linhas e nenhuma delas seria clicada. */
            $de  = max(1, $atual - 2);
            $ate = min($paginas, $de + 4);
            $de  = max(1, $ate - 4);
          ?>
          <?php if ($de > 1): ?>
            <a class="adm-pager__btn" href="<?= e($lnk(['p' => '1'])) ?>">1</a>
            <?php if ($de > 2): ?><span class="adm-pager__gap">…</span><?php endif ?>
          <?php endif ?>
          <?php for ($i = $de; $i <= $ate; $i++): ?>
            <a class="adm-pager__btn<?= $i === $atual ? ' is-active' : '' ?>" href="<?= e($lnk(['p' => (string)$i])) ?>"><?= e(num($i)) ?></a>
          <?php endfor ?>
          <?php if ($ate < $paginas): ?>
            <?php if ($ate < $paginas - 1): ?><span class="adm-pager__gap">…</span><?php endif ?>
            <a class="adm-pager__btn" href="<?= e($lnk(['p' => (string)$paginas])) ?>"><?= e(num($paginas)) ?></a>
          <?php endif ?>
          <a class="adm-pager__btn<?= $atual >= $paginas ? ' is-disabled' : '' ?>" href="<?= e($lnk(['p' => (string)min($paginas, $atual + 1)])) ?>"
             aria-label="Próxima página"><?= icon('chevron_right') ?></a>
        <?php endif ?>
      </nav>
    </footer>
  <?php endif ?>
</section>
<!--/eh:lista-->

<?php /* --------------------------------------------------- bônus por escopo (desafios) */ ?>
<?php if ($aba === 'desafios'): ?>
  <?php $bonus = (array)($data['bonus'] ?? []); $livres = (array)($data['escopos_livres'] ?? []); ?>
  <section class="adm-card ev-bonus">
    <header class="adm-card__head">
      <h2 class="adm-card__title">
        Bônus por escopo
        <small>Um por aba: o jogador resgata quando conclui os desafios daquele escopo.</small>
      </h2>
      <?php if ($livres !== []): ?>
        <div class="adm-card__tools">
          <a class="adm-btn adm-btn--sm" href="<?= e($lnk(['form' => 'bonus', 'id' => ''])) ?>"><?= icon('add') ?>Novo bônus</a>
        </div>
      <?php endif ?>
    </header>

    <?php if ($bonus === []): ?>
      <div class="adm-empty">
        <?= icon('redeem') ?>
        <h3 class="adm-empty__title">Nenhum bônus configurado</h3>
        <p class="adm-empty__txt">Sem bônus, concluir todos os desafios de uma aba não entrega nada ao jogador.</p>
        <a class="adm-btn adm-btn--primary" href="<?= e($lnk(['form' => 'bonus', 'id' => ''])) ?>">Criar o primeiro</a>
      </div>
    <?php else: ?>
      <div class="adm-tablewrap">
        <table class="adm-table adm-table--compact">
          <thead>
            <tr>
              <th>Escopo</th><th>Tarja</th><th>Recompensa</th>
              <th class="tr">XP</th><th class="tr">Créditos</th><th class="tr">Desafios na aba</th>
              <th class="adm-table__acts"><span class="ev-sr">Ações</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($bonus as $b): ?>
              <tr>
                <td><span class="adm-badge adm-badge--<?= e((string)$b['scope'] === 'temporada' ? 'purple' : ((string)$b['scope'] === 'semanais' ? 'info' : 'ok')) ?>"><?= e((string)($escopos[(string)$b['scope']] ?? $b['scope'])) ?></span></td>
                <td><?= e((string)$b['label']) ?></td>
                <td class="ev-cel-nome">
                  <span class="ev-nome"><strong><?= e((string)$b['title']) ?></strong></span>
                  <?php if (($b['description'] ?? '') !== ''): ?>
                    <small class="ev-dim"><?= e(mb_strimwidth((string)$b['description'], 0, 72, '…')) ?></small>
                  <?php endif ?>
                </td>
                <td class="tr num"><?= e(num((int)$b['xp'])) ?></td>
                <td class="tr num"><?= e(num((int)$b['credits'])) ?></td>
                <td class="tr num<?= (int)$b['desafios'] === 0 ? ' ev-zero' : '' ?>"
                    title="<?= e((int)$b['desafios'] === 0 ? 'Nenhum desafio neste escopo: o bônus nunca é alcançado.' : '') ?>"><?= e(num((int)$b['desafios'])) ?></td>
                <td class="adm-table__acts">
                  <a class="adm-btn adm-btn--sm adm-btn--icon" title="Editar"
                     href="<?= e($lnk(['form' => 'bonus', 'id' => (string)$b['scope']])) ?>"><?= icon('edit') ?></a>
                  <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--danger" title="Excluir"
                     href="<?= e($lnk(['excluir' => (string)$b['scope'], 'tipo' => 'bonus'])) ?>"><?= icon('delete') ?></a>
                </td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
    <?php endif ?>
  </section>
<?php endif ?>
