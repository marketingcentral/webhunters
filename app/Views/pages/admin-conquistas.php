<?php declare(strict_types=1);

/**
 * Tela ADMIN · CONQUISTAS (/admin/conquistas).
 *
 * Escopo: $page, $player, $nav, $server, $data, $flash, $csrf. Layout: layouts/admin.php.
 *
 * A view não consulta o banco e não decide nada: tudo chega pronto de
 * App\Repo\AchievementRepo por App\Controllers\Admin\AchievementsController. Aqui só existe
 * apresentação — recorte de texto, escolha de cor por estado e a montagem dos formulários.
 *
 * DUAS COLUNAS, UM CARTÃO DE CADA VEZ NA DIREITA:
 *   sem parâmetro  -> painel de categorias (o retrato do catálogo)
 *   ?novo / ?editar -> formulário
 *   ?excluir        -> confirmação, ou o bloqueio com o motivo escrito
 *
 * REGRA DE ALTURA: a página não rola. Quem rola é a tabela dentro do cartão da esquerda e o
 * corpo do cartão da direita. Em 1366×768 isso é o que impede o rodapé de sumir e a última
 * linha de ficar cortada ao meio.
 *
 * SEM JAVASCRIPT: filtro é <form method="get">, confirmação de exclusão é uma tela e o botão
 * que apaga é POST. A tela inteira funciona no `curl` — que é como ela é conferida.
 */

/* ---------------------------------------------------------------------------- dados prontos */
$cromo    = is_array($data['cromo'] ?? null) ? $data['cromo'] : [];
$catRot   = (array)($cromo['categorias'] ?? []);
$estRot   = (array)($cromo['estados'] ?? []);
$recRot   = (array)($cromo['recompensas'] ?? []);
$ordens   = (array)($cromo['ordens'] ?? []);
$titulos  = (array)($cromo['titulos'] ?? []);
$vazios   = (array)($cromo['vazios'] ?? []);
$micro    = (array)($cromo['micro'] ?? []);
$msgErro  = (array)($cromo['erros'] ?? []);

$resumo   = (array)($data['resumo'] ?? []);
$catalogo = (array)($data['catalogo'] ?? []);
$linhas   = (array)($catalogo['linhas'] ?? []);
$cats     = (array)($data['cats'] ?? []);
$filtros  = (array)($data['filtros'] ?? []);
$form     = is_array($data['form'] ?? null) ? $data['form'] : null;
$exclusao = is_array($data['exclusao'] ?? null) ? $data['exclusao'] : null;
$itens    = (array)($data['itens'] ?? []);
$destaque = (int)($data['destaque'] ?? 0);

$temFiltro = ($filtros['busca'] ?? '') !== ''
          || ($filtros['categoria'] ?? '') !== ''
          || ($filtros['estado'] ?? '') !== '';

/* Query string dos filtros ativos: viaja em TODO link da tela (ordenar, paginar, editar,
   excluir, cancelar) para o operador nunca perder o recorte que abriu. */
$qs = [];
if (($filtros['busca'] ?? '') !== '')     { $qs['q'] = (string)$filtros['busca']; }
if (($filtros['categoria'] ?? '') !== '') { $qs['cat'] = (string)$filtros['categoria']; }
if (($filtros['estado'] ?? '') !== '')    { $qs['estado'] = (string)$filtros['estado']; }
if (($filtros['ordem'] ?? 'ordem') !== 'ordem') { $qs['ordem'] = (string)$filtros['ordem']; }
if ((int)($filtros['pagina'] ?? 1) > 1)   { $qs['pagina'] = (string)(int)$filtros['pagina']; }

/* $extra PRIMEIRO, de propósito: em PHP o `+` de arrays mantém a chave do operando da
   ESQUERDA. Com $qs na frente, todo link do paginador saía com a página que já estava na URL
   — na página 2, os seis botões apontavam para a página 2. */
$rota = static fn(array $extra = []): string => url('admin/conquistas', $extra + $qs);

/* ------------------------------------------------------------------------------ formatadores */

/** Rótulo de categoria. Categoria que existe no banco e não tem tradução mostra o próprio slug. */
$labelCat = static function (string $slug) use ($catRot): string {
    return (string)($catRot[$slug]['label'] ?? $slug);
};
$iconeCat = static function (string $slug) use ($catRot): string {
    return (string)($catRot[$slug]['icone'] ?? 'label');
};
$catConhecida = static fn(string $slug): bool => isset($catRot[$slug]);

/** Uma recompensa em UMA expressão curta: "6.500 XP", "2.400", "AK-103". */
$textoRecompensa = static function (array $r) use ($recRot): string {
    $tipo = (string)$r['kind'];
    if ($tipo === 'item') {
        $nome = (string)($r['item_nome'] ?? '');
        $qtd  = (int)($r['amount'] ?? 1);
        $nome = $nome !== '' ? $nome : 'item removido do catálogo';
        return $qtd > 1 ? $qtd . '× ' . $nome : $nome;
    }
    $sufixo = (string)($recRot[$tipo]['sufixo'] ?? '');
    return num((int)($r['amount'] ?? 0)) . ($sufixo !== '' ? ' ' . $sufixo : '');
};

