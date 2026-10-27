<?php declare(strict_types=1);

/**
 * Tela ADMIN · DASHBOARD (/admin).
 *
 * Escopo: $page, $player, $nav, $server, $data, $flash, $csrf. Layout: layouts/admin.php.
 *
 * A view NÃO consulta o banco e NÃO calcula métrica: tudo já chegou pronto de
 * App\Repo\AdminRepo, via App\Controllers\Admin\DashboardController. O que existe aqui é
 * geometria (os SVG dos gráficos) e formatação (duração, dinheiro, "há 5 min") — coisas de
 * apresentação, que não fazem sentido dentro do repositório.
 *
 * Os gráficos são SVG montado no PHP de propósito: a CSP do projeto (app/Core/Security.php)
 * proíbe script de terceiros e só libera <script> com nonce, então nenhuma biblioteca de
 * gráfico entraria aqui. O desenho ainda tem a vantagem de existir no HTML — ele aparece com
 * o JavaScript desligado e sai inteiro no `curl`, que é como esta tela é conferida.
 *
 * Toda métrica carrega a sua procedência (AdminRepo::FONTE_*) e a tela MOSTRA isso: número
 * medido e número de série de demonstração nunca aparecem com a mesma cara. A frase inteira
 * da procedência mora no `title` do selo — nota de rodapé rouba altura de painel.
 *
 * REGRA DE ALTURA: o painel de visão geral cabe em UMA tela. A grade é de altura fechada
 * (ver .dash em assets/css/admin/admin-dashboard.css) e quem rola, quando rola, é a lista
 * de dentro do cartão — nunca a página.
 */

$cromo   = is_array($data['cromo'] ?? null) ? $data['cromo'] : [];
$titulos = (array)($cromo['titulos'] ?? []);
$links   = (array)($cromo['links'] ?? []);
$estados = (array)($cromo['estados'] ?? []);
$fontes  = (array)($cromo['fontes'] ?? []);
$vazios  = (array)($cromo['vazios'] ?? []);
$micro   = (array)($cromo['micro'] ?? []);

$conta   = is_array($data['admin_conta'] ?? null) ? $data['admin_conta'] : [];
$nome    = (string)($conta['username'] ?? 'operador');

$kpis       = (array)($data['kpis'] ?? []);
$serie      = (array)($data['serie'] ?? []);
$servidores = (array)($data['servidores'] ?? []);
$ranking    = (array)($data['ranking'] ?? []);
$receita    = (array)($data['receita'] ?? []);
$eventos    = (array)($data['eventos'] ?? []);
$atividade  = (array)($data['atividade'] ?? []);
$sistema    = (array)($data['sistema'] ?? []);
$acoes      = (array)($data['acoes'] ?? []);
$dias       = (int)($data['dias'] ?? 7);

$agora = time();

/* Quantas posições o ranking sempre mostra quando JÁ HÁ progresso: quatro linhas mais o
   cabeçalho é o que cabe inteiro no cartão estreito da terceira faixa em 1366×768 — a quinta
   linha só existia para ser cortada pela borda. */
$POSICOES = 4;

/* Ninguém pontuou ainda: uma tabela cujas colunas NÍVEL e XP só contêm zero e travessão não
   informa nada e faz a tela parecer desligada. Neste caso o cartão diz o fato uma vez e lista
   as contas que existem, sem colunas numéricas. */
$rankingSemProgresso = $ranking !== [] && array_sum(array_map(
    static fn(array $j): int => (int)$j['nivel'] + (int)$j['xp'],
    $ranking
)) === 0;

/* =========================================================================================
   FORMATADORES
   ========================================================================================= */

/** Dinheiro a partir de centavos, como o resto do jogo escreve: R$ 1.234,56 */
$dinheiro = static fn(int $centavos): string => 'R$ ' . num($centavos / 100, 2);

/** Segundos -> "02h 45m" (ou "45m 10s" quando não chega a uma hora). */
$duracao = static function (int $segundos): string {
    $h = intdiv($segundos, 3600);
    $m = intdiv($segundos % 3600, 60);
    return $h > 0
        ? sprintf('%02dh %02dm', $h, $m)
        : sprintf('%02dm %02ds', $m, $segundos % 60);
};

/**
 * O MESMO intervalo em duas casas decimais a menos: "11d 19h".
 *
 * É o que a lista de eventos mostra. A conta cheia ("Termina em: 11d 19h 34m") continua
 * existindo, mas no `title`: com o prefixo escrito na linha, cinco contadores saíam
 * truncados por reticências em notebook de 1366px.
 */
$intervaloCurto = static function (int $segundos): string {
    $segundos = max(0, $segundos);
    $d = intdiv($segundos, 86400);
    $h = intdiv($segundos % 86400, 3600);
    $m = intdiv($segundos % 3600, 60);
    if ($d > 0) { return sprintf('%dd %02dh', $d, $h); }
    return $h > 0 ? sprintf('%02dh %02dm', $h, $m) : sprintf('%02dm', $m);
};

/** Distância entre dois instantes, do jeito curto da referência: "5d 12h 34m". */
$intervalo = static function (int $segundos): string {
    $segundos = max(0, $segundos);
    $d = intdiv($segundos, 86400);
    $h = intdiv($segundos % 86400, 3600);
    $m = intdiv($segundos % 3600, 60);
    if ($d > 0) {
        return sprintf('%dd %02dh %02dm', $d, $h, $m);
    }
    return $h > 0 ? sprintf('%02dh %02dm', $h, $m) : sprintf('%02dm', $m);
};

/** "há 5 min" / "há 2 h" / "há 3 dias" — o carimbo da linha do tempo. */
$desde = static function (int $ts) use ($agora): string {
    $s = max(0, $agora - $ts);
    if ($s < 60)     { return 'agora'; }
    if ($s < 3600)   { return 'há ' . intdiv($s, 60) . ' min'; }
    if ($s < 86400)  { return 'há ' . intdiv($s, 3600) . ' h'; }
    $d = intdiv($s, 86400);
    return 'há ' . $d . ($d === 1 ? ' dia' : ' dias');
};

