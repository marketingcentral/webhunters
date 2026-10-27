<?php declare(strict_types=1);

/**
 * Tela ADMIN · MAPAS (/admin/mapas).
 *
 * Escopo: $page, $player, $nav, $server, $data, $flash, $csrf. Layout: layouts/admin.php.
 *
 * A view não consulta banco e não decide regra: tudo chega pronto de
 * App\Controllers\Admin\MapsController (que lê App\Repo\MapsRepo). O que existe aqui é
 * marcação, formatação e a escolha de qual estado desenhar.
 *
 * A tela tem DOIS modos, e nunca os dois ao mesmo tempo:
 *   lista  — catálogo com busca, filtros, ordenação por coluna e paginação;
 *   editor — um mapa: cadastro, estado do setor (região) e a grade de marcadores.
 *
 * Sem JavaScript nenhum. A confirmação de exclusão é uma tela servida pelo servidor
 * (?excluir=…), a ordenação são links e os filtros são um <form method="get">. É o que faz
 * esta tela ser conferível inteira por `curl` — e a CSP do projeto proíbe script inline.
 */

$cromo   = (array)($data['cromo'] ?? []);
$colunas = (array)($cromo['colunas'] ?? []);
$ajudas  = (array)($cromo['ajudas'] ?? []);
$vazios  = (array)($cromo['vazios'] ?? []);
$cores   = (array)($cromo['cores'] ?? []);
$ancoras = (array)($cromo['rotulos'] ?? []);

$modo    = (string)($data['modo'] ?? 'lista');
$filtros = (array)($data['filtros'] ?? []);
$resumo  = (array)($data['resumo'] ?? []);
$tipos   = (array)($data['tipos'] ?? []);
$erros   = (array)($data['erros'] ?? []);

/* ---- apoios de formulário ------------------------------------------------
   Três funções minúsculas que aparecem em quase todo campo. Escritas aqui porque são
   apresentação pura: qual campo fica vermelho e qual frase acompanha. */

/** Mensagem de erro do campo, ou string vazia. */
$erro = static fn(string $campo): string => (string)($erros[$campo] ?? '');

/** ' is-invalid' para o .adm-field quando o campo tem erro. */
$inv = static fn(string $campo): string => isset($erros[$campo]) ? ' is-invalid' : '';

/** O <p class="adm-error"> do campo (nada quando está válido). */
$msg = static function (string $campo) use ($erros): string {
    if (!isset($erros[$campo])) {
        return '';
    }
    return '<p class="adm-error">' . icon('error') . '<span>' . e((string)$erros[$campo]) . '</span></p>';
};

/** Coordenada como o operador lê: 4 casas, vírgula decimal. */
$coord = static fn(float $v): string => number_format($v, 4, ',', '');

/** Valor de coordenada dentro de um <input type=number>: ponto, sempre. */
$coordIn = static fn(float $v): string => number_format($v, 4, '.', '');
?>

