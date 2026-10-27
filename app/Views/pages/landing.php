<?php declare(strict_types=1);
/**
 * PÁGINA DE DIVULGAÇÃO — raiz do site (/). Layout: layouts/landing.php.
 *
 * $data: title, description, nav[], hero[], pilares[], queda[], bestiario[], sobreviver[],
 *        estados[], extracoes[], mundo[], sistemas[], faccoes[], faccoes_txt[], galeria[],
 *        final[], rodape[]                                  (app/Data/landing.php — o que se lê)
 *        criaturas{grupos,abordagens,lista}                 (app/Data/criaturas.php — fonte única)
 *        regions[], region, active_region, markers[], bases[], map_image, map_alt,
 *        map_opacity, world_labels[], controls[], compass, legend[], legend_all,
 *        contagens{regioes,pontos,bases,extracoes}           (banco, via WorldMap — o que é)
 *
 * A ESTRUTURA segue a referência que o cliente mandou (topo, herói em três colunas, faixa de
 * pilares, criaturas, três estados, mapa, sistemas, facções, galeria, chamada final), com o
 * enredo v6 no meio: a Queda, o bestiário do fungo, o ritmo solo/equipe e a contaminação. Cada
 * seção tem um GESTO: o bestiário é um carrossel filtrado por grupo que abre a ficha embaixo,
 * com uma leitura só de como lidar; arrastar a contaminação mostra o que acontece com o corpo; escolher um
 * estado repinta a página; o mapa é o do jogo; assinar com uma facção apaga a outra.
 *
 * TUDO FUNCIONA SEM JAVASCRIPT: as abas mostram o primeiro painel, o bestiário mostra todas as
 * criaturas e a primeira ficha, as faixas de contaminação aparecem todas, o arquivo classificado
 * é um <details>, a galeria são links. O script (assets/js/pages/landing.js) só dá movimento.
 *
 * A ARTE DO ENREDO AINDA NÃO EXISTE. Retratos e as cenas da Queda passam por creature_img(), que
 * devolve null enquanto o arquivo não foi gerado — e aí `$quadro` desenha um quadro de arquivo
 * vazio no lugar. Quando a imagem aparecer em assets/img/criaturas/, a página a usa sozinha.
 */

$hero    = (array)($data['hero'] ?? []);
$queda   = (array)($data['queda'] ?? []);
$mist    = (array)($queda['misterio'] ?? []);
$btxt    = (array)($data['bestiario'] ?? []);
$surv    = (array)($data['sobreviver'] ?? []);
$contam  = (array)($surv['contaminacao'] ?? []);
$estados = (array)($data['estados'] ?? []);
$extr    = (array)($data['extracoes'] ?? []);
$mundo   = (array)($data['mundo'] ?? []);
$sist    = (array)($data['sistemas'] ?? []);
$facs    = (array)($data['faccoes'] ?? []);
$ftxt    = (array)($data['faccoes_txt'] ?? []);
$gal     = (array)($data['galeria'] ?? []);
$final   = (array)($data['final'] ?? []);
$foot    = (array)($data['rodape'] ?? []);
$region  = (array)($data['region'] ?? []);
$rstate  = is_array($region['state'] ?? null) ? $region['state'] : [];
$event   = is_array($region['event'] ?? null) ? $region['event'] : null;
$cont    = (array)($data['contagens'] ?? []);
$markers = (array)($data['markers'] ?? []);
$cap     = (array)($rstate['capacity'] ?? ['total' => 20, 'hunters' => 12, 'mercenaries' => 8]);
$inf     = (int)($rstate['infestation'] ?? 0);

/* ---- bestiário: tudo do catálogo canônico (app/Data/criaturas.php) ---- */
$cat    = (array)($data['criaturas'] ?? []);
$grupos = (array)($cat['grupos'] ?? []);
$lista  = array_values(array_filter((array)($cat['lista'] ?? []), 'is_array'));
$abord  = (array)($cat['abordagens'] ?? []);
$semArte = (string)($btxt['sem_arte'] ?? 'REGISTRO VISUAL PENDENTE');

/** As cores dos grupos no catálogo são nomes; aqui viram o r,g,b que o CSS usa em rgba(). */
$RGB = ['red' => '229,72,77', 'amber' => '245,166,35', 'purple' => '168,85,247', 'lime' => '155,224,74', 'cyan' => '62,201,240'];
$gcor = static fn (string $g): string => $RGB[(string)($grupos[$g]['cor'] ?? 'red')] ?? $RGB['red'];

/** Como lidar com a criatura: [nome, cor, ícone, texto] da abordagem dela. */
$comoLidar = static fn (array $c): array => (array)($abord[(string)($c['abordagem'] ?? '')] ?? ['nome' => '', 'cor' => 'lime', 'ms' => 'help', 'texto' => '']);
$porGrupo = [];
foreach ($lista as $c) { $porGrupo[(string)($c['grupo'] ?? '')] = ($porGrupo[(string)($c['grupo'] ?? '')] ?? 0) + 1; }

/** <picture> webp + jpg de assets/img — toda a arte do jogo existe nos dois formatos. */
$pic = static function (string $base, string $alt = '', string $cls = '', string $extra = 'loading="lazy" decoding="async"'): string {
    return '<picture' . ($cls !== '' ? ' class="' . e($cls) . '"' : '') . '>'
         . '<source srcset="' . e(img($base . '.webp')) . '" type="image/webp">'
         . '<img src="' . e(img($base . '.jpg')) . '" alt="' . e($alt) . '" ' . $extra . '>'
         . '</picture>';
};

/**
 * Quadro da arte do enredo (retrato de criatura, a Queda, a cratera). Com arquivo: a imagem.
 * Sem arquivo: um quadro de arquivo vazio — grão, varredura, ícone e nome — no MESMO tamanho,
 * para a grade não pular quando a arte chegar. É <span> porque também vive dentro de <button>.
 */
