<?php declare(strict_types=1);

/**
 * Tela ADMIN · TRANSAÇÕES (/admin/transacoes).
 *
 * Escopo: $page, $player, $nav, $server, $data, $flash, $csrf. Layout: layouts/admin.php.
 *
 * A view NÃO consulta o banco e NÃO decide recorte: tudo chega pronto de
 * App\Repo\TransactionsRepo por App\Controllers\Admin\TransactionsController. O que existe
 * aqui é geometria (o SVG das barras) e formatação (data curta, número compacto) — coisas
 * de apresentação, que não têm lugar dentro de um repositório.
 *
 * O gráfico é SVG montado no PHP de propósito: a CSP do projeto (app/Core/Security.php)
 * proíbe script de terceiros, então nenhuma biblioteca de gráfico entraria aqui. O desenho
 * ainda ganha com isso — ele existe no HTML, aparece com JavaScript desligado e sai inteiro
 * no `curl`, que é como esta tela é conferida.
 *
 * A REGRA QUE GOVERNA O LAYOUT: moeda de jogo não soma entre si. Nenhum lugar desta tela
 * mostra "total do período" como número único; o total mora no quadro por moeda, o gráfico
 * tem uma faixa por moeda e a tabela carrega a moeda em toda linha.
 */

$cromo    = is_array($data['cromo'] ?? null) ? $data['cromo'] : [];
$titulos  = (array)($cromo['titulos'] ?? []);
$colunas  = (array)($cromo['colunas'] ?? []);
$rotKpi   = (array)($cromo['kpis'] ?? []);
$micro    = (array)($cromo['micro'] ?? []);
$vazios   = (array)($cromo['vazios'] ?? []);
$acoes    = (array)($cromo['acoes'] ?? []);

$filtro   = (array)($data['filtro'] ?? []);
$f        = (array)($filtro['f'] ?? ['de' => '', 'ate' => '', 'moeda' => '', 'busca' => '']);
$periodo  = (string)($filtro['periodo'] ?? '30');
$ord      = (string)($filtro['ord'] ?? 'data');
$dir      = (string)($filtro['dir'] ?? 'desc');
$bruto    = (array)($filtro['bruto'] ?? ['de' => '', 'ate' => '']);
$erros    = (array)($filtro['erros'] ?? []);

$extremos = (array)($data['extremos'] ?? ['n' => 0, 'primeira' => null, 'ultima' => null]);
$moedas   = (array)($data['moedas'] ?? []);
$totais   = (array)($data['totais'] ?? ['transacoes' => 0, 'unidades' => 0, 'jogadores' => 0]);
$porMoeda = (array)($data['moeda_a_moeda'] ?? []);
$serie    = (array)($data['serie'] ?? ['granularidade' => 'dia', 'moedas' => []]);
$linhas   = (array)($data['linhas'] ?? []);
$topItens = (array)($data['itens'] ?? []);
$topJog   = (array)($data['jogadores'] ?? []);
$pag      = (array)($data['paginacao'] ?? ['pagina' => 1, 'paginas' => 1, 'total' => 0, 'primeiro' => 0, 'ultimo' => 0]);

/* Nunca houve compra: a tela inteira vira UMA frase. Quatro KPIs zerados, um gráfico sem
   barra e uma tabela sem linha diriam a mesma coisa quatro vezes, e nenhuma delas diria o
   que o operador precisa saber — quem escreve nesta tabela. */
$semLedger = (int)($extremos['n'] ?? 0) === 0;

/* =========================================================================================
   FORMATADORES
   ========================================================================================= */

/** "04/09/26 00:37" — a data que cabe numa coluna sem quebrar. O ano completo vai no title. */
$dataHora = static function (?string $sql): string {
    $t = $sql !== null ? strtotime($sql) : false;
    return $t === false ? '—' : date('d/m/y H:i', $t);
};
$dataLonga = static function (?string $sql): string {
    $t = $sql !== null ? strtotime($sql) : false;
    return $t === false ? '' : date('d/m/Y \à\s H:i:s', $t);
};

/** Números do eixo do gráfico: curtos, ou a régua come metade da largura útil. */
$compacto = static function (float $v): string {
    if (abs($v) >= 1000000) { return num($v / 1000000, 1) . ' M'; }
    if (abs($v) >= 10000)   { return num($v / 1000, 0) . ' k'; }
    return num($v);
};

