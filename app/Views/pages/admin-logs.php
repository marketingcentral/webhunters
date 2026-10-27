<?php declare(strict_types=1);

/**
 * Tela ADMIN · LOGS DO SISTEMA (/admin/logs).
 *
 * Escopo: $page, $player, $nav, $server, $data, $flash, $csrf. Layout: layouts/admin.php.
 *
 * A view NÃO consulta o banco e NÃO conta nada: tudo chega pronto de App\Repo\AuditRepo pelo
 * App\Controllers\Admin\LogsController. Aqui só existe FORMATAÇÃO (data, rótulo de ação,
 * meta em JSON) e a montagem dos endereços de filtro, ordenação e paginação.
 *
 * TUDO FUNCIONA SEM JAVASCRIPT. Filtro é <form method="get">, ordenação é link no cabeçalho
 * da coluna, paginação é link e o detalhe é `?log=<id>`. Três motivos, nesta ordem:
 *   1. auditoria se confere fora do navegador — a tela inteira sai no `curl`;
 *   2. o registro aberto vira ENDEREÇO: copia-se a barra e a outra pessoa vê a mesma linha;
 *   3. a CSP do projeto (app/Core/Security.php) não deixa script sem nonce entrar aqui.
 *
 * REGRA DE ALTURA: a página não rola. Quem rola é a tabela dentro do cartão — o cabeçalho
 * das colunas fica preso no topo (.adm-table thead th é sticky) e a paginação, no rodapé do
 * cartão, nunca sai da vista.
 */

$cromo    = is_array($data['cromo'] ?? null) ? $data['cromo'] : [];
$titulos  = (array)($cromo['titulos'] ?? []);
$rotulos  = (array)($cromo['filtros'] ?? []);
$colunas  = (array)($cromo['colunas'] ?? []);
$dicAcoes = (array)($cromo['acoes'] ?? []);
$papeis   = (array)($cromo['papeis'] ?? []);
$atores   = (array)($cromo['atores'] ?? []);
$txtDet   = (array)($cromo['detalhe'] ?? []);
$txtRes   = (array)($cromo['resumo'] ?? []);
$vazios   = (array)($cromo['vazios'] ?? []);
$micro    = (array)($cromo['micro'] ?? []);

$filtro   = (array)($data['filtro'] ?? []);
$lista    = (array)($data['lista'] ?? []);
$opcoes   = (array)($data['opcoes'] ?? []);
$resumo   = (array)($data['resumo'] ?? []);
$detalhe  = is_array($data['detalhe'] ?? null) ? $data['detalhe'] : null;
$temFiltro= (bool)($data['tem_filtro'] ?? false);

$linhas   = (array)($lista['linhas'] ?? []);
$total    = (int)($lista['total'] ?? 0);
$pagina   = (int)($lista['pagina'] ?? 1);
$paginas  = (int)($lista['paginas'] ?? 1);
$falhou   = (bool)($lista['falha'] ?? false);
$totalGeral = (int)($opcoes['total'] ?? 0);

$agora = time();
$hoje  = date('Y-m-d', $agora);

/* =========================================================================================
   ENDEREÇOS — o estado inteiro da tela mora na query string
   ========================================================================================= */

/**
 * URL desta tela com o filtro atual e as MUDANÇAS pedidas.
 *
 * O que fica vazio sai da URL (e `pag=1` também): endereço de auditoria é copiado e colado
 * em chamado, e um rastro de `&q=&acao=&de=` só atrapalha quem vai lê-lo depois.
 *
 * @param array<string,string|int|null> $muda
 */
$linkCom = static function (array $muda = []) use ($filtro): string {
    $q = [
        'q'    => (string)($filtro['q'] ?? ''),
        'acao' => (string)($filtro['acao'] ?? ''),
        'quem' => (string)($filtro['quem'] ?? ''),
        'de'   => (string)($filtro['de'] ?? ''),
        'ate'  => (string)($filtro['ate'] ?? ''),
        'ord'  => (string)($filtro['ord'] ?? 'quando'),
        'dir'  => (string)($filtro['dir'] ?? 'desc'),
        'pag'  => (int)($filtro['pag'] ?? 1),
        'log'  => (int)($filtro['log'] ?? 0),
    ];
    foreach ($muda as $k => $v) {
        $q[$k] = $v === null ? '' : $v;
    }
    // A ordenação padrão não precisa aparecer escrita.
    if ($q['ord'] === 'quando' && $q['dir'] === 'desc') {
        $q['ord'] = $q['dir'] = '';
    }
    $limpo = [];
    foreach ($q as $k => $v) {
        if ($v === '' || $v === 0 || ($k === 'pag' && (int)$v <= 1)) {
            continue;
        }
        $limpo[$k] = (string)$v;
    }
    return url('admin/logs', $limpo);
};