/** Valor de um cartão de KPI, já no formato que ele declara. Null vira travessão. */
$valorKpi = static function (array $kpi) use ($dinheiro, $duracao): string {
    $v = $kpi['valor'] ?? null;
    if ($v === null) {
        return '—';
    }
    return match ((string)($kpi['formato'] ?? 'inteiro')) {
        'dinheiro' => $dinheiro((int)$v),
        'duracao'  => $duracao((int)$v),
        default    => num((int)$v),
    };
};

/**
 * Qualificador do TÍTULO do painel enquanto o número não é medido.
 *
 * Sem ele, "Jogadores online" aparecia duas vezes na mesma tela — 1 no KPI ao vivo e 267 no
 * gráfico — e o selo de 11px no canto não segurava a diferença. Quando a série virar
 * telemetria de verdade, a fonte muda e o título volta a ficar limpo sozinho.
 */
$qualificador = static function (?string $fonte) use ($micro): string {
    $q = (array)($micro['qualificador'] ?? []);
    return (string)($q[(string)$fonte] ?? '');
};

/**
 * Link "ver tudo" de um cartão. Rota registrada vira <a>; rota que ainda não existe NÃO
 * desenha nada.
 *
 * A rodada anterior deixava um selo apagado "VER RANKING · EM BREVE" no lugar. Somados aos
 * da barra lateral davam vinte e três avisos idênticos na mesma tela: o texto mais repetido
 * do painel passou a ser o aviso de que a tela não existe, e nos cabeçalhos estreitos o selo
 * empurrava o título para duas linhas e desalinhava a base da faixa inteira. Enquanto a rota
 * não existir, fica só o título — o mapa das telas futuras é a barra lateral.
 */
$cardLink = static function (array $lk): string {
    $label = (string)($lk['label'] ?? '');
    if ($label === '' || empty($lk['disponivel'])) {
        return '';
    }
    return '<a class="adm-card__link" href="' . e(url((string)$lk['rota'])) . '">'
         . e($label) . icon('arrow_forward') . '</a>';
};

/**
 * Selo de procedência. É o compromisso da tela: cada número diz de onde veio — e a frase
 * inteira (mais a origem exata, quando o painel tem uma) vive no `title`, e não numa nota
 * de rodapé que desalinha a base dos cartões da faixa.
 */
$selo = static function (?string $fonte, string $extra = '') use ($fontes): string {
    $f = (string)$fonte;
    if ($f === '' || !isset($fontes[$f])) {
        return '';
    }
    $titulo = (string)$fontes[$f]['texto'] . ($extra !== '' ? "\n" . $extra : '');
    return '<span class="dash-src dash-src--' . e($f) . '" title="' . e($titulo) . '">'
        . e((string)$fontes[$f]['selo']) . '</span>';
};

/* =========================================================================================
   GRÁFICOS — SVG montado aqui, a partir dos dados que já chegaram prontos

   O viewBox é escolhido com a PROPORÇÃO do painel onde o desenho entra (largo e baixo no
   gráfico de linha, alto e estreito no de barras): o CSS estica o SVG até o corpo do cartão,
   e um viewBox desproporcional deixaria o gráfico achatado no meio de um vazio.
   ========================================================================================= */

/**
 * Topo "redondo" de um eixo: 313 vira 400, 1.813 vira 2.000. Sem isso os rótulos do eixo Y
 * saem com números quebrados e o gráfico fica impossível de ler de relance.
 *
 * @return array{topo:int, passo:int}
 */
$escala = static function (int $max, int $divisoes = 4): array {
    if ($max <= 0) {
        return ['topo' => $divisoes, 'passo' => 1];
    }
    $bruto = $max / $divisoes;
    $ordem = 10 ** (int)floor(log10(max(1.0, $bruto)));
    foreach ([1, 2, 2.5, 5, 10] as $m) {
        $passo = (int)round($m * $ordem);
        if ($passo > 0 && $passo * $divisoes >= $max) {
            return ['topo' => $passo * $divisoes, 'passo' => $passo];
        }
    }
    return ['topo' => $max, 'passo' => (int)ceil($max / $divisoes)];
};

/**
 * Gráfico de linha com área. Coordenadas fixas (viewBox) e escala uniforme: o texto do eixo
 * acompanha o tamanho do painel sem esticar.
 *
 * @param list<array{rotulo:string,valor:int}> $pontos
 */
$graficoLinha = static function (array $pontos) use ($escala): string {
    if ($pontos === []) {
        return '';
    }

    $W = 640; $H = 250;
    $ml = 46; $mr = 10; $mt = 12; $mb = 28;
    $iw = $W - $ml - $mr;
    $ih = $H - $mt - $mb;

    $valores = array_column($pontos, 'valor');
    ['topo' => $topo, 'passo' => $passo] = $escala((int)max($valores));
    $topo = max(1, $topo);

    $n = count($pontos);
    $x = static fn(int $i): float => $n === 1 ? $ml + $iw / 2 : $ml + $iw * $i / ($n - 1);
    $y = static fn(int $v): float => $mt + $ih - $ih * min(1.0, $v / $topo);

    $svg  = '<svg class="dash-chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" '
          . 'aria-label="Pico de jogadores simultâneos por dia">';

    // grade + eixo Y
    for ($v = 0; $v <= $topo; $v += $passo) {
        $py = round($y($v), 2);
        $svg .= '<line class="dash-chart__grid" x1="' . $ml . '" y1="' . $py . '" x2="' . ($W - $mr) . '" y2="' . $py . '"/>'
              . '<text class="dash-chart__tick" x="' . ($ml - 8) . '" y="' . ($py + 4) . '" text-anchor="end">' . e(num($v)) . '</text>';
    }

    // área e linha
    $linha = [];
    foreach ($pontos as $i => $p) {
        $linha[] = round($x($i), 2) . ',' . round($y((int)$p['valor']), 2);
    }
    $base = round($mt + $ih, 2);
    $svg .= '<polygon class="dash-chart__area" points="' . round($x(0), 2) . ',' . $base . ' '
          . implode(' ', $linha) . ' ' . round($x($n - 1), 2) . ',' . $base . '"/>';
    $svg .= '<polyline class="dash-chart__line" points="' . implode(' ', $linha) . '"/>';

    // pontos + eixo X (com rótulo salteado quando a janela é longa, para não empastelar)
    $salto = (int)max(1, ceil($n / 8));
    foreach ($pontos as $i => $p) {
        $px = round($x($i), 2);
        $svg .= '<circle class="dash-chart__dot" cx="' . $px . '" cy="' . round($y((int)$p['valor']), 2) . '" r="3.5">'
              . '<title>' . e($p['rotulo'] . ' · ' . num((int)$p['valor']) . ' jogadores') . '</title></circle>';
        if ($i % $salto === 0 || $i === $n - 1) {
            $svg .= '<text class="dash-chart__tick" x="' . $px . '" y="' . ($H - 9) . '" text-anchor="middle">'
                  . e($p['rotulo']) . '</text>';
        }
    }

    return $svg . '</svg>';
};