/**
 * Passo "redondo" de uma régua com ~$div divisões: 1, 2, 2,5 ou 5 vezes uma potência de dez.
 * Sem isso o eixo sai com marcas em 16.833 — número que ninguém lê como referência.
 */
$passoBonito = static function (int $max, int $div): int {
    if ($max <= 0) {
        return 1;
    }
    $alvo = $max / max(1, $div);
    $mag  = 10 ** max(0, (int)floor(log10(max(1e-9, $alvo))));
    foreach ([1, 2, 2.5, 5, 10] as $m) {
        $c = $mag * $m;
        if ($alvo <= $c) {
            return (int)max(1, round($c));
        }
    }
    return (int)max(1, $mag * 10);
};

/**
 * Uma faixa de barras — um balde por dia (ou por semana), na cor da moeda.
 *
 * O viewBox tem a proporção da caixa em que o SVG entra: com um viewBox mais alto que a
 * caixa, o preserveAspectRatio padrão encolhe o desenho e o centraliza, deixando faixas
 * mortas nas laterais em vez de o gráfico encostar nas bordas.
 *
 * @param list<array{rotulo:string,titulo:string,valor:int,n:int}> $pontos
 */
$grafico = static function (array $pontos, int $max, string $tom, string $sigla, string $rotulo)
    use ($passoBonito, $compacto): string {
    if ($pontos === []) {
        return '';
    }

    $W = 720; $H = 176;
    $ml = 58; $mr = 8; $mt = 10; $mb = 24;
    $iw = $W - $ml - $mr;
    $ih = $H - $mt - $mb;

    // O topo fecha no máximo REAL arredondado para cima ao passo; a régua sozinha subiria a
    // 60.000 para uma barra de 48.500 e deixaria um quinto do desenho vazio.
    $passo = $passoBonito($max, 4);
    $topo  = max($passo, (int)ceil(max(1, $max) / $passo) * $passo);
    // 6% de respiro: a barra mais alta ocupa ~94% da altura útil sem tocar a moldura.
    $dominio = $topo * 1.06;

    $n    = count($pontos);
    $cel  = $iw / $n;
    $larg = max(1.5, $cel * 0.72);

    $svg = '<svg class="txn-chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="'
         . e('Movimento diário em ' . $rotulo) . '">';

    for ($v = 0; $v <= $topo; $v += $passo) {
        $py = round($mt + $ih - $ih * ($v / $dominio), 2);
        $svg .= '<line class="txn-chart__grid" x1="' . $ml . '" y1="' . $py . '" x2="' . ($W - $mr) . '" y2="' . $py . '"/>'
              . '<text class="txn-chart__tick" x="' . ($ml - 6) . '" y="' . ($py + 4) . '" text-anchor="end">'
              . e($compacto((float)$v)) . '</text>';
    }

    $salto = (int)max(1, ceil($n / 7));
    foreach ($pontos as $i => $b) {
        $altura = $ih * min(1.0, (int)$b['valor'] / $dominio);
        $bx = round($ml + $cel * $i + ($cel - $larg) / 2, 2);
        $by = round($mt + $ih - $altura, 2);

        // Balde zerado continua existindo como marca de 1px na linha de base: é o que
        // diferencia "não houve venda neste dia" de "este dia não está no período".
        $classe = 'txn-chart__bar txn-chart__bar--' . $tom . ((int)$b['valor'] === 0 ? ' is-zero' : '');
        $legenda = $b['titulo'] . ' · ' . ((int)$b['valor'] === 0
            ? 'sem movimento'
            : num((int)$b['valor']) . ' ' . $sigla . ' em ' . num((int)$b['n']) . ' transaç' . ((int)$b['n'] === 1 ? 'ão' : 'ões'));

        $svg .= '<rect class="' . e($classe) . '" x="' . $bx . '" y="' . $by . '" width="' . round($larg, 2)
              . '" height="' . round(max(1.0, $altura), 2) . '" rx="1.5"><title>' . e($legenda) . '</title></rect>';

        if ($i % $salto === 0 || $i === $n - 1) {
            $svg .= '<text class="txn-chart__tick" x="' . round($bx + $larg / 2, 2) . '" y="' . ($H - 6)
                  . '" text-anchor="middle">' . e($b['rotulo']) . '</text>';
        }
    }

    return $svg . '</svg>';
};

/* =========================================================================================
   LIGAÇÕES — toda navegação da tela preserva o recorte inteiro
   ========================================================================================= */