/** Cabeçalho de coluna ordenável: mesmo campo inverte a direção; campo novo começa em desc. */
$ordLink = static function (string $campo) use ($filtro, $linkCom): string {
    $atualCampo = (string)($filtro['ord'] ?? 'quando');
    $atualDir   = (string)($filtro['dir'] ?? 'desc');
    $dir = $atualCampo === $campo && $atualDir === 'desc' ? 'asc' : 'desc';
    return $linkCom(['ord' => $campo, 'dir' => $dir, 'pag' => 1]);
};

/* =========================================================================================
   FORMATADORES
   ========================================================================================= */

/** Carimbo curto da coluna (com segundos: três falhas no mesmo minuto são três fatos). */
$carimbo = static fn(int $ts): string => date('d/m H:i:s', $ts);

/** Carimbo por extenso, para o `title` e para o detalhe. */
$carimboCheio = static fn(int $ts): string => date('d/m/Y H:i:s', $ts);

/** "há 5 min" / "há 2 h" / "há 3 dias" — a distância até agora. */
$desde = static function (int $ts) use ($agora): string {
    $s = max(0, $agora - $ts);
    if ($s < 60)    { return 'agora mesmo'; }
    if ($s < 3600)  { return 'há ' . intdiv($s, 60) . ' min'; }
    if ($s < 86400) { return 'há ' . intdiv($s, 3600) . ' h'; }
    $d = intdiv($s, 86400);
    return 'há ' . $d . ($d === 1 ? ' dia' : ' dias');
};

/**
 * Verbete da ação. Sem entrada no dicionário (app/Data/admin-logs.php), o nome do banco é
 * mostrado com os sublinhados virando espaço e tom neutro — a tela não batiza ação nenhuma,
 * e o `title` avisa que aquele rótulo é o identificador cru.
 *
 * @return array{label:string,icone:string,tom:string,nota:string,conhecida:bool}
 */
$verbete = static function (string $acao) use ($dicAcoes): array {
    $v = (array)($dicAcoes[$acao] ?? []);
    if ($v !== []) {
        return [
            'label' => (string)($v['label'] ?? $acao),
            'icone' => (string)($v['icone'] ?? 'bolt'),
            'tom'   => (string)($v['tom'] ?? 'muted'),
            'nota'  => (string)($v['nota'] ?? ''),
            'conhecida' => true,
        ];
    }
    return [
        'label' => ucfirst(str_replace('_', ' ', $acao)),
        'icone' => 'help',
        'tom'   => 'muted',
        'nota'  => 'Ação sem verbete na tela: o rótulo é o próprio identificador gravado em admin_audit_log.',
        'conhecida' => false,
    ];
};

/**
 * Quem assinou a linha, nos três estados que o repo distingue.
 *
 * @param array<string,mixed> $quem
 * @return array{nome:string,papel:string,tom:string,title:string}
 */
$autor = static function (array $quem) use ($papeis, $atores): array {
    $tipo  = (string)($quem['tipo'] ?? 'anonimo');
    $id    = (int)($quem['id'] ?? 0);
    $papel = (string)($quem['papel'] ?? '');

    if ($tipo === 'anonimo') {
        return [
            'nome'  => (string)($atores['anonimo'] ?? 'anônimo'),
            'papel' => '',
            'tom'   => 'anonimo',
            'title' => (string)($atores['nota_anonimo'] ?? ''),
        ];
    }
    if ($tipo === 'removido') {
        return [
            'nome'  => (string)($atores['removido'] ?? 'conta removida') . ' #' . $id,
            'papel' => '',
            'tom'   => 'removido',
            'title' => sprintf((string)($atores['nota_removido'] ?? '%s'), (string)$id),
        ];
    }

    $rotulo = (string)($papeis[$papel] ?? $papel);
    // Um jogador comum na trilha administrativa é sempre notícia: ele só aparece aqui por
    // ter batido na porta do painel. O crachá diz isso em texto, não só na cor.
    $nota = $papel === 'player'
        ? (string)($atores['nota_jogador'] ?? '')
        : (string)($atores['nota_papel'] ?? '');

    return [
        'nome'  => (string)($quem['nome'] ?? ''),
        'papel' => $rotulo,
        'tom'   => $papel === 'owner' ? 'owner' : ($papel === 'admin' ? 'admin' : 'player'),
        'title' => '#' . $id . ' · ' . $rotulo . ($nota !== '' ? "\n" . $nota : ''),
    ];
};