<?php if ($modo === 'lista'): ?>
<?php
  $linhas    = (array)($data['linhas'] ?? []);
  $total     = (int)($data['total'] ?? 0);
  $pagina    = (int)($data['pagina'] ?? 1);
  $paginas   = (int)($data['paginas'] ?? 1);
  $porPagina = (int)($data['por_pagina'] ?? 20);
  $filtrando = (bool)($data['filtrando'] ?? false);

  $ordemAtual = (string)($filtros['ordem'] ?? 'ordem');
  $dirAtual   = strtolower((string)($filtros['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

  /* Link de qualquer estado da lista preservando busca e filtros — trocar de página
     nunca pode jogar fora o filtro que o operador acabou de montar. */
  $linkLista = static function (array $troca) use ($filtros): string {
      $q = array_filter([
          'busca'  => (string)($filtros['busca'] ?? ''),
          'tipo'   => (string)($filtros['tipo'] ?? ''),
          'estado' => (string)($filtros['estado'] ?? ''),
          'ordem'  => (string)($filtros['ordem'] ?? ''),
          'dir'    => (string)($filtros['dir'] ?? ''),
      ], static fn(string $v): bool => $v !== '');
      $q = array_filter(array_replace($q, $troca), static fn($v): bool => (string)$v !== '');
      return url('admin/mapas', $q);
  };
?>
<?php /* Região da lista (App\Core\Fragmento): o pedaço que volta do servidor quando o
         filtro é aplicado sem recarregar a página. */ ?>
<!--eh:lista-->
<section class="adm-card mapas-lista">
  <?php /* data-adm-preservar: transplantado para dentro do HTML novo em vez de substituído
           — é onde está o cursor de quem digita. */ ?>
  <form class="adm-toolbar" method="get" action="<?= e(url('admin/mapas')) ?>" role="search"
        data-adm-preservar="filtros">
    <div class="adm-toolbar__grow">
      <label class="adm-search">
        <?= icon('search') ?>
        <span class="sr-only">Buscar mapa</span>
        <input class="adm-input" type="search" name="busca" value="<?= e((string)($filtros['busca'] ?? '')) ?>"
               placeholder="Nome, identificador, modo ou level da Unreal" autocomplete="off">
      </label>
    </div>

    <label class="mapas-filtro">
      <span class="sr-only">Tipo</span>
      <select class="adm-select" name="tipo">
        <option value="">Todos os tipos</option>
        <?php foreach ($tipos as $valor => $rotulo): ?>
          <option value="<?= e((string)$valor) ?>"<?= (string)($filtros['tipo'] ?? '') === (string)$valor ? ' selected' : '' ?>><?= e((string)$rotulo) ?></option>
        <?php endforeach ?>
      </select>
    </label>

    <label class="mapas-filtro">
      <span class="sr-only">Estado</span>
      <select class="adm-select" name="estado">
        <?php foreach ((array)($cromo['estados'] ?? []) as $valor => $rotulo): ?>
          <option value="<?= e((string)$valor) ?>"<?= (string)($filtros['estado'] ?? '') === (string)$valor ? ' selected' : '' ?>><?= e((string)$rotulo) ?></option>
        <?php endforeach ?>
      </select>
    </label>

    <?php /* A ordenação escolhida por clique no cabeçalho viaja junto, senão filtrar
             desfazia a ordenação e vice-versa. */ ?>
    <input type="hidden" name="ordem" value="<?= e($ordemAtual) ?>">
    <input type="hidden" name="dir" value="<?= e($dirAtual) ?>">

    <button class="adm-btn" type="submit"><?= icon('filter_alt') ?><span>Filtrar</span></button>
    <?php if ($filtrando): ?>
      <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/mapas')) ?>"><?= icon('filter_alt_off') ?><span>Limpar</span></a>
    <?php endif ?>
  </form>

  <?php if ($linhas === []): ?>
    <?php $vazio = $filtrando ? (array)($vazios['sem_mapa_filtro'] ?? []) : (array)($vazios['sem_mapa'] ?? []); ?>
    <div class="adm-empty">
      <?= icon($filtrando ? 'search_off' : 'map') ?>
      <h2 class="adm-empty__title"><?= e((string)($vazio['titulo'] ?? 'Nada por aqui')) ?></h2>
      <p class="adm-empty__txt"><?= e((string)($vazio['txt'] ?? '')) ?></p>
      <?php if ($filtrando): ?>
        <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/mapas')) ?>"><?= icon('filter_alt_off') ?><span>Limpar os filtros</span></a>
      <?php else: ?>
        <a class="adm-btn adm-btn--primary" href="<?= e(url('admin/mapas', ['novo' => 1])) ?>"><?= icon('add_location_alt') ?><span>Cadastrar o primeiro mapa</span></a>
      <?php endif ?>
    </div>
  <?php else: ?>
    <div class="adm-tablewrap">
      <table class="adm-table mapas-tabela">
        <thead>
          <tr>
            <?php foreach ($colunas as $chave => $col): ?>
              <?php
                $ordena = $col['ordena'] ?? false;
                $chaveOrdem = $ordena === true ? (string)$chave : (is_string($ordena) ? $ordena : '');
                $ativo = $chaveOrdem !== '' && $chaveOrdem === $ordemAtual;
                // Clicar na coluna já ordenada INVERTE; clicar numa nova começa crescente.
                $proxDir = $ativo && $dirAtual === 'asc' ? 'desc' : 'asc';
                $classe  = trim((string)($col['classe'] ?? '') . ($ativo ? ' is-sorted' : ''));
              ?>
              <th class="<?= e($classe) ?>"<?= $ativo ? ' aria-sort="' . ($dirAtual === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>>
                <?php if ($chaveOrdem !== ''): ?>
                  <a class="mapas-ord" href="<?= e($linkLista(['ordem' => $chaveOrdem, 'dir' => $proxDir, 'pagina' => ''])) ?>">
                    <span><?= e((string)($col['label'] ?? '')) ?></span>
                    <?= icon($ativo ? ($dirAtual === 'asc' ? 'arrow_upward' : 'arrow_downward') : 'unfold_more', 'mapas-ord__ico') ?>
                  </a>
                <?php else: ?>
                  <?= e((string)($col['label'] ?? '')) ?>
                <?php endif ?>
              </th>
            <?php endforeach ?>
            <th class="adm-table__acts"><span class="sr-only">Ações</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($linhas as $m): ?>
            <?php
              $id     = (int)$m['id'];
              $kind   = (string)$m['kind'];
              $ue     = trim((string)($m['unreal_level'] ?? ''));
              $lmin   = $m['level_min'];
              $lmax   = $m['level_max'];
              $nivel  = $lmin === null && $lmax === null
                        ? '—'
                        : ($lmin !== null && $lmax !== null ? (int)$lmin . '–' . (int)$lmax : (string)(int)($lmin ?? $lmax));
              $config = trim(implode(' · ', array_filter([
                  (string)($m['mode'] ?? ''),
                  (string)($m['players'] ?? ''),
              ], static fn(string $v): bool => $v !== '')));
            ?>
            <tr>
              <td class="tr num"><?= e((string)(int)$m['sort_order']) ?></td>
              <td>
                <a class="mapas-nome" href="<?= e(url('admin/mapas', ['id' => $id])) ?>">
                  <strong><?= e((string)$m['name']) ?></strong>
                  <small><?= e((string)$m['slug']) ?></small>
                </a>
                <?php if ($ue !== ''): ?>
                  <span class="mapas-ue" title="Level da Unreal"><?= icon('deployed_code', 'mapas-ue__ico') ?><?= e($ue) ?></span>
                <?php else: ?>
                  <span class="mapas-ue mapas-ue--none" title="Nenhum level da Unreal apontado neste cadastro"><?= icon('deployed_code_alert', 'mapas-ue__ico') ?>sem level da Unreal</span>
                <?php endif ?>
              </td>
              <td><span class="adm-badge <?= $kind === 'region' ? 'adm-badge--info' : 'adm-badge--purple' ?>"><?= e((string)($tipos[$kind] ?? $kind)) ?></span></td>
              <td class="adm-table__wrap"><?= $config !== '' ? e($config) : '<span class="mapas-vazio">—</span>' ?></td>
              <td class="tr num"><?= e($nivel) ?></td>
              <td class="tr num"><?= e((string)(int)($m['marcadores'] ?? 0)) ?></td>
              <td>
                <?php if ((int)$m['locked'] === 1): ?>
                  <span class="adm-badge adm-badge--warn"><?= icon('lock') ?>Bloqueado</span>
                <?php else: ?>
                  <span class="adm-badge adm-badge--ok">Aberto</span>
                <?php endif ?>
              </td>
              <td class="adm-table__acts">
                <a class="adm-btn adm-btn--sm" href="<?= e(url('admin/mapas', ['id' => $id])) ?>"><?= icon('edit') ?><span>Editar</span></a>
              </td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>

    <div class="adm-card__foot">
      <div class="adm-pager">
        <?php
          $de  = ($pagina - 1) * $porPagina + 1;
          $ate = min($total, $pagina * $porPagina);
        ?>
        <span class="adm-pager__info" data-adm-total>
          Mostrando <span class="num"><?= e((string)$de) ?></span>–<span class="num"><?= e((string)$ate) ?></span>
          de <span class="num"><?= e(num($total)) ?></span> mapa<?= $total === 1 ? '' : 's' ?><?= $filtrando ? ' no filtro atual' : '' ?>
        </span>
        <?php if ($paginas > 1): ?>
          <a class="adm-pager__btn<?= $pagina <= 1 ? ' is-disabled' : '' ?>" href="<?= e($linkLista(['pagina' => max(1, $pagina - 1)])) ?>" aria-label="Página anterior"><?= icon('chevron_left') ?></a>
          <?php for ($p = 1; $p <= $paginas; $p++): ?>
            <a class="adm-pager__btn<?= $p === $pagina ? ' is-active' : '' ?>" href="<?= e($linkLista(['pagina' => $p])) ?>"><?= e((string)$p) ?></a>
          <?php endfor ?>
          <a class="adm-pager__btn<?= $pagina >= $paginas ? ' is-disabled' : '' ?>" href="<?= e($linkLista(['pagina' => min($paginas, $pagina + 1)])) ?>" aria-label="Próxima página"><?= icon('chevron_right') ?></a>
        <?php endif ?>
      </div>
    </div>
  <?php endif ?>
</section>
<!--/eh:lista-->

<?php else: ?>
<?php
  /* ================================ MODO EDITOR ================================ */
  $mapa       = is_array($data['mapa'] ?? null) ? $data['mapa'] : null;
  $novo       = (bool)($data['novo'] ?? false) || $mapa === null;
  $form       = (array)($data['form'] ?? []);
  $marcadores = (array)($data['marcadores'] ?? []);
  $mkPost     = (array)($data['mk_post'] ?? []);
  $novoPost   = (array)($data['novo_post'] ?? []);
  $confirmar  = is_array($data['confirmar'] ?? null) ? $data['confirmar'] : null;
  $vinculos   = (array)($data['vinculos'] ?? []);
  $vinculoTxt = (string)($data['vinculo_txt'] ?? '');
  $metaExtra  = (array)($data['meta_extra'] ?? []);
  $mkTipos    = (array)($data['marcador_tipos'] ?? []);
  $exTipos    = (array)($data['extracao_tipos'] ?? []);
  $exEstados  = (array)($data['extracao_estados'] ?? []);
  $atividades = (array)($data['atividades'] ?? []);
  $capPadrao  = (array)($data['capacidade_padrao'] ?? []);

  $mapId  = (int)($form['id'] ?? 0);
  $ehRegiao = (string)($form['kind'] ?? 'region') === 'region';

  /* Linhas de extração: as que existem mais duas em branco, para acrescentar sem
     recarregar a tela. Uma linha em branco salva continua em branco (o controller
     descarta), então oferecer duas não cria lixo. */
  $exLinhas = (array)($form['extracoes'] ?? []);
  $exLinhas[] = ['name' => '', 'type' => 'publica', 'state' => 'active'];
  $exLinhas[] = ['name' => '', 'type' => 'publica', 'state' => 'active'];
?>

<?php if ($confirmar !== null && ($confirmar['tipo'] ?? '') === 'mapa'): ?>
  <section class="adm-card mapas-confirma">
    <div class="adm-card__head">
      <h2 class="adm-card__title"><?= icon('delete_forever') ?>Excluir o mapa "<?= e((string)$confirmar['nome']) ?>"?</h2>
    </div>
    <div class="adm-card__body">
      <p class="mapas-confirma__txt">
        Isto apaga o cadastro
        <?php if ((int)$confirmar['marcadores'] > 0): ?>
          e, junto com ele, <strong class="num"><?= e((string)(int)$confirmar['marcadores']) ?></strong>
          marcador<?= (int)$confirmar['marcadores'] === 1 ? '' : 'es' ?> — extrações, bases e ninhos que este mapa desenha no jogo.
        <?php else: ?>
          deste mapa. Ele não tem marcadores.
        <?php endif ?>
        Não há desfazer.
      </p>
      <form class="adm-btns mapas-confirma__acts" method="post" action="<?= e(url('admin/mapas/excluir')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="alvo" value="mapa">
        <input type="hidden" name="id" value="<?= e((string)$confirmar['id']) ?>">
        <button class="adm-btn adm-btn--danger" type="submit"><?= icon('delete_forever') ?><span>Sim, excluir definitivamente</span></button>
        <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/mapas', ['id' => $confirmar['id']])) ?>"><?= icon('close') ?><span>Cancelar</span></a>
      </form>
    </div>
  </section>
<?php endif ?>

<?php if ($confirmar !== null && ($confirmar['tipo'] ?? '') === 'marcador'): ?>
  <section class="adm-card mapas-confirma">
    <div class="adm-card__head">
      <h2 class="adm-card__title"><?= icon('wrong_location') ?>Excluir o marcador "<?= e((string)$confirmar['nome']) ?>"?</h2>
    </div>
    <div class="adm-card__body">
      <p class="mapas-confirma__txt">
        Tipo <strong><?= e((string)$confirmar['kind']) ?></strong>, em
        X <span class="num"><?= e($coord((float)$confirmar['x'])) ?></span> ·
        Y <span class="num"><?= e($coord((float)$confirmar['y'])) ?></span>.
        Ele some do mapa no jogo assim que você confirmar. Não há desfazer.
      </p>
      <form class="adm-btns mapas-confirma__acts" method="post" action="<?= e(url('admin/mapas/excluir')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="alvo" value="marcador">
        <input type="hidden" name="marcador_id" value="<?= e((string)$confirmar['id']) ?>">
        <button class="adm-btn adm-btn--danger" type="submit"><?= icon('delete_forever') ?><span>Sim, excluir o marcador</span></button>
        <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/mapas', ['id' => $mapId])) ?>"><?= icon('close') ?><span>Cancelar</span></a>
      </form>
    </div>
  </section>
<?php endif ?>

<?php if ($erros !== []): ?>
  <div class="adm-flash adm-flash--error mapas-erro-topo" role="alert">
    <?= icon('error') ?>
    <span>Nada foi gravado: <?= e((string)count($erros)) ?> campo<?= count($erros) === 1 ? '' : 's' ?> precisa<?= count($erros) === 1 ? '' : 'm' ?> de correção. Os campos estão marcados abaixo.</span>
  </div>
<?php endif ?>

<form class="adm-form mapas-editor" method="post" action="<?= e(url('admin/mapas/salvar')) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="acao" value="mapa">
  <input type="hidden" name="id" value="<?= e((string)$mapId) ?>">

  <section class="adm-card">
    <div class="adm-card__head">
      <h2 class="adm-card__title">
        <?= $novo ? 'Novo mapa' : 'Cadastro' ?>
        <small><?= $novo ? 'O identificador e o tipo definem onde ele aparece no jogo.' : 'O que o jogador vê na lista de mapas e no Mapa do Mundo.' ?></small>
      </h2>
    </div>
    <div class="adm-card__body">
      <div class="adm-form__row adm-form__row--3">
        <div class="adm-field<?= $inv('name') ?>">
          <label class="adm-label" for="f-name">Nome<span class="adm-label__req">*</span></label>
          <input class="adm-input" id="f-name" name="name" maxlength="80" required
                 value="<?= e((string)($form['name'] ?? '')) ?>" placeholder="FLORESTA VERDE">
          <?= $msg('name') ?>
        </div>

        <div class="adm-field<?= $inv('slug') ?>">
          <label class="adm-label" for="f-slug">Identificador<span class="adm-label__req">*</span></label>
          <input class="adm-input mono" id="f-slug" name="slug" maxlength="64" required
                 value="<?= e((string)($form['slug'] ?? '')) ?>" placeholder="regiao-floresta-verde">
          <?= $msg('slug') ?: '<p class="adm-hint">' . e((string)($ajudas['slug'] ?? '')) . '</p>' ?>
        </div>

        <div class="adm-field<?= $inv('kind') ?>">
          <label class="adm-label" for="f-kind">Tipo<span class="adm-label__req">*</span></label>
          <select class="adm-select" id="f-kind" name="kind">
            <?php foreach ($tipos as $valor => $rotulo): ?>
              <option value="<?= e((string)$valor) ?>"<?= (string)($form['kind'] ?? '') === (string)$valor ? ' selected' : '' ?>><?= e((string)$rotulo) ?></option>
            <?php endforeach ?>
          </select>
          <?= $msg('kind') ?: '<p class="adm-hint">' . e((string)($ajudas['kind'] ?? '')) . '</p>' ?>
        </div>
      </div>

      <div class="adm-form__row adm-form__row--3">
        <div class="adm-field<?= $inv('mode') ?>">
          <label class="adm-label" for="f-mode">Modo</label>
          <input class="adm-input" id="f-mode" name="mode" maxlength="40"
                 value="<?= e((string)($form['mode'] ?? '')) ?>" placeholder="MATA-MATA EM EQUIPE">
          <?= $msg('mode') ?>
        </div>

        <div class="adm-field<?= $inv('players') ?>">
          <label class="adm-label" for="f-players">Jogadores</label>
          <input class="adm-input" id="f-players" name="players" maxlength="16"
                 value="<?= e((string)($form['players'] ?? '')) ?>" placeholder="6v6">
          <?= $msg('players') ?>
        </div>

        <div class="adm-field<?= $inv('sort_order') ?>">
          <label class="adm-label" for="f-ordem">Ordem na lista</label>
          <input class="adm-input num" id="f-ordem" name="sort_order" type="number" min="0" max="65535" step="1"
                 value="<?= e((string)($form['sort_order'] ?? '0')) ?>">
          <?= $msg('sort_order') ?: '<p class="adm-hint">' . e((string)($ajudas['sort_order'] ?? '')) . '</p>' ?>
        </div>
      </div>

      <div class="adm-form__row adm-form__row--3">
        <div class="adm-field<?= $inv('level_min') ?>">
          <label class="adm-label" for="f-lmin">Nível mínimo</label>
          <input class="adm-input num" id="f-lmin" name="level_min" type="number" min="0" max="999" step="1"
                 value="<?= e((string)($form['level_min'] ?? '')) ?>" placeholder="—">
          <?= $msg('level_min') ?>
        </div>

        <div class="adm-field<?= $inv('level_max') ?>">
          <label class="adm-label" for="f-lmax">Nível máximo</label>
          <input class="adm-input num" id="f-lmax" name="level_max" type="number" min="0" max="999" step="1"
                 value="<?= e((string)($form['level_max'] ?? '')) ?>" placeholder="—">
          <?= $msg('level_max') ?>
        </div>

        <div class="adm-field">
          <span class="adm-label">Disponibilidade</span>
          <label class="adm-switch">
            <input class="adm-switch__in" type="checkbox" name="locked" value="1"<?= !empty($form['locked']) ? ' checked' : '' ?>>
            <span class="adm-switch__ui"></span>
            <span>Bloqueado para os jogadores</span>
          </label>
          <p class="adm-hint"><?= e((string)($ajudas['locked'] ?? '')) ?></p>
        </div>
      </div>

      <div class="adm-form__row">
        <div class="adm-field<?= $inv('unreal_level') ?>">
          <label class="adm-label" for="f-ue">Level da Unreal</label>
          <input class="adm-input mono" id="f-ue" name="unreal_level" maxlength="200"
                 value="<?= e((string)($form['unreal_level'] ?? '')) ?>" placeholder="/Game/Maps/L_FlorestaVerde">
          <?= $msg('unreal_level') ?: '<p class="adm-hint">' . e((string)($ajudas['unreal_level'] ?? '')) . '</p>' ?>
        </div>

        <div class="adm-field<?= $inv('image') ?>">
          <label class="adm-label" for="f-img">Imagem</label>
          <?php /* Campo de caminho + envio de arquivo (components/admin-upload.php). Mesmo
                   name="image" de antes: o controller e o banco não mudam. */ ?>
          <?php component('admin-upload', [
              'id'          => 'f-img',
              'name'        => 'image',
              'entidade'    => 'mapas',
              'valor'       => (string)($form['image'] ?? ''),
              'maxlength'   => 160,
              'mono'        => true,
              'placeholder' => 'bg/map-world.jpg',
              'invalido'    => $inv('image') !== '',
          ]) ?>
          <?= $msg('image') ?: '<p class="adm-hint">' . e((string)($ajudas['image'] ?? '')) . '</p>' ?>
        </div>
      </div>

      <div class="adm-field<?= $inv('description') ?>">
        <label class="adm-label" for="f-desc">Descrição</label>
        <textarea class="adm-textarea" id="f-desc" name="description" maxlength="400" rows="3"
                  placeholder="O que o jogador precisa saber antes de entrar."><?= e((string)($form['description'] ?? '')) ?></textarea>
        <?= $msg('description') ?: '<p class="adm-hint">Até 400 caracteres.</p>' ?>
      </div>
    </div>
  </section>

  <?php /* ---- estado do setor: só faz sentido para região do mundo persistente ---- */ ?>
  <section class="adm-card">
    <div class="adm-card__head">
      <h2 class="adm-card__title">
        Estado do setor
        <small>
          <?= $ehRegiao
              ? 'Capacidade, infestação, atividade e extrações — o que o Mapa do Mundo lê deste mapa.'
              : 'Este cadastro é um mapa de PARTIDA: o mundo persistente não se aplica. Os campos abaixo são os do cartão de seleção de mapa.' ?>
        </small>
      </h2>
    </div>
    <div class="adm-card__body">
      <?php if ($ehRegiao): ?>
        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field<?= $inv('chave') ?>">
            <label class="adm-label" for="f-chave">Chave na URL do jogo</label>
            <input class="adm-input mono" id="f-chave" name="chave" maxlength="64"
                   value="<?= e((string)($form['chave'] ?? '')) ?>" placeholder="floresta-verde">
            <?= $msg('chave') ?: '<p class="adm-hint">Vazio usa o identificador sem o prefixo "regiao-".</p>' ?>
          </div>

          <div class="adm-field<?= $inv('nivel_txt') ?>">
            <label class="adm-label" for="f-nivel">Faixa de nível exibida</label>
            <input class="adm-input" id="f-nivel" name="nivel_txt" maxlength="16"
                   value="<?= e((string)($form['nivel_txt'] ?? '')) ?>" placeholder="1-10">
            <?= $msg('nivel_txt') ?>
          </div>

          <div class="adm-field<?= $inv('icone') ?>">
            <label class="adm-label" for="f-icone">Ícone</label>
            <input class="adm-input mono" id="f-icone" name="icone" maxlength="32"
                   value="<?= e((string)($form['icone'] ?? '')) ?>" placeholder="pine">
            <?= $msg('icone') ?>
          </div>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field<?= $inv('cap_total') ?>">
            <label class="adm-label" for="f-cap">Capacidade total</label>
            <input class="adm-input num" id="f-cap" name="cap_total" type="number" min="0" max="200" step="1"
                   value="<?= e((string)($form['cap_total'] ?? '')) ?>" placeholder="<?= e((string)($capPadrao['total'] ?? 20)) ?>">
            <?= $msg('cap_total') ?: '<p class="adm-hint">' . e((string)($ajudas['capacidade'] ?? '')) . '</p>' ?>
          </div>

          <div class="adm-field<?= $inv('cap_hunters') ?>">
            <label class="adm-label" for="f-caph">Cota de Hunters</label>
            <input class="adm-input num" id="f-caph" name="cap_hunters" type="number" min="0" max="200" step="1"
                   value="<?= e((string)($form['cap_hunters'] ?? '')) ?>" placeholder="<?= e((string)($capPadrao['hunters'] ?? 12)) ?>">
            <?= $msg('cap_hunters') ?>
          </div>

          <div class="adm-field<?= $inv('cap_merc') ?>">
            <label class="adm-label" for="f-capm">Cota de Mercenários</label>
            <input class="adm-input num" id="f-capm" name="cap_merc" type="number" min="0" max="200" step="1"
                   value="<?= e((string)($form['cap_merc'] ?? '')) ?>" placeholder="<?= e((string)($capPadrao['mercenaries'] ?? 8)) ?>">
            <?= $msg('cap_merc') ?>
          </div>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field<?= $inv('infestacao') ?>">
            <label class="adm-label" for="f-inf">Infestação Veyra</label>
            <input class="adm-input num" id="f-inf" name="infestacao" type="number" min="0" max="100" step="1"
                   value="<?= e((string)($form['infestacao'] ?? '')) ?>" placeholder="0">
            <?= $msg('infestacao') ?: '<p class="adm-hint">' . e((string)($ajudas['infestacao'] ?? '')) . '</p>' ?>
          </div>

          <div class="adm-field<?= $inv('veyra') ?>">
            <label class="adm-label" for="f-veyra">Atividade Veyra</label>
            <select class="adm-select" id="f-veyra" name="veyra">
              <option value="">— não informada —</option>
              <?php foreach ($atividades as $degrau => $cor): ?>
                <option value="<?= e((string)$degrau) ?>"<?= mb_strtoupper((string)($form['veyra'] ?? '')) === (string)$degrau ? ' selected' : '' ?>><?= e((string)$degrau) ?></option>
              <?php endforeach ?>
            </select>
            <?= $msg('veyra') ?>
          </div>

          <div class="adm-field<?= $inv('humana') ?>">
            <label class="adm-label" for="f-humana">Atividade humana</label>
            <select class="adm-select" id="f-humana" name="humana">
              <option value="">— não informada —</option>
              <?php foreach ($atividades as $degrau => $cor): ?>
                <option value="<?= e((string)$degrau) ?>"<?= mb_strtoupper((string)($form['humana'] ?? '')) === (string)$degrau ? ' selected' : '' ?>><?= e((string)$degrau) ?></option>
              <?php endforeach ?>
            </select>
            <?= $msg('humana') ?>
          </div>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field<?= $inv('clima') ?>">
            <label class="adm-label" for="f-clima">Clima atual</label>
            <input class="adm-input" id="f-clima" name="clima" maxlength="32"
                   value="<?= e((string)($form['clima'] ?? '')) ?>" placeholder="Variável">
            <?= $msg('clima') ?>
          </div>

          <div class="adm-field<?= $inv('setor') ?>">
            <label class="adm-label" for="f-setor">Estado do setor</label>
            <input class="adm-input" id="f-setor" name="setor" maxlength="24"
                   value="<?= e((string)($form['setor'] ?? '')) ?>" placeholder="ATIVO">
            <?= $msg('setor') ?>
          </div>

          <div class="adm-field">
            <span class="adm-label">Mundo</span>
            <label class="adm-switch">
              <input class="adm-switch__in" type="checkbox" name="persistente" value="1"<?= !empty($form['persistente']) ? ' checked' : '' ?>>
              <span class="adm-switch__ui"></span>
              <span>Persistente</span>
            </label>
            <p class="adm-hint">Desligado, a região deixa de anunciar mundo persistente no painel de informações.</p>
          </div>
        </div>

        <div class="adm-sep"></div>

        <p class="adm-label mapas-sub">Extrações</p>
        <p class="adm-hint mapas-sub__hint"><?= e((string)($ajudas['extracoes'] ?? '')) ?></p>

        <div class="adm-tablewrap">
          <table class="adm-table adm-table--compact mapas-ex">
            <thead>
              <tr>
                <th>Nome do ponto</th>
                <th>Tipo</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($exLinhas as $i => $x): ?>
                <tr>
                  <td>
                    <input class="adm-input" name="ex[<?= e((string)$i) ?>][name]" maxlength="80"
                           value="<?= e((string)($x['name'] ?? '')) ?>" placeholder="ROTA SUL"
                           aria-label="Nome da extração <?= e((string)($i + 1)) ?>">
                  </td>
                  <td class="mapas-ex__sel">
                    <select class="adm-select<?= $inv('ex.' . $i . '.type') ?>" name="ex[<?= e((string)$i) ?>][type]" aria-label="Tipo da extração <?= e((string)($i + 1)) ?>">
                      <?php foreach ($exTipos as $slug => $t): ?>
                        <option value="<?= e((string)$slug) ?>"<?= (string)($x['type'] ?? '') === (string)$slug ? ' selected' : '' ?>><?= e((string)$t['label']) ?></option>
                      <?php endforeach ?>
                    </select>
                  </td>
                  <td class="mapas-ex__sel">
                    <select class="adm-select<?= $inv('ex.' . $i . '.state') ?>" name="ex[<?= e((string)$i) ?>][state]" aria-label="Estado da extração <?= e((string)($i + 1)) ?>">
                      <?php foreach ($exEstados as $slug => $t): ?>
                        <option value="<?= e((string)$slug) ?>"<?= (string)($x['state'] ?? '') === (string)$slug ? ' selected' : '' ?>><?= e((string)$t['label']) ?></option>
                      <?php endforeach ?>
                    </select>
                  </td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>

        <div class="adm-sep"></div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field<?= $inv('evento_nome') ?>">
            <label class="adm-label" for="f-ev">Evento em cartaz</label>
            <input class="adm-input" id="f-ev" name="evento_nome" maxlength="80"
                   value="<?= e((string)($form['evento_nome'] ?? '')) ?>" placeholder="ENTREGA AÉREA">
            <?= $msg('evento_nome') ?: '<p class="adm-hint">' . e((string)($ajudas['evento'] ?? '')) . '</p>' ?>
          </div>

          <div class="adm-field<?= $inv('evento_prazo') ?>">
            <label class="adm-label" for="f-evp">Prazo</label>
            <input class="adm-input num" id="f-evp" name="evento_prazo" maxlength="24"
                   value="<?= e((string)($form['evento_prazo'] ?? '')) ?>" placeholder="12:34">
            <?= $msg('evento_prazo') ?>
          </div>

          <div class="adm-field<?= $inv('evento_rotulo') ?>">
            <label class="adm-label" for="f-evr">Rótulo do prazo</label>
            <input class="adm-input" id="f-evr" name="evento_rotulo" maxlength="32"
                   value="<?= e((string)($form['evento_rotulo'] ?? '')) ?>" placeholder="TERMINA EM">
            <?= $msg('evento_rotulo') ?>
          </div>
        </div>
      <?php else: ?>
        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field<?= $inv('tamanho') ?>">
            <label class="adm-label" for="f-tam">Tamanho</label>
            <input class="adm-input" id="f-tam" name="tamanho" maxlength="24"
                   value="<?= e((string)($form['tamanho'] ?? '')) ?>" placeholder="MÉDIO">
            <?= $msg('tamanho') ?>
          </div>

          <div class="adm-field<?= $inv('bioma') ?>">
            <label class="adm-label" for="f-bio">Bioma</label>
            <input class="adm-input" id="f-bio" name="bioma" maxlength="60"
                   value="<?= e((string)($form['bioma'] ?? '')) ?>" placeholder="Floresta / Montanha">
            <?= $msg('bioma') ?>
          </div>

          <div class="adm-field<?= $inv('clima') ?>">
            <label class="adm-label" for="f-clima">Clima</label>
            <input class="adm-input" id="f-clima" name="clima" maxlength="32"
                   value="<?= e((string)($form['clima'] ?? '')) ?>" placeholder="Nublado">
            <?= $msg('clima') ?>
          </div>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field<?= $inv('recorde') ?>">
            <label class="adm-label" for="f-rec">Melhor tempo</label>
            <input class="adm-input num" id="f-rec" name="recorde" maxlength="16"
                   value="<?= e((string)($form['recorde'] ?? '')) ?>" placeholder="04:12">
            <?= $msg('recorde') ?>
          </div>

          <div class="adm-field<?= $inv('recorde_por') ?>">
            <label class="adm-label" for="f-recp">Recorde de</label>
            <input class="adm-input" id="f-recp" name="recorde_por" maxlength="40"
                   value="<?= e((string)($form['recorde_por'] ?? '')) ?>" placeholder="V1P3R_88">
            <?= $msg('recorde_por') ?>
          </div>

          <div class="adm-field<?= $inv('icone') ?>">
            <label class="adm-label" for="f-icone">Ícone</label>
            <input class="adm-input mono" id="f-icone" name="icone" maxlength="32"
                   value="<?= e((string)($form['icone'] ?? '')) ?>" placeholder="mk-base">
            <?= $msg('icone') ?>
          </div>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field<?= $inv('infestacao') ?>">
            <label class="adm-label" for="f-inf">Infestação Veyra</label>
            <input class="adm-input num" id="f-inf" name="infestacao" type="number" min="0" max="100" step="1"
                   value="<?= e((string)($form['infestacao'] ?? '')) ?>" placeholder="0">
            <?= $msg('infestacao') ?>
          </div>

          <div class="adm-field<?= $inv('humana') ?>">
            <label class="adm-label" for="f-humana">Atividade humana</label>
            <select class="adm-select" id="f-humana" name="humana">
              <option value="">— não informada —</option>
              <?php foreach ($atividades as $degrau => $cor): ?>
                <option value="<?= e((string)$degrau) ?>"<?= mb_strtoupper((string)($form['humana'] ?? '')) === (string)$degrau ? ' selected' : '' ?>><?= e((string)$degrau) ?></option>
              <?php endforeach ?>
            </select>
            <?= $msg('humana') ?>
          </div>

          <div class="adm-field"></div>
        </div>
      <?php endif ?>

      <?php if ($metaExtra !== []): ?>
        <?php /* Honestidade sobre o JSON: o operador precisa saber que existe dado que este
                 formulário não mostra, mas também não apaga. */ ?>
        <p class="mapas-preservado">
          <?= icon('database') ?>
          <span>
            <strong class="num"><?= e((string)count($metaExtra)) ?></strong>
            chave<?= count($metaExtra) === 1 ? '' : 's' ?> de <code>meta</code> que esta tela não edita
            (<?= e(implode(', ', array_slice($metaExtra, 0, 8))) ?><?= count($metaExtra) > 8 ? '…' : '' ?>)
            <?= count($metaExtra) === 1 ? 'é preservada' : 'são preservadas' ?> ao salvar.
          </span>
        </p>
      <?php endif ?>
    </div>

    <div class="adm-card__foot">
      <div class="adm-form__actions mapas-acoes">
        <button class="adm-btn adm-btn--primary" type="submit">
          <?= icon('save') ?><span><?= $novo ? 'Criar mapa' : 'Salvar cadastro' ?></span>
        </button>
        <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/mapas')) ?>"><?= icon('close') ?><span>Cancelar</span></a>

        <?php if (!$novo): ?>
          <span class="adm-spacer"></span>
          <?php if ($vinculos !== []): ?>
            <span class="mapas-bloqueio" title="<?= e($vinculoTxt) ?>">
              <?= icon('lock') ?>
              <span>Não pode ser excluído: <?= e($vinculoTxt) ?></span>
            </span>
          <?php else: ?>
            <a class="adm-btn adm-btn--danger" href="<?= e(url('admin/mapas', ['id' => $mapId, 'excluir' => 'mapa'])) ?>">
              <?= icon('delete') ?><span>Excluir mapa</span>
            </a>
          <?php endif ?>
        <?php endif ?>
      </div>
    </div>
  </section>
</form>

<?php if (!$novo): ?>
  <?php /* ---- grade de marcadores: um formulário para N linhas ---------------- */ ?>
  <form class="mapas-mk-form" method="post" action="<?= e(url('admin/mapas/salvar')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="acao" value="marcadores">
    <input type="hidden" name="id" value="<?= e((string)$mapId) ?>">

    <section class="adm-card">
      <div class="adm-card__head">
        <h2 class="adm-card__title">
          Marcadores
          <small>
            Coordenadas de 0 a 1 sobre a imagem do mapa: X cresce para a direita, Y para baixo.
            Excluir abre a confirmação e descarta edições ainda não salvas nesta grade.
          </small>
        </h2>
        <div class="adm-card__tools">
          <span class="adm-meta"><span class="num"><?= e((string)count($marcadores)) ?></span> marcador<?= count($marcadores) === 1 ? '' : 'es' ?></span>
        </div>
      </div>

      <?php if ($marcadores === []): ?>
        <div class="adm-empty">
          <?= icon('wrong_location') ?>
          <h2 class="adm-empty__title"><?= e((string)($vazios['sem_marcador']['titulo'] ?? '')) ?></h2>
          <p class="adm-empty__txt"><?= e((string)($vazios['sem_marcador']['txt'] ?? '')) ?></p>
        </div>
      <?php else: ?>
        <div class="adm-tablewrap">
          <table class="adm-table adm-table--compact mapas-mk">
            <thead>
              <tr>
                <th>Tipo</th>
                <th>Rótulo</th>
                <th class="tr">X</th>
                <th class="tr">Y</th>
                <th>Cor</th>
                <th>Âncora</th>
                <th class="adm-table__acts"><span class="sr-only">Excluir</span></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($marcadores as $mk): ?>
                <?php
                  $mid   = (int)$mk['id'];
                  $meta  = (array)($mk['meta_arr'] ?? []);
                  $post  = is_array($mkPost[$mid] ?? null) ? $mkPost[$mid] : [];
                  /* Em erro de validação a linha volta com o que foi DIGITADO nela. */
                  $vKind = (string)($post['kind']   ?? $mk['kind']);
                  $vLab  = (string)($post['label']  ?? ($mk['label'] ?? ''));
                  $vX    = isset($post['x']) ? (string)$post['x'] : $coordIn((float)$mk['x']);
                  $vY    = isset($post['y']) ? (string)$post['y'] : $coordIn((float)$mk['y']);
                  $vCor  = (string)($post['color']  ?? ($meta['color'] ?? ''));
                  $vAnc  = (string)($post['anchor'] ?? (is_string($meta['label'] ?? null) ? $meta['label'] : ''));
                  $p     = 'mk.' . $mid . '.';
                ?>
                <tr<?= isset($erros[$p . 'kind']) || isset($erros[$p . 'x']) || isset($erros[$p . 'y']) ? ' class="is-invalid-row"' : '' ?>>
                  <td class="mapas-mk__tipo">
                    <select class="adm-select<?= $inv($p . 'kind') ?>" name="mk[<?= e((string)$mid) ?>][kind]" aria-label="Tipo do marcador <?= e($vLab !== '' ? $vLab : (string)$mid) ?>">
                      <?php foreach ($mkTipos as $slug => $rotulo): ?>
                        <option value="<?= e((string)$slug) ?>"<?= $vKind === (string)$slug ? ' selected' : '' ?>><?= e((string)$rotulo) ?></option>
                      <?php endforeach ?>
                    </select>
                    <?= $msg($p . 'kind') ?>
                  </td>
                  <td>
                    <input class="adm-input" name="mk[<?= e((string)$mid) ?>][label]" maxlength="80"
                           value="<?= e($vLab) ?>" aria-label="Rótulo do marcador <?= e((string)$mid) ?>" placeholder="sem rótulo">
                  </td>
                  <td class="mapas-mk__coord">
                    <input class="adm-input num<?= $inv($p . 'x') ?>" name="mk[<?= e((string)$mid) ?>][x]" type="number"
                           min="0" max="1" step="0.0001" value="<?= e($vX) ?>" aria-label="Coordenada X do marcador <?= e((string)$mid) ?>">
                    <?= $msg($p . 'x') ?>
                  </td>
                  <td class="mapas-mk__coord">
                    <input class="adm-input num<?= $inv($p . 'y') ?>" name="mk[<?= e((string)$mid) ?>][y]" type="number"
                           min="0" max="1" step="0.0001" value="<?= e($vY) ?>" aria-label="Coordenada Y do marcador <?= e((string)$mid) ?>">
                    <?= $msg($p . 'y') ?>
                  </td>
                  <td class="mapas-mk__cor">
                    <select class="adm-select" name="mk[<?= e((string)$mid) ?>][color]" aria-label="Cor do marcador <?= e((string)$mid) ?>">
                      <option value="">— padrão —</option>
                      <?php foreach ($cores as $slug => $rotulo): ?>
                        <option value="<?= e((string)$slug) ?>"<?= $vCor === (string)$slug ? ' selected' : '' ?>><?= e((string)$rotulo) ?></option>
                      <?php endforeach ?>
                    </select>
                  </td>
                  <td class="mapas-mk__anc">
                    <select class="adm-select" name="mk[<?= e((string)$mid) ?>][anchor]" aria-label="Âncora do rótulo do marcador <?= e((string)$mid) ?>">
                      <?php foreach ($ancoras as $slug => $rotulo): ?>
                        <option value="<?= e((string)$slug) ?>"<?= $vAnc === (string)$slug ? ' selected' : '' ?>><?= e((string)$rotulo) ?></option>
                      <?php endforeach ?>
                    </select>
                  </td>
                  <td class="adm-table__acts">
                    <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--danger"
                       href="<?= e(url('admin/mapas', ['id' => $mapId, 'excluir' => 'marcador', 'mk' => $mid])) ?>"
                       title="Excluir este marcador" aria-label="Excluir o marcador <?= e($vLab !== '' ? $vLab : (string)$mid) ?>"><?= icon('delete') ?></a>
                  </td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>

        <div class="adm-card__foot">
          <div class="adm-form__actions mapas-acoes">
            <button class="adm-btn adm-btn--primary" type="submit"><?= icon('save') ?><span>Salvar marcadores</span></button>
            <span class="adm-meta"><?= e((string)($ajudas['coordenada'] ?? '')) ?></span>
          </div>
        </div>
      <?php endif ?>
    </section>
  </form>

  <?php /* ---- marcador novo: formulário próprio, para o erro dele não confundir a grade ---- */ ?>
  <form class="mapas-mk-novo" method="post" action="<?= e(url('admin/mapas/salvar')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="acao" value="marcador_novo">
    <input type="hidden" name="id" value="<?= e((string)$mapId) ?>">

    <section class="adm-card">
      <div class="adm-card__head">
        <h2 class="adm-card__title">Novo marcador<small>Ele aparece no mapa do jogo assim que for gravado.</small></h2>
      </div>
      <div class="adm-card__body">
        <div class="mapas-novo-grid">
          <div class="adm-field<?= $inv('novo.kind') ?>">
            <label class="adm-label" for="n-kind">Tipo<span class="adm-label__req">*</span></label>
            <select class="adm-select" id="n-kind" name="novo[kind]">
              <?php foreach ($mkTipos as $slug => $rotulo): ?>
                <option value="<?= e((string)$slug) ?>"<?= (string)($novoPost['kind'] ?? 'loot') === (string)$slug ? ' selected' : '' ?>><?= e((string)$rotulo) ?></option>
              <?php endforeach ?>
            </select>
            <?= $msg('novo.kind') ?>
          </div>

          <div class="adm-field">
            <label class="adm-label" for="n-label">Rótulo</label>
            <input class="adm-input" id="n-label" name="novo[label]" maxlength="80"
                   value="<?= e((string)($novoPost['label'] ?? '')) ?>" placeholder="VILA ABANDONADA">
          </div>

          <div class="adm-field<?= $inv('novo.x') ?>">
            <label class="adm-label" for="n-x">X<span class="adm-label__req">*</span></label>
            <input class="adm-input num" id="n-x" name="novo[x]" type="number" min="0" max="1" step="0.0001"
                   value="<?= e((string)($novoPost['x'] ?? '0.5000')) ?>">
            <?= $msg('novo.x') ?>
          </div>

          <div class="adm-field<?= $inv('novo.y') ?>">
            <label class="adm-label" for="n-y">Y<span class="adm-label__req">*</span></label>
            <input class="adm-input num" id="n-y" name="novo[y]" type="number" min="0" max="1" step="0.0001"
                   value="<?= e((string)($novoPost['y'] ?? '0.5000')) ?>">
            <?= $msg('novo.y') ?>
          </div>

          <div class="adm-field">
            <label class="adm-label" for="n-cor">Cor</label>
            <select class="adm-select" id="n-cor" name="novo[color]">
              <option value="">— padrão —</option>
              <?php foreach ($cores as $slug => $rotulo): ?>
                <option value="<?= e((string)$slug) ?>"<?= (string)($novoPost['color'] ?? '') === (string)$slug ? ' selected' : '' ?>><?= e((string)$rotulo) ?></option>
              <?php endforeach ?>
            </select>
          </div>

          <div class="adm-field">
            <label class="adm-label" for="n-anc">Âncora</label>
            <select class="adm-select" id="n-anc" name="novo[anchor]">
              <?php foreach ($ancoras as $slug => $rotulo): ?>
                <option value="<?= e((string)$slug) ?>"<?= (string)($novoPost['anchor'] ?? '') === (string)$slug ? ' selected' : '' ?>><?= e((string)$rotulo) ?></option>
              <?php endforeach ?>
            </select>
          </div>

          <div class="adm-field mapas-novo-grid__acao">
            <span class="adm-label" aria-hidden="true">&nbsp;</span>
            <button class="adm-btn adm-btn--primary" type="submit"><?= icon('add_location_alt') ?><span>Adicionar</span></button>
          </div>
        </div>
        <p class="adm-hint"><?= e((string)($ajudas['coordenada'] ?? '')) ?></p>
      </div>
    </section>
  </form>
<?php endif ?>

<?php endif ?>