/* O período custom viaja pelas datas EFETIVAS: um link de ordenação que perdesse `de`/`ate`
   devolveria a tabela ordenada de um período diferente do que está no gráfico acima. */
$base = [
    'periodo' => $periodo,
    'moeda'   => (string)$f['moeda'],
    'q'       => (string)$f['busca'],
    'ord'     => $ord,
    'dir'     => $dir,
];
if ($periodo === 'custom') {
    $base['de']  = (string)$f['de'];
    $base['ate'] = (string)$f['ate'];
}

$link = static function (array $over = []) use ($base): string {
    $q = array_replace($base, $over);
    $q = array_filter($q, static fn($v): bool => $v !== '' && $v !== null);
    return url('admin/transacoes', $q);
};

/** Cabeçalho clicável: mesma coluna inverte a direção, coluna nova começa decrescente. */
$cab = static function (string $chave, string $rotulo, string $classe = '')
    use ($ord, $dir, $link, $micro): string {
    $ativo = $ord === $chave;
    $prox  = $ativo && $dir === 'desc' ? 'asc' : 'desc';
    $seta  = $ativo ? ($dir === 'desc' ? 'arrow_downward' : 'arrow_upward') : 'unfold_more';

    return '<th class="' . e(trim($classe)) . ($ativo ? ' is-sorted' : '') . '"'
         . ($ativo ? ' aria-sort="' . ($dir === 'desc' ? 'descending' : 'ascending') . '"' : '') . '>'
         . '<a class="txn-sort" href="' . e($link(['ord' => $chave, 'dir' => $prox, 'p' => null])) . '"'
         . ' title="' . e((string)($micro['ordenar'] ?? 'Ordenar')) . '">'
         . e($rotulo) . icon($seta, 'txn-sort__ic') . '</a></th>';
};

$granularidade = (string)($serie['granularidade'] ?? 'dia');
?>