$quadro = static function (string $slug, string $nome, string $icone, string $cls = '', string $sub = '') use ($semArte): string {
    $src = creature_img($slug);
    if ($src !== null) {
        return '<span class="ph ph--img ' . e($cls) . '"><img src="' . e($src) . '" alt="' . e($nome) . '" loading="lazy" decoding="async"></span>';
    }
    return '<span class="ph ' . e($cls) . '" role="img" aria-label="' . e($nome) . '">'
         . '<span class="ph__cross" aria-hidden="true"></span>'
         . '<span class="ph__body" aria-hidden="true">' . icon($icone, 'ph__ic')
         . '<b class="ph__nome">' . e($nome) . '</b>'
         . '<small class="ph__sub">' . e($sub !== '' ? $sub : $semArte) . '</small></span>'
         . '</span>';
};
$entrar = url('criar-conta');
$login  = url('login');
?>

<?php /* Moldura de HUD por cima de tudo: cantos, varredura e grão. É o que faz a página
         parecer uma tela do jogo em vez de um site sobre o jogo. */ ?>
<div class="hudframe" aria-hidden="true">
  <span class="hudframe__corner hudframe__corner--tl"></span>
  <span class="hudframe__corner hudframe__corner--tr"></span>
  <span class="hudframe__corner hudframe__corner--bl"></span>
  <span class="hudframe__corner hudframe__corner--br"></span>
  <span class="hudframe__scan"></span>
  <span class="hudframe__grain"></span>
</div>

<!-- ======================================================================= TOPO -->
<header class="top" data-top>
  <a class="top__brand" href="#inicio">
    <img class="top__emblem" src="<?= e(img('logo/emblem.png')) ?>" alt="" width="34" height="34">
    <span class="top__name">EXTRACT<b>HUNTERS</b></span>
  </a>

  <nav class="top__nav" aria-label="Seções">
    <?php foreach ((array)($data['nav'] ?? []) as [$href, $rot]): ?>
    <a href="<?= e($href) ?>"><?= e($rot) ?></a>
    <?php endforeach ?>
  </nav>

  <?php /* O selo acompanha o estado escolhido lá embaixo: é o fio que amarra a página toda. */ ?>
  <span class="top__state" data-state-badge>
    <i class="top__dot"></i><b data-state-name>HUNTER</b><small data-state-sub>RECRUTA</small>
  </span>

  <a class="btn btn--ghost top__login" href="<?= e($login) ?>">ENTRAR</a>
  <a class="btn btn--primary top__cta" href="<?= e($entrar) ?>"><?= e((string)($hero['cta'] ?? 'ENTRAR NA OPERAÇÃO')) ?></a>
</header>

<!-- ======================================================================= HERO -->
<section class="hero" id="inicio" data-hero>
  <div class="hero__art" data-parallax>
    <?= $pic('bg/home-hero-wide', '', '', 'fetchpriority="high" decoding="async"') ?>
  </div>
  <span class="hero__veil" aria-hidden="true"></span>
  <span class="hero__grid" aria-hidden="true"></span>

  <div class="hero__in">

    <?php /* COLUNA ESQUERDA — o operador. Ele responde ao mouse (camada de paralaxe), com a
             leitura de coordenadas ao pé como numa mira de capacete. */ ?>
    <div class="hero__left" data-depth="18">
      <p class="hero__kicker"><?= e((string)($hero['kicker'] ?? '')) ?></p>
      <div class="hero__op">
        <?= $pic((string)($hero['retrato'] ?? 'portraits/op-assault'), 'Operador do Hunter Command', 'hero__oppic', 'fetchpriority="high" decoding="async"') ?>
        <span class="hero__opfade" aria-hidden="true"></span>
      </div>
      <p class="hero__coords mono">
        <?php foreach ((array)($hero['coords'] ?? []) as $c): ?><span><?= e($c) ?></span><?php endforeach ?>
        <em><i class="led"></i>SINAL ESTÁVEL</em>
      </p>
    </div>

    <?php /* CENTRO — o título. A sequência de conexão é reescrita letra a letra pelo JS; o
             texto real está aqui para quem lê sem script. */ ?>
    <div class="hero__mid">
      <p class="boot" data-boot>
        <span class="boot__led"></span>
        <span class="boot__txt" data-boot-txt><?= e((string)($hero['boot'] ?? '')) ?></span>
      </p>

      <h1 class="hero__title"><span>EXTRACT</span><b>HUNTERS</b></h1>
      <p class="hero__tag"><?= e((string)($hero['tagline'] ?? '')) ?></p>
      <p class="hero__lead"><?= e((string)($hero['lead'] ?? '')) ?></p>

      <div class="hero__cta">
        <a class="btn btn--ghost btn--lg" href="#mundo"><?= icon('travel_explore') ?><?= e((string)($hero['cta2'] ?? '')) ?></a>
        <a class="btn btn--primary btn--lg" href="<?= e($entrar) ?>"><?= e((string)($hero['cta'] ?? '')) ?><?= icon('arrow_forward') ?></a>
      </div>

      <ul class="hero__stats">
        <li><b data-count="<?= (int)($cap['total'] ?? 20) ?>"><?= (int)($cap['total'] ?? 20) ?></b><span>OPERADORES POR SETOR</span></li>
        <li><b data-count="<?= (int)($cont['regioes'] ?? 0) ?>"><?= (int)($cont['regioes'] ?? 0) ?></b><span>REGIÕES</span></li>
        <li><b data-count="<?= (int)($cont['pontos'] ?? 0) ?>"><?= (int)($cont['pontos'] ?? 0) ?></b><span>PONTOS NO MAPA</span></li>
        <li><b data-count="<?= count($lista) ?>"><?= count($lista) ?></b><span>CRIATURAS NO BESTIÁRIO</span></li>
      </ul>
    </div>

    <?php /* DIREITA — telemetria VIVA do setor: o que está aqui veio do banco, não do texto.
             É o mesmo dado que a tela /mapa mostra ao jogador. O mini-mapa é a arte real. */ ?>
    <aside class="tele" data-depth="-12" aria-label="<?= e((string)($hero['telemetria_titulo'] ?? 'Telemetria')) ?>">
      <header class="tele__head">
        <span class="tele__t"><?= e((string)($hero['telemetria_titulo'] ?? 'TELEMETRIA')) ?></span>
        <span class="tele__sector mono"><?= e((string)($hero['setor'] ?? '')) ?></span>
      </header>

      <div class="tele__map" data-tele-map style="background-image:url('<?= e(media((string)($data['map_image'] ?? 'bg/map-world.webp'))) ?>')">
        <span class="tele__ret" data-tele-ret aria-hidden="true"></span>
        <span class="tele__sweep" aria-hidden="true"></span>
        <b class="tele__region"><?= e((string)($region['name'] ?? '')) ?></b>
      </div>

      <dl class="tele__rows">
        <div><dt>MUNDO</dt><dd class="accent">PERSISTENTE</dd></div>
        <div><dt>CAPACIDADE</dt><dd><span class="mono"><?= (int)($cap['total'] ?? 20) ?></span> <small><?= (int)($cap['hunters'] ?? 12) ?> HUNTERS · ATÉ <?= (int)($cap['mercenaries'] ?? 8) ?> MERC.</small></dd></div>
        <div><dt>INFESTAÇÃO</dt><dd><span class="meter meter--red" style="--v:<?= $inf ?>%"><i></i></span><small class="mono"><?= $inf ?>%</small></dd></div>
        <div><dt>VEYRA</dt><dd><?= e((string)($rstate['veyra_activity'] ?? '—')) ?></dd></div>
        <div><dt>ATIV. HUMANA</dt><dd><?= e((string)($rstate['human_activity'] ?? '—')) ?></dd></div>
        <?php if (!empty($rstate['weather'])): ?>
        <div><dt>CLIMA</dt><dd><?= e((string)$rstate['weather']) ?></dd></div>
        <?php endif ?>
        <?php if ($event): ?>
        <div class="tele__event"><dt><?= e((string)($event['ends_label'] ?? 'TERMINA EM')) ?></dt>
          <dd><b><?= e((string)($event['name'] ?? '')) ?></b> <span class="mono" data-countdown="<?= e((string)($event['ends_in'] ?? '')) ?>"><?= e((string)($event['ends_in'] ?? '')) ?></span></dd></div>
        <?php endif ?>
      </dl>

      <ul class="tele__vert" aria-hidden="true">
        <?php foreach ((array)($hero['verticais'] ?? []) as $v): ?><li><?= e($v) ?></li><?php endforeach ?>
      </ul>
    </aside>
  </div>

  <?php /* Fita de regiões: o estado de cada setor, corrido, como o painel de um centro de
           operações. Vem do banco (maps.meta). */ ?>
  <div class="ticker" aria-hidden="true">
    <div class="ticker__run" data-ticker>
      <?php for ($rep = 0; $rep < 2; $rep++): foreach ((array)($data['regions'] ?? []) as $r): $st = (array)($r['state'] ?? []); ?>
      <span class="ticker__i"><b><?= e((string)$r['name']) ?></b>
        <em>INFESTAÇÃO <?= (int)($st['infestation'] ?? 0) ?>%</em>
        <em><?= e((string)($st['sector_state'] ?? 'ATIVO')) ?></em>
        <?php if (!empty($st['weather'])): ?><em><?= e((string)$st['weather']) ?></em><?php endif ?>
      </span>
      <?php endforeach; endfor ?>
    </div>
  </div>