/** Alvo da ação: `entity` é o tipo de coisa, `entity_id` é qual. */
$alvo = static function (array $l): string {
    $e = (string)$l['entidade'];
    $i = (string)$l['alvo'];
    return $i === '' ? $e : $e . ' · ' . $i;
};

/** Valor de uma chave do meta em uma linha só; o que não é escalar volta como JSON compacto. */
$metaValor = static function (mixed $v): string {
    if ($v === null)     { return 'null'; }
    if (is_bool($v))     { return $v ? 'true' : 'false'; }
    if (is_scalar($v))   { return (string)$v; }
    return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};

/* Atalhos de período: cada chip só escreve o campo "De". O ativo é o que bate com o filtro
   em uso — inclusive "Tudo", que é o estado sem data nenhuma. */
$periodos = [];
foreach ((array)($cromo['periodos'] ?? []) as $p) {
    $dias = $p['dias'] ?? null;
    $de   = $dias === null ? '' : date('Y-m-d', $agora - (int)$dias * 86400);
    $periodos[] = [
        'label' => (string)$p['label'],
        'href'  => $linkCom(['de' => $de, 'ate' => '', 'pag' => 1, 'log' => 0]),
        'ativo' => (string)($filtro['de'] ?? '') === $de && (string)($filtro['ate'] ?? '') === '',
    ];
}

/* Faixa de páginas desenhada: sempre a primeira, a última e as vizinhas da atual. Sem isto,
   uma trilha com 400 páginas viraria uma tira de números maior que a tabela. */
$faixa = [];
if ($paginas > 1) {
    foreach ([1, $paginas, $pagina - 1, $pagina, $pagina + 1] as $n) {
        if ($n >= 1 && $n <= $paginas) { $faixa[$n] = true; }
    }
    ksort($faixa);
    $faixa = array_keys($faixa);
}