/**
 * Barras da receita. Uma barra por dia, largura calculada para a janela caber inteira.
 *
 * @param list<array{rotulo:string,centavos:int}> $barras
 */
$graficoBarras = static function (array $barras, int $maxCent) use ($escala, $dinheiro): string {
    if ($barras === []) {
        return '';
    }

    // O viewBox tem a MESMA proporção da caixa onde o SVG entra (~1,7 no cartão de receita).
    // Com um viewBox mais alto que a caixa, o preserveAspectRatio padrão encolhe o desenho e o
    // centraliza: sobravam ~26px mortos de cada lado e o gráfico não encostava nas bordas.
    $W = 340; $H = 200;
    $ml = 46; $mr = 6; $mt = 10; $mb = 22;
    $iw = $W - $ml - $mr;
    $ih = $H - $mt - $mb;

    // O passo sai da escala redonda, mas o TOPO fecha no máximo real arredondado — a escala
    // sozinha subia até 1.500 para uma barra de 1.000 e deixava um terço do plot vazio.
    ['passo' => $passoReais] = $escala((int)ceil($maxCent / 100), 3);
    $passo = max(1, $passoReais) * 100;
    $topo  = max($passo, (int)ceil(max(1, $maxCent) / $passo) * $passo);
    // 5% de respiro acima da barra mais alta: ela ocupa ~95% da altura útil sem tocar a moldura.
    $dominio = $topo * 1.05;

    $n   = count($barras);
    $celula = $iw / $n;
    $larg   = max(2.0, $celula * 0.78);

    $svg = '<svg class="dash-chart dash-chart--bars" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" '
         . 'aria-label="Receita diária do período">';

    for ($v = 0; $v <= $topo; $v += $passo) {
        $py = round($mt + $ih - $ih * ($v / $dominio), 2);
        $svg .= '<line class="dash-chart__grid" x1="' . $ml . '" y1="' . $py . '" x2="' . ($W - $mr) . '" y2="' . $py . '"/>'
              . '<text class="dash-chart__tick" x="' . ($ml - 6) . '" y="' . ($py + 4) . '" text-anchor="end">'
              . e(num($v / 100)) . '</text>';
    }

    $salto = (int)max(1, ceil($n / 4));
    foreach ($barras as $i => $b) {
        $altura = $ih * min(1.0, (int)$b['centavos'] / $dominio);
        $bx = round($ml + $celula * $i + ($celula - $larg) / 2, 2);
        $by = round($mt + $ih - $altura, 2);
        $svg .= '<rect class="dash-chart__bar" x="' . $bx . '" y="' . $by . '" width="' . round($larg, 2)
              . '" height="' . round(max(1.0, $altura), 2) . '" rx="1.5">'
              . '<title>' . e($b['rotulo'] . ' · ' . $dinheiro((int)$b['centavos'])) . '</title></rect>';
        if ($i % $salto === 0 || $i === $n - 1) {
            $svg .= '<text class="dash-chart__tick" x="' . round($bx + $larg / 2, 2) . '" y="' . ($H - 6)
                  . '" text-anchor="middle">' . e($b['rotulo']) . '</text>';
        }
    }

    return $svg . '</svg>';
};

/**
 * Anel de progresso: arco(s) desenhados com dasharray sobre a circunferência.
 *
 * Quando o repositório manda `segmentos`, cada fatia sai com a COR DO SEU ESTADO. É o caso
 * das regiões: um anel verde inteiro afirmando "4/4" enquanto o cartão ao lado pinta uma
 * região de âmbar fazia a tela se contradizer pela cor.
 *
 * @param list<array{fracao:float,tom:string}> $segmentos
 */
