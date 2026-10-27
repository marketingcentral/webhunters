<?php declare(strict_types=1);

/**
 * Layout ADMIN — casca de todas as telas do painel administrativo.
 *
 * Uso no controller:
 *   return $this->render('admin/jogadores', $page, $data, 'admin');
 *
 * $page (além do contrato normal):
 *   'slug'     => 'admin-jogadores'   view/css/js da tela
 *   'nav'      => 'jogadores'         qual item da sidebar acende (default: slug)
 *   'title'    => 'Jogadores'         título grande da topbar
 *   'subtitle' => 'Contas, ...'       linha de apoio
 *   'actions'  => '<a class="adm-btn ...">'  HTML já montado, colado à direita do título
 *
 * $data (tudo opcional — a casca renderiza inteira sem nenhum deles):
 *   'admin_nav'  => grupos da sidebar (mesmo formato de app/Data/admin_nav.php)
 *   'admin'      => ['name','role','avatar','env','version','logout','menu'=>[...]]
 *   'admin_kpis' => [['icon','label','value','tone','sub'], ...] — bloco compacto da topbar
 *   'admin_alerts' => [['icon','text','meta','tone'], ...] — sino de notificações
 *
 * Os KPIs e o sino NÃO inventam número: sem dado do controller, mostram travessão.
 * CSS: assets/css/admin.css   JS: assets/js/admin.js
 */

use App\Core\Config;
use App\Core\Router;

$assets = Config::assets();
$nonce  = nonce();

$slug     = preg_replace('/[^a-z0-9_-]/', '', (string)($page['slug'] ?? 'admin')) ?: 'admin';
$navAtivo = (string)($page['nav'] ?? $slug);
$titulo   = trim((string)($page['title'] ?? 'Painel Admin'));
$subtitulo= trim((string)($page['subtitle'] ?? ''));
$docTitle = ($titulo !== '' ? $titulo . ' · ' : '') . 'Painel · ' . Config::APP_NAME;

/* Grupos da sidebar: controller manda, senão o arquivo de dados. Nunca escrito aqui. */
$admNav = $data['admin_nav'] ?? data_mock('admin_nav');
$admNav = is_array($admNav) ? $admNav : [];

$adm     = is_array($data['admin'] ?? null) ? $data['admin'] : [];
$admNome = (string)($adm['name'] ?? 'Administrador');
$admPapel= (string)($adm['role'] ?? 'Operador');
$admFoto = (string)($adm['avatar'] ?? '');
$admEnv  = (string)($adm['env'] ?? (Config::debug() ? 'Ambiente local' : 'Produção'));
$admVer  = (string)($adm['version'] ?? 'v1.0');
$admSair = (string)($adm['logout'] ?? url('sair'));
$admMenu = is_array($adm['menu'] ?? null) ? $adm['menu'] : [];

$admKpis   = is_array($data['admin_kpis'] ?? null) ? $data['admin_kpis'] : [];
$admAlerts = is_array($data['admin_alerts'] ?? null) ? $data['admin_alerts'] : [];

/* Casco primeiro, CSS da tela por último: a tela precisa vencer o sistema.
   `admin/lista.css` entra no meio (depois do sistema, antes da tela) porque é o par de
   assets/js/admin/lista.js — a atualização de lista sem recarga —, e serve a QUALQUER tela
   com lista, não a uma só. Nada dele é acionado sem JavaScript. */
$styles = ['fonts/fonts.css', 'css/tokens.css', 'css/base.css', 'css/components.css',
           'css/logo.css', 'css/admin.css', 'css/admin/lista.css', 'css/admin/' . $slug . '.css'];
foreach ((array)($page['css'] ?? []) as $extra) {
    $extra = ltrim((string)$extra, '/');
    $extra = str_starts_with($extra, 'css/') ? $extra : 'css/' . $extra;
    if (!in_array($extra, $styles, true)) {
        $styles[] = $extra;
    }
}

/* `admin/lista.js` é carregado pelo layout, e não importado por admin.js, porque só o
   layout sabe pôr o ?v=<mtime> do asset() — um import relativo dentro de admin.js ficaria
   preso no cache do navegador do operador depois de cada correção. Em tela sem lista ele
   não encontra os marcadores e sai sem fazer nada. */
$scripts = ['js/admin.js', 'js/admin/lista.js', 'js/admin/' . $slug . '.js'];
foreach ((array)($page['js'] ?? []) as $extra) {
    $extra = ltrim((string)$extra, '/');
    $extra = str_starts_with($extra, 'js/') ? $extra : 'js/' . $extra;
    if (!in_array($extra, $scripts, true)) {
        $scripts[] = $extra;
    }
}