</section>

<!-- ==================================================================== PILARES -->
<section class="pillars" aria-label="O que é o jogo">
  <ul class="wrap pillars__in">
    <?php foreach ((array)($data['pilares'] ?? []) as $i => [$ic, $t, $txt]): ?>
    <li data-rev style="--i:<?= (int)$i ?>">
      <span class="pillars__ic"><?= icon($ic) ?></span>
      <b><?= e($t) ?></b><span><?= e($txt) ?></span>
    </li>
    <?php endforeach ?>
  </ul>
</section>

<!-- ======================================================================= QUEDA -->
<section class="queda" id="queda">
  <div class="wrap">
    <div class="queda__head">
      <div>
        <p class="kicker" data-rev><?= e((string)($queda['kicker'] ?? '')) ?></p>
        <h2 class="h2 h2--xl" data-rev><?= e((string)($queda['titulo'] ?? 'A QUEDA')) ?></h2>
      </div>
      <blockquote class="quote quote--sm" data-rev>“<?= e((string)($queda['citacao'] ?? '')) ?>”</blockquote>
    </div>

    <div class="queda__top">
      <?php $arte = (array)($queda['arte'] ?? []); ?>
      <figure class="frame" data-rev>
        <?= $quadro((string)($arte['slug'] ?? 'a-queda'), 'A QUEDA', (string)($arte['icone'] ?? 'flare'), 'ph--wide') ?>
        <figcaption class="frame__cap mono"><?= e((string)($arte['legenda'] ?? '')) ?></figcaption>
      </figure>
      <div class="queda__txt" data-rev>
        <p><?= e((string)($queda['texto'] ?? '')) ?></p>
        <h3 class="queda__ft"><?= e((string)($queda['fungo_titulo'] ?? '')) ?></h3>
        <p class="queda__fp"><?= e((string)($queda['fungo_texto'] ?? '')) ?></p>
      </div>
    </div>

    <?php /* Linha do tempo 2057 → 2063. A Queda e o "você entra aqui" são os dois pontos que
             o visitante precisa levar: de onde veio, e em que ano ele chega. */ ?>
    <ol class="tl" aria-label="Linha do tempo">
      <?php foreach ((array)($queda['linha'] ?? []) as $i => [$quando, $tt, $tx, $marca]): ?>
      <li class="tl__i<?= $marca !== '' ? ' tl__i--' . e($marca) : '' ?>" style="--i:<?= (int)$i ?>" data-rev>
        <span class="tl__dot" aria-hidden="true"></span>
        <b class="tl__when mono"><?= e($quando) ?></b>
        <h3 class="tl__t"><?= e($tt) ?></h3>
        <p class="tl__p"><?= e($tx) ?></p>
        <?php if ($marca === 'agora'): ?><em class="tl__agora"><i class="led"></i><?= e((string)($queda['agora'] ?? '')) ?></em><?php endif ?>
      </li>
      <?php endforeach ?>
    </ol>

    <ul class="fungo">
      <?php foreach ((array)($queda['fungo'] ?? []) as $i => [$ic, $ft, $fx]): ?>
      <li style="--i:<?= (int)$i ?>" data-rev>
        <span class="fungo__ic"><?= icon($ic) ?></span>
        <b><?= e($ft) ?></b>
        <span class="fungo__tx"><?= e($fx) ?></span>
      </li>
      <?php endforeach ?>
    </ul>
  </div>

  <?php /* O MISTÉRIO — o que antes era a seção do Coro. O Coro continua (é canon), mas agora
           ele aponta para um lugar: a cratera, o Núcleo Negro e o que a rocha realmente é. */ ?>
  <div class="mist">
    <div class="wrap mist__grid">
      <div class="mist__txt">
        <p class="kicker" data-rev><?= e((string)($mist['kicker'] ?? '')) ?></p>
        <h2 class="h2" data-rev><?= e((string)($mist['titulo'] ?? '')) ?></h2>
        <blockquote class="quote quote--sm" data-rev><?= e((string)($mist['quote'] ?? '')) ?></blockquote>

        <div class="arqs">
          <?php foreach ((array)($mist['arquivos'] ?? []) as [$ic, $at, $ar, $ax]): ?>
          <article class="arq" data-rev>
            <p class="arq__r mono"><?= icon($ic) ?><?= e($ar) ?></p>
            <h3><?= e($at) ?></h3>
            <p><?= e($ax) ?></p>
          </article>
          <?php endforeach ?>

          <?php /* Classificado: <details> nativo, então abre sem script. Fechado, é uma tarja. */
                $cl = (array)($mist['classificado'] ?? []); ?>
          <details class="arq arq--x" data-rev>
            <summary>
              <span class="arq__r mono"><?= icon('lock') ?><?= e((string)($cl['rotulo'] ?? '')) ?></span>
              <span class="arq__bar" aria-hidden="true"></span>
              <span class="arq__abrir"><?= e((string)($cl['abrir'] ?? 'ABRIR')) ?><?= icon('expand_more') ?></span>
            </summary>
            <h3><?= e((string)($cl['titulo'] ?? '')) ?></h3>
            <p><?= e((string)($cl['texto'] ?? '')) ?></p>
          </details>
        </div>

        <p class="mist__q" data-rev><?= e((string)($mist['pergunta'] ?? '')) ?></p>
      </div>

      <div class="mist__side">
        <?php $carte = (array)($mist['arte'] ?? []); ?>
        <figure class="frame" data-rev>
          <?= $quadro((string)($carte['slug'] ?? 'cratera'), 'A CRATERA', (string)($carte['icone'] ?? 'crisis_alert'), 'ph--wide') ?>
          <figcaption class="frame__cap mono"><?= e((string)($carte['legenda'] ?? '')) ?></figcaption>
        </figure>
        <?php /* Leitura de sinal, não player: nada toca. Sem JS fica a moldura vazia. */ ?>
        <div class="sig" data-rev>
          <span class="sig__lbl mono">ICARUS · MICÉLIO · BANDA 3 · <b data-sig-freq>17.4</b> Hz</span>
          <canvas class="sig__wave" data-wave width="900" height="120" aria-hidden="true"></canvas>
          <span class="sig__lbl sig__lbl--r mono">PADRÃO REPETIDO · COLÔNIAS A <b>41 km</b> · CONVERGÊNCIA: <b>CRATERA</b></span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- =================================================================== CRIATURAS -->