$anel = static function (float $fracao, string $texto, string $tom, array $segmentos = []): string {
    $r    = 32.0;
    $circ = 2 * M_PI * $r;

    $arcos = '';
    if ($segmentos !== []) {
        $vao  = count($segmentos) > 1 ? 0.014 : 0.0;   // respiro entre fatias, para serem contáveis
        $giro = -90.0;
        foreach ($segmentos as $seg) {
            $f = max(0.0, min(1.0, (float)($seg['fracao'] ?? 0)));
            if ($f <= 0.0) {
                continue;
            }
            $comp = max(0.0, $f - $vao) * $circ;
            $arcos .= '<circle class="dash-ring__arc dash-ring__arc--seg dash-ring__arc--'
                    . e(preg_replace('/[^a-z]/', '', (string)($seg['tom'] ?? 'lime')) ?: 'lime')
                    . '" cx="40" cy="40" r="' . $r . '" '
                    . 'stroke-dasharray="' . round($comp, 2) . ' ' . round($circ, 2) . '" '
                    . 'transform="rotate(' . round($giro, 2) . ' 40 40)"/>';
            $giro += $f * 360;
        }
    } else {
        $frac = max(0.0, min(1.0, $fracao));
        // Zero MEDIDO não pode ficar igual a "sem dado": um anel 100% cinza no meio de três
        // anéis coloridos lê como widget quebrado. O arco mínimo (3°, ponta reta) diz "esta
        // métrica está viva e marca zero"; a pista tracejada logo abaixo diz que o valor é
        // o piso da escala. Quem não tem origem nenhuma já tem o chip SEM ORIGEM no rodapé.
        $zerado = $frac <= 0.0;
        $comp   = $zerado ? $circ * (3 / 360) : $circ * $frac;
        $arcos  = '<circle class="dash-ring__arc' . ($zerado ? ' dash-ring__arc--zero' : '')
                . '" cx="40" cy="40" r="' . $r . '" '
                . 'stroke-dasharray="' . round($comp, 2) . ' ' . round($circ, 2) . '" '
                . 'transform="rotate(-90 40 40)"/>';
    }

    $vazio = $segmentos === [] && $fracao <= 0.0;

    return '<svg class="dash-ring dash-ring--' . e($tom) . '" viewBox="0 0 80 80" role="img" aria-label="'
        . e($texto) . '">'
        . '<circle class="dash-ring__track' . ($vazio ? ' dash-ring__track--zero' : '') . '" cx="40" cy="40" r="' . $r . '"/>'
        . $arcos
        . '<text class="dash-ring__txt" x="40" y="45" text-anchor="middle">' . e($texto) . '</text>'
        . '</svg>';
};

/**
 * Atalhos: só entra no bloco o que tem rota de verdade — e em número PAR.
 *
 * A grade é de duas colunas: cinco atalhos deixavam uma célula vazia visível no canto do
 * cartão. Quando a conta fica ímpar, o último cede a vez (a barra lateral continua levando
 * a todas as telas), em vez de a tela abrir um buraco. O que ainda não tem rota não vira
 * linha de rodapé nenhuma: já está anunciado na navegação.
 */
$acoesVivas = array_values(array_filter($acoes, static fn(array $a): bool => !empty($a['disponivel'])));
if (count($acoesVivas) % 2 === 1) {
    array_pop($acoesVivas);
}

