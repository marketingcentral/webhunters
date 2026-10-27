<?php declare(strict_types=1);

/**
 * Tela ADMIN · RELATÓRIOS (/admin/relatorios).
 *
 * Escopo: $page, $player, $nav, $server, $data, $flash, $csrf. Layout: layouts/admin.php.
 *
 * A view não consulta o banco e não decide regra: tudo chegou pronto de App\Repo\ReportsRepo
 * por App\Controllers\Admin\ReportsController. O que existe aqui é composição (.adm-* de
 * assets/css/admin.css), formatação de data e a montagem dos links que preservam o recorte.
 *
 * TUDO NAVEGA PELA URL
 * --------------------
 * Aba, busca, filtros, página, relatório aberto e confirmação de exclusão são parâmetros de
 * `GET`. Nenhum estado desta tela mora em JavaScript. É o que permite mandar para um colega o
 * link exato de um bug, voltar pelo botão do navegador e conferir a tela inteira por `curl`.
 *
 * A EXCLUSÃO CONFIRMA NO SERVIDOR
 * -------------------------------
 * O botão EXCLUIR é um link que recarrega a tela com `confirmar=<id>`; só então aparece o
 * POST de verdade. Um `confirm()` de JavaScript não passaria pela CSP do projeto e, pior,
 * sumiria em silêncio se o script falhasse — a exclusão aconteceria sem pergunta nenhuma.
 */

$cromo   = (array)($data['cromo'] ?? []);
$abas    = (array)($cromo['abas'] ?? []);
$estados = (array)(($cromo['status'] ?? [])[$data['aba'] ?? ''] ?? []);
$sev     = (array)($cromo['severidades'] ?? []);
$prio    = (array)($cromo['prioridades'] ?? []);
$ordens  = (array)($cromo['ordens'] ?? []);
$vazios  = (array)($cromo['vazios'] ?? []);
$micro   = (array)($cromo['micro'] ?? []);
$erros   = (array)($cromo['erros'] ?? []);

$aba       = (string)($data['aba'] ?? 'bugs');
$ehBug     = $aba === 'bugs';
$filtros   = (array)($data['filtros'] ?? []);
$lista     = (array)($data['lista'] ?? []);
$linhas    = (array)($lista['linhas'] ?? []);
$contagens = (array)($data['contagens'] ?? []);
$grupos    = (array)($data['grupos'] ?? []);
$pesos     = (array)($data['pesos'] ?? []);
$resumo    = (array)($data['resumo'] ?? []);
$detalhe   = is_array($data['detalhe'] ?? null) ? $data['detalhe'] : null;
$erro      = (string)($data['erro'] ?? '');
$confirmar = (int)($data['confirmar'] ?? 0);

/* Pesos (gravidade/prioridade) indexados pelo valor gravado, para a linha da tabela achar o
   rótulo e a cor sem varrer a lista a cada iteração. */
$mapaPeso = $ehBug ? $sev : $prio;

/* Categorias indexadas do mesmo jeito. O que não estiver no catálogo aparece cru e em caixa
   alta — não pode sumir da linha só porque uma build antiga gravou uma área que já não existe. */
$mapaGrupo = [];
foreach ($grupos as $g) {
    $mapaGrupo[(string)$g['valor']] = (string)$g['rotulo'];
}

$agora = time();

/* =========================================================================================
   LINKS — todo link desta tela sai do recorte atual, só trocando o que ele muda
   ========================================================================================= */

/* Parâmetros que descrevem o recorte de agora. O que é padrão (ordem `recentes`, página 1)
   fica FORA: URL cheia de parâmetro no valor padrão é impossível de comparar quando algo
   dá errado, e o servidor já sabe o padrão. */