<section class="cri" id="criaturas">
  <div class="wrap">
    <div class="cri__head">
      <div>
        <p class="kicker" data-rev><?= e((string)($btxt['kicker'] ?? '')) ?></p>
        <h2 class="h2" data-rev><?= e((string)($btxt['titulo'] ?? '')) ?></h2>
      </div>
      <blockquote class="quote quote--sm" data-rev>“<?= e((string)($btxt['citacao'] ?? '')) ?>”</blockquote>
    </div>
    <p class="sub" data-rev><?= e((string)($btxt['texto'] ?? '')) ?></p>

    <div class="bestiary" data-bestiary>
      <?php /* FILTRO POR GRUPO. Sem JS todas aparecem e TODAS fica marcado — é o mesmo que
               o filtro mostra quando ninguém escolheu nada. */ ?>
      <div class="cgroups" role="group" aria-label="Grupos de criaturas" data-rev>
        <button class="cgroup is-on" type="button" data-grupo="" aria-pressed="true">
          <?= icon('select_all') ?><span><?= e((string)($btxt['todos'] ?? 'TODAS')) ?></span><b class="mono"><?= count($lista) ?></b></button>
        <?php foreach ($grupos as $gid => $g): ?>
        <button class="cgroup" type="button" data-grupo="<?= e((string)$gid) ?>" aria-pressed="false" style="--gc:<?= e($gcor((string)$gid)) ?>">
          <?= icon((string)($g['ms'] ?? 'coronavirus')) ?><span><?= e((string)($g['nome'] ?? '')) ?></span><b class="mono"><?= (int)($porGrupo[$gid] ?? 0) ?></b></button>
        <?php endforeach ?>
      </div>
      <?php foreach ($grupos as $gid => $g): ?>
      <p class="cgroup__txt" data-grupo-txt="<?= e((string)$gid) ?>" style="--gc:<?= e($gcor((string)$gid)) ?>" hidden>
        <?= icon((string)($g['ms'] ?? 'coronavirus')) ?><span><?= e((string)($g['texto'] ?? '')) ?></span></p>
      <?php endforeach ?>

      <?php /* COMO LIDAR — a legenda das quatro leituras. Uma criatura tem uma só, a mesma em
               toda tela; o "como" (arma, silêncio, porta) está no CONTRA da ficha. */ ?>
      <p class="lidar" data-rev>
        <span class="lidar__t"><?= e((string)($btxt['legenda'] ?? 'COMO LIDAR')) ?></span>
        <?php foreach ($abord as $ak => $a): ?>
        <span class="lidar__i" title="<?= e((string)$a['texto']) ?>"><span class="verdict verdict--<?= e((string)$a['cor']) ?>"><?= icon((string)$a['ms']) ?><?= e((string)$a['nome']) ?></span><small><?= e((string)($a['curto'] ?? '')) ?></small></span>
        <?php endforeach ?>
      </p>

      <?php /* O CARROSSEL: quinze cartas numa faixa só, na ordem do catálogo (infectados,
               fauna, flora). Escolher uma abre a ficha logo embaixo. Sem JS a faixa rola
               de lado e a primeira ficha fica aberta. */ ?>
      <div class="carr" data-rev>
        <button class="carr__nav carr__nav--prev" type="button" data-carr="-1" aria-label="<?= e((string)($btxt['anterior'] ?? 'Anteriores')) ?>"><?= icon('chevron_left') ?></button>
        <div class="carr__track" role="tablist" aria-label="Criaturas" data-carr-track>
          <?php foreach ($lista as $i => $c):
              $slug = (string)$c['slug'];
              $gid  = (string)($c['grupo'] ?? '');
              $a    = $comoLidar($c);
          ?>
          <button class="beast<?= $i === 0 ? ' is-on' : '' ?><?= ($c['abordagem'] ?? '') === 'fuja' ? ' beast--x' : '' ?>" type="button" role="tab"
                  aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" data-beast="<?= e($slug) ?>" data-grupo="<?= e($gid) ?>"
                  style="--gc:<?= e($gcor($gid)) ?>">
            <span class="beast__img"><?= $quadro($slug, (string)$c['nome'], (string)($grupos[$gid]['ms'] ?? 'coronavirus'), 'ph--carta') ?></span>
            <span class="beast__verd verdict verdict--<?= e((string)$a['cor']) ?>"><?= icon((string)$a['ms']) ?><?= e((string)$a['nome']) ?></span>
            <span class="beast__txt">
              <em><?= e((string)($c['papel'] ?? '')) ?></em>
              <b><?= e((string)$c['nome']) ?></b>
            </span>
          </button>
          <?php endforeach ?>
        </div>
        <button class="carr__nav carr__nav--next" type="button" data-carr="1" aria-label="<?= e((string)($btxt['proxima'] ?? 'Próximas')) ?>"><?= icon('chevron_right') ?></button>
      </div>

      <?php /* Ficha em destaque: uma por criatura, a primeira visível. */ ?>
      <div class="bestiary__view">
        <?php foreach ($lista as $i => $c):
            $slug = (string)$c['slug'];
            $gid  = (string)($c['grupo'] ?? '');
            $a    = $comoLidar($c);
            $gms  = (string)($grupos[$gid]['ms'] ?? 'coronavirus');
        ?>
        <article class="beastview<?= $i === 0 ? ' is-on' : '' ?>" data-beast-view="<?= e($slug) ?>" data-grupo="<?= e($gid) ?>"
                 style="--gc:<?= e($gcor($gid)) ?>" <?= $i === 0 ? '' : 'hidden' ?>>
          <div class="beastview__art">
            <?= $quadro($slug, (string)$c['nome'], $gms, 'ph--retrato') ?>
            <span class="beastview__tag"><?= e((string)($c['papel'] ?? '')) ?></span>
            <span class="beastview__porte mono"><?= icon('straighten') ?><?= e((string)($c['porte'] ?? '')) ?></span>
          </div>
          <div class="beastview__txt">
            <p class="beastview__grupo"><?= icon($gms) ?><?= e((string)($grupos[$gid]['nome'] ?? '')) ?></p>
            <h3><?= e((string)$c['nome']) ?></h3>
            <p class="beastview__line"><?= e((string)($c['linha'] ?? '')) ?></p>

            <?php /* A resposta da pergunta "dá para eu encarar?", antes de qualquer detalhe. */ ?>
            <p class="beastview__lidar beastview__lidar--<?= e((string)$a['cor']) ?>">
              <?= icon((string)$a['ms']) ?><b><?= e((string)$a['nome']) ?></b><span><?= e((string)$a['texto']) ?></span></p>

            <p><?= e((string)($c['texto'] ?? '')) ?></p>

            <dl class="beastview__facts">
              <div><dt><?= icon('hearing') ?>SINAL</dt><dd><?= e((string)($c['sinal'] ?? '')) ?></dd></div>
              <div><dt><?= icon('shield') ?>CONTRA</dt><dd><?= e((string)($c['contra'] ?? '')) ?></dd></div>
              <div><dt><?= icon('coronavirus') ?>CONTÁGIO</dt><dd><?= e((string)($c['contagio'] ?? '')) ?></dd></div>
              <div><dt><?= icon('warning') ?>AMEAÇA</dt>
                <dd><span class="meter meter--red" style="--v:<?= (int)($c['ameaca'] ?? 0) ?>%"><i></i></span><b class="mono"><?= (int)($c['ameaca'] ?? 0) ?>%</b></dd></div>
            </dl>

            <p class="beastview__nota"><?= icon('menu_book') ?><?= e((string)($btxt['ficha_nota'] ?? '')) ?></p>
          </div>
        </article>
        <?php endforeach ?>
      </div>
    </div>
  </div>