<div class="txn">

  <?php if ($semLedger): ?>

    <?php /* Livro-caixa vazio. Não é erro, não é filtro: é o jogo que ainda não vendeu nada. */ ?>
    <section class="adm-card">
      <div class="adm-empty">
        <?= icon('receipt_long') ?>
        <h2 class="adm-empty__title"><?= e((string)($titulos['lista'] ?? 'Transações')) ?></h2>
        <p class="adm-empty__txt"><?= e((string)($vazios['ledger'] ?? '')) ?></p>
      </div>
    </section>

  <?php else: ?>

    <?php /* Região da lista (App\Core\Fragmento). Aqui ela é GRANDE de propósito: os KPIs,
             o gráfico e os quadros por moeda são leituras DO RECORTE FILTRADO, não do
             livro-caixa inteiro. Trocar só a tabela deixaria "1.240 transações" em cima de
             uma lista de sete linhas. */ ?>
    <!--eh:lista-->

    <!-- ------------------------------------------------------------------ filtros -->
    <section class="adm-card txn-filtros">
      <?php /* data-adm-preservar: transplantado para dentro do HTML novo em vez de
               substituído — é onde está o cursor de quem digita. */ ?>
      <form class="adm-toolbar txn-form" method="get" action="<?= e(url('admin/transacoes')) ?>"
            data-adm-preservar="filtros">
        <?php /* Ordenação viaja escondida: aplicar um filtro não pode desfazer a ordem escolhida. */ ?>
        <input type="hidden" name="ord" value="<?= e($ord) ?>">
        <input type="hidden" name="dir" value="<?= e($dir) ?>">

        <div class="adm-toolbar__grow">
          <label class="adm-search">
            <?= icon('search') ?>
            <span class="sr-only">Buscar</span>
            <input class="adm-input" type="search" name="q" value="<?= e((string)$f['busca']) ?>"
                   placeholder="Jogador, item ou nº da transação" maxlength="60">
          </label>
        </div>

        <div class="txn-field<?= isset($erros['de']) ? ' is-invalid' : '' ?>">
          <label class="adm-label" for="txn-de">De</label>
          <input class="adm-input" type="date" id="txn-de" name="de"
                 value="<?= e($bruto['de'] !== '' ? (string)$bruto['de'] : ($periodo === 'custom' ? (string)$f['de'] : '')) ?>"
                 <?= isset($erros['de']) ? 'aria-invalid="true" aria-describedby="txn-de-erro"' : '' ?>>
          <?php if (isset($erros['de'])): ?>
            <span class="adm-error" id="txn-de-erro"><?= icon('error') ?><?= e((string)$erros['de']) ?></span>
          <?php endif ?>
        </div>

        <div class="txn-field<?= isset($erros['ate']) ? ' is-invalid' : '' ?>">
          <label class="adm-label" for="txn-ate">Até</label>
          <input class="adm-input" type="date" id="txn-ate" name="ate"
                 value="<?= e($bruto['ate'] !== '' ? (string)$bruto['ate'] : ($periodo === 'custom' ? (string)$f['ate'] : '')) ?>"
                 <?= isset($erros['ate']) ? 'aria-invalid="true" aria-describedby="txn-ate-erro"' : '' ?>>
          <?php if (isset($erros['ate'])): ?>
            <span class="adm-error" id="txn-ate-erro"><?= icon('error') ?><?= e((string)$erros['ate']) ?></span>
          <?php endif ?>
        </div>

        <div class="txn-field<?= isset($erros['moeda']) ? ' is-invalid' : '' ?>">
          <label class="adm-label" for="txn-moeda">Moeda</label>
          <?php /* Só as moedas que EXISTEM no livro-caixa: oferecer um filtro que não pode
                   devolver nada é armadilha, não opção. */ ?>
          <select class="adm-select" id="txn-moeda" name="moeda">
            <option value="">Todas</option>
            <?php foreach ($moedas as $m): ?>
              <option value="<?= e((string)$m) ?>"<?= (string)$f['moeda'] === (string)$m ? ' selected' : '' ?>>
                <?= e(App\Repo\TransactionsRepo::rotuloMoeda((string)$m)) ?>
              </option>
            <?php endforeach ?>
          </select>
          <?php if (isset($erros['moeda'])): ?>
            <span class="adm-error"><?= icon('error') ?><?= e((string)$erros['moeda']) ?></span>
          <?php endif ?>
        </div>

        <button class="adm-btn adm-btn--primary" type="submit">
          <?= icon('filter_alt') ?><?= e((string)($acoes['aplicar'] ?? 'Aplicar')) ?>
        </button>
        <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/transacoes')) ?>">
          <?= e((string)($acoes['limpar'] ?? 'Limpar')) ?>
        </a>
      </form>

      <div class="txn-bar">
        <nav class="txn-seg" aria-label="Período">
          <?php foreach ((array)($cromo['periodos'] ?? []) as $per): ?>
            <?php $on = $periodo === (string)$per['chave']; ?>
            <?php /* O preset NÃO carrega de/ate: clicar em "7 dias" com datas no formulário
                     precisa realmente trocar a janela, não misturar as duas. */ ?>
            <a class="txn-seg__op<?= $on ? ' is-active' : '' ?>"
               href="<?= e(url('admin/transacoes', array_filter([
                        'periodo' => (string)$per['chave'],
                        'moeda'   => (string)$f['moeda'],
                        'q'       => (string)$f['busca'],
                        'ord'     => $ord,
                        'dir'     => $dir,
                      ], static fn($v): bool => $v !== ''))) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
              <?= e((string)$per['label']) ?>
            </a>
          <?php endforeach ?>
          <?php if ($periodo === 'custom'): ?>
            <span class="txn-seg__op is-active" aria-current="page">Personalizado</span>
          <?php endif ?>
        </nav>

        <p class="txn-bar__janela">
          <?= icon('event') ?>
          <span><?= e($dataHora($f['de'] . ' 00:00:00')) ?> — <?= e($dataHora($f['ate'] . ' 23:59:59')) ?></span>
        </p>

        <?php if (isset($erros['de']) || isset($erros['ate'])): ?>
          <?php /* O recorte pedido foi recusado e a tela está mostrando OUTRO. Sem esta frase,
                   o erro ficaria só no campo lá em cima e o operador leria os números abaixo
                   como se fossem do período que ele digitou. */ ?>
          <span class="adm-badge adm-badge--warn txn-bar__recusa">
            <?= e((string)($micro['periodo_recusado'] ?? '')) ?>
          </span>
        <?php endif ?>

        <p class="txn-bar__aviso" title="<?= e((string)($micro['somavel'] ?? '')) ?>">
          <?= icon('info') ?><span><?= e((string)($micro['moeda_de_jogo'] ?? '')) ?></span>
        </p>

        <a class="adm-btn adm-btn--sm adm-btn--ghost txn-bar__csv"
           href="<?= e($link(['export' => 'csv'])) ?>"
           title="<?= e((string)($micro['export'] ?? '')) ?>">
          <?= icon('download') ?><?= e((string)($acoes['csv'] ?? 'Exportar CSV')) ?>
        </a>
      </div>
    </section>

    <!-- --------------------------------------------------------------------- KPIs -->
    <div class="adm-grid adm-grid--4 txn-kpis">
      <article class="adm-kpi adm-kpi--cyan">
        <span class="adm-kpi__ico"><?= icon('receipt_long') ?></span>
        <span class="adm-kpi__txt">
          <span class="adm-kpi__label"><?= e((string)($rotKpi['transacoes'] ?? 'Transações')) ?></span>
          <span class="adm-kpi__value num"><?= e(num((int)$totais['transacoes'])) ?></span>
        </span>
      </article>

      <article class="adm-kpi adm-kpi--purple">
        <span class="adm-kpi__ico"><?= icon('inventory_2') ?></span>
        <span class="adm-kpi__txt">
          <span class="adm-kpi__label"><?= e((string)($rotKpi['unidades'] ?? 'Unidades vendidas')) ?></span>
          <span class="adm-kpi__value num"><?= e(num((int)$totais['unidades'])) ?></span>
        </span>
      </article>

      <article class="adm-kpi">
        <span class="adm-kpi__ico"><?= icon('group') ?></span>
        <span class="adm-kpi__txt">
          <span class="adm-kpi__label"><?= e((string)($rotKpi['jogadores'] ?? 'Jogadores compradores')) ?></span>
          <span class="adm-kpi__value num"><?= e(num((int)$totais['jogadores'])) ?></span>
        </span>
      </article>

      <?php
        /* O quarto cartão é o ticket médio — e ele SÓ existe quando há uma moeda em cena.
           Com duas ou três, a média de valores incomparáveis não é um número: o cartão diz
           isso e manda para o quadro que tem a conta certa. */
        $umaMoeda = count($porMoeda) === 1 ? $porMoeda[0] : null;
      ?>
      <article class="adm-kpi adm-kpi--gold">
        <span class="adm-kpi__ico"><?= icon('sell') ?></span>
        <span class="adm-kpi__txt">
          <span class="adm-kpi__label"><?= e((string)($rotKpi['ticket'] ?? 'Ticket médio')) ?></span>
          <?php if ($umaMoeda !== null): ?>
            <span class="adm-kpi__value num" title="<?= e((string)($micro['ticket_calc'] ?? '')) ?>">
              <?= e(num((float)$umaMoeda['ticket'], 0)) ?><small><?= e((string)$umaMoeda['curto']) ?></small>
            </span>
          <?php elseif ($porMoeda === []): ?>
            <span class="adm-kpi__value num">—</span>
          <?php else: ?>
            <span class="adm-kpi__value txn-kpi__nota" title="<?= e((string)($micro['ticket_multi'] ?? '')) ?>">
              <?= e(count($porMoeda)) ?> moedas
            </span>
          <?php endif ?>
        </span>
      </article>
    </div>

    <!-- ------------------------------------------------------- gráfico + por moeda -->
    <div class="txn-row txn-row--grafico">

      <section class="adm-card txn-panel">
        <div class="adm-card__head">
          <h2 class="adm-card__title">
            <?= e((string)($titulos['grafico'] ?? 'Movimento por dia')) ?>
            <small><?= e($granularidade === 'semana'
                    ? (string)($micro['semana'] ?? '')
                    : (string)($micro['granul_dia'] ?? '')) ?></small>
          </h2>
        </div>
        <div class="adm-card__body txn-plot">
          <?php if (($serie['moedas'] ?? []) === []): ?>
            <div class="adm-empty"><?= icon('bar_chart') ?><p class="adm-empty__txt"><?= e((string)($vazios['grafico'] ?? '')) ?></p></div>
          <?php else: ?>
            <?php /* Uma FAIXA por moeda, cada uma com a sua régua. Empilhar as três num
                     gráfico só somaria unidades incomparáveis — a altura da pilha seria uma
                     grandeza que não existe. */ ?>
            <?php foreach ((array)$serie['moedas'] as $sm): ?>
              <div class="txn-serie">
                <div class="txn-serie__head">
                  <span class="adm-badge adm-badge--<?= e($sm['tom'] === 'lime' ? 'ok' : ($sm['tom'] === 'cyan' ? 'info' : ($sm['tom'] === 'amber' ? 'warn' : 'muted'))) ?>">
                    <?= e((string)$sm['label']) ?>
                  </span>
                  <span class="txn-serie__total num">
                    <?= e(num((int)$sm['total'])) ?><small><?= e((string)$sm['curto']) ?></small>
                  </span>
                  <span class="txn-serie__pico num" title="Maior <?= e($granularidade === 'semana' ? 'semana' : 'dia') ?> do período">
                    pico <?= e(num((int)$sm['max'])) ?>
                  </span>
                </div>
                <?= $grafico((array)$sm['pontos'], (int)$sm['max'], (string)$sm['tom'], (string)$sm['curto'], (string)$sm['label']) ?>
              </div>
            <?php endforeach ?>
          <?php endif ?>
        </div>
      </section>

      <section class="adm-card txn-panel">
        <div class="adm-card__head">
          <h2 class="adm-card__title">
            <?= e((string)($titulos['resumo'] ?? 'Total por moeda')) ?>
            <small><?= e((string)($micro['delta_base'] ?? '')) ?></small>
          </h2>
        </div>
        <div class="adm-card__body adm-card__body--flush">
          <?php if ($porMoeda === []): ?>
            <div class="adm-empty"><?= icon('payments') ?><p class="adm-empty__txt"><?= e((string)($vazios['periodo'] ?? '')) ?></p></div>
          <?php else: ?>
            <ul class="txn-moedas">
              <?php foreach ($porMoeda as $m): ?>
                <li class="txn-moeda txn-moeda--<?= e((string)$m['tom']) ?>">
                  <span class="txn-moeda__nome">
                    <?= e((string)$m['label']) ?>
                    <small><?= e(num((int)$m['transacoes'])) ?> transaç<?= (int)$m['transacoes'] === 1 ? 'ão' : 'ões' ?>
                      · <?= e(num((int)$m['jogadores'])) ?> jogador<?= (int)$m['jogadores'] === 1 ? '' : 'es' ?></small>
                  </span>
                  <span class="txn-moeda__val num">
                    <?= e(num((int)$m['total'])) ?><small><?= e((string)$m['curto']) ?></small>
                    <small class="txn-moeda__ticket" title="<?= e((string)($micro['ticket_calc'] ?? '')) ?>">
                      ticket <?= e(num((float)$m['ticket'], 0)) ?>
                    </small>
                  </span>
                  <?php if ($m['delta'] === null): ?>
                    <span class="txn-delta is-void" title="<?= e((string)($micro['delta_sem'] ?? '')) ?>"><?= icon('remove') ?></span>
                  <?php else: ?>
                    <?php $up = (float)$m['delta'] >= 0; ?>
                    <span class="txn-delta <?= $up ? 'is-up' : 'is-down' ?>"
                          title="<?= e((string)($micro['delta_base'] ?? '')) ?>">
                      <?= icon($up ? 'trending_up' : 'trending_down') ?>
                      <?= e(($up ? '+' : '') . num((float)$m['delta'] * 100, 1) . '%') ?>
                    </span>
                  <?php endif ?>
                </li>
              <?php endforeach ?>
            </ul>
          <?php endif ?>
        </div>
      </section>
    </div>

    <!-- ------------------------------------------------------ mais vendidos / gastadores -->
    <div class="txn-row txn-row--tops">

      <section class="adm-card txn-panel">
        <div class="adm-card__head">
          <h2 class="adm-card__title"><?= e((string)($titulos['itens'] ?? 'Itens mais vendidos')) ?></h2>
        </div>
        <div class="adm-card__body adm-card__body--flush">
          <?php if ($topItens === []): ?>
            <div class="adm-empty"><?= icon('inventory_2') ?><p class="adm-empty__txt"><?= e((string)($vazios['itens'] ?? '')) ?></p></div>
          <?php else: ?>
            <?php $maxUn = max(array_map(static fn(array $i): int => (int)$i['unidades'], $topItens)); ?>
            <ul class="txn-top">
              <?php foreach ($topItens as $i): ?>
                <li class="txn-top__row">
                  <span class="txn-top__bar" aria-hidden="true"
                        style="--v:<?= e((string)round((int)$i['unidades'] / max(1, $maxUn) * 100)) ?>%"></span>
                  <span class="txn-top__nome">
                    <?= e($i['nome'] !== '' ? (string)$i['nome'] : 'Item #' . (int)$i['item_id']) ?>
                    <?php if ($i['nome'] === ''): ?>
                      <small title="<?= e((string)($micro['item_sumido'] ?? '')) ?>">removido do catálogo</small>
                    <?php endif ?>
                  </span>
                  <span class="txn-top__n num"><?= e(num((int)$i['unidades'])) ?> un</span>
                  <span class="txn-top__v num">
                    <?= e(num((int)$i['total'])) ?><small><?= e(App\Repo\TransactionsRepo::siglaMoeda((string)$i['moeda'])) ?></small>
                  </span>
                </li>
              <?php endforeach ?>
            </ul>
          <?php endif ?>
        </div>
      </section>

      <section class="adm-card txn-panel">
        <div class="adm-card__head">
          <h2 class="adm-card__title"><?= e((string)($titulos['jogadores'] ?? 'Quem mais gastou')) ?></h2>
        </div>
        <div class="adm-card__body adm-card__body--flush">
          <?php if ($topJog === []): ?>
            <div class="adm-empty"><?= icon('group') ?><p class="adm-empty__txt"><?= e((string)($vazios['jogadores'] ?? '')) ?></p></div>
          <?php else: ?>
            <?php $maxG = max(array_map(static fn(array $j): int => (int)$j['total'], $topJog)); ?>
            <ul class="txn-top">
              <?php foreach ($topJog as $j): ?>
                <li class="txn-top__row">
                  <span class="txn-top__bar" aria-hidden="true"
                        style="--v:<?= e((string)round((int)$j['total'] / max(1, $maxG) * 100)) ?>%"></span>
                  <span class="txn-top__nome">
                    <?= e($j['nome'] !== '' ? (string)$j['nome'] : 'Conta #' . (int)$j['user_id']) ?>
                    <?php if ($j['nome'] === ''): ?>
                      <small title="<?= e((string)($micro['conta_sumida'] ?? '')) ?>">conta removida</small>
                    <?php endif ?>
                  </span>
                  <span class="txn-top__n num"><?= e(num((int)$j['transacoes'])) ?>×</span>
                  <span class="txn-top__v num">
                    <?= e(num((int)$j['total'])) ?><small><?= e(App\Repo\TransactionsRepo::siglaMoeda((string)$j['moeda'])) ?></small>
                  </span>
                </li>
              <?php endforeach ?>
            </ul>
          <?php endif ?>
        </div>
      </section>
    </div>

    <!-- -------------------------------------------------------------------- tabela -->
    <section class="adm-card txn-lista">
      <div class="adm-card__head">
        <h2 class="adm-card__title">
          <?= e((string)($titulos['lista'] ?? 'Transações')) ?>
          <small data-adm-total><?= e(num((int)$pag['total'])) ?> no período</small>
        </h2>
      </div>

      <?php if ($linhas === []): ?>
        <div class="adm-empty">
          <?= icon('search_off') ?>
          <p class="adm-empty__txt">
            <?= e((string)($vazios['periodo'] ?? '')) ?>
            <?php if (!empty($extremos['ultima'])): ?>
              A última compra registrada foi em <?= e($dataHora((string)$extremos['ultima'])) ?>.
            <?php endif ?>
          </p>
          <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/transacoes', ['periodo' => 'tudo'])) ?>">
            <?= icon('history') ?><?= e((string)($acoes['tudo'] ?? 'Ver todo o período')) ?>
          </a>
        </div>
      <?php else: ?>
        <div class="adm-tablewrap">
          <table class="adm-table txn-table">
            <thead>
              <tr>
                <?= $cab('id', (string)($colunas['id'] ?? '#'), 'tr') ?>
                <?= $cab('data', (string)($colunas['data'] ?? 'Data')) ?>
                <?= $cab('jogador', (string)($colunas['jogador'] ?? 'Jogador')) ?>
                <?= $cab('item', (string)($colunas['item'] ?? 'Item')) ?>
                <th class="tr"><?= e((string)($colunas['qtd'] ?? 'Qtd')) ?></th>
                <th class="tr"><?= e((string)($colunas['unit'] ?? 'Unitário')) ?></th>
                <?= $cab('valor', (string)($colunas['total'] ?? 'Total'), 'tr') ?>
                <th><?= e((string)($colunas['moeda'] ?? 'Moeda')) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($linhas as $l): ?>
                <?php
                  $moeda = (string)$l['currency'];
                  $tom   = App\Repo\TransactionsRepo::tomMoeda($moeda);
                  $selo  = $tom === 'lime' ? 'ok' : ($tom === 'cyan' ? 'info' : ($tom === 'amber' ? 'warn' : 'muted'));
                  $nomeJ = (string)($l['username'] ?? '');
                  $nomeI = (string)($l['item_name'] ?? '');
                ?>
                <tr>
                  <td class="tr num txn-id">#<?= e((string)(int)$l['id']) ?></td>
                  <td title="<?= e($dataLonga((string)$l['bought_at'])) ?>"><?= e($dataHora((string)$l['bought_at'])) ?></td>
                  <td>
                    <?php if ($nomeJ !== ''): ?>
                      <strong><?= e($nomeJ) ?></strong>
                      <?php if ((string)($l['user_status'] ?? 'active') !== 'active'): ?>
                        <?php /* A conta mudou de estado DEPOIS da compra: a linha continua
                                 válida, mas quem lê o relatório precisa ver isso aqui. */ ?>
                        <span class="adm-badge adm-badge--danger txn-mini"><?= e((string)$l['user_status']) ?></span>
                      <?php endif ?>
                    <?php else: ?>
                      <span class="txn-sumido" title="<?= e((string)($micro['conta_sumida'] ?? '')) ?>">
                        Conta #<?= e((string)(int)$l['user_id']) ?>
                      </span>
                    <?php endif ?>
                  </td>
                  <td>
                    <?php if ($nomeI !== ''): ?>
                      <strong><?= e($nomeI) ?></strong>
                      <?php if (!empty($l['item_category'])): ?>
                        <small class="txn-cat"><?= e((string)$l['item_category']) ?></small>
                      <?php endif ?>
                    <?php else: ?>
                      <span class="txn-sumido" title="<?= e((string)($micro['item_sumido'] ?? '')) ?>">
                        Item #<?= e((string)(int)$l['item_id']) ?>
                      </span>
                    <?php endif ?>
                  </td>
                  <td class="tr num"><?= e(num((int)$l['quantity'])) ?></td>
                  <td class="tr num"><?= e(num((int)$l['price'])) ?></td>
                  <td class="tr num txn-total"><?= e(num((int)$l['total'])) ?></td>
                  <td><span class="adm-badge adm-badge--<?= e($selo) ?>"><?= e(App\Repo\TransactionsRepo::siglaMoeda($moeda)) ?></span></td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>

        <div class="adm-card__foot">
          <nav class="adm-pager" aria-label="Paginação das transações">
            <span class="adm-pager__info">
              <?= e(num((int)$pag['primeiro'])) ?>–<?= e(num((int)$pag['ultimo'])) ?>
              de <?= e(num((int)$pag['total'])) ?>
            </span>

            <?php $atual = (int)$pag['pagina']; $paginas = (int)$pag['paginas']; ?>
            <a class="adm-pager__btn<?= $atual <= 1 ? ' is-disabled' : '' ?>"
               href="<?= e($link(['p' => $atual - 1 > 1 ? (string)($atual - 1) : null])) ?>"
               aria-label="Página anterior"<?= $atual <= 1 ? ' aria-disabled="true"' : '' ?>><?= icon('chevron_left') ?></a>

            <?php
              /* Janela de páginas: primeira, última e duas vizinhas. Com 40 páginas, imprimir
                 todas encheria a linha e empurraria o rodapé para fora do cartão. */
              $mostrar = [1, $paginas, $atual, $atual - 1, $atual + 1];
              $mostrar = array_values(array_unique(array_filter($mostrar,
                          static fn(int $n): bool => $n >= 1 && $n <= $paginas)));
              sort($mostrar);
              $anterior = 0;
            ?>
            <?php foreach ($mostrar as $n): ?>
              <?php if ($anterior > 0 && $n > $anterior + 1): ?>
                <span class="adm-pager__gap">…</span>
              <?php endif ?>
              <a class="adm-pager__btn<?= $n === $atual ? ' is-active' : '' ?>"
                 href="<?= e($link(['p' => $n > 1 ? (string)$n : null])) ?>"
                 <?= $n === $atual ? 'aria-current="page"' : '' ?>><?= e((string)$n) ?></a>
              <?php $anterior = $n; ?>
            <?php endforeach ?>

            <a class="adm-pager__btn<?= $atual >= $paginas ? ' is-disabled' : '' ?>"
               href="<?= e($link(['p' => (string)($atual + 1)])) ?>"
               aria-label="Próxima página"<?= $atual >= $paginas ? ' aria-disabled="true"' : '' ?>><?= icon('chevron_right') ?></a>
          </nav>
        </div>
      <?php endif ?>
    </section>
    <!--/eh:lista-->

  <?php endif ?>
</div>