$recorte = ['aba' => $aba];
foreach (['q', 'status', 'grupo', 'peso'] as $k) {
    if (($filtros[$k] ?? '') !== '') {
        $recorte[$k] = (string)$filtros[$k];
    }
}
if (($filtros['ord'] ?? 'recentes') !== 'recentes') {
    $recorte['ord'] = (string)$filtros['ord'];
}
if ((int)($filtros['pag'] ?? 1) > 1) {
    $recorte['pag'] = (string)$filtros['pag'];
}
/* O relatório aberto acompanha paginação e ordenação: trocar a ordem da lista não é motivo
   para fechar na cara do operador o texto que ele estava lendo. Quem fecha é o X, e quem
   troca o CONJUNTO (busca, filtro, aba) — aí o item aberto pode nem pertencer mais à lista. */
if ($detalhe !== null) {
    $recorte['ver'] = (string)$detalhe['id'];
}

/** Link para o mesmo recorte com as trocas de $over; valor '' remove o parâmetro. */
$linkPara = static function (array $over) use ($recorte): string {
    $q = $over + $recorte;
    $q = array_filter($q, static fn($v): bool => (string)$v !== '');
    ksort($q);
    return url('admin/relatorios', $q);
};

/** Link que troca um filtro e VOLTA para a página 1 — mudar o filtro e cair na página 7 vazia
 *  é o defeito clássico de lista filtrada. */
$linkFiltro = static fn(array $over): string => $linkPara($over + ['pag' => '', 'ver' => '']);

$temFiltro = ($filtros['q'] ?? '') !== '' || ($filtros['status'] ?? '') !== ''
          || ($filtros['grupo'] ?? '') !== '' || ($filtros['peso'] ?? '') !== '';

/* =========================================================================================
   FORMATADORES
   ========================================================================================= */

/** "12/09 · 14h32" — data curta para a coluna, ano só quando não é o ano corrente. */
$quando = static function (int $ts) use ($agora): string {
    return date('Y', $ts) === date('Y', $agora) ? date('d/m · H\hi', $ts) : date('d/m/Y', $ts);
};

/** "há 5 min" / "há 3 dias" — a idade do relatório, que é o que decide a ordem de atendimento. */
$desde = static function (int $ts) use ($agora): string {
    $s = max(0, $agora - $ts);
    if ($s < 60)    { return 'agora'; }
    if ($s < 3600)  { return 'há ' . intdiv($s, 60) . ' min'; }
    if ($s < 86400) { return 'há ' . intdiv($s, 3600) . ' h'; }
    $d = intdiv($s, 86400);
    return 'há ' . $d . ($d === 1 ? ' dia' : ' dias');
};

/** Selo de estado: cor E palavra, sempre — cor sozinha não é informação. */
$selo = static function (string $status) use ($estados): string {
    $m = (array)($estados[$status] ?? []);
    return '<span class="adm-badge adm-badge--' . e((string)($m['tom'] ?? 'muted')) . '" title="'
        . e((string)($m['ajuda'] ?? '')) . '">' . e((string)($m['rotulo'] ?? mb_strtoupper($status))) . '</span>';
};

/** Selo de gravidade/prioridade. Sem ponto: numa tabela, dois pontos coloridos por linha
 *  competem entre si e nenhum dos dois é lido. */
$seloPeso = static function (string $valor) use ($mapaPeso): string {
    $m = (array)($mapaPeso[$valor] ?? []);
    return '<span class="adm-badge adm-badge--nodot adm-badge--' . e((string)($m['tom'] ?? 'muted')) . '">'
        . e((string)($m['rotulo'] ?? mb_strtoupper($valor))) . '</span>';
};