</section>

<!-- ================================================================== SOBREVIVER -->
<section class="surv" id="sobreviver">
  <div class="wrap">
    <div class="surv__head">
      <div>
        <p class="kicker" data-rev><?= e((string)($surv['kicker'] ?? '')) ?></p>
        <h2 class="h2" data-rev><?= e((string)($surv['titulo'] ?? '')) ?></h2>
      </div>
      <blockquote class="quote quote--sm" data-rev>“<?= e((string)($surv['citacao'] ?? '')) ?>”</blockquote>
    </div>

    <div class="duo">
      <?php foreach (['solo', 'equipe'] as $k => $lado): $d = (array)($surv[$lado] ?? []); ?>
      <?php if ($k === 1): ?>
      <div class="duo__mid" aria-hidden="true"><span class="mono">1</span><i></i><b><?= e((string)($surv['unidade'] ?? '')) ?></b><i></i><span class="mono">4</span></div>
      <?php endif ?>
      <article class="duo__c duo__c--<?= e($lado) ?>" data-rev>
        <header class="duo__h"><span class="duo__ic"><?= icon((string)($d['icone'] ?? 'person')) ?></span><h3><?= e((string)($d['titulo'] ?? '')) ?></h3></header>
        <p class="duo__p"><?= e((string)($d['texto'] ?? '')) ?></p>
        <ul class="rules">
          <?php foreach ((array)($d['itens'] ?? []) as [$rk, $rv]): ?>
          <li><b><?= e($rk) ?></b><span><?= e($rv) ?></span></li>
          <?php endforeach ?>
        </ul>
      </article>
      <?php endforeach ?>
    </div>

    <ul class="ritmo">
      <?php foreach ((array)($surv['regras'] ?? []) as $i => [$ic, $rt, $rx]): ?>
      <li style="--i:<?= (int)$i ?>" data-rev><?= icon($ic) ?><b><?= e($rt) ?></b><span><?= e($rx) ?></span></li>
      <?php endforeach ?>
    </ul>

    <?php /* CONTAMINAÇÃO. Sem JS: as seis faixas, lidas como uma tabela. Com JS: um medidor
             que o visitante arrasta, e a faixa em que ele cai acende. Em 100 o quadro troca de
             tom — é o ponto em que o operador vira mais um Errante no mundo persistente. */
          $faixas = (array)($contam['faixas'] ?? []);
          $ini = max(0, min(100, (int)($contam['inicial'] ?? 0)));
          $fAt = [];
          foreach ($faixas as $f) { if ($ini >= (int)$f[0] && $ini <= (int)$f[1]) { $fAt = $f; break; } } ?>
    <div class="contam" data-contam data-cor="<?= e((string)($fAt[5] ?? 'lime')) ?>" data-rev>
      <div class="contam__head">
        <div>
          <p class="kicker"><?= e((string)($contam['kicker'] ?? '')) ?></p>
          <h3 class="contam__t"><?= e((string)($contam['titulo'] ?? '')) ?></h3>
          <p class="contam__p"><?= e((string)($contam['texto'] ?? '')) ?></p>
        </div>
        <div class="contam__read" aria-live="polite">
          <b class="mono" data-contam-n><?= $ini ?></b>
          <span data-contam-estado><?= e((string)($fAt[2] ?? '')) ?></span>
        </div>
      </div>

      <label class="contam__ctl" data-contam-ctl hidden>
        <span class="sr-only"><?= e((string)($contam['rotulo'] ?? 'Contaminação')) ?></span>
        <input type="range" min="0" max="100" step="1" value="<?= $ini ?>" data-contam-range style="--v:<?= $ini ?>%">
        <span class="contam__scale mono" aria-hidden="true"><i>0</i><i>25</i><i>50</i><i>75</i><i>100</i></span>
      </label>

      <ol class="cfx">
        <?php foreach ($faixas as [$de, $ate, $est, $acon, $trat, $cor]): $on = $ini >= $de && $ini <= $ate; ?>
        <li class="cf cf--<?= e((string)$cor) ?><?= $on ? ' is-on' : '' ?><?= (int)$de === 100 ? ' cf--fim' : '' ?>" data-de="<?= (int)$de ?>" data-ate="<?= (int)$ate ?>" data-cor="<?= e((string)$cor) ?>" data-estado="<?= e((string)$est) ?>">
          <span class="cf__r mono"><?= (int)$de === (int)$ate ? (int)$de : (int)$de . '–' . (int)$ate ?></span>
          <b class="cf__e"><?= e((string)$est) ?></b>
          <?php if ((int)$de === 100): ?>
          <span class="cf__thumb"><?= $quadro('errante', 'ERRANTE', 'coronavirus', 'ph--mini', 'ERRANTE') ?></span>
          <?php endif ?>
          <p class="cf__a"><?= e((string)$acon) ?></p>
          <p class="cf__tr"><?= icon('medical_services') ?><span><?= e((string)$trat) ?></span></p>
        </li>
        <?php endforeach ?>
      </ol>
    </div>
  </div>