$preloadFonts = ['barlow-condensed-5.woff2', 'barlow-2.woff2', 'share-tech-mono-0.woff2', 'material-symbols-0.woff2'];
?>
<!DOCTYPE html>
<?php /* data-tema sai do servidor no ESCURO: é o padrão do painel e o que quem
         está sem JavaScript continua vendo. O script logo abaixo corrige para a
         escolha salva ANTES da primeira pintura. */ ?>
<html lang="pt-BR" data-tema="escuro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($docTitle) ?></title>
<?php /* Este script vem ANTES das folhas de estilo de propósito: ele roda durante
         a análise do <head>, então o atributo já está no <html> quando a primeira
         regra de cor é aplicada. Trocar o tema depois (no fim do <body> ou no
         módulo admin.js, que é adiado) pintaria a tela escura e só então a
         clarearia — o flash branco ao contrário, igualmente feio. */ ?>
<script nonce="<?= e($nonce) ?>">
(function(){
  var h = document.documentElement, t;
  try { t = localStorage.getItem('eh.admin.tema'); } catch (e) { t = null; }
  if (t !== 'claro' && t !== 'escuro') {
    /* Sem escolha explícita, o sistema é só um PALPITE — e o padrão continua escuro. */
    t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) ? 'claro' : 'escuro';
  }
  h.setAttribute('data-tema', t);
})();
</script>
<meta name="color-scheme" content="dark light">
<meta name="theme-color" content="#070907">
<meta name="referrer" content="strict-origin-when-cross-origin">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= e($csrf ?? '') ?>">
<link rel="icon" type="image/png" href="<?= e(img('logo/emblem.png')) ?>">
<?php foreach ($preloadFonts as $font): ?>
<?php if (is_file($assets . '/fonts/' . $font)): ?>
<link rel="preload" as="font" type="font/woff2" crossorigin href="<?= e(asset('fonts/' . $font, false)) ?>">
<?php endif; ?>
<?php endforeach; ?>
<?php foreach ($styles as $css): ?>
<?php if (is_file($assets . '/' . $css)): ?>
<link rel="stylesheet" href="<?= e(asset($css)) ?>">
<?php endif; ?>
<?php endforeach; ?>
</head>
<body class="adm-body page-<?= e($slug) ?>" data-page="<?= e($slug) ?>" data-base="<?= e(Config::baseUrl()) ?>" data-nonce="<?= e($nonce) ?>">
<?php /* Overlay de fundo (assets/css/admin.css §1.0) — as quatro camadas da HUD
         em versão sóbria: brilho, grade, grão e vinheta. Fica FORA do .adm, fixo
         atrás de tudo, para não entrar no scroll do conteúdo. Puramente
         decorativo: aria-hidden e sem nenhum evento. */ ?>
<div class="adm-bg" aria-hidden="true">
  <span class="adm-bg__glow"></span>
  <span class="adm-bg__grid"></span>
  <span class="adm-bg__grain"></span>
  <span class="adm-bg__vig"></span>