?>
<div class="rel">

  <!-- ==================================================================== ABAS -->
  <nav class="rel-tabs" aria-label="Tipo de relatório">
    <?php foreach ($abas as $chave => $meta): ?>
      <?php
        $on   = (string)$chave === $aba;
        /* O número da aba é a FILA daquela tabela, não o total: é ele que faz o operador
           trocar de aba. O total inteiro está no rodapé da lista. */
        $fila = (int)($resumo[(string)$chave === 'bugs' ? 'bugs_abertos' : 'tickets_abertos'] ?? 0);
      ?>
      <?php /* data-adm-recarrega: trocar de aba troca a tabela, os chips, as colunas e o
               painel de detalhe — é tela nova, não um recorte da mesma lista. */ ?>
      <a class="rel-tab<?= $on ? ' is-active' : '' ?>" href="<?= e(url('admin/relatorios', ['aba' => (string)$chave])) ?>"
         data-adm-recarrega <?= $on ? 'aria-current="page"' : '' ?>>
        <?= icon((string)($meta['icone'] ?? 'description'), 'rel-tab__ico') ?>
        <span class="rel-tab__txt"><?= e((string)($meta['label'] ?? $chave)) ?></span>
        <span class="rel-tab__n num<?= $fila > 0 ? ' is-hot' : '' ?>"><?= e(num($fila)) ?></span>
      </a>
    <?php endforeach ?>
  </nav>

  <div class="rel__grid">

    <!-- ================================================================ LISTA -->
    <?php /* Região da lista (App\Core\Fragmento): o pedaço que volta do servidor quando o
             filtro é aplicado sem recarregar. O painel de detalhe ao lado fica de fora. */ ?>
    <!--eh:lista-->
    <section class="adm-card rel-list">

      <!-- filtros: um GET só, para o recorte inteiro caber na URL -->
      <?php /* data-adm-preservar: transplantado para dentro do HTML novo em vez de
               substituído — é onde está o cursor de quem digita. */ ?>
      <form class="adm-toolbar rel-bar" method="get" action="<?= e(url('admin/relatorios')) ?>"
            data-adm-preservar="filtros">
        <input type="hidden" name="aba" value="<?= e($aba) ?>">
        <?php /* O estado filtrado viaja escondido porque quem o troca são os chips abaixo —
                 dois controles para o mesmo filtro na mesma barra confundem mais do que ajudam.
                 O campo vai SEMPRE, mesmo vazio: é nele que a escolha do chip sobrevive ao
                 próximo "Filtrar" (vazio o navegador manda `status=`, que o controller lê
                 como "todos", igual a não mandar nada). */ ?>
        <input type="hidden" name="status" value="<?= e((string)($filtros['status'] ?? '')) ?>">

        <label class="adm-search adm-toolbar__grow">
          <span class="sr-only"><?= e((string)($micro[$ehBug ? 'busca_bugs' : 'busca_suporte'] ?? 'Buscar')) ?></span>
          <?= icon('search') ?>
          <input class="adm-input" type="search" name="q" value="<?= e((string)($filtros['q'] ?? '')) ?>"
                 maxlength="80" placeholder="<?= e((string)($micro[$ehBug ? 'busca_bugs' : 'busca_suporte'] ?? '')) ?>">
        </label>

        <label class="rel-bar__sel">
          <span class="sr-only"><?= e((string)($micro[$ehBug ? 'todas_areas' : 'todas_cats'] ?? '')) ?></span>
          <select class="adm-select" name="grupo">
            <option value=""><?= e((string)($micro[$ehBug ? 'todas_areas' : 'todas_cats'] ?? 'TODAS')) ?></option>
            <?php foreach ($grupos as $g): ?>
              <option value="<?= e((string)$g['valor']) ?>"<?= ($filtros['grupo'] ?? '') === $g['valor'] ? ' selected' : '' ?>>
                <?= e((string)$g['rotulo']) ?>
              </option>
            <?php endforeach ?>
          </select>
        </label>

        <label class="rel-bar__sel">
          <span class="sr-only"><?= e((string)($micro[$ehBug ? 'todas_sev' : 'todas_prio'] ?? '')) ?></span>
          <select class="adm-select" name="peso">
            <option value=""><?= e((string)($micro[$ehBug ? 'todas_sev' : 'todas_prio'] ?? 'TODAS')) ?></option>
            <?php foreach ($pesos as $p): ?>
              <option value="<?= e((string)$p['valor']) ?>"<?= ($filtros['peso'] ?? '') === $p['valor'] ? ' selected' : '' ?>>
                <?= e((string)$p['rotulo']) ?>
              </option>
            <?php endforeach ?>
          </select>
        </label>

        <label class="rel-bar__sel">
          <span class="sr-only">Ordenar</span>
          <select class="adm-select" name="ord">
            <?php foreach ($ordens as $valor => $rotulo): ?>
              <option value="<?= e((string)$valor) ?>"<?= ($filtros['ord'] ?? 'recentes') === $valor ? ' selected' : '' ?>>
                <?= e((string)$rotulo) ?>
              </option>
            <?php endforeach ?>
          </select>
        </label>

        <button class="adm-btn adm-btn--primary adm-btn--sm" type="submit"><?= icon('filter_alt') ?><span>FILTRAR</span></button>
        <?php if ($temFiltro): ?>
          <a class="adm-btn adm-btn--ghost adm-btn--sm" href="<?= e(url('admin/relatorios', ['aba' => $aba])) ?>">
            <?= icon('filter_alt_off') ?><span><?= e((string)($micro['limpar'] ?? 'LIMPAR')) ?></span>
          </a>
        <?php endif ?>
      </form>

      <!-- chips de estado: o filtro mais usado, com a contagem do recorte atual -->
      <div class="rel-chips" role="group" aria-label="Filtrar por estado">
        <?php $totalEstados = array_sum(array_map('intval', $contagens)); ?>
        <a class="rel-chip<?= ($filtros['status'] ?? '') === '' ? ' is-active' : '' ?>"
           href="<?= e($linkFiltro(['status' => ''])) ?>">
          <span><?= e((string)($micro['todos_status'] ?? 'TODOS')) ?></span>
          <b class="num"><?= e(num($totalEstados)) ?></b>
        </a>
        <?php foreach ($estados as $chave => $meta): ?>
          <?php
            $n  = (int)($contagens[(string)$chave] ?? 0);
            $on = ($filtros['status'] ?? '') === (string)$chave;
            /* Estado com zero no recorte atual continua VISÍVEL e clicável, só apagado: some
               a linha e o operador perde a única pista de que o estado existe — e de que a
               fila daquele estado está zerada, que é uma informação, não um vazio. */
          ?>
          <a class="rel-chip rel-chip--<?= e((string)($meta['tom'] ?? 'muted')) ?><?= $on ? ' is-active' : '' ?><?= $n === 0 ? ' is-zero' : '' ?>"
             href="<?= e($linkFiltro(['status' => (string)$chave])) ?>" title="<?= e((string)($meta['ajuda'] ?? '')) ?>">
            <span><?= e((string)($meta['rotulo'] ?? $chave)) ?></span>
            <b class="num"><?= e(num($n)) ?></b>
          </a>
        <?php endforeach ?>
      </div>

      <div class="adm-card__body adm-card__body--flush rel-list__body">
        <?php if ($linhas === []): ?>
          <div class="adm-empty">
            <?= icon($temFiltro ? 'search_off' : ($ehBug ? 'bug_report' : 'support_agent')) ?>
            <p class="adm-empty__txt"><?= e((string)($vazios[$temFiltro ? 'filtro' : $aba] ?? '')) ?></p>
            <?php if ($temFiltro): ?>
              <a class="adm-btn adm-btn--ghost adm-btn--sm" href="<?= e(url('admin/relatorios', ['aba' => $aba])) ?>">
                <?= icon('filter_alt_off') ?><span><?= e((string)($micro['limpar'] ?? 'LIMPAR')) ?></span>
              </a>
            <?php endif ?>
          </div>
        <?php else: ?>
          <div class="adm-tablewrap">
            <table class="adm-table rel-table">
              <thead>
                <tr>
                  <th><?= $ehBug ? 'CÓDIGO' : 'PROTOCOLO' ?></th>
                  <th><?= $ehBug ? 'RELATÓRIO' : 'CHAMADO' ?></th>
                  <th><?= $ehBug ? 'GRAVIDADE' : 'PRIORIDADE' ?></th>
                  <th>ESTADO</th>
                  <th class="tr">ABERTO</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($linhas as $l): ?>
                  <?php $aberto = $detalhe !== null && (int)$detalhe['id'] === (int)$l['id']; ?>
                  <tr class="rel-row<?= $aberto ? ' is-open' : '' ?>">
                    <td class="rel-row__code">
                      <a class="rel-row__link" href="<?= e($linkPara(['ver' => (string)$l['id'], 'confirmar' => ''])) ?>"
                         title="<?= e((string)($micro['abrir'] ?? 'Abrir')) ?>">
                        <span class="num"><?= e((string)$l['codigo']) ?></span>
                      </a>
                    </td>
                    <td class="rel-row__what">
                      <?php /* Título e jogador na MESMA célula: são a identidade da linha e,
                               em colunas separadas, o título perdia a largura de que precisa
                               e saía com reticências em qualquer notebook. */ ?>
                      <?php $rotuloGrupo = $mapaGrupo[(string)$l['grupo']] ?? mb_strtoupper((string)$l['grupo']); ?>
                      <strong title="<?= e((string)$l['titulo']) ?>"><?= e((string)$l['titulo']) ?></strong>
                      <?php
                        /* A linha de apoio é só JOGADOR · CATEGORIA. O marcador de logs saiu
                           daqui: com ele ("· LOGS ANEXADOS") a linha pedia 322px numa célula
                           de 281 e era cortada em três dos quatro chamados — e ele não decide
                           nada na triagem; quem precisa saber abre o relatório, onde a
                           informação está escrita por extenso.
                           A frase inteira vai no `title` de qualquer forma: nome de jogador
                           não tem tamanho máximo, e a elipse não pode ser o fim da linha. */
                        $apoio = ((string)$l['jogador'] !== '' ? (string)$l['jogador'] : (string)($micro['conta_removida'] ?? ''))
                               . ' · ' . $rotuloGrupo;
                      ?>
                      <small title="<?= e($apoio) ?>">
                        <?php if ((string)$l['jogador'] !== ''): ?>
                          <?= icon('person', 'rel-row__ico') ?><?= e((string)$l['jogador']) ?>
                        <?php else: ?>
                          <?= icon('person_off', 'rel-row__ico') ?><?= e((string)($micro['conta_removida'] ?? 'conta removida')) ?>
                        <?php endif ?>
                        <?php /* A CATEGORIA mora aqui, e não numa coluna própria: medida em
                                 tela, uma coluna de categoria custava ~170px (a mais larga é
                                 "COMPRAS E PAGAMENTOS") e era exatamente o que faltava para o
                                 TÍTULO caber inteiro — três dos oito relatórios saíam com
                                 reticências em 1600×900, e mais em 1366×768. Categoria se
                                 filtra pelo seletor e se lê aqui; título só se lê. */ ?>
                        <span class="rel-row__grupo">· <?= e($rotuloGrupo) ?></span>
                      </small>
                    </td>
                    <td><?= $seloPeso((string)$l['peso']) ?></td>
                    <td><?= $selo((string)$l['status']) ?></td>
                    <td class="tr num" title="<?= e($quando((int)$l['criado'])) ?>"><?= e($desde((int)$l['criado'])) ?></td>
                  </tr>
                <?php endforeach ?>
              </tbody>
            </table>
          </div>
        <?php endif ?>
      </div>

      <?php if ((int)($lista['total'] ?? 0) > 0): ?>
        <div class="adm-card__foot rel-foot">
          <nav class="adm-pager" aria-label="Páginas">
            <span class="adm-pager__info" data-adm-total>
              <?= e(sprintf(
                    (string)($micro['pager'] ?? '%s–%s de %s'),
                    num((int)$lista['de']), num((int)$lista['ate']), num((int)$lista['total'])
              )) ?>
            </span>
            <?php
              $pag  = (int)$lista['pagina'];
              $pags = (int)$lista['paginas'];
            ?>
            <?php if ($pags > 1): ?>
              <a class="adm-pager__btn<?= $pag <= 1 ? ' is-disabled' : '' ?>"
                 href="<?= e($linkPara(['pag' => (string)max(1, $pag - 1)])) ?>" aria-label="Página anterior"><?= icon('chevron_left') ?></a>
              <?php
                /* Janela curta em volta da página atual: com trinta páginas, trinta botões
                   empurrariam o rodapé para duas linhas e nenhum deles seria alvo fácil. */
                $ini = max(1, min($pag - 2, $pags - 4));
                $fim = min($pags, max($pag + 2, 5));
              ?>
              <?php if ($ini > 1): ?>
                <a class="adm-pager__btn" href="<?= e($linkPara(['pag' => '1'])) ?>">1</a>
                <?php if ($ini > 2): ?><span class="adm-pager__gap">…</span><?php endif ?>
              <?php endif ?>
              <?php for ($p = $ini; $p <= $fim; $p++): ?>
                <a class="adm-pager__btn<?= $p === $pag ? ' is-active' : '' ?>"
                   href="<?= e($linkPara(['pag' => (string)$p])) ?>"<?= $p === $pag ? ' aria-current="page"' : '' ?>><?= e((string)$p) ?></a>
              <?php endfor ?>
              <?php if ($fim < $pags): ?>
                <?php if ($fim < $pags - 1): ?><span class="adm-pager__gap">…</span><?php endif ?>
                <a class="adm-pager__btn" href="<?= e($linkPara(['pag' => (string)$pags])) ?>"><?= e((string)$pags) ?></a>
              <?php endif ?>
              <a class="adm-pager__btn<?= $pag >= $pags ? ' is-disabled' : '' ?>"
                 href="<?= e($linkPara(['pag' => (string)min($pags, $pag + 1)])) ?>" aria-label="Próxima página"><?= icon('chevron_right') ?></a>
            <?php endif ?>
          </nav>
        </div>
      <?php endif ?>
    </section>
    <!--/eh:lista-->

    <!-- =============================================================== DETALHE -->
    <aside class="adm-card rel-detail">
      <?php if ($detalhe === null): ?>
        <div class="adm-empty rel-detail__none">
          <?= icon('article') ?>
          <p class="adm-empty__txt"><?= e((string)($vazios['detalhe'] ?? '')) ?></p>
        </div>
      <?php else: ?>
        <?php
          $st        = (string)$detalhe['status'];
          $encerrado = (array)($estados[$st] ?? []);
          $podeApagar= !empty($encerrado['encerra']);
          $confirmaEste = $confirmar === (int)$detalhe['id'];
          /* O erro só marca o campo quando é DESTE formulário: um "não encontrado" de uma
             exclusão anterior não pode pintar de vermelho o seletor de estado. */
          $erroEstado = in_array($erro, ['status_invalido', 'status_igual'], true) ? $erro : '';
        ?>
        <div class="adm-card__head rel-detail__head">
          <h2 class="adm-card__title">
            <span class="num"><?= e((string)$detalhe['codigo']) ?></span>
            <small><?= e($mapaGrupo[(string)$detalhe['grupo']] ?? mb_strtoupper((string)$detalhe['grupo'])) ?></small>
          </h2>
          <div class="adm-card__tools">
            <?= $selo($st) ?>
            <a class="adm-btn adm-btn--ghost adm-btn--sm adm-btn--icon" href="<?= e($linkPara(['ver' => '', 'confirmar' => ''])) ?>"
               aria-label="Fechar detalhe"><?= icon('close') ?></a>
          </div>
        </div>

        <div class="adm-card__body rel-detail__body scroll-y">
          <h3 class="rel-detail__title"><?= e((string)$detalhe['titulo']) ?></h3>

          <dl class="adm-dl rel-detail__meta">
            <dt><?= e((string)($micro['jogador'] ?? 'JOGADOR')) ?></dt>
            <dd>
              <?php if ((string)$detalhe['jogador'] !== ''): ?>
                <?= e((string)$detalhe['jogador']) ?><?php if ((string)$detalhe['conta'] !== '' && $detalhe['conta'] !== $detalhe['jogador']): ?>
                  <small class="adm-meta">(<?= e((string)$detalhe['conta']) ?>)</small>
                <?php endif ?>
              <?php else: ?>
                <span class="adm-meta"><?= e((string)($micro['conta_removida'] ?? '')) ?></span>
              <?php endif ?>
            </dd>

            <dt><?= $ehBug ? 'GRAVIDADE' : 'PRIORIDADE' ?></dt>
            <dd><?= $seloPeso((string)$detalhe['peso']) ?></dd>

            <dt><?= e((string)($micro['aberto_em'] ?? 'ABERTO EM')) ?></dt>
            <dd class="num"><?= e($quando((int)$detalhe['criado'])) ?> <small class="adm-meta"><?= e($desde((int)$detalhe['criado'])) ?></small></dd>

            <?php if ($ehBug): ?>
              <dt><?= e((string)($micro['encerrado_em'] ?? 'ENCERRADO EM')) ?></dt>
              <dd class="num">
                <?php if (!empty($detalhe['encerrado'])): ?>
                  <?= e($quando((int)$detalhe['encerrado'])) ?>
                <?php else: ?>
                  <span class="adm-meta">— <?= e((string)($micro['em_aberto'] ?? '')) ?></span>
                <?php endif ?>
              </dd>
            <?php else: ?>
              <dt><?= e((string)($micro['atualizado_em'] ?? 'ATUALIZADO EM')) ?></dt>
              <dd class="num"><?= e($quando((int)($detalhe['atualizado'] ?? $detalhe['criado']))) ?></dd>
            <?php endif ?>

            <dt>LOGS</dt>
            <dd><?= e((string)($micro[$detalhe['logs'] ? 'logs_on' : 'logs_off'] ?? '')) ?></dd>
          </dl>

          <?php /* Texto do jogador: `pre-wrap` preserva as quebras que ele digitou (os passos
                   são numerados linha a linha) e o e() garante que nada ali vire marcação. */ ?>
          <h4 class="rel-detail__lbl"><?= e((string)($micro[$ehBug ? 'descricao' : 'mensagem'] ?? 'DESCRIÇÃO')) ?></h4>
          <p class="rel-detail__txt<?= (string)($detalhe['descricao'] ?? '') === '' ? ' is-void' : '' ?>">
            <?= e((string)($detalhe['descricao'] ?? '') !== '' ? (string)$detalhe['descricao'] : (string)($vazios['sem_texto'] ?? '')) ?>
          </p>

          <?php if ($ehBug): ?>
            <h4 class="rel-detail__lbl"><?= e((string)($micro['passos'] ?? 'PASSOS')) ?></h4>
            <p class="rel-detail__txt<?= (string)($detalhe['passos'] ?? '') === '' ? ' is-void' : '' ?>">
              <?= e((string)($detalhe['passos'] ?? '') !== '' ? (string)$detalhe['passos'] : (string)($vazios['sem_texto'] ?? '')) ?>
            </p>
          <?php endif ?>
        </div>

        <div class="adm-card__foot rel-detail__foot">
          <?php if ($confirmaEste): ?>
            <?php /* Segunda etapa da exclusão: a pergunta é do SERVIDOR e nomeia o que some. */ ?>
            <form class="rel-danger" method="post" action="<?= e(url('admin/relatorios/excluir')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="aba" value="<?= e($aba) ?>">
              <input type="hidden" name="id" value="<?= e((string)$detalhe['id']) ?>">
              <?php foreach (['q', 'status', 'grupo', 'peso', 'ord', 'pag'] as $k): ?>
                <input type="hidden" name="<?= e($k) ?>" value="<?= e((string)($filtros[$k] ?? '')) ?>">
              <?php endforeach ?>
              <p class="rel-danger__ask">
                <?= icon('warning') ?>
                <span><?= e(sprintf((string)($micro['excluir_confirma'] ?? 'Excluir %s?'), (string)$detalhe['codigo'])) ?></span>
              </p>
              <p class="rel-danger__note"><?= e((string)($micro['excluir_ajuda'] ?? '')) ?></p>
              <div class="adm-btns">
                <button class="adm-btn adm-btn--danger adm-btn--sm" type="submit">
                  <?= icon('delete_forever') ?><span><?= e((string)($micro['excluir'] ?? 'EXCLUIR')) ?></span>
                </button>
                <a class="adm-btn adm-btn--ghost adm-btn--sm" href="<?= e($linkPara(['ver' => (string)$detalhe['id'], 'confirmar' => ''])) ?>">CANCELAR</a>
              </div>
            </form>
          <?php else: ?>
            <form class="rel-move" method="post" action="<?= e(url('admin/relatorios/salvar')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="aba" value="<?= e($aba) ?>">
              <input type="hidden" name="id" value="<?= e((string)$detalhe['id']) ?>">
              <?php foreach (['q', 'status', 'grupo', 'peso', 'ord', 'pag'] as $k): ?>
                <input type="hidden" name="<?= e($k) ?>" value="<?= e((string)($filtros[$k] ?? '')) ?>">
              <?php endforeach ?>

              <fieldset class="rel-move__set<?= $erroEstado !== '' ? ' is-invalid' : '' ?>">
                <legend class="adm-label"><?= e((string)($micro['mudar'] ?? 'MUDAR ESTADO')) ?></legend>
                <?php if ($erroEstado !== ''): ?>
                  <p class="adm-error"><?= icon('error') ?><span><?= e((string)($erros[$erroEstado] ?? '')) ?></span></p>
                <?php endif ?>
                <?php /* Um botão por estado, e não um <select> + APLICAR: são no máximo cinco
                         destinos, cada um com a sua cor, e o caminho fica de um clique só.
                         O estado atual aparece marcado e desabilitado — é o "você está aqui". */ ?>
                <div class="rel-move__opts">
                  <?php foreach ($estados as $chave => $meta): ?>
                    <?php $atual = (string)$chave === $st; ?>
                    <?php /* O campo é `novo`, e não `status`: `status` é o nome do FILTRO da
                             lista, que viaja escondido no mesmo formulário para o POST voltar
                             ao recorte de onde saiu. Dois campos com o mesmo nome no mesmo
                             form fariam o novo estado virar filtro na URL de volta. */ ?>
                    <button class="rel-move__op rel-move__op--<?= e((string)($meta['tom'] ?? 'muted')) ?><?= $atual ? ' is-current' : '' ?>"
                            type="submit" name="novo" value="<?= e((string)$chave) ?>"
                            title="<?= e((string)($meta['ajuda'] ?? '')) ?>"<?= $atual ? ' disabled aria-current="true"' : '' ?>>
                      <?= icon((string)($meta['icone'] ?? 'chevron_right')) ?>
                      <span><?= e((string)($meta['rotulo'] ?? $chave)) ?></span>
                    </button>
                  <?php endforeach ?>
                </div>
              </fieldset>
            </form>

            <?php /* Excluir mora ABAIXO da linha, separado dos estados: é a única ação desta
                     tela que não tem volta, e não pode dividir a grade com os botões que o
                     operador usa dez vezes por dia. */ ?>
            <div class="rel-detail__danger">
              <?php if ($podeApagar): ?>
                <a class="adm-btn adm-btn--danger adm-btn--sm" href="<?= e($linkPara(['ver' => (string)$detalhe['id'], 'confirmar' => (string)$detalhe['id']])) ?>">
                  <?= icon('delete') ?><span><?= e((string)($micro['excluir'] ?? 'EXCLUIR')) ?></span>
                </a>
              <?php else: ?>
                <?php /* Bloqueio explicado no lugar do botão: o operador precisa saber que a
                         exclusão existe e o que falta para ela ser possível. */ ?>
                <span class="adm-btn adm-btn--danger adm-btn--sm is-disabled" aria-disabled="true">
                  <?= icon('delete') ?><span><?= e((string)($micro['excluir'] ?? 'EXCLUIR')) ?></span>
                </span>
                <span class="adm-hint rel-detail__lock">
                  <?= icon('lock') ?><?= e((string)($micro['excluir_travado'] ?? '')) ?>
                </span>
              <?php endif ?>
            </div>
          <?php endif ?>
        </div>
      <?php endif ?>
    </aside>
  </div>
</div>