</section>

<!-- ===================================================================== ESTADOS -->
<section class="states" id="estados">
  <div class="wrap states__grid">
    <div class="states__intro">
      <p class="kicker" data-rev>ESCOLHA SEU CAMINHO</p>
      <h2 class="h2" data-rev>TRÊS ESTADOS<br>OPERACIONAIS</h2>
      <p class="sub" data-rev>
        Não são personagens diferentes: é o mesmo operador, com o mesmo inventário e a mesma
        contaminação no sangue. Escolha um e veja o mundo mudar de lado — quem reconhece a sua
        autoridade, quem te protege, quem te caça e por onde você sai.
      </p>
      <p class="states__now" data-rev>ESTADO ATUAL · <b data-state-name>HUNTER</b></p>
    </div>

    <div class="states__cards" role="tablist" aria-label="Estados operacionais">
      <?php $n = 0; foreach ($estados as $id => $s): ?>
      <button class="scard<?= $id === 'hunter' ? ' is-on' : '' ?>" type="button" role="tab"
              id="tab-<?= e($id) ?>" aria-controls="painel-<?= e($id) ?>"
              aria-selected="<?= $id === 'hunter' ? 'true' : 'false' ?>"
              data-state="<?= e($id) ?>" data-cor="<?= e((string)$s['cor']) ?>" data-selo="<?= e((string)$s['selo']) ?>"
              style="--cor:<?= e((string)$s['cor']) ?>;--i:<?= $n++ ?>" data-rev>
        <span class="scard__img"><?= $pic((string)$s['img'], '', '') ?></span>
        <span class="scard__num mono"><?= e((string)$s['num']) ?></span>
        <span class="scard__txt">
          <b><?= e((string)$s['nome']) ?></b>
          <small><?= e((string)$s['titulo']) ?></small>
          <span><?= e((string)$s['linha']) ?></span>
        </span>
        <i class="scard__bar" aria-hidden="true"></i>
      </button>
      <?php endforeach ?>
    </div>
  </div>

  <div class="wrap">
    <?php foreach ($estados as $id => $s): ?>
    <div class="spanel<?= $id === 'hunter' ? ' is-on' : '' ?>" id="painel-<?= e($id) ?>"
         role="tabpanel" aria-labelledby="tab-<?= e($id) ?>" data-state-panel="<?= e($id) ?>"
         <?= $id === 'hunter' ? '' : 'hidden' ?>>
      <div class="spanel__txt">
        <p class="spanel__body"><?= e((string)$s['texto']) ?></p>
        <ul class="rules">
          <?php foreach ((array)$s['regras'] as [$rk, $rv]): ?>
          <li><b><?= e($rk) ?></b><span><?= e($rv) ?></span></li>
          <?php endforeach ?>
        </ul>
      </div>

      <?php /* A tabela de extração é a prova visível da escolha: mudar de estado apaga e
               acende rotas de saída na frente de quem está lendo. */ ?>
      <aside class="exits">
        <p class="exits__t">ROTAS DE SAÍDA NESTE ESTADO</p>
        <ul>
          <?php foreach ($extr as $x): $ok = (bool)(($s['extracoes'] ?? [])[$x['id']] ?? false); ?>
          <li class="<?= $ok ? 'is-open' : 'is-shut' ?>" data-exit="<?= e((string)$x['id']) ?>">
            <i aria-hidden="true"></i>
            <span><b><?= e((string)$x['nome']) ?></b><small><?= e((string)$x['sub']) ?></small></span>
            <em><?= $ok ? 'DISPONÍVEL' : 'BLOQUEADA' ?></em>
          </li>
          <?php endforeach ?>
        </ul>
      </aside>
    </div>
    <?php endforeach ?>
  </div>