/** A frase inteira das recompensas, para o title da célula (a tela mostra no máximo três). */
$resumoRecompensas = static function (array $rs) use ($textoRecompensa, $recRot, $micro): string {
    if ($rs === []) {
        return (string)($micro['recompensa_som'] ?? 'Sem recompensa configurada.');
    }
    $partes = [];
    foreach ($rs as $r) {
        $partes[] = (string)($recRot[(string)$r['kind']]['label'] ?? $r['kind']) . ': ' . $textoRecompensa($r);
    }
    return implode(' · ', $partes);
};

/** Erro de um campo do formulário, ou '' quando o campo está limpo. */
$erroDe = static function (string $campo) use ($form): string {
    return (string)(($form['erros'] ?? [])[$campo] ?? '');
};
$valorDe = static function (string $campo) use ($form): string {
    return (string)(($form['valores'] ?? [])[$campo] ?? '');
};

/** Bloco <span class="adm-error"> de um campo — some sozinho quando não há erro. */
$erroHtml = static function (string $campo) use ($erroDe): string {
    $e = $erroDe($campo);
    return $e === '' ? '' : '<span class="adm-error">' . icon('error') . e($e) . '</span>';
};

?>
<div class="cq">

  <!-- ================================================================== LISTA (esquerda) -->
  <?php /* Região da lista (App\Core\Fragmento): o pedaço que volta do servidor quando o
           filtro é aplicado sem recarregar. A coluna da direita fica de fora. */ ?>
  <!--eh:lista-->
  <section class="adm-card cq__list">

    <div class="adm-card__head">
      <h2 class="adm-card__title">
        <?= e((string)($titulos['lista'] ?? 'Catálogo de conquistas')) ?>
        <small>
          <?php
            /* O subtítulo é o retrato do catálogo INTEIRO, não do filtro: é o número que diz
               se a lista curta na tela é o catálogo todo ou um recorte dele. */
            $partes = [
                num((int)($resumo['conquistas'] ?? 0)) . ' no catálogo',
                num((int)($resumo['categorias'] ?? 0)) . ' categorias',
                num((int)($resumo['desbloqueios'] ?? 0)) . ' desbloqueios',
            ];
            if ((int)($resumo['sem_recompensa'] ?? 0) > 0) {
                $partes[] = num((int)$resumo['sem_recompensa']) . ' ' . (string)($vazios['sem_recompensa'] ?? 'sem recompensa');
            }
            echo e(implode(' · ', $partes));
          ?>
        </small>
      </h2>
    </div>

    <!-- ---- filtros: form GET, sem JavaScript nenhum ---- -->
    <?php /* data-adm-preservar: o formulário é transplantado para dentro do HTML novo em
             vez de ser substituído — é onde está o cursor de quem digita. */ ?>
    <form class="adm-toolbar cq-filtros" method="get" action="<?= e(url('admin/conquistas')) ?>"
          data-adm-preservar="filtros">
      <div class="adm-toolbar__grow adm-search">
        <?= icon('search') ?>
        <input class="adm-input" type="search" name="q" value="<?= e((string)($filtros['busca'] ?? '')) ?>"
               placeholder="Buscar por nome, slug ou descrição" aria-label="Buscar conquista" maxlength="80">
      </div>

      <label class="cq-filtros__campo">
        <span class="cq-filtros__lbl">Categoria</span>
        <select class="adm-select" name="cat">
          <option value="">Todas</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?= e((string)$c['slug']) ?>"<?= (string)$c['slug'] === (string)($filtros['categoria'] ?? '') ? ' selected' : '' ?>>
              <?= e($labelCat((string)$c['slug'])) ?> (<?= e(num((int)$c['conquistas'])) ?>)
            </option>
          <?php endforeach ?>
        </select>
      </label>

      <label class="cq-filtros__campo">
        <span class="cq-filtros__lbl">Estado</span>
        <select class="adm-select" name="estado">
          <option value="">Qualquer</option>
          <?php foreach ($estRot as $slug => $est): ?>
            <option value="<?= e((string)$slug) ?>"<?= (string)$slug === (string)($filtros['estado'] ?? '') ? ' selected' : '' ?>>
              <?= e((string)$est['label']) ?>
            </option>
          <?php endforeach ?>
        </select>
      </label>

      <label class="cq-filtros__campo">
        <span class="cq-filtros__lbl">Ordenar</span>
        <select class="adm-select" name="ordem">
          <?php foreach ($ordens as $slug => $rot): ?>
            <option value="<?= e((string)$slug) ?>"<?= (string)$slug === (string)($filtros['ordem'] ?? 'ordem') ? ' selected' : '' ?>>
              <?= e((string)$rot) ?>
            </option>
          <?php endforeach ?>
        </select>
      </label>

      <button class="adm-btn adm-btn--ghost" type="submit"><?= icon('filter_alt') ?><span>Filtrar</span></button>
      <?php if ($temFiltro): ?>
        <a class="adm-btn adm-btn--ghost cq-filtros__limpar" href="<?= e(url('admin/conquistas')) ?>">
          <?= icon('backspace') ?><span>Limpar</span>
        </a>
      <?php endif ?>
    </form>

    <!-- ---- tabela ---- -->
    <div class="adm-card__body adm-card__body--flush cq__tabela">
      <?php if ($linhas === []): ?>
        <?php /* Vazio por FILTRO e vazio por CATÁLOGO são problemas diferentes e têm saídas
                 diferentes: um se resolve limpando o filtro, o outro criando conteúdo. */ ?>
        <div class="adm-empty">
          <?= icon($temFiltro ? 'search_off' : 'military_tech') ?>
          <h3 class="adm-empty__title"><?= e($temFiltro ? 'Nada com estes filtros' : 'Catálogo vazio') ?></h3>
          <p class="adm-empty__txt">
            <?php if ($temFiltro): ?>
              <?= e(sprintf(
                    (string)($vazios['filtro'] ?? 'Nenhuma conquista responde a estes filtros. O catálogo tem %s.'),
                    num((int)($resumo['conquistas'] ?? 0)) . ' conquista' . ((int)($resumo['conquistas'] ?? 0) === 1 ? '' : 's')
              )) ?>
            <?php else: ?>
              <?= e((string)($vazios['catalogo'] ?? 'A tabela achievements está vazia.')) ?>
            <?php endif ?>
          </p>
          <?php if ($temFiltro): ?>
            <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/conquistas')) ?>"><?= icon('backspace') ?><span>Limpar filtros</span></a>
          <?php else: ?>
            <a class="adm-btn adm-btn--primary" href="<?= e(url('admin/conquistas', ['novo' => '1'])) ?>"><?= icon('add') ?><span>Criar a primeira</span></a>
          <?php endif ?>
        </div>
      <?php else: ?>
        <div class="adm-tablewrap">
          <?php /* table-layout fixo + colgroup: é o que garante que nome comprido vire
                   reticências em vez de empurrar a tabela para fora do cartão. */ ?>
          <table class="adm-table cq-tab">
            <colgroup>
              <col class="cq-tab__c-nome">
              <col class="cq-tab__c-rec">
              <col class="cq-tab__c-meta">
              <col class="cq-tab__c-desb">
              <col class="cq-tab__c-est">
              <col class="cq-tab__c-act">
            </colgroup>
            <thead>
              <tr>
                <th scope="col">Conquista</th>
                <th scope="col">Recompensas</th>
                <th scope="col" class="tr">Meta</th>
                <th scope="col" class="tr">Desbloqueios</th>
                <th scope="col">Estado</th>
                <th scope="col" class="tr">Ações</th>
              </tr>
            </thead>
            <tbody>
              <?php $catAtual = null; ?>
              <?php foreach ($linhas as $l): ?>

                <?php if ((string)$l['category'] !== $catAtual): ?>
                  <?php
                    $catAtual = (string)$l['category'];
                    /* Contagem da categoria INTEIRA (não só do que caiu nesta página): o
                       cabeçalho do grupo é o mapa, e um mapa que muda de número conforme a
                       paginação não é mapa. */
                    $info = null;
                    foreach ($cats as $c) {
                        if ((string)$c['slug'] === $catAtual) { $info = $c; break; }
                    }
                  ?>
                  <tr class="cq-grp">
                    <th colspan="6" scope="colgroup"><span class="cq-grp__in">
                      <span class="cq-grp__nome">
                        <?= icon($iconeCat($catAtual), 'cq-grp__ico') ?>
                        <?= e($labelCat($catAtual)) ?>
                        <?php if (!$catConhecida($catAtual)): ?>
                          <em class="cq-grp__alien" title="<?= e((string)($micro['categoria_nova'] ?? '')) ?>">
                            <?= icon('help') ?><?= e((string)($micro['categoria_nova'] ?? 'fora do catálogo')) ?>
                          </em>
                        <?php endif ?>
                      </span>
                      <?php if ($info !== null): ?>
                        <span class="cq-grp__n num">
                          <?= e(num((int)$info['conquistas'])) ?> conquista<?= (int)$info['conquistas'] === 1 ? '' : 's' ?>
                          · <?= e(num((int)$info['desbloqueios'])) ?> desbloqueio<?= (int)$info['desbloqueios'] === 1 ? '' : 's' ?>
                        </span>
                      <?php endif ?>
                      <?php if ((string)($filtros['categoria'] ?? '') === ''): ?>
                        <a class="cq-grp__so" href="<?= e($rota(['cat' => $catAtual, 'pagina' => '1'])) ?>">só esta</a>
                      <?php endif ?>
                    </span></th>
                  </tr>
                <?php endif ?>

                <?php
                  $id     = (int)$l['id'];
                  $estado = (string)$l['estado'];
                  $tom    = (string)($estRot[$estado]['tom'] ?? 'muted');
                  $recs   = (array)$l['recompensas'];
                  $desc   = (string)$l['description'];
                  $metaTxt = (int)$l['target'] <= 1
                      ? (string)($micro['meta_um'] ?? 'Passo único.')
                      : sprintf((string)($micro['meta_n'] ?? 'Meta: %s'), num((int)$l['target']));
                ?>
                <tr class="cq-row cq-row--<?= e($estado) ?><?= $id === $destaque ? ' is-destaque' : '' ?>"
                    id="conquista-<?= e((string)$id) ?>">

                  <td>
                    <span class="cq-nome">
                      <span class="cq-nome__hex cq-nome__hex--<?= e($tom) ?>"><?= icon((string)$l['icon'] !== '' ? (string)$l['icon'] : 'military_tech') ?></span>
                      <span class="cq-nome__txt">
                        <strong title="<?= e((string)$l['name'] . ($desc !== '' ? ' — ' . $desc : '')) ?>"><?= e((string)$l['name']) ?></strong>
                        <small title="<?= e('slug: ' . (string)$l['slug'] . ' · ' . (string)($micro['ordem'] ?? '')) ?>">
                          <?= e((string)$l['slug']) ?> <span class="cq-nome__ordem num">#<?= e((string)$l['sort_order']) ?></span>
                        </small>
                      </span>
                    </span>
                  </td>

                  <td title="<?= e($resumoRecompensas($recs)) ?>">
                    <?php if ($recs === []): ?>
                      <span class="cq-sem"><?= icon('block') ?><?= e((string)($vazios['sem_recompensa'] ?? 'sem recompensa')) ?></span>
                    <?php else: ?>
                      <span class="cq-recs">
                        <?php foreach (array_slice($recs, 0, 3) as $r): ?>
                          <?php $rt = (string)$r['kind']; ?>
                          <span class="cq-rec cq-rec--<?= e((string)($recRot[$rt]['tom'] ?? 'muted')) ?>">
                            <?= icon((string)($recRot[$rt]['icone'] ?? 'redeem')) ?>
                            <span class="num"><?= e($textoRecompensa($r)) ?></span>
                          </span>
                        <?php endforeach ?>
                        <?php if (count($recs) > 3): ?>
                          <span class="cq-rec cq-rec--mais num">+<?= e((string)(count($recs) - 3)) ?></span>
                        <?php endif ?>
                      </span>
                    <?php endif ?>
                  </td>

                  <td class="tr num" title="<?= e($metaTxt) ?>"><?= e(num((int)$l['target'])) ?></td>

                  <td class="tr">
                    <span class="cq-desb">
                      <span class="num<?= (int)$l['desbloqueios'] === 0 ? ' is-zero' : '' ?>"><?= e(num((int)$l['desbloqueios'])) ?></span>
                      <?php if ((int)$l['progresso'] > 0): ?>
                        <small class="num"><?= e(sprintf((string)($micro['progresso_n'] ?? '%s em progresso'), num((int)$l['progresso']))) ?></small>
                      <?php elseif ((int)$l['desbloqueios'] === 0): ?>
                        <small><?= e((string)($micro['nunca'] ?? 'ninguém ainda')) ?></small>
                      <?php endif ?>
                    </span>
                  </td>

                  <td>
                    <?php /* Estado com COR e TEXTO — a cor é atalho, a palavra é o dado. */ ?>
                    <span class="adm-badge adm-badge--<?= e($tom) ?>" title="<?= e((string)($estRot[$estado]['texto'] ?? '')) ?>">
                      <?= e((string)($estRot[$estado]['label'] ?? $estado)) ?>
                    </span>
                  </td>

                  <td class="adm-table__acts">
                    <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--ghost"
                       href="<?= e($rota(['editar' => (string)$id])) ?>"
                       title="Editar <?= e((string)$l['name']) ?>" aria-label="Editar <?= e((string)$l['name']) ?>">
                      <?= icon('edit') ?>
                    </a>
                    <?php if (!empty($l['pode_excluir'])): ?>
                      <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--danger"
                         href="<?= e($rota(['excluir' => (string)$id])) ?>"
                         title="Excluir <?= e((string)$l['name']) ?>" aria-label="Excluir <?= e((string)$l['name']) ?>">
                        <?= icon('delete') ?>
                      </a>
                    <?php else: ?>
                      <?php /* Botão desarmado com o motivo no title: sumir com ele deixaria a
                               coluna desalinhada e o operador sem saber por que não pode. A
                               trava de verdade está no controller — isto aqui é cortesia. */ ?>
                      <span class="adm-btn adm-btn--sm adm-btn--icon adm-btn--ghost is-disabled cq-trava"
                            aria-disabled="true"
                            title="<?= e(sprintf(
                                (string)($micro['trava_excluir'] ?? 'Não dá para excluir: %s já está no histórico de %s.'),
                                '"' . (string)$l['name'] . '"',
                                num((int)$l['desbloqueios']) . ' jogador' . ((int)$l['desbloqueios'] === 1 ? '' : 'es')
                            )) ?>">
                        <?= icon('lock') ?>
                      </span>
                    <?php endif ?>
                  </td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
      <?php endif ?>
    </div>

    <!-- ---- paginação: só existe quando há mais de uma página ---- -->
    <?php if ((int)($catalogo['paginas'] ?? 1) > 1): ?>
      <div class="adm-card__foot">
        <nav class="adm-pager" aria-label="Paginação do catálogo">
          <span class="adm-pager__info num" data-adm-total>
            <?= e(num((int)$catalogo['inicio'])) ?>–<?= e(num((int)$catalogo['fim'])) ?>
            de <?= e(num((int)$catalogo['total'])) ?>
          </span>
          <?php
            $pag = (int)$catalogo['pagina'];
            $tot = (int)$catalogo['paginas'];
            // Janela de 5 páginas ao redor da atual: com 20 botões a barra vira outra tabela.
            $ini = max(1, min($pag - 2, $tot - 4));
            $fim = min($tot, max($pag + 2, 5));
          ?>
          <a class="adm-pager__btn<?= $pag <= 1 ? ' is-disabled' : '' ?>"
             href="<?= e($rota(['pagina' => (string)max(1, $pag - 1)])) ?>" aria-label="Página anterior"><?= icon('chevron_left') ?></a>
          <?php if ($ini > 1): ?>
            <a class="adm-pager__btn" href="<?= e($rota(['pagina' => '1'])) ?>">1</a>
            <?php if ($ini > 2): ?><span class="adm-pager__gap">…</span><?php endif ?>
          <?php endif ?>
          <?php for ($i = $ini; $i <= $fim; $i++): ?>
            <a class="adm-pager__btn<?= $i === $pag ? ' is-active' : '' ?>"
               href="<?= e($rota(['pagina' => (string)$i])) ?>"<?= $i === $pag ? ' aria-current="page"' : '' ?>><?= e((string)$i) ?></a>
          <?php endfor ?>
          <?php if ($fim < $tot): ?>
            <?php if ($fim < $tot - 1): ?><span class="adm-pager__gap">…</span><?php endif ?>
            <a class="adm-pager__btn" href="<?= e($rota(['pagina' => (string)$tot])) ?>"><?= e((string)$tot) ?></a>
          <?php endif ?>
          <a class="adm-pager__btn<?= $pag >= $tot ? ' is-disabled' : '' ?>"
             href="<?= e($rota(['pagina' => (string)min($tot, $pag + 1)])) ?>" aria-label="Próxima página"><?= icon('chevron_right') ?></a>
        </nav>
      </div>
    <?php endif ?>
  </section>
  <!--/eh:lista-->

  <!-- ================================================================= COLUNA DA DIREITA -->
  <aside class="cq__side">

    <?php if ($form !== null): ?>
      <?php
        $editando = (string)($form['modo'] ?? 'novo') === 'editar';
        $formId   = (int)($form['id'] ?? 0);
        $uso      = is_array($form['uso'] ?? null) ? $form['uso'] : null;
        $recs     = (array)($form['recompensas'] ?? []);
        $errosRec = (array)(($form['erros'] ?? [])['recompensas'] ?? []);

        /* Linhas do bloco de recompensas: as que existem mais dois slots livres, dentro do
           teto. Sem JavaScript não há "adicionar linha" — então a tela já entrega os campos
           vazios, que é o que faz o formulário funcionar de primeira. */
        $maxRec  = max(1, (int)($data['max_recompensas'] ?? 6));
        $nLinhas = min($maxRec, max(count($recs) + 2, 3));

        $categoriasForm = $catRot;
        // Categoria que veio do banco sem tradução continua selecionável: obrigar a trocar de
        // categoria para conseguir salvar o nome seria a tela mandando no conteúdo.
        foreach ($cats as $c) {
            if (!isset($categoriasForm[(string)$c['slug']])) {
                $categoriasForm[(string)$c['slug']] = ['label' => (string)$c['slug'], 'icone' => 'label'];
            }
        }
      ?>
      <section class="adm-card cq-form">
        <div class="adm-card__head">
          <h2 class="adm-card__title">
            <?= e((string)($titulos[$editando ? 'editar' : 'novo'] ?? 'Conquista')) ?>
            <?php if ($editando): ?><small><?= e($valorDe('slug')) ?></small><?php endif ?>
          </h2>
          <div class="adm-card__tools">
            <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--ghost" href="<?= e($rota()) ?>"
               title="Fechar formulário" aria-label="Fechar formulário"><?= icon('close') ?></a>
          </div>
        </div>

        <form class="adm-card__body cq-form__body scroll-y" method="post"
              action="<?= e(url('admin/conquistas/salvar')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= e((string)$formId) ?>">
          <?php /* Os filtros abertos viajam no POST: sem eles o redirect depois de salvar
                   devolveria o operador para a lista inteira, na primeira página. */ ?>
          <input type="hidden" name="f_q" value="<?= e((string)($filtros['busca'] ?? '')) ?>">
          <input type="hidden" name="f_cat" value="<?= e((string)($filtros['categoria'] ?? '')) ?>">
          <input type="hidden" name="f_estado" value="<?= e((string)($filtros['estado'] ?? '')) ?>">
          <input type="hidden" name="f_ordem" value="<?= e((string)($filtros['ordem'] ?? '')) ?>">
          <input type="hidden" name="f_pagina" value="<?= e((string)(int)($filtros['pagina'] ?? 1)) ?>">

          <?php if ($editando && $uso !== null): ?>
            <p class="cq-form__uso<?= (int)$uso['desbloqueios'] > 0 ? ' is-travada' : '' ?>">
              <?= icon((int)$uso['desbloqueios'] > 0 ? 'lock' : 'group') ?>
              <span>
                <?php if ((int)$uso['desbloqueios'] > 0): ?>
                  <?= e(num((int)$uso['desbloqueios'])) ?> jogador<?= (int)$uso['desbloqueios'] === 1 ? '' : 'es' ?> já concluiu esta conquista.
                  Editar é seguro; excluir, não.
                <?php elseif ((int)$uso['progresso'] > 0): ?>
                  <?= e(num((int)$uso['progresso'])) ?> jogador<?= (int)$uso['progresso'] === 1 ? '' : 'es' ?> com progresso e nenhuma conclusão.
                <?php else: ?>
                  <?= e((string)($micro['sem_vinculo'] ?? 'Nenhum jogador tem registro nesta conquista.')) ?>
                <?php endif ?>
              </span>
            </p>
          <?php endif ?>

          <div class="adm-field<?= $erroDe('name') !== '' ? ' is-invalid' : '' ?>">
            <label class="adm-label" for="cq-name">Nome<span class="adm-label__req">*</span></label>
            <input class="adm-input" type="text" id="cq-name" name="name" maxlength="120" required
                   value="<?= e($valorDe('name')) ?>" placeholder="CAÇADOR EXPERIENTE">
            <?= $erroHtml('name') ?>
          </div>

          <div class="adm-field<?= $erroDe('slug') !== '' ? ' is-invalid' : '' ?>">
            <label class="adm-label" for="cq-slug">Slug<?php if (!$editando): ?><span class="adm-hint cq-form__auto">gerado do nome se ficar em branco</span><?php endif ?></label>
            <input class="adm-input cq-form__mono" type="text" id="cq-slug" name="slug" maxlength="64"
                   value="<?= e($valorDe('slug')) ?>" placeholder="cacador-experiente"
                   pattern="[a-z0-9]+(-[a-z0-9]+)*">
            <?php if ($erroDe('slug') !== ''): ?>
              <?= $erroHtml('slug') ?>
            <?php elseif ($editando): ?>
              <span class="adm-hint"><?= e((string)($micro['slug_travado'] ?? '')) ?></span>
            <?php endif ?>
          </div>

          <div class="adm-form__row">
            <div class="adm-field<?= $erroDe('category') !== '' ? ' is-invalid' : '' ?>">
              <label class="adm-label" for="cq-cat">Categoria<span class="adm-label__req">*</span></label>
              <select class="adm-select" id="cq-cat" name="category" required>
                <?php foreach ($categoriasForm as $slug => $c): ?>
                  <option value="<?= e((string)$slug) ?>"<?= (string)$slug === $valorDe('category') ? ' selected' : '' ?>>
                    <?= e((string)$c['label']) ?>
                  </option>
                <?php endforeach ?>
              </select>
              <?= $erroHtml('category') ?>
            </div>

            <div class="adm-field<?= $erroDe('icon') !== '' ? ' is-invalid' : '' ?>">
              <label class="adm-label" for="cq-icon">Ícone</label>
              <span class="cq-form__icone">
                <input class="adm-input cq-form__mono" type="text" id="cq-icon" name="icon" maxlength="48"
                       value="<?= e($valorDe('icon')) ?>" placeholder="military_tech" pattern="[a-z0-9_]*">
                <?php /* A prévia é o próprio ícone que a HUD vai desenhar: um nome errado de
                         ligadura aparece aqui como palavra, e o operador vê o erro na hora. */ ?>
                <span class="cq-form__prev" aria-hidden="true"><?= icon($valorDe('icon') !== '' ? $valorDe('icon') : 'military_tech') ?></span>
              </span>
              <?= $erroHtml('icon') ?>
            </div>
          </div>

          <div class="adm-field<?= $erroDe('description') !== '' ? ' is-invalid' : '' ?>">
            <label class="adm-label" for="cq-desc">Descrição</label>
            <textarea class="adm-textarea" id="cq-desc" name="description" maxlength="400"
                      placeholder="O que o jogador precisa fazer."><?= e($valorDe('description')) ?></textarea>
            <?= $erroHtml('description') ?>
          </div>

          <div class="adm-form__row">
            <div class="adm-field<?= $erroDe('target') !== '' ? ' is-invalid' : '' ?>">
              <label class="adm-label" for="cq-target">Meta<span class="adm-label__req">*</span></label>
              <input class="adm-input num" type="number" id="cq-target" name="target" min="1" max="1000000" required
                     value="<?= e($valorDe('target')) ?>">
              <?php if ($erroDe('target') !== ''): ?>
                <?= $erroHtml('target') ?>
              <?php else: ?>
                <span class="adm-hint">1 = passo único</span>
              <?php endif ?>
            </div>

            <div class="adm-field<?= $erroDe('sort_order') !== '' ? ' is-invalid' : '' ?>">
              <label class="adm-label" for="cq-ordem">Ordem</label>
              <input class="adm-input num" type="number" id="cq-ordem" name="sort_order" min="0" max="65535"
                     value="<?= e($valorDe('sort_order')) ?>">
              <?php if ($erroDe('sort_order') !== ''): ?>
                <?= $erroHtml('sort_order') ?>
              <?php else: ?>
                <span class="adm-hint">dentro da categoria</span>
              <?php endif ?>
            </div>
          </div>

          <!-- ---- recompensas ---- -->
          <fieldset class="cq-rw">
            <legend class="adm-label">Recompensas<span class="cq-rw__teto">até <?= e((string)$maxRec) ?></span></legend>
            <p class="adm-hint cq-rw__ajuda">O campo <b>item</b> só é usado quando o tipo é <b>Item</b>; nos demais vale o valor.</p>

            <div class="cq-rw__cab" aria-hidden="true">
              <span>Tipo</span><span>Valor</span><span>Item</span>
            </div>

            <?php for ($i = 0; $i < $nLinhas; $i++): ?>
              <?php
                $r    = $recs[$i] ?? ['kind' => '', 'amount' => '', 'item_id' => ''];
                $erro = (string)($errosRec[$i] ?? '');
              ?>
              <div class="cq-rw__linha<?= $erro !== '' ? ' is-invalid' : '' ?>">
                <select class="adm-select" name="rec_kind[]" aria-label="Tipo da recompensa <?= e((string)($i + 1)) ?>">
                  <option value="">—</option>
                  <?php foreach ($recRot as $slug => $rt): ?>
                    <option value="<?= e((string)$slug) ?>"<?= (string)$slug === (string)$r['kind'] ? ' selected' : '' ?>>
                      <?= e((string)$rt['label']) ?>
                    </option>
                  <?php endforeach ?>
                </select>
                <input class="adm-input num" type="number" name="rec_amount[]" min="0" step="1"
                       value="<?= e((string)($r['amount'] ?? '')) ?>"
                       aria-label="Valor da recompensa <?= e((string)($i + 1)) ?>" placeholder="0">
                <select class="adm-select" name="rec_item[]" aria-label="Item da recompensa <?= e((string)($i + 1)) ?>">
                  <option value="">—</option>
                  <?php foreach ($itens as $it): ?>
                    <option value="<?= e((string)$it['id']) ?>"<?= (string)$it['id'] === (string)($r['item_id'] ?? '') ? ' selected' : '' ?>>
                      <?= e((string)$it['name']) ?>
                    </option>
                  <?php endforeach ?>
                </select>
                <?php if ($erro !== ''): ?>
                  <span class="adm-error cq-rw__erro"><?= icon('error') ?><?= e($erro) ?></span>
                <?php endif ?>
              </div>
            <?php endfor ?>

            <?php /* Erro que não pertence a nenhuma linha (estouro do teto) aparece no pé. */ ?>
            <?php if (isset($errosRec[$maxRec])): ?>
              <span class="adm-error"><?= icon('error') ?><?= e((string)$errosRec[$maxRec]) ?></span>
            <?php endif ?>
          </fieldset>

          <div class="adm-form__actions">
            <button class="adm-btn adm-btn--primary" type="submit">
              <?= icon('save') ?><span><?= e($editando ? 'Salvar alterações' : 'Criar conquista') ?></span>
            </button>
            <a class="adm-btn adm-btn--ghost" href="<?= e($rota()) ?>">Cancelar</a>
            <?php if ($editando && ($form['uso']['desbloqueios'] ?? 1) === 0): ?>
              <a class="adm-btn adm-btn--danger adm-spacer" href="<?= e($rota(['excluir' => (string)$formId])) ?>">
                <?= icon('delete') ?><span>Excluir</span>
              </a>
            <?php endif ?>
          </div>
        </form>
      </section>

    <?php elseif ($exclusao !== null): ?>
      <?php
        $alvo = (array)$exclusao['conquista'];
        $uso  = (array)$exclusao['uso'];
        $bloq = (bool)$exclusao['bloqueada'];
      ?>
      <section class="adm-card cq-del<?= $bloq ? ' cq-del--bloq' : '' ?>">
        <div class="adm-card__head">
          <h2 class="adm-card__title">
            <?= e((string)($titulos[$bloq ? 'bloqueio' : 'excluir'] ?? 'Excluir conquista')) ?>
          </h2>
          <div class="adm-card__tools">
            <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--ghost" href="<?= e($rota()) ?>"
               title="Cancelar" aria-label="Cancelar"><?= icon('close') ?></a>
          </div>
        </div>

        <div class="adm-card__body scroll-y">
          <p class="cq-del__alvo">
            <span class="cq-nome__hex cq-nome__hex--<?= e((string)($estRot[(string)$alvo['estado']]['tom'] ?? 'muted')) ?>">
              <?= icon((string)$alvo['icon'] !== '' ? (string)$alvo['icon'] : 'military_tech') ?>
            </span>
            <span>
              <strong><?= e((string)$alvo['name']) ?></strong>
              <small class="cq-form__mono"><?= e((string)$alvo['slug']) ?> · <?= e($labelCat((string)$alvo['category'])) ?></small>
            </span>
          </p>

          <dl class="adm-dl cq-del__nums">
            <dt>Desbloqueios</dt><dd class="num"><?= e(num((int)$uso['desbloqueios'])) ?></dd>
            <dt>Em progresso</dt><dd class="num"><?= e(num((int)$uso['progresso'])) ?></dd>
            <dt>Recompensas</dt><dd class="num"><?= e(num(count((array)$alvo['recompensas']))) ?></dd>
          </dl>

          <?php if ($bloq): ?>
            <p class="cq-del__motivo">
              <?= icon('lock') ?>
              <span><?= e((string)$exclusao['motivo']) ?></span>
            </p>
            <p class="cq-del__saida">
              O histórico do jogador cairia junto (<code>player_achievements</code> tem
              <code>ON DELETE CASCADE</code>). Para tirar a conquista de circulação sem apagar
              o passado de ninguém, edite-a — mudar categoria, meta ou descrição é seguro.
            </p>
            <div class="adm-form__actions">
              <a class="adm-btn adm-btn--primary" href="<?= e($rota(['editar' => (string)$alvo['id']])) ?>">
                <?= icon('edit') ?><span>Editar em vez de excluir</span>
              </a>
              <a class="adm-btn adm-btn--ghost" href="<?= e($rota()) ?>">Voltar</a>
            </div>
          <?php else: ?>
            <p class="cq-del__aviso">
              <?= icon('warning') ?>
              <span>
                <?php if ((int)$uso['progresso'] > 0): ?>
                  <?= e(sprintf(
                        (string)($micro['perde_progresso'] ?? '%s tem progresso e vai perdê-lo.'),
                        num((int)$uso['progresso']) . ' jogador' . ((int)$uso['progresso'] === 1 ? '' : 'es')
                  )) ?>
                <?php else: ?>
                  <?= e((string)($micro['sem_vinculo'] ?? 'Nenhum jogador tem registro nesta conquista.')) ?>
                <?php endif ?>
                <?= e((string)($micro['confirmar'] ?? 'Esta ação não tem desfazer.')) ?>
              </span>
            </p>
            <form method="post" action="<?= e(url('admin/conquistas/excluir')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= e((string)$alvo['id']) ?>">
              <input type="hidden" name="f_q" value="<?= e((string)($filtros['busca'] ?? '')) ?>">
              <input type="hidden" name="f_cat" value="<?= e((string)($filtros['categoria'] ?? '')) ?>">
              <input type="hidden" name="f_estado" value="<?= e((string)($filtros['estado'] ?? '')) ?>">
              <input type="hidden" name="f_ordem" value="<?= e((string)($filtros['ordem'] ?? '')) ?>">
              <input type="hidden" name="f_pagina" value="<?= e((string)(int)($filtros['pagina'] ?? 1)) ?>">
              <div class="adm-form__actions">
                <button class="adm-btn adm-btn--danger" type="submit">
                  <?= icon('delete_forever') ?><span>Excluir definitivamente</span>
                </button>
                <a class="adm-btn adm-btn--ghost" href="<?= e($rota()) ?>">Cancelar</a>
              </div>
            </form>
          <?php endif ?>
        </div>
      </section>

    <?php else: ?>
      <?php /* Nada aberto: a coluna mostra o retrato do catálogo por categoria. É o painel que
               responde "onde está o conteúdo e onde está o vazio" sem o operador filtrar nada. */ ?>
      <section class="adm-card cq-cats">
        <div class="adm-card__head">
          <h2 class="adm-card__title">
            <?= e((string)($titulos['categorias'] ?? 'Categorias no banco')) ?>
            <small><?= e(num((int)($resumo['categorias'] ?? 0))) ?> em uso · <?= e(num((int)($resumo['paradas'] ?? 0))) ?> conquista(s) sem uso</small>
          </h2>
        </div>
        <div class="adm-card__body adm-card__body--flush cq-cats__corpo scroll-y">
          <?php if ($cats === []): ?>
            <div class="adm-empty">
              <?= icon('category') ?>
              <p class="adm-empty__txt"><?= e((string)($vazios['catalogo'] ?? 'Catálogo vazio.')) ?></p>
            </div>
          <?php else: ?>
            <?php $maxCat = max(array_map(static fn(array $c): int => (int)$c['conquistas'], $cats)); ?>
            <ul class="cq-cats__lista">
              <?php foreach ($cats as $c): ?>
                <?php
                  $slug = (string)$c['slug'];
                  $on   = $slug === (string)($filtros['categoria'] ?? '');
                  $fatia = $maxCat > 0 ? (int)round((int)$c['conquistas'] / $maxCat * 100) : 0;
                ?>
                <li class="cq-cats__item<?= $on ? ' is-on' : '' ?>">
                  <a href="<?= e($on ? url('admin/conquistas') : $rota(['cat' => $slug, 'pagina' => '1'])) ?>"
                     title="<?= e($on ? 'Remover o filtro de categoria' : 'Filtrar por ' . $labelCat($slug)) ?>">
                    <?= icon($iconeCat($slug), 'cq-cats__ico') ?>
                    <span class="cq-cats__txt">
                      <strong><?= e($labelCat($slug)) ?></strong>
                      <small class="num"><?= e(num((int)$c['desbloqueios'])) ?> desbloqueio<?= (int)$c['desbloqueios'] === 1 ? '' : 's' ?></small>
                    </span>
                    <span class="cq-cats__n num"><?= e(num((int)$c['conquistas'])) ?></span>
                    <span class="cq-cats__bar" style="--v:<?= e((string)$fatia) ?>%" aria-hidden="true"></span>
                  </a>
                </li>
              <?php endforeach ?>
            </ul>
          <?php endif ?>
        </div>
        <p class="adm-card__foot cq-cats__pe">
          <?= icon('info') ?>
          <span>Categoria é texto livre em <code>achievements.category</code>: o que estiver no banco aparece aqui, com rótulo ou sem.</span>
        </p>
      </section>
    <?php endif ?>
  </aside>
</div>