</div>
<div class="adm">

  <aside class="adm-side" id="adm-side">
    <a class="adm-brand" href="<?= e(url('admin')) ?>">
      <?php component('logo', ['variant' => 'mark', 'class' => 'adm-brand__mark']) ?>
      <span class="adm-brand__txt">
        <span class="adm-brand__name"><?= e(Config::APP_NAME) ?></span>
        <span class="adm-brand__env"><?= e($admEnv) ?></span>
      </span>
    </a>

    <nav class="adm-nav scroll-y" aria-label="Navegação do painel">
      <?php foreach ($admNav as $grupo): ?>
        <?php
          $itens = is_array($grupo['items'] ?? null) ? $grupo['items'] : [];
          if ($itens === []) { continue; }
          /* A rota de cada item é conferida UMA vez, antes de desenhar a linha: o rótulo do
             grupo precisa saber se o módulo INTEIRO ainda está por vir para dizer isso uma
             única vez — era essa frase, repetida item a item, que virava uma segunda coluna
             de pílulas na barra e competia com os próprios rótulos. */
          $vivos = 0;
          $resolver = static function (array $it) use (&$vivos): array {
              $r = trim((string)($it['route'] ?? $it['slug'] ?? ''), '/');
              $it['_rota'] = $r;
              $it['_vivo'] = $r !== '' && Router::existeGet($r);
              $vivos += $it['_vivo'] ? 1 : 0;
              return $it;
          };
          foreach ($itens as $i => $it) {
              $itens[$i] = $resolver($it);
              foreach ((array)($it['children'] ?? []) as $j => $f) {
                  $itens[$i]['children'][$j] = $resolver($f);
              }
          }
          $grupoPendente = $vivos === 0;
        ?>
        <div class="adm-nav__group">
          <?php if (!empty($grupo['label'])): ?>
            <p class="adm-nav__label">
              <?= e((string)$grupo['label']) ?><?php if ($grupoPendente): ?><span class="adm-nav__label-soon"> · em breve</span><?php endif ?>
            </p>
          <?php endif ?>
          <?php foreach ($itens as $item): ?>
            <?php
              $filhos = (array)($item['children'] ?? []);
              $itSlug = (string)($item['slug'] ?? '');
              /* O pai acende também quando quem está aberto é um filho: senão a barra diria
                 que nenhum item está ativo enquanto o operador edita um mapa, e o ramo
                 inteiro pareceria desligado. */
              $ramoOn = false;
              foreach ($filhos as $f) { if ((string)($f['slug'] ?? '') === $navAtivo) { $ramoOn = true; break; } }
              $itOn   = $itSlug !== '' && $itSlug === $navAtivo;
              $itFeat = !empty($item['feature']);
              $itBadge= $item['badge'] ?? null;
              $itRota = (string)($item['_rota'] ?? '');
              /* A rota ainda não está na whitelist: o item continua ANUNCIANDO a tela, mas
                 não pode ser clicável. Um <a> aqui devolveria o 404 do JOGO — o operador
                 sairia do painel inteiro por clicar no menu. */
              $itVivo = !empty($item['_vivo']);
              $itCls  = 'adm-nav__item' . ($itFeat ? ' adm-nav__item--feature' : '')
                      . ($itOn ? ' is-active' : '') . ($itVivo ? '' : ' is-soon')
                      . ($filhos !== [] ? ' adm-nav__item--parent' : '')
                      /* Ramo aberto ≠ item aberto: quando quem está na tela é o filho, o pai
                         recebe um realce mais fraco. Marcá-lo como `is-active` poria dois
                         marcadores de "você está aqui" na mesma coluna, e o operador teria de
                         decidir qual dos dois está mentindo. */
                      . ($ramoOn && !$itOn ? ' is-branch' : '');
            ?>
            <?php if ($itVivo): ?>
              <a class="<?= e($itCls) ?>" href="<?= e(url($itRota)) ?>"<?= $itOn ? ' aria-current="page"' : '' ?>>
                <?= icon((string)($item['icon'] ?? 'chevron_right'), 'adm-nav__ico') ?>
                <span class="adm-nav__txt"><?= e((string)($item['label'] ?? '')) ?></span>
                <?php if ($itBadge !== null && $itBadge !== ''): ?>
                  <span class="adm-nav__count num"><?= e((string)$itBadge) ?></span>
                <?php endif ?>
                <?= icon('chevron_right', 'adm-nav__chev') ?>
              </a>
            <?php else: ?>
              <?php /* Indisponível é um ESTADO, não um aviso escrito: opacidade e um cadeado de
                       12px. A frase inteira vai para o title e para o aria-label — quem lê com
                       leitor de tela continua sabendo, e a barra volta a ter uma coluna só. */ ?>
              <span class="<?= e($itCls) ?>" aria-disabled="true"
                    aria-label="<?= e((string)($item['label'] ?? '') . ' — em breve') ?>"
                    title="<?= e((string)($item['label'] ?? '') . ' — tela ainda não publicada') ?>">
                <?= icon((string)($item['icon'] ?? 'chevron_right'), 'adm-nav__ico') ?>
                <span class="adm-nav__txt"><?= e((string)($item['label'] ?? '')) ?></span>
                <?= icon('lock', 'adm-nav__lock') ?>
              </span>
            <?php endif ?>
            <?php if ($filhos !== []): ?>
              <div class="adm-nav__sub">
                <?php foreach ($filhos as $filho): ?>
                  <?php
                    $fSlug = (string)($filho['slug'] ?? '');
                    $fOn   = $fSlug !== '' && $fSlug === $navAtivo;
                    $fCls  = 'adm-nav__item adm-nav__item--child' . ($fOn ? ' is-active' : '')
                           . (!empty($filho['_vivo']) ? '' : ' is-soon');
                  ?>
                  <?php if (!empty($filho['_vivo'])): ?>
                    <a class="<?= e($fCls) ?>" href="<?= e(url((string)$filho['_rota'])) ?>"<?= $fOn ? ' aria-current="page"' : '' ?>>
                      <?= icon((string)($filho['icon'] ?? 'chevron_right'), 'adm-nav__ico') ?>
                      <span class="adm-nav__txt"><?= e((string)($filho['label'] ?? '')) ?></span>
                    </a>
                  <?php else: ?>
                    <span class="<?= e($fCls) ?>" aria-disabled="true"
                          title="<?= e((string)($filho['label'] ?? '') . ' — tela ainda não publicada') ?>">
                      <?= icon((string)($filho['icon'] ?? 'chevron_right'), 'adm-nav__ico') ?>
                      <span class="adm-nav__txt"><?= e((string)($filho['label'] ?? '')) ?></span>
                      <?= icon('lock', 'adm-nav__lock') ?>
                    </span>
                  <?php endif ?>
                <?php endforeach ?>
              </div>
            <?php endif ?>
          <?php endforeach ?>
        </div>
      <?php endforeach ?>
    </nav>

    <div class="adm-side__foot">
      <form method="post" action="<?= e($admSair) ?>">
        <?= csrf_field() ?>
        <button class="adm-side__out" type="submit"><?= icon('logout') ?><span>Sair do painel</span></button>
      </form>
    </div>
  </aside>

  <div class="adm-main">
    <header class="adm-top">
      <button class="adm-top__rail" type="button" data-adm-rail aria-label="Recolher menu"><?= icon('menu_open') ?></button>

      <div class="adm-top__id">
        <h1 class="adm-top__title"><?= e($titulo) ?></h1>
        <?php if ($subtitulo !== ''): ?><p class="adm-top__sub"><?= e($subtitulo) ?></p><?php endif ?>
      </div>

      <?php if (!empty($page['actions'])): ?>
        <div class="adm-top__actions"><?= $page['actions'] ?></div>
      <?php endif ?>

      <div class="adm-top__kpis">
        <?php
          /* Sem dado do controller, os três KPIs padrão aparecem em branco (travessão):
             um painel administrativo que inventa número é pior do que um que admite não saber. */
          $kpisTop = $admKpis !== [] ? $admKpis : [
              ['icon' => 'group',      'label' => 'Jogadores online', 'value' => null, 'tone' => 'lime'],
              ['icon' => 'payments',   'label' => 'Receita (mês)',    'value' => null, 'tone' => 'cyan'],
              ['icon' => 'schedule',   'label' => 'Uptime',           'value' => null, 'tone' => 'amber'],
          ];
        ?>
        <?php foreach (array_slice($kpisTop, 0, 3) as $kpi): ?>
          <?php $tom = preg_replace('/[^a-z]/', '', strtolower((string)($kpi['tone'] ?? 'lime'))) ?: 'lime'; ?>
          <div class="adm-top__kpi adm-top__kpi--<?= e($tom) ?>">
            <?= icon((string)($kpi['icon'] ?? 'monitoring'), 'adm-top__kpi-ico') ?>
            <span class="adm-top__kpi-txt">
              <span class="adm-top__kpi-lbl"><?= e((string)($kpi['label'] ?? '')) ?></span>
              <span class="adm-top__kpi-val num"><?= ($kpi['value'] ?? null) === null || $kpi['value'] === '' ? '—' : e((string)$kpi['value']) ?><?php if (!empty($kpi['sub'])): ?><small><?= e((string)$kpi['sub']) ?></small><?php endif ?></span>
            </span>
          </div>
        <?php endforeach ?>
      </div>

      <div class="adm-top__tools">
        <?php /* Alternador de tema: duas casas, a ligada marcada por aria-pressed.
                 O estado real mora no <html data-tema> — assets/js/admin.js só
                 sincroniza os botões e grava a escolha. Sem JS, o painel fica no
                 escuro e os botões não mentem: nenhum dos dois acende. */ ?>
        <div class="adm-tema" role="group" aria-label="Tema do painel">
          <button class="adm-tema__opt" type="button" data-adm-tema="escuro"
                  aria-pressed="false" title="Modo escuro"><?= icon('dark_mode') ?><span class="sr-only">Modo escuro</span></button>
          <button class="adm-tema__opt" type="button" data-adm-tema="claro"
                  aria-pressed="false" title="Modo claro"><?= icon('light_mode') ?><span class="sr-only">Modo claro</span></button>
        </div>

        <div class="adm-pop-wrap">
          <button class="adm-icobtn<?= $admAlerts !== [] ? ' has-news' : '' ?>" type="button"
                  data-adm-pop="adm-alerts" aria-expanded="false" aria-controls="adm-alerts"
                  aria-label="Notificações"><?= icon('notifications') ?>
            <?php if ($admAlerts !== []): ?><span class="adm-icobtn__count num"><?= e((string)count($admAlerts)) ?></span><?php endif ?>
          </button>
          <div class="adm-pop adm-pop--alerts" id="adm-alerts" hidden>
            <p class="adm-pop__head">Notificações</p>
            <?php if ($admAlerts === []): ?>
              <p class="adm-pop__empty">Nada novo por aqui.</p>
            <?php else: ?>
              <ul class="adm-pop__list">
                <?php foreach ($admAlerts as $al): ?>
                  <li class="adm-pop__item adm-pop__item--<?= e(preg_replace('/[^a-z]/', '', strtolower((string)($al['tone'] ?? 'lime'))) ?: 'lime') ?>">
                    <?= icon((string)($al['icon'] ?? 'info'), 'adm-pop__ico') ?>
                    <span class="adm-pop__txt"><?= e((string)($al['text'] ?? '')) ?>
                      <?php if (!empty($al['meta'])): ?><small><?= e((string)$al['meta']) ?></small><?php endif ?>
                    </span>
                  </li>
                <?php endforeach ?>
              </ul>
            <?php endif ?>
          </div>
        </div>

        <div class="adm-pop-wrap">
          <button class="adm-user" type="button" data-adm-pop="adm-user-menu" aria-expanded="false" aria-controls="adm-user-menu">
            <?php if ($admFoto !== ''): ?>
              <img class="adm-user__ava" src="<?= e(media($admFoto)) ?>" alt="" width="40" height="40" decoding="async">
            <?php else: ?>
              <span class="adm-user__ava adm-user__ava--ph"><?= icon('shield_person') ?></span>
            <?php endif ?>
            <span class="adm-user__txt">
              <span class="adm-user__name"><?= e($admNome) ?></span>
              <span class="adm-user__role"><?= e($admPapel) ?></span>
            </span>
            <?= icon('expand_more', 'adm-user__chev') ?>
          </button>
          <div class="adm-pop adm-pop--menu" id="adm-user-menu" hidden>
            <?php foreach ($admMenu as $mi): ?>
              <a class="adm-pop__link" href="<?= e(url((string)($mi['route'] ?? ''))) ?>"><?= icon((string)($mi['icon'] ?? 'chevron_right')) ?><span><?= e((string)($mi['label'] ?? '')) ?></span></a>
            <?php endforeach ?>
            <a class="adm-pop__link" href="<?= e(url('inicio')) ?>"><?= icon('sports_esports') ?><span>Voltar ao jogo</span></a>
            <form method="post" action="<?= e($admSair) ?>">
              <?= csrf_field() ?>
              <button class="adm-pop__link adm-pop__link--danger" type="submit"><?= icon('logout') ?><span>Sair do painel</span></button>
            </form>
          </div>
        </div>
      </div>
    </header>

    <main class="adm-content scroll-y" id="main">
      <?php if (!empty($flash) && is_array($flash)): ?>
        <div class="adm-flashes" role="status" aria-live="polite">
          <?php foreach ($flash as $kind => $msg): ?>
            <?php if (is_string($msg) && $msg !== ''): ?>
              <?php $k = preg_replace('/[^a-z0-9_-]/', '', (string)$kind) ?: 'info'; ?>
              <div class="adm-flash adm-flash--<?= e($k) ?>" data-flash data-autohide="<?= $k === 'error' ? 9000 : 5000 ?>">
                <?= icon($k === 'error' ? 'error' : ($k === 'ok' ? 'check_circle' : 'info')) ?><span><?= e($msg) ?></span>
                <button class="adm-flash__x" type="button" data-adm-close aria-label="Fechar"><?= icon('close') ?></button>
              </div>
            <?php endif ?>
          <?php endforeach ?>
        </div>
      <?php endif ?>
      <?= $content ?>
    </main>

    <footer class="adm-foot">
      <span>&copy; <?= e(date('Y')) ?> <?= e(Config::APP_NAME) ?>. Todos os direitos reservados.</span>
      <span class="adm-foot__ver">Painel Administrativo <?= e($admVer) ?></span>
    </footer>
  </div>
</div>
<?php foreach ($scripts as $js): ?>
<?php if (is_file($assets . '/' . $js)): ?>
<script type="module" nonce="<?= e($nonce) ?>" src="<?= e(asset($js)) ?>"></script>
<?php endif; ?>
<?php endforeach; ?>
</body>
</html>