</section>

<!-- ======================================================================= MUNDO -->
<section class="world" id="mundo">
  <div class="wrap world__grid">
    <div class="world__side">
      <p class="kicker" data-rev><?= e((string)($mundo['kicker'] ?? 'EXPLORE')) ?></p>
      <h2 class="h2" data-rev><?= e((string)($mundo['titulo'] ?? 'A ILHA')) ?></h2>
      <p class="sub" data-rev><?= e((string)($mundo['texto'] ?? '')) ?></p>

      <a class="btn btn--primary" href="<?= e($entrar) ?>" data-rev><?= e((string)($mundo['cta'] ?? 'EXPLORAR O MAPA')) ?><?= icon('arrow_forward') ?></a>

      <ul class="world__stats" data-rev>
        <li><?= icon('landscape') ?><b class="mono"><?= (int)($cont['regioes'] ?? 0) ?></b><span>REGIÕES PERSISTENTES</span></li>
        <li><?= icon('flag') ?><b class="mono"><?= (int)($cont['bases'] ?? 0) ?></b><span>BASES HUNTER COMMAND</span></li>
        <li><?= icon('flight_takeoff') ?><b class="mono"><?= (int)($cont['extracoes'] ?? 0) ?></b><span>PONTOS DE EXTRAÇÃO</span></li>
        <li><?= icon('location_on') ?><b class="mono"><?= (int)($cont['pontos'] ?? 0) ?></b><span>PONTOS DE INTERESSE</span></li>
        <li><?= icon('groups') ?><b class="mono"><?= (int)($cap['total'] ?? 20) ?></b><span>OPERADORES POR SETOR</span></li>
      </ul>

      <p class="world__hint" data-rev><?= icon('mouse') ?><?= e((string)($mundo['dica'] ?? '')) ?></p>
    </div>

    <div class="world__box" data-rev>
      <?php /* A legenda É o filtro, igual à HUD: cada chip liga/desliga a sua camada e TODOS
               volta a mostrar o mapa inteiro. Os glifos são os mesmos dos marcadores. */ ?>
      <div class="world__filters" data-map-filters role="group" aria-label="Camadas do mapa">
        <button class="chip mlayer is-active" type="button" data-value="todos" aria-pressed="true">
          <?= icon('done_all') ?><?= e((string)($data['legend_all'] ?? 'TODOS')) ?></button>
        <?php
          $usadas = [];
          foreach ($markers as $m) { $usadas[(string)($m['layer'] ?? '')] = true; }
          foreach ((array)($data['legend'] ?? []) as $l):
            if (empty($usadas[(string)($l['layer'] ?? '')])) { continue; }   // só camadas com pontos
        ?>
        <button class="chip mlayer chip--<?= e((string)($l['color'] ?? 'lime')) ?>" type="button"
                data-value="<?= e((string)$l['layer']) ?>" aria-pressed="false">
          <?= map_glyph((string)$l['glyph'], 'chip__gl') ?><?= e((string)$l['label']) ?></button>
        <?php endforeach ?>
      </div>

      <?php /* O PALCO É O DA HUD: mesmo parcial, mesmo CSS (.page-mapa), mesmo map3d.js.
               Sem o controle de opacidade — ele grava na conta, e aqui não há conta. */ ?>
      <div class="page-mapa world__stage">
        <div class="mapa__map">
          <?php partial('map-stage', ['markers' => $markers, 'opacidade_form' => false]) ?>
        </div>
      </div>

      <div class="world__foot">
        <span><?= icon('layers') ?><b data-map-visible><?= count($markers) ?></b> pontos visíveis</span>
        <span class="world__foot-r">REGIÃO · <b><?= e((string)($region['name'] ?? '')) ?></b></span>
      </div>
    </div>
  </div>
</section>

<!-- ==================================================================== SISTEMAS -->
<section class="sys" id="gameplay">
  <div class="wrap">
    <div class="sys__head">
      <div>
        <p class="kicker" data-rev><?= e((string)($sist['kicker'] ?? '')) ?></p>
        <h2 class="h2" data-rev><?= e((string)($sist['titulo'] ?? '')) ?></h2>
      </div>
      <p class="sub sub--tight" data-rev><?= e((string)($sist['texto'] ?? '')) ?></p>
    </div>

    <?php /* Sanfona horizontal: a carta sob o ponteiro (ou com foco) cresce e mostra o texto;
             as outras encolhem para uma coluna com o título em pé. */ ?>
    <div class="sysgrid" data-sys>
      <?php foreach ((array)($sist['itens'] ?? []) as $i => [$ic, $t, $sub, $txt, $im]): ?>
      <article class="syscard<?= $i === 0 ? ' is-on' : '' ?>" tabindex="0" data-sys-card style="--i:<?= (int)$i ?>" data-rev>
        <?= $pic($im, '', 'syscard__bg') ?>
        <span class="syscard__shade" aria-hidden="true"></span>
        <span class="syscard__ic"><?= icon($ic) ?></span>
        <h3 class="syscard__t"><?= e($t) ?></h3>
        <div class="syscard__body">
          <p class="syscard__sub"><?= e($sub) ?></p>
          <p class="syscard__txt"><?= e($txt) ?></p>
        </div>
        <span class="syscard__n mono">0<?= (int)$i + 1 ?></span>
      </article>
      <?php endforeach ?>
    </div>
  </div>
</section>