?>
<div class="lg">

  <!-- ==================================================================== TRILHA -->
  <?php /* Região da lista (App\Core\Fragmento): o pedaço que volta do servidor quando o
           filtro é aplicado sem recarregar. A coluna da direita (detalhe e resumo) fica
           de fora — ela responde a `?ver=`, que é outra pergunta. */ ?>
  <!--eh:lista-->
  <section class="adm-card lg-list">

    <div class="adm-card__head">
      <h2 class="adm-card__title">
        <?= e((string)($titulos['lista'] ?? 'Trilha de auditoria')) ?>
        <small data-adm-total><?= e(sprintf((string)($micro['contando'] ?? '%s de %s'), num($total), num($totalGeral))) ?></small>
      </h2>
      <div class="adm-card__tools">
        <?php /* De onde a tela lê, escrito na própria tela: é o mínimo que uma prova de
                 auditoria deve dizer sobre si mesma. */ ?>
        <span class="lg-src" title="<?= e((string)($micro['origem'] ?? '')) ?>">admin_audit_log</span>
        <span class="lg-ro" title="<?= e((string)($micro['somente_leitura'] ?? '')) ?>">
          <?= icon('lock') ?><span>somente leitura</span>
        </span>
      </div>
    </div>

    <!-- --------------------------------------------------------------- filtros -->
    <?php /* GET puro: o recorte fica na URL e pode ser compartilhado. `pag` e `log` NÃO
             viajam no formulário de propósito — filtrar recomeça na primeira página, e o
             registro aberto pode nem existir dentro do novo recorte. */ ?>
    <?php /* data-adm-preservar: transplantado para dentro do HTML novo em vez de
             substituído — é onde está o cursor de quem digita. Os dois campos ocultos de
             ordenação são reconciliados com a URL pelo script, porque quem os troca são os
             links do cabeçalho da tabela. */ ?>
    <form class="lg-filters" id="lg-filtro" method="get" action="<?= e(url('admin/logs')) ?>" role="search"
          data-adm-preservar="filtros">
      <input type="hidden" name="ord" value="<?= e((string)($filtro['ord'] ?? 'quando')) ?>">
      <input type="hidden" name="dir" value="<?= e((string)($filtro['dir'] ?? 'desc')) ?>">

      <div class="adm-field lg-filters__busca">
        <label class="adm-label" for="lg-q"><?= e((string)($rotulos['busca_rotulo'] ?? 'Buscar')) ?></label>
        <span class="adm-search">
          <?= icon('search') ?>
          <input class="adm-input" type="search" id="lg-q" name="q" maxlength="60"
                 value="<?= e((string)($filtro['q'] ?? '')) ?>"
                 placeholder="<?= e((string)($rotulos['busca_dica'] ?? '')) ?>">
        </span>
      </div>

      <div class="adm-field lg-filters__acao">
        <label class="adm-label" for="lg-acao"><?= e((string)($rotulos['acao_rotulo'] ?? 'Ação')) ?></label>
        <select class="adm-select" id="lg-acao" name="acao">
          <option value=""><?= e((string)($rotulos['acao_todas'] ?? 'Todas')) ?></option>
          <?php foreach ((array)($opcoes['acoes'] ?? []) as $op): ?>
            <?php $v = $verbete((string)$op['acao']); ?>
            <option value="<?= e((string)$op['acao']) ?>"<?= (string)($filtro['acao'] ?? '') === (string)$op['acao'] ? ' selected' : '' ?>>
              <?= e($v['label'] . ' (' . num((int)$op['n']) . ')') ?>
            </option>
          <?php endforeach ?>
        </select>
      </div>

      <div class="adm-field lg-filters__quem">
        <label class="adm-label" for="lg-quem"><?= e((string)($rotulos['quem_rotulo'] ?? 'Quem fez')) ?></label>
        <select class="adm-select" id="lg-quem" name="quem">
          <option value=""><?= e((string)($rotulos['quem_todos'] ?? 'Qualquer pessoa')) ?></option>
          <?php foreach ((array)($opcoes['contas'] ?? []) as $c): ?>
            <?php
              /* Conta apagada continua na lista, pelo id: é dela que a auditoria mais
                 precisa, e um filtro que a esconde esconde justamente o caso difícil. */
              $nome = (string)$c['nome'] !== ''
                  ? (string)$c['nome']
                  : (string)($atores['removido'] ?? 'conta removida') . ' #' . (int)$c['id'];
            ?>
            <option value="<?= e((string)$c['id']) ?>"<?= (string)($filtro['quem'] ?? '') === (string)$c['id'] ? ' selected' : '' ?>>
              <?= e($nome . ' (' . num((int)$c['n']) . ')') ?>
            </option>
          <?php endforeach ?>
          <?php if ((int)($opcoes['anonimos'] ?? 0) > 0): ?>
            <option value="anonimo"<?= (string)($filtro['quem'] ?? '') === 'anonimo' ? ' selected' : '' ?>>
              <?= e((string)($rotulos['quem_anonimo'] ?? 'Sem conta identificada') . ' (' . num((int)$opcoes['anonimos']) . ')') ?>
            </option>
          <?php endif ?>
        </select>
      </div>

      <div class="adm-field lg-filters__data">
        <label class="adm-label" for="lg-de"><?= e((string)($rotulos['de_rotulo'] ?? 'De')) ?></label>
        <input class="adm-input" type="date" id="lg-de" name="de" max="<?= e($hoje) ?>"
               value="<?= e((string)($filtro['de'] ?? '')) ?>">
      </div>

      <div class="adm-field lg-filters__data">
        <label class="adm-label" for="lg-ate"><?= e((string)($rotulos['ate_rotulo'] ?? 'Até')) ?></label>
        <input class="adm-input" type="date" id="lg-ate" name="ate" max="<?= e($hoje) ?>"
               value="<?= e((string)($filtro['ate'] ?? '')) ?>">
      </div>

    </form>

    <div class="lg-quick">
      <span class="lg-quick__lbl"><?= e((string)($rotulos['periodo_rotulo'] ?? 'Período')) ?></span>
      <?php foreach ($periodos as $p): ?>
        <a class="lg-chip<?= $p['ativo'] ? ' is-active' : '' ?>" href="<?= e($p['href']) ?>"
           <?= $p['ativo'] ? 'aria-current="true"' : '' ?>><?= e($p['label']) ?></a>
      <?php endforeach ?>
      <?php if (($filtro['de'] ?? '') !== '' || ($filtro['ate'] ?? '') !== ''): ?>
        <span class="lg-quick__janela">
          <?= e(sprintf(
              (string)($micro['de_ate'] ?? '%s até %s'),
              ($filtro['de'] ?? '') !== '' ? date('d/m/Y', (int)strtotime((string)$filtro['de'])) : 'o início',
              ($filtro['ate'] ?? '') !== '' ? date('d/m/Y', (int)strtotime((string)$filtro['ate'])) : 'agora'
          )) ?>
        </span>
      <?php endif ?>
      <?php /* Os dois botões do recorte moram aqui, junto dos atalhos de período — que
               também são filtro. O "Filtrar" continua sendo o submit do formulário de
               cima pelo atributo `form`: assim a fileira dos campos fica com a largura
               inteira e cabe em UMA linha em 1366px. Cada linha economizada no topo do
               cartão é uma linha a mais de trilha visível sem rolar. */ ?>
      <div class="lg-quick__acoes">
        <button class="adm-btn adm-btn--sm adm-btn--primary" type="submit" form="lg-filtro">
          <?= icon('filter_alt') ?><span><?= e((string)($rotulos['aplicar'] ?? 'Filtrar')) ?></span>
        </button>
        <?php if ($temFiltro): ?>
          <a class="adm-btn adm-btn--sm adm-btn--ghost" href="<?= e(url('admin/logs')) ?>">
            <?= icon('filter_alt_off') ?><span><?= e((string)($rotulos['limpar'] ?? 'Limpar filtros')) ?></span>
          </a>
        <?php endif ?>
      </div>
    </div>

    <!-- ----------------------------------------------------------------- tabela -->
    <div class="adm-card__body adm-card__body--flush lg-list__body">
      <?php if ($falhou): ?>
        <?php /* Banco mudo NÃO é trilha vazia: dizer "nada aconteceu" aqui seria a única
                 mentira que uma tela de auditoria não pode contar. */ ?>
        <div class="adm-empty lg-empty">
          <?= icon('database_off') ?>
          <h3 class="adm-empty__title">Trilha indisponível</h3>
          <p class="adm-empty__txt"><?= e((string)($vazios['falha'] ?? '')) ?></p>
        </div>
      <?php elseif ($linhas === []): ?>
        <div class="adm-empty lg-empty">
          <?= icon($temFiltro ? 'filter_alt_off' : 'history_toggle_off') ?>
          <h3 class="adm-empty__title"><?= e($temFiltro ? 'Nada neste recorte' : 'Trilha vazia') ?></h3>
          <p class="adm-empty__txt"><?= e((string)($vazios[$temFiltro ? 'filtro' : 'trilha'] ?? '')) ?></p>
          <?php if ($temFiltro): ?>
            <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/logs')) ?>">
              <?= icon('restart_alt') ?><span><?= e((string)($rotulos['limpar'] ?? 'Limpar filtros')) ?></span>
            </a>
          <?php endif ?>
        </div>
      <?php else: ?>
        <div class="adm-tablewrap lg-wrap">
          <table class="adm-table adm-table--compact lg-table">
            <thead>
              <tr>
                <th class="tr lg-col-id"><?= e((string)($colunas['id'] ?? '#')) ?></th>
                <?php
                  /* Cabeçalho ordenável: a coluna ativa mostra a seta do sentido em uso.
                     Quem não ordena (alvo/resumo/ip) fica como texto, sem link falso. */
                  $ordenaveis = [
                      'quando' => ['classe' => 'lg-col-quando', 'rotulo' => (string)($colunas['quando'] ?? 'Quando')],
                      'acao'   => ['classe' => 'lg-col-acao',   'rotulo' => (string)($colunas['acao'] ?? 'Ação')],
                      'quem'   => ['classe' => 'lg-col-quem',   'rotulo' => (string)($colunas['quem'] ?? 'Quem fez')],
                      'alvo'   => ['classe' => 'lg-col-alvo',   'rotulo' => (string)($colunas['alvo'] ?? 'Alvo')],
                  ];
                ?>
                <?php foreach ($ordenaveis as $campo => $c): ?>
                  <?php $on = (string)($filtro['ord'] ?? 'quando') === $campo; ?>
                  <th class="<?= e($c['classe']) ?><?= $on ? ' is-sorted' : '' ?>">
                    <a class="lg-sort" href="<?= e($ordLink($campo)) ?>"
                       title="<?= e(sprintf((string)($micro['ordenar'] ?? 'Ordenar por %s'), $c['rotulo'])) ?>">
                      <span><?= e($c['rotulo']) ?></span>
                      <?= icon($on ? ((string)($filtro['dir'] ?? 'desc') === 'asc' ? 'arrow_upward' : 'arrow_downward') : 'unfold_more', 'lg-sort__ico') ?>
                    </a>
                  </th>
                <?php endforeach ?>
                <th class="lg-col-resumo"><?= e((string)($colunas['resumo'] ?? 'O que aconteceu')) ?></th>
                <th class="lg-col-ip"><?= e((string)($colunas['ip'] ?? 'IP')) ?></th>
                <th class="lg-col-ver"><span class="lg-sr">Detalhe</span></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($linhas as $l): ?>
                <?php
                  $v   = $verbete((string)$l['acao']);
                  $a   = $autor((array)$l['quem']);
                  $sel = (int)($filtro['log'] ?? 0) === (int)$l['id'];
                  $href = $linkCom(['log' => (int)$l['id']]);
                  $ts  = (int)$l['quando_ts'];
                ?>
                <tr class="lg-row<?= $sel ? ' is-selected' : '' ?>">
                  <td class="tr num lg-col-id"><?= e((string)$l['id']) ?></td>
                  <td class="lg-col-quando">
                    <time class="num" datetime="<?= e(date('c', $ts)) ?>"
                          title="<?= e($carimboCheio($ts) . ' · ' . $desde($ts)) ?>"><?= e($carimbo($ts)) ?></time>
                  </td>
                  <td class="lg-col-acao">
                    <?php /* O rótulo vai dentro de um <span> próprio para poder ser cortado
                             com reticências: a coluna tem largura fixa e `action` é uma
                             coluna de 64 caracteres — uma ação sem verbete pode ser longa. */ ?>
                    <span class="adm-badge adm-badge--<?= e($v['tom']) ?><?= $v['conhecida'] ? '' : ' lg-badge--crua' ?>"
                          title="<?= e((string)$l['acao'] . ($v['nota'] !== '' ? "\n" . $v['nota'] : '')) ?>">
                      <span class="lg-acao__txt"><?= e($v['label']) ?></span>
                    </span>
                  </td>
                  <td class="lg-col-quem">
                    <span class="lg-who lg-who--<?= e($a['tom']) ?>" title="<?= e($a['title']) ?>">
                      <strong><?= e($a['nome']) ?></strong>
                      <?php if ($a['papel'] !== ''): ?><small><?= e($a['papel']) ?></small><?php endif ?>
                    </span>
                  </td>
                  <td class="lg-col-alvo"><span class="lg-alvo" title="<?= e($alvo($l)) ?>"><?= e($alvo($l)) ?></span></td>
                  <?php /* O resumo é a única coluna elástica: ela encolhe com reticências em
                           vez de empurrar a tabela para fora da tela (a frase inteira está
                           no title e no detalhe). */ ?>
                  <td class="lg-col-resumo" title="<?= e((string)$l['resumo']) ?>"><?= e((string)$l['resumo']) ?></td>
                  <td class="lg-col-ip num"><?= $l['ip'] !== '' ? e((string)$l['ip']) : '<span class="lg-void">—</span>' ?></td>
                  <td class="lg-col-ver">
                    <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--ghost" href="<?= e($href) ?>"
                       aria-label="<?= e((string)($micro['ver_detalhe'] ?? 'Ver registro') . ' #' . (string)$l['id']) ?>"
                       title="<?= e((string)($micro['ver_detalhe'] ?? 'Ver registro')) ?>"><?= icon('chevron_right') ?></a>
                  </td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
      <?php endif ?>
    </div>

    <!-- -------------------------------------------------------------- paginação -->
    <?php if (!$falhou && $total > 0): ?>
      <div class="adm-card__foot lg-foot">
        <nav class="adm-pager" aria-label="Paginação da trilha">
          <span class="adm-pager__info">
            <?= e(sprintf((string)($micro['pagina'] ?? 'Página %s de %s'), num($pagina), num($paginas))) ?>
            · <?= e(sprintf((string)($micro['contando'] ?? '%s de %s'), num($total), num($totalGeral))) ?>
          </span>
          <?php if ($paginas > 1): ?>
            <a class="adm-pager__btn<?= $pagina <= 1 ? ' is-disabled' : '' ?>"
               href="<?= e($linkCom(['pag' => max(1, $pagina - 1)])) ?>" aria-label="Página anterior"><?= icon('chevron_left') ?></a>
            <?php $ultimo = 0; ?>
            <?php foreach ($faixa as $n): ?>
              <?php if ($ultimo > 0 && $n > $ultimo + 1): ?><span class="adm-pager__gap">…</span><?php endif ?>
              <a class="adm-pager__btn<?= $n === $pagina ? ' is-active' : '' ?>"
                 href="<?= e($linkCom(['pag' => $n])) ?>"<?= $n === $pagina ? ' aria-current="page"' : '' ?>><?= e((string)$n) ?></a>
              <?php $ultimo = $n; ?>
            <?php endforeach ?>
            <a class="adm-pager__btn<?= $pagina >= $paginas ? ' is-disabled' : '' ?>"
               href="<?= e($linkCom(['pag' => min($paginas, $pagina + 1)])) ?>" aria-label="Próxima página"><?= icon('chevron_right') ?></a>
          <?php endif ?>
        </nav>
      </div>
    <?php endif ?>
  </section>
  <!--/eh:lista-->

  <!-- ================================================================= COLUNA DIREITA -->
  <aside class="lg-side">

    <!-- ---------------------------------------------------------------- detalhe -->
    <section class="adm-card lg-detail" id="detalhe">
      <div class="adm-card__head">
        <h2 class="adm-card__title">
          <?= e((string)($titulos['detalhe'] ?? 'Detalhe do registro')) ?>
          <?php if ($detalhe !== null): ?><small class="num">#<?= e((string)$detalhe['id']) ?></small><?php endif ?>
        </h2>
        <?php if ($detalhe !== null): ?>
          <div class="adm-card__tools">
            <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--ghost" href="<?= e($linkCom(['log' => 0])) ?>"
               title="<?= e((string)($txtDet['fechar'] ?? 'Fechar')) ?>"
               aria-label="<?= e((string)($txtDet['fechar'] ?? 'Fechar')) ?>"><?= icon('close') ?></a>
          </div>
        <?php endif ?>
      </div>

      <div class="adm-card__body lg-detail__body scroll-y">
        <?php if ($detalhe === null): ?>
          <div class="adm-empty lg-empty lg-empty--sm">
            <?= icon('data_object') ?>
            <p class="adm-empty__txt"><?= e((string)($txtDet['vazio'] ?? '')) ?></p>
          </div>
        <?php else: ?>
          <?php
            $v  = $verbete((string)$detalhe['acao']);
            $a  = $autor((array)$detalhe['quem']);
            $ts = (int)$detalhe['quando_ts'];
            $meta = is_array($detalhe['meta'] ?? null) ? $detalhe['meta'] : [];
          ?>
          <p class="lg-detail__acao">
            <span class="adm-badge adm-badge--<?= e($v['tom']) ?>"><?= icon($v['icone']) ?><?= e($v['label']) ?></span>
            <?php /* O identificador cru fica SEMPRE visível: o rótulo em português é
                     conveniência de leitura, o que está gravado no banco é isto. */ ?>
            <code class="lg-code"><?= e((string)$detalhe['acao']) ?></code>
          </p>
          <?php if ($v['nota'] !== ''): ?>
            <p class="lg-detail__nota"><?= e($v['nota']) ?></p>
          <?php endif ?>

          <dl class="adm-dl lg-dl">
            <dt><?= e((string)($txtDet['campo_quando'] ?? 'Quando')) ?></dt>
            <dd><span class="num"><?= e($carimboCheio($ts)) ?></span> <small><?= e($desde($ts)) ?></small></dd>

            <dt><?= e((string)($txtDet['campo_quem'] ?? 'Quem fez')) ?></dt>
            <dd class="lg-who lg-who--<?= e($a['tom']) ?>" title="<?= e($a['title']) ?>">
              <strong><?= e($a['nome']) ?></strong>
              <?php if ($a['papel'] !== ''): ?><small><?= e($a['papel'] . ' · conta #' . (int)$detalhe['quem']['id']) ?></small><?php endif ?>
            </dd>

            <dt><?= e((string)($txtDet['campo_alvo'] ?? 'Alvo')) ?></dt>
            <dd><code class="lg-code"><?= e((string)$detalhe['entidade']) ?></code>
              <?php if ((string)$detalhe['alvo'] !== ''): ?><span class="lg-alvo"><?= e((string)$detalhe['alvo']) ?></span><?php endif ?>
            </dd>

            <dt><?= e((string)($txtDet['campo_ip'] ?? 'IP de origem')) ?></dt>
            <dd class="num"><?= (string)$detalhe['ip'] !== '' ? e((string)$detalhe['ip']) : '<span class="lg-void">—</span>' ?></dd>

            <dt><?= e((string)($txtDet['campo_resumo'] ?? 'Resumo')) ?></dt>
            <dd class="lg-detail__resumo"><?= e((string)$detalhe['resumo']) ?></dd>
          </dl>

          <h3 class="lg-detail__sub"><?= e((string)($txtDet['campo_meta'] ?? 'Meta')) ?></h3>
          <?php if ($meta === []): ?>
            <p class="lg-detail__nota lg-detail__nota--void"><?= e((string)($txtDet['sem_meta'] ?? '')) ?></p>
          <?php else: ?>
            <dl class="adm-dl lg-dl lg-dl--meta">
              <?php foreach ($meta as $chave => $valor): ?>
                <dt><?= e((string)$chave) ?></dt>
                <dd class="lg-meta__val"><?= e($metaValor($valor)) ?></dd>
              <?php endforeach ?>
            </dl>
            <?php /* O JSON como está gravado, sem reordenar e sem traduzir: é a prova.
                     <details> porque ele é longo e não pode empurrar o resto para fora. */ ?>
            <details class="lg-raw">
              <summary><?= icon('code') ?><span><?= e((string)($txtDet['json_bruto'] ?? 'JSON bruto')) ?></span></summary>
              <pre class="lg-raw__pre"><?= e((string)json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
            </details>
          <?php endif ?>
        <?php endif ?>
      </div>
    </section>

    <!-- ----------------------------------------------------------------- resumo -->
    <section class="adm-card lg-resumo">
      <div class="adm-card__head">
        <h2 class="adm-card__title"><?= e((string)($titulos['resumo'] ?? 'Resumo do recorte')) ?></h2>
      </div>
      <div class="adm-card__body lg-resumo__body">
        <?php if ($falhou || $total === 0): ?>
          <p class="lg-detail__nota lg-detail__nota--void">
            <?= e($falhou ? (string)($vazios['falha'] ?? '') : (string)($vazios[$temFiltro ? 'filtro' : 'trilha'] ?? '')) ?>
          </p>
        <?php else: ?>
          <div class="lg-stats">
            <div class="lg-stat">
              <span class="lg-stat__lbl"><?= e((string)($txtRes['total'] ?? 'Registros')) ?></span>
              <span class="lg-stat__val num"><?= e(num($total)) ?></span>
            </div>
            <div class="lg-stat">
              <span class="lg-stat__lbl"><?= e((string)($txtRes['operadores'] ?? 'Contas')) ?></span>
              <span class="lg-stat__val num"><?= e(num((int)($resumo['contas'] ?? 0))) ?></span>
            </div>
          </div>

          <p class="lg-periodo">
            <span class="lg-stat__lbl"><?= e((string)($txtRes['periodo'] ?? 'Período coberto')) ?></span>
            <span class="num">
              <?php
                $pri = (string)($resumo['primeiro'] ?? '');
                $ult = (string)($resumo['ultimo'] ?? '');
              ?>
              <?= $pri !== '' && $ult !== ''
                    ? e(date('d/m/Y H:i', (int)strtotime($pri)) . ' — ' . date('d/m/Y H:i', (int)strtotime($ult)))
                    : e((string)($txtRes['sem_periodo'] ?? '—')) ?>
            </span>
          </p>

          <?php $acoesResumo = (array)($resumo['acoes'] ?? []); ?>
          <?php if ($acoesResumo !== []): ?>
            <h3 class="lg-detail__sub"><?= e((string)($txtRes['acoes'] ?? 'Ações no recorte')) ?></h3>
            <ul class="lg-bars">
              <?php $maior = max(array_map(static fn(array $x): int => (int)$x['n'], $acoesResumo)); ?>
              <?php foreach ($acoesResumo as $x): ?>
                <?php
                  $vv  = $verbete((string)$x['acao']);
                  $pct = $maior > 0 ? (int)round((int)$x['n'] / $maior * 100) : 0;
                  /* A barra compara com a AÇÃO MAIS FREQUENTE do recorte, não com o total:
                     é a leitura que responde "o que mais acontece por aqui". */
                ?>
                <li class="lg-bar lg-bar--<?= e($vv['tom']) ?>">
                  <a class="lg-bar__link" href="<?= e($linkCom(['acao' => (string)$x['acao'], 'pag' => 1, 'log' => 0])) ?>"
                     title="<?= e((string)$x['acao']) ?>">
                    <span class="lg-bar__lbl"><?= e($vv['label']) ?></span>
                    <span class="lg-bar__n num"><?= e(num((int)$x['n'])) ?></span>
                  </a>
                  <span class="lg-bar__track"><span class="lg-bar__fill" style="--v:<?= e((string)$pct) ?>%"></span></span>
                </li>
              <?php endforeach ?>
            </ul>
          <?php endif ?>
        <?php endif ?>
      </div>
    </section>
  </aside>
</div>