?>
<div class="dash">

  <!-- ============================================================ COLUNA PRINCIPAL -->
  <div class="dash__main">

    <!-- ---------------------------------------------- faixa de boas-vindas + 4 KPIs -->
    <?php $hero = (array)($cromo['boas_vindas'] ?? []); ?>
    <section class="dash-hero" style="background-image:url('<?= e(img((string)($hero['imagem'] ?? 'bg/admin.jpg'))) ?>')">
      <div class="dash-hero__mask">
        <h2 class="dash-hero__title">
          <?= e(sprintf((string)($hero['saudacao'] ?? 'Bem-vindo(a), %s'), $nome)) ?>
          <?= icon('verified', 'dash-hero__seal') ?>
        </h2>

        <div class="dash-hero__kpis">
          <?php foreach ($kpis as $kpi): ?>
            <?php
              $tom   = preg_replace('/[^a-z]/', '', (string)($kpi['tom'] ?? 'lime')) ?: 'lime';
              $delta = $kpi['delta'] ?? null;
            ?>
            <article class="adm-kpi adm-kpi--<?= e($tom) ?> dash-kpi">
              <span class="adm-kpi__ico"><?= icon((string)($kpi['icone'] ?? 'monitoring')) ?></span>
              <span class="adm-kpi__txt">
                <?php /* Rótulo e selo dividem a MESMA linha em grade: o selo flutuando sobre o
                         cartão imprimia a sigla por cima do texto do rótulo. */ ?>
                <span class="dash-kpi__top">
                  <span class="adm-kpi__label" title="<?= e((string)($kpi['rotulo'] ?? '')) ?>"><?= e((string)($kpi['rotulo'] ?? '')) ?></span>
                  <?= $selo((string)($kpi['fonte'] ?? ''), (string)($kpi['nota'] ?? $kpi['origem'] ?? '')) ?>
                </span>
                <span class="adm-kpi__value num">
                  <?= e($valorKpi($kpi)) ?>
                  <?php if (!empty($kpi['sub'])): ?><small><?= e((string)$kpi['sub']) ?></small><?php endif ?>
                </span>
                <?php if ($delta !== null): ?>
                  <span class="adm-kpi__delta <?= (float)$delta >= 0 ? 'is-up' : 'is-down' ?>">
                    <?= icon((float)$delta >= 0 ? 'trending_up' : 'trending_down') ?>
                    <?= e(num(abs((float)$delta) * 100, 1)) ?>%
                    <small><?= e((string)($kpi['delta_rotulo'] ?? '')) ?></small>
                  </span>
                <?php else: ?>
                  <?php /* A ausência de base é UM traço mudo, com a frase no title: repetida por
                           extenso em três cartões vizinhos, ela fazia a faixa parecer desligada. */ ?>
                  <span class="adm-kpi__delta is-void" title="<?= e((string)($micro['sem_base'] ?? '')) ?>">
                    <?= icon('remove') ?>
                  </span>
                <?php endif ?>
              </span>
            </article>
          <?php endforeach ?>
        </div>
      </div>
    </section>

    <!-- ------------------------------------------- gráfico de linha + servidores -->
    <div class="dash-row dash-row--chart">

      <section class="adm-card dash-panel">
        <div class="adm-card__head">
          <?php
            /* O subtítulo diz de que série é a curva ANTES do número: o operador não pode ler
               "jogadores online: 267" a dois palmos do KPI ao vivo que marca 1. */
            $qSerie = $qualificador((string)($serie['fonte'] ?? ''));
            /* Sem repetir a janela: o seletor ao lado já diz 7/14/30, e o cabeçalho tem uma
               linha só de altura para toda a faixa ficar no mesmo eixo. */
            $subSerie = ($qSerie !== '' ? $qSerie . ' · ' : '')
                      . sprintf((string)($micro['media'] ?? 'Média: %s'), num((int)($serie['media'] ?? 0)));
          ?>
          <h2 class="adm-card__title">
            <?= e((string)($titulos['grafico'] ?? 'Curva de jogadores online')) ?>
            <small><?= e($subSerie) ?></small>
          </h2>
          <div class="adm-card__tools">
            <?= $selo((string)($serie['fonte'] ?? ''), 'Origem: ' . (string)($serie['origem'] ?? '')) ?>
            <nav class="dash-seg" aria-label="Período do gráfico">
              <?php foreach ((array)($cromo['periodos'] ?? []) as $per): ?>
                <?php $on = (int)$per['dias'] === $dias; ?>
                <a class="dash-seg__op<?= $on ? ' is-active' : '' ?>"
                   href="<?= e(url('admin', ['dias' => (string)$per['dias']])) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
                  <?= e((string)$per['label']) ?>
                </a>
              <?php endforeach ?>
            </nav>
          </div>
        </div>
        <div class="adm-card__body dash-panel__plot">
          <?php if (($serie['pontos'] ?? []) === []): ?>
            <div class="adm-empty"><?= icon('show_chart') ?><p class="adm-empty__txt"><?= e((string)($vazios['grafico'] ?? '')) ?></p></div>
          <?php else: ?>
            <?= $graficoLinha((array)$serie['pontos']) ?>
          <?php endif ?>
        </div>
      </section>

      <section class="adm-card dash-panel">
        <div class="adm-card__head">
          <?php $qSrv = $qualificador((string)($servidores['fonte'] ?? '')); ?>
          <h2 class="adm-card__title">
            <?= e((string)($titulos['servidores'] ?? 'Status dos servidores')) ?>
            <?php if ($qSrv !== ''): ?><small><?= e($qSrv) ?></small><?php endif ?>
          </h2>
          <div class="adm-card__tools">
            <?= $selo((string)($servidores['fonte'] ?? ''), (string)($servidores['escala'] ?? '')) ?>
          </div>
        </div>
        <div class="adm-card__body adm-card__body--flush dash-panel__list">
          <?php if (($servidores['regioes'] ?? []) === []): ?>
            <div class="adm-empty"><?= icon('dns') ?><p class="adm-empty__txt"><?= e((string)($vazios['servidores'] ?? '')) ?></p></div>
          <?php else: ?>
            <ul class="dash-srv">
              <?php foreach ((array)$servidores['regioes'] as $r): ?>
                <?php /* A linha inteira num title: em tela estreita a coluna de ping sai de
                         cena (ver o corte de 1400px) e o número não pode sumir do painel. */ ?>
                <li class="dash-srv__row dash-srv__row--<?= e((string)$r['estado']) ?>"
                    title="<?= e((string)$r['label'] . ' · ' . num((int)$r['jogadores']) . ' jogadores · '
                              . $r['ping'] . ' ms' . ($r['nota'] ? ' · ' . $r['nota'] : '')) ?>">
                  <span class="dash-srv__dot" aria-hidden="true"></span>
                  <span class="dash-srv__name">
                    <?= e((string)$r['label']) ?>
                    <?php if (!empty($r['nota'])): ?><small><?= e((string)$r['nota']) ?></small><?php endif ?>
                  </span>
                  <?php $estCurto = (array)($cromo['estados_regiao'] ?? []); ?>
                  <span class="dash-srv__state"><?= e((string)($estCurto[(string)$r['estado']]
                        ?? $estados[(string)$r['estado']] ?? $r['estado_rotulo'])) ?></span>
                  <?php /* Contagem e ping em COLUNAS SEPARADAS de largura fixa: dividindo a mesma
                           célula, um ping de três dígitos empurrava a contagem e os quatro
                           números não compartilhavam eixo nenhum. */ ?>
                  <span class="dash-srv__count num"><?= e(num((int)$r['jogadores'])) ?></span>
                  <span class="dash-srv__ping num"><?= e($r['ping'] . ' ms') ?></span>
                  <span class="dash-srv__bar" style="--v:<?= e((string)round((float)$r['ocupacao'] * 100)) ?>%"
                        title="<?= e((string)($micro['ocupacao'] ?? '')) ?>"></span>
                </li>
              <?php endforeach ?>
            </ul>
          <?php endif ?>
        </div>
        <?php /* Os serviços ficam FORA do corpo que rola: eles são o resumo do painel e não
                 podem sumir por baixo da lista de regiões. */ ?>
        <?php if (($servidores['servicos'] ?? []) !== []): ?>
          <?php
            $servicos = (array)$servidores['servicos'];
            $foraDoPadrao = count(array_filter($servicos, static fn(array $s): bool => ($s['estado'] ?? '') !== 'online'));
            $verSrv = $cardLink((array)($links['servidores'] ?? []));
          ?>
          <ul class="adm-card__foot dash-svc">
            <?php foreach ($servicos as $s): ?>
              <?php /* O estado mora na COR e no ponto, nunca dentro do rótulo: com
                       "LOJA · MANUTENÇÃO" escrito no chip, ele ficava com o dobro da largura
                       dos vizinhos e a fileira parecia um chip transbordando. A palavra vai
                       para o title e, uma vez só, para a legenda da fileira. */ ?>
              <li class="dash-svc__chip dash-svc__chip--<?= e((string)$s['estado']) ?>"
                  title="<?= e((string)$s['label'] . ' · ' . (string)$s['rotulo']) ?>">
                <span class="dash-svc__dot" aria-hidden="true"></span>
                <span><?= e((string)$s['label']) ?></span>
              </li>
            <?php endforeach ?>
          </ul>
          <?php if ($foraDoPadrao > 0 || $verSrv !== ''): ?>
            <p class="adm-card__foot dash-svc__legenda">
              <?php if ($foraDoPadrao > 0): ?>
                <span><?= e(sprintf(
                    (string)($micro['servicos_fora'] ?? '%s de %s serviços fora do padrão'),
                    num($foraDoPadrao),
                    num(count($servicos))
                )) ?></span>
              <?php endif ?>
              <?= $verSrv ?>
            </p>
          <?php endif ?>
        <?php endif ?>
      </section>
    </div>

    <!-- ------------------------------------- ranking + receita + eventos ativos -->
    <div class="dash-row dash-row--trio">

      <section class="adm-card dash-panel">
        <div class="adm-card__head">
          <h2 class="adm-card__title"><?= e((string)($titulos['ranking'] ?? 'Top jogadores')) ?></h2>
          <div class="adm-card__tools">
            <?= $selo('vivo', 'Origem: players + users, ordenado por nível') ?>
            <?= $cardLink((array)($links['ranking'] ?? [])) ?>
          </div>
        </div>
        <div class="adm-card__body adm-card__body--flush dash-panel__list dash-panel__list--rank">
          <?php if ($ranking === []): ?>
            <div class="adm-empty"><?= icon('military_tech') ?><p class="adm-empty__txt"><?= e((string)($vazios['ranking'] ?? '')) ?></p></div>
          <?php elseif ($rankingSemProgresso): ?>
            <?php
              /* Nenhum nível e nenhum XP em nenhuma conta: cinco linhas de "0, 0, —, —, —"
                 não são um ranking, são uma tabela vazia com moldura. O fato vai escrito uma
                 vez e as contas ficam listadas, sem coluna numérica que só teria zero. */
              $n = count($ranking);
              $contas = (array)($micro['contas'] ?? ['%s conta criada', '%s contas criadas']);
              $frase  = sprintf(
                  (string)($micro['sem_progresso'] ?? '%s'),
                  sprintf((string)($contas[$n === 1 ? 0 : 1] ?? '%s contas criadas'), num($n))
              );
            ?>
            <div class="dash-rank__none">
              <p class="dash-rank__none-txt"><?= icon('military_tech') ?><span><?= e($frase) ?></span></p>
              <ul class="dash-rank__contas">
                <?php foreach ($ranking as $j): ?>
                  <li>
                    <span class="adm-table__who">
                      <?php if ($j['avatar'] !== ''): ?>
                        <img src="<?= e(media((string)$j['avatar'])) ?>" alt="" width="24" height="24" loading="lazy" decoding="async">
                      <?php else: ?>
                        <span class="adm-table__ava"></span>
                      <?php endif ?>
                      <span><strong><?= e((string)$j['nome']) ?></strong><?php if ($j['patente'] !== ''): ?><small><?= e((string)$j['patente']) ?></small><?php endif ?></span>
                    </span>
                  </li>
                <?php endforeach ?>
              </ul>
            </div>
          <?php else: ?>
            <div class="adm-tablewrap">
              <table class="adm-table adm-table--compact dash-rank">
                <thead><tr><th class="tc">#</th><th>Jogador</th><th class="tr">Nível</th><th class="tr">XP</th></tr></thead>
                <tbody>
                  <?php foreach ($ranking as $j): ?>
                    <tr>
                      <td class="tc"><span class="dash-pos dash-pos--<?= e((string)min(4, (int)$j['posicao'])) ?>"><?= e((string)$j['posicao']) ?></span></td>
                      <td>
                        <span class="adm-table__who">
                          <?php if ($j['avatar'] !== ''): ?>
                            <img src="<?= e(media((string)$j['avatar'])) ?>" alt="" width="32" height="32" loading="lazy" decoding="async">
                          <?php else: ?>
                            <span class="adm-table__ava"></span>
                          <?php endif ?>
                          <span><strong><?= e((string)$j['nome']) ?></strong><small><?= e((string)$j['patente']) ?></small></span>
                        </span>
                      </td>
                      <td class="tr num"><?= e(num((int)$j['nivel'])) ?></td>
                      <td class="tr num"><?= e(num((int)$j['xp'])) ?></td>
                    </tr>
                  <?php endforeach ?>
                  <?php /* Vagas: o pódio tem tamanho fixo. Sem elas o cartão ficava com duas
                           linhas e meio metro de nada embaixo, por causa da faixa igualada. */ ?>
                  <?php for ($p = count($ranking) + 1; $p <= $POSICOES; $p++): ?>
                    <tr class="dash-rank__vaga">
                      <td class="tc"><span class="dash-pos"><?= e((string)$p) ?></span></td>
                      <td><span class="adm-table__who"><span class="adm-table__ava"></span><span><?= e((string)($micro['vaga'] ?? 'Vaga em aberto')) ?></span></span></td>
                      <td class="tr num">—</td>
                      <td class="tr num">—</td>
                    </tr>
                  <?php endfor ?>
                </tbody>
              </table>
            </div>
          <?php endif ?>
        </div>
      </section>

      <section class="adm-card dash-panel">
        <div class="adm-card__head">
          <h2 class="adm-card__title"><?= e((string)($titulos['receita'] ?? 'Receita')) ?></h2>
          <div class="adm-card__tools">
            <?php $cp = (array)($receita['compras'] ?? ['n' => 0, 'creditos' => 0]); ?>
            <?= $selo(
                  (string)($receita['fonte'] ?? ''),
                  sprintf((string)($micro['compras'] ?? '%s / %s'), num((int)$cp['n']), num((int)$cp['creditos']) . ' créditos')
                ) ?>
            <?= $cardLink((array)($links['receita'] ?? [])) ?>
          </div>
        </div>
        <div class="adm-card__body dash-rev">
          <p class="dash-rev__top">
            <span class="dash-money num"><?= e($dinheiro((int)($receita['total_centavos'] ?? 0))) ?></span>
            <?php $dr = $receita['delta'] ?? null; ?>
            <span class="adm-kpi__delta <?= $dr === null ? 'is-void' : ((float)$dr >= 0 ? 'is-up' : 'is-down') ?>"
                  <?= $dr === null ? 'title="' . e((string)($micro['sem_base'] ?? '')) . '"' : '' ?>>
              <?php if ($dr === null): ?>
                <?= icon('remove') ?>
              <?php else: ?>
                <?= icon((float)$dr >= 0 ? 'trending_up' : 'trending_down') ?>
                <?= e(num(abs((float)$dr) * 100, 1)) ?>%
              <?php endif ?>
            </span>
          </p>
          <?php
            /* O qualificador fica COLADO no número, e não no cabeçalho: R$ 18.134,69 é o
               segundo maior número da tela e mora a 300px do KPI "RECEITA (MÊS) R$ 6.407,65".
               Sem esta linha, o operador lê duas receitas quase da mesma janela diferindo
               três vezes e não tem como saber qual é a medida. Mesmo tratamento que salvou o
               gráfico de linha. Quando a série virar receita real, o qualificador some só. */
            $qRec = $qualificador((string)($receita['fonte'] ?? ''));
            $notaRec = ($qRec !== '' ? $qRec . ' · ' : '') . (int)($receita['dias'] ?? 30) . ' dias';
          ?>
          <p class="dash-rev__note"><?= e($notaRec) ?></p>
          <div class="dash-rev__plot">
            <?php if (($receita['barras'] ?? []) === []): ?>
              <div class="adm-empty"><?= icon('bar_chart') ?><p class="adm-empty__txt"><?= e((string)($vazios['receita'] ?? '')) ?></p></div>
            <?php else: ?>
              <?= $graficoBarras((array)$receita['barras'], (int)($receita['max_centavos'] ?? 0)) ?>
            <?php endif ?>
          </div>
        </div>
      </section>

      <section class="adm-card dash-panel">
        <div class="adm-card__head">
          <h2 class="adm-card__title"><?= e((string)($titulos['eventos'] ?? 'Eventos ativos')) ?></h2>
          <div class="adm-card__tools">
            <?= $selo('vivo', 'Origem: seasons + maintenance_windows') ?>
            <?= $cardLink((array)($links['eventos'] ?? [])) ?>
          </div>
        </div>
        <div class="adm-card__body adm-card__body--flush dash-panel__feed dash-panel__feed--evt">
          <?php if ($eventos === []): ?>
            <div class="adm-empty"><?= icon('event') ?><p class="adm-empty__txt"><?= e((string)($vazios['eventos'] ?? '')) ?></p></div>
          <?php else: ?>
            <ul class="dash-evt">
              <?php foreach ($eventos as $ev): ?>
                <?php
                  $est = (string)$ev['estado'];
                  /* Na linha vai só o contador ("11d 19h"); o prefixo inteiro mora no title.
                     Escrito por extenso, ele empurrava cinco contadores para as reticências
                     em notebook de 1366px — e reticência não é dado, é falta de dado. */
                  [$prazo, $prazoCheio] = match ($est) {
                      'agendado'  => [$intervaloCurto((int)$ev['inicio'] - $agora),
                                      'Começa em: ' . $intervalo((int)$ev['inicio'] - $agora)],
                      'encerrado' => [$desde((int)$ev['fim']),
                                      'Terminou ' . $desde((int)$ev['fim'])],
                      default     => [$intervaloCurto((int)$ev['fim'] - $agora),
                                      'Termina em: ' . $intervalo((int)$ev['fim'] - $agora)],
                  };
                ?>
                <li class="dash-evt__row dash-evt__row--<?= e($est) ?>">
                  <?php if (!empty($ev['imagem'])): ?>
                    <img class="dash-evt__thumb" src="<?= e(media((string)$ev['imagem'])) ?>" alt="" width="72" height="52" loading="lazy" decoding="async">
                  <?php else: ?>
                    <span class="dash-evt__thumb dash-evt__thumb--ph"><?= icon('event') ?></span>
                  <?php endif ?>
                  <span class="dash-evt__txt">
                    <?php /* O título ocupa a largura inteira e pode usar duas linhas: com o
                             selo de estado ao lado, nenhum evento cabia sem reticências. */ ?>
                    <strong title="<?= e((string)$ev['titulo']) ?>"><?= e((string)$ev['titulo']) ?></strong>
                    <span class="dash-evt__meta">
                      <span class="adm-badge adm-badge--<?= $est === 'ativo' ? 'ok' : ($est === 'agendado' ? 'info' : 'muted') ?>">
                        <?= e((string)($estados[$est] ?? $est)) ?>
                      </span>
                      <em title="<?= e($prazoCheio) ?>"><?= e($prazo) ?></em>
                    </span>
                  </span>
                </li>
              <?php endforeach ?>
            </ul>
          <?php endif ?>
        </div>
        <?php /* O rodapé só existe quando a rota de destino existe: enquanto não existir, o
                 cartão mostra os TRÊS eventos que ele busca (ver DashboardController) e nada
                 fica escondido em resolução nenhuma — não há o que um "ver todos" alcançasse. */ ?>
        <?php $verEvt = $cardLink((array)($links['eventos'] ?? [])); ?>
        <?php if ($verEvt !== ''): ?>
          <div class="adm-card__foot dash-evt__foot"><?= $verEvt ?></div>
        <?php endif ?>
      </section>
    </div>
  </div>

  <!-- ================================================================ COLUNA DIREITA -->
  <aside class="dash__side">

    <section class="adm-card">
      <div class="adm-card__head"><h2 class="adm-card__title"><?= e((string)($titulos['acoes'] ?? 'Ações rápidas')) ?></h2></div>
      <div class="adm-card__body">
        <div class="dash-acts">
          <?php foreach ($acoesVivas as $a): ?>
            <?php
              /* "Atualizar" é o próprio painel recarregado — a única forma que existe hoje de
                 reconsultar as métricas. A janela do gráfico viaja junto, senão o atalho
                 devolveria o operador para os 7 dias padrão. */
              $rotaAcao = (string)($a['rota'] ?? '');
              $href = $rotaAcao === 'admin' ? url('admin', ['dias' => (string)$dias]) : url($rotaAcao);
            ?>
            <a class="dash-act" href="<?= e($href) ?>">
              <?= icon((string)($a['icone'] ?? 'chevron_right'), 'dash-act__ico') ?>
              <span class="dash-act__txt">
                <strong><?= e((string)($a['label'] ?? '')) ?></strong>
                <small><?= e((string)($a['sub'] ?? '')) ?></small>
              </span>
            </a>
          <?php endforeach ?>
        </div>
        <?php /* A HUD do jogador não é operação administrativa: vira link discreto no pé do
                 cartão, e não um bloco com o mesmo peso de "Saúde" ou "Suporte". Sair do painel
                 não aparece aqui de forma nenhuma — é o botão grande da barra lateral. */ ?>
        <?php $jogo = (array)($micro['jogo'] ?? []); ?>
        <?php if (!empty($jogo['label'])): ?>
          <p class="dash-acts__go">
            <a href="<?= e(url((string)($jogo['rota'] ?? 'inicio'))) ?>">
              <?= icon('sports_esports') ?><span><?= e((string)$jogo['label']) ?></span>
            </a>
          </p>
        <?php endif ?>
      </div>
    </section>

    <section class="adm-card dash-panel">
      <div class="adm-card__head">
        <h2 class="adm-card__title"><?= e((string)($titulos['atividade'] ?? 'Atividade recente')) ?></h2>
        <div class="adm-card__tools">
          <?= $cardLink((array)($links['atividade'] ?? [])) ?>
        </div>
      </div>
      <?php /* SEM rolagem: o corpo é uma grade de N linhas IGUAIS que dividem a altura
               disponível (ver .dash-feed), e o controller pede exatamente N entradas. Antes
               daqui a lista rolava por dentro do cartão e a borda cortava a terceira entrada
               no meio da linha de meta, com duas outras invisíveis e sem nenhuma pista de que
               existiam. Linha cortada ao meio é defeito; entrada nunca é meia-entrada. */ ?>
      <div class="adm-card__body adm-card__body--flush dash-panel__feed">
        <?php if ($atividade === []): ?>
          <div class="adm-empty"><?= icon('history') ?><p class="adm-empty__txt"><?= e((string)($vazios['atividade'] ?? '')) ?></p></div>
        <?php else: ?>
          <ul class="dash-feed">
            <?php foreach ($atividade as $ev): ?>
              <?php $vezes = max(1, (int)($ev['repeticoes'] ?? 1)); ?>
              <li class="dash-feed__row dash-feed__row--<?= e(preg_replace('/[^a-z]/', '', (string)$ev['tom']) ?: 'lime') ?>">
                <?= icon((string)$ev['icone'], 'dash-feed__ico') ?>
                <span class="dash-feed__txt">
                  <?php /* O contador vem ANTES do texto: como a mensagem é cortada em duas
                           linhas, um selo no fim ficaria escondido justo nos casos repetidos. */ ?>
                  <span class="dash-feed__msg" title="<?= e((string)$ev['texto']) ?>"><?php if ($vezes > 1): ?><b class="dash-feed__n"><?= e($vezes . '×') ?></b><?php endif ?><?= e((string)$ev['texto']) ?></span>
                  <?php if (!empty($ev['detalhe'])): ?><small><?= e((string)$ev['detalhe']) ?></small><?php endif ?>
                </span>
                <time class="dash-feed__when" datetime="<?= e(date('c', (int)$ev['quando'])) ?>"><?= e($desde((int)$ev['quando'])) ?></time>
              </li>
            <?php endforeach ?>
          </ul>
        <?php endif ?>
      </div>
    </section>

    <section class="adm-card">
      <div class="adm-card__head"><h2 class="adm-card__title"><?= e((string)($titulos['sistema'] ?? 'Status do sistema')) ?></h2></div>
      <div class="adm-card__body">
        <div class="dash-rings">
          <?php foreach ((array)($sistema['aneis'] ?? []) as $an): ?>
            <?php
              /* Zero medido diz o que é, no title: "sem dado" já tem o chip SEM ORIGEM no
                 rodapé, e o anel não pode significar as duas coisas. */
              $tituloAnel = 'Origem: ' . (string)$an['origem']
                          . ((float)$an['fracao'] <= 0.0 && ($an['segmentos'] ?? []) === []
                             ? "\n" . (string)($micro['ocupacao_zero'] ?? '') : '');
            ?>
            <div class="dash-rings__cell" title="<?= e($tituloAnel) ?>">
              <?= $anel(
                    (float)$an['fracao'],
                    (string)$an['texto'],
                    preg_replace('/[^a-z]/', '', (string)$an['tom']) ?: 'lime',
                    (array)($an['segmentos'] ?? [])
                  ) ?>
              <span class="dash-rings__lbl"><?= e((string)$an['rotulo']) ?></span>
            </div>
          <?php endforeach ?>
        </div>
      </div>
      <div class="adm-card__foot dash-sys">
        <?php foreach ((array)($sistema['rodape'] ?? []) as $rp): ?>
          <div class="dash-sys__cell" title="<?= e((string)$rp['origem']) ?>">
            <span class="dash-sys__lbl"><?= e((string)$rp['rotulo']) ?></span>
            <span class="dash-sys__val num<?= $rp['valor'] === null ? ' is-void' : '' ?>">
              <?= $rp['valor'] === null ? '—' : e((string)$rp['valor']) ?>
            </span>
            <?= $selo((string)$rp['fonte'], (string)$rp['origem']) ?>
          </div>
        <?php endforeach ?>
      </div>
    </section>
  </aside>
</div>