<!-- ===================================================================== FACÇÕES -->
<section class="fac" id="faccoes">
  <div class="wrap">
    <div class="fac__head">
      <div>
        <p class="kicker" data-rev><?= e((string)($ftxt['kicker'] ?? '')) ?></p>
        <h2 class="h2" data-rev><?= e((string)($ftxt['titulo'] ?? '')) ?> <span class="h2__sub"><?= e((string)($ftxt['sub'] ?? '')) ?></span></h2>
      </div>
      <p class="sub sub--tight" data-rev><?= e((string)($ftxt['texto'] ?? '')) ?></p>
    </div>

    <div class="fac__grid" data-fac>
      <?php foreach ($facs as $f): ?>
      <article class="faction" data-faction="<?= e((string)$f['id']) ?>" style="--fc:<?= e((string)$f['cor']) ?>" data-rev>
        <?= $pic((string)$f['img'], '', 'faction__bg') ?>
        <span class="faction__shade" aria-hidden="true"></span>
        <header class="faction__head">
          <img src="<?= e(img('emblemas/' . (string)$f['id'] . '.webp')) ?>" alt="" width="64" height="64" loading="lazy">
          <div><h3><?= e((string)$f['nome']) ?></h3><p><?= e((string)$f['lema']) ?></p></div>
        </header>
        <p class="faction__frase">“<?= e((string)$f['frase']) ?>”</p>
        <p class="faction__origin"><?= e((string)$f['origem']) ?></p>
        <ul class="faction__list">
          <?php foreach ((array)$f['itens'] as [$k, $vtxt]): ?>
          <li><b><?= e($k) ?></b><span><?= e($vtxt) ?></span></li>
          <?php endforeach ?>
        </ul>
        <div class="faction__rep">
          <span>REPUTAÇÃO</span>
          <span class="meter" style="--v:0%"><i data-rep-bar></i></span>
          <b class="mono" data-rep>0</b>
          <em data-rep-tier>SEM CONTRATO</em>
        </div>
        <button class="btn btn--primary faction__sign" type="button" data-sign="<?= e((string)$f['id']) ?>"
                data-l-assinar="<?= e((string)($ftxt['assinar'] ?? 'ASSINAR CONTRATO')) ?>"
                data-l-assinado="<?= e((string)($ftxt['assinado'] ?? 'CONTRATO ATIVO')) ?>"><?= e((string)($ftxt['assinar'] ?? 'ASSINAR CONTRATO')) ?></button>
        <span class="faction__blocked" aria-hidden="true">IFF REVOGADO · REPUTAÇÃO ZERADA</span>
      </article>
      <?php endforeach ?>
    </div>

    <p class="fac__aviso" data-fac-aviso hidden>
      <?= icon('warning') ?><?= e((string)($ftxt['aviso'] ?? '')) ?>
      <button class="btn btn--ghost btn--sm" type="button" data-unsign><?= e((string)($ftxt['romper'] ?? 'ROMPER CONTRATO')) ?></button>
    </p>
  </div>
</section>

<!-- ===================================================================== GALERIA -->
<section class="gallery" id="galeria">
  <div class="wrap">
    <div class="gallery__head">
      <p class="kicker" data-rev><?= e((string)($gal['kicker'] ?? 'GALERIA')) ?></p>
      <h2 class="h2 h2--sm" data-rev><?= e((string)($gal['titulo'] ?? '')) ?></h2>
    </div>
    <?php /* Sem JS são links para a imagem cheia; com JS abrem no <dialog>. */ ?>
    <div class="gallery__grid" data-gallery>
      <?php foreach ((array)($gal['itens'] ?? []) as $i => [$im, $leg]): ?>
      <a class="shot" href="<?= e(img($im . '.jpg')) ?>" data-shot="<?= e(img($im . '.webp')) ?>" data-legenda="<?= e($leg) ?>" style="--i:<?= (int)$i ?>" data-rev>
        <?= $pic($im, $leg) ?>
        <span class="shot__leg"><?= e($leg) ?></span>
      </a>
      <?php endforeach ?>
    </div>
  </div>

  <dialog class="lightbox" data-lightbox aria-label="Imagem ampliada">
    <button class="lightbox__x" type="button" data-lb-close aria-label="Fechar"><?= icon('close') ?></button>
    <button class="lightbox__nav lightbox__nav--l" type="button" data-lb-prev aria-label="Anterior"><?= icon('chevron_left') ?></button>
    <figure>
      <img src="" alt="" data-lb-img>
      <figcaption class="mono" data-lb-cap></figcaption>
    </figure>
    <button class="lightbox__nav lightbox__nav--r" type="button" data-lb-next aria-label="Próxima"><?= icon('chevron_right') ?></button>
  </dialog>
</section>

<!-- ========================================================================= CTA -->
<section class="final">
  <div class="final__art"><?= $pic('bg/jogar-banner', '') ?></div>
  <span class="final__veil" aria-hidden="true"></span>
  <div class="wrap final__in">
    <p class="kicker" data-rev><?= e((string)($final['kicker'] ?? '')) ?></p>
    <h2 class="final__h" data-rev><?= e((string)($final['titulo'] ?? '')) ?></h2>
    <p class="final__p" data-rev><?= e((string)($final['texto'] ?? '')) ?></p>
    <div class="final__cta" data-rev>
      <a class="btn btn--primary btn--lg" href="<?= e($entrar) ?>"><?= e((string)($final['cta'] ?? '')) ?><?= icon('arrow_forward') ?></a>
      <a class="btn btn--ghost btn--lg" href="<?= e($login) ?>"><?= e((string)($final['cta2'] ?? '')) ?></a>
    </div>
  </div>
  <p class="final__side" aria-hidden="true"><?= e((string)($final['lateral'] ?? '')) ?></p>
</section>

<footer class="foot">
  <div class="wrap foot__in">
    <a class="foot__brand" href="#inicio">
      <img src="<?= e(img('logo/emblem.png')) ?>" alt="" width="28" height="28">
      <span>EXTRACT<b>HUNTERS</b></span>
    </a>
    <p class="foot__tag"><?= e((string)($foot['tagline'] ?? '')) ?></p>
    <nav class="foot__nav" aria-label="Conta">
      <a href="<?= e($login) ?>">ENTRAR</a>
      <a href="<?= e($entrar) ?>">CRIAR CONTA</a>
      <a href="<?= e(url('recuperar-senha')) ?>">RECUPERAR SENHA</a>
    </nav>
    <p class="foot__dim"><?= e((string)($foot['canon'] ?? '')) ?></p>
  </div>
</footer>
