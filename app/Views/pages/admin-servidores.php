<?php declare(strict_types=1);

/**
 * Tela ADMIN · SERVIDORES (/admin/servidores).
 *
 * Escopo: $page, $player, $nav, $server, $data, $flash, $csrf. Layout: layouts/admin.php.
 *
 * A view não consulta o banco e não decide regra: tudo chega pronto de App\Repo\ServersRepo,
 * por App\Controllers\Admin\ServersController. Aqui só existe apresentação — formatação de
 * data e de duração, montagem de link de ordenação e o desenho das três tabelas.
 *
 * TRÊS COISAS QUE ESTA TELA FAZ DE PROPÓSITO:
 *
 * 1. Cada bloco declara o ALCANCE dele (app/Data/admin-servidores.php). Quem edita aqui muda
 *    o que o jogador vê na tela /suporte e na caixa de mensagens — e o painel diz isso ANTES
 *    do clique, não depois.
 *
 * 2. Estado tem cor E texto, sempre. A cor sozinha não sobrevive a monitor ruim nem a quem
 *    não distingue verde de âmbar; e a linha da região mostra o estado EFETIVO (o mesmo do
 *    dashboard), que pode divergir da coluna `state` quando há janela ativa — quando divergem,
 *    a célula mostra os dois, senão o operador tenta "consertar" um estado que está correto.
 *
 * 3. Nada de `confirm()` de JavaScript: excluir é uma etapa, com o conteúdo do registro à
 *    vista. Sem JavaScript a tela funciona inteira — é assim que ela é conferida no `curl`.
 */

$cromo    = (array)($data['cromo'] ?? []);
$titulos  = (array)($cromo['titulos'] ?? []);
$subtits  = (array)($cromo['subtitulos'] ?? []);
$alcance  = (array)($cromo['alcance'] ?? []);
$vazios   = (array)($cromo['vazios'] ?? []);
$micro    = (array)($cromo['micro'] ?? []);
$cForm    = (array)($cromo['form'] ?? []);
$cDel     = (array)($cromo['excluir'] ?? []);

$resumo   = (array)($data['resumo'] ?? []);
$regioes  = (array)($data['regioes'] ?? []);
$servicos = (array)($data['servicos'] ?? []);
$janelas  = (array)($data['janelas'] ?? ['linhas' => [], 'total' => 0, 'pagina' => 1, 'paginas' => 1, 'de' => 0, 'ate' => 0]);

$filtro    = (array)($data['filtro'] ?? []);
$estados   = (array)($data['estados'] ?? []);
$situacoes = (array)($data['situacoes'] ?? []);
$regRotulos= (array)($data['rotulos_reg'] ?? []);
$noticias  = (array)($data['noticias'] ?? []);

$form     = is_array($data['form'] ?? null) ? $data['form'] : null;
$exclusao = is_array($data['exclusao'] ?? null) ? $data['exclusao'] : null;

$agora    = time();
$temFiltro = ($filtro['q'] ?? '') !== '' || ($filtro['estado'] ?? '') !== '' || ($filtro['situacao'] ?? '') !== '';

/* =========================================================================================
   FORMATADORES E MONTADORES
   ========================================================================================= */

/** Minutos -> "2h 00m" (ou "45m"). Duração de janela nunca é escrita em minutos crus. */
$dur = static function (int $min): string {
    $h = intdiv($min, 60);
    $m = $min % 60;
    return $h > 0 ? sprintf('%dh %02dm', $h, $m) : $m . 'm';
};

/** "faltam 2d 04h" / "há 3 h" — o quanto falta (ou passou) para um instante. */
$conta = static function (int $delta): string {
    $s = abs($delta);
    $d = intdiv($s, 86400);
    $h = intdiv($s % 86400, 3600);
    $m = intdiv($s % 3600, 60);
    $txt = $d > 0 ? sprintf('%dd %02dh', $d, $h) : ($h > 0 ? sprintf('%02dh %02dm', $h, $m) : $m . 'm');
    return ($delta >= 0 ? 'em ' : 'há ') . $txt;
};

/** Classe de badge por estado efetivo. Cada estado tem cor PRÓPRIA — e texto junto. */
$badge = static fn(string $estado): string => match ($estado) {
    'online'     => 'adm-badge--ok',
    'instavel'   => 'adm-badge--warn',
    'fora'       => 'adm-badge--danger',
    'manutencao' => 'adm-badge--info',
    'agendada'   => 'adm-badge--purple',
    'ativa'      => 'adm-badge--info',
    'encerrada'  => 'adm-badge--muted',
    default      => 'adm-badge--muted',
};

/** URL desta tela preservando os filtros correntes. @param array<string,mixed> $mais */
$aqui = static function (array $mais = [], array $remove = []) use ($filtro): string {
    $q = $filtro;
    foreach ($remove as $k) { unset($q[$k]); }
    foreach ($mais as $k => $v) {
        if ($v === null) { unset($q[$k]); } else { $q[$k] = (string)$v; }
    }
    return url('admin/servidores', $q);
};

/**
 * Cabeçalho de coluna que ordena. Clicar de novo na mesma coluna inverte o sentido, e a seta
 * mostra o sentido ATUAL — sem ela, a tabela reordena e ninguém sabe por quê.
 */
$th = static function (string $chave, string $rotulo, string $pCol, string $pDir, string $classe = '') use ($filtro, $aqui): string {
    $ativa = ($filtro[$pCol] ?? '') === $chave;
    $dir   = $ativa && ($filtro[$pDir] ?? 'asc') === 'asc' ? 'desc' : 'asc';
    $href  = $aqui([$pCol => $chave, $pDir => $dir]);
    $seta  = $ativa ? icon(($filtro[$pDir] ?? 'asc') === 'asc' ? 'arrow_upward' : 'arrow_downward', 'srv-th__ar') : '';
    return '<th class="' . e($classe) . ($ativa ? ' is-sorted' : '') . '">'
         . '<a class="srv-th" href="' . e($href) . '">' . e($rotulo) . $seta . '</a></th>';
};

/** Os campos escondidos que levam os filtros junto no POST — gravar não pode perder a lista. */
$filtrosOcultos = static function () use ($filtro): string {
    $h = '';
    foreach (['q' => 'f_q', 'estado' => 'f_estado', 'situacao' => 'f_situacao', 'jp' => 'f_jp'] as $k => $nome) {
        if (($filtro[$k] ?? '') !== '') {
            $h .= '<input type="hidden" name="' . e($nome) . '" value="' . e((string)$filtro[$k]) . '">';
        }
    }
    return $h;
};

/** Um campo do formulário, com rótulo, erro por campo e ajuda. */
$campo = static function (string $nome, string $rotulo, string $controle, string $ajuda = '', bool $obrig = false) use ($form): string {
    $erro = (string)($form['erros'][$nome] ?? '');
    return '<div class="adm-field' . ($erro !== '' ? ' is-invalid' : '') . '">'
         . '<label class="adm-label" for="f-' . e($nome) . '">' . e($rotulo)
         . ($obrig ? '<span class="adm-label__req">*</span>' : '') . '</label>'
         . $controle
         . ($erro !== '' ? '<p class="adm-error">' . icon('error') . '<span>' . e($erro) . '</span></p>' : '')
         . ($ajuda !== '' ? '<p class="adm-hint">' . e($ajuda) . '</p>' : '')
         . '</div>';
};

/** Valor corrente de um campo do formulário aberto. */
$v = static fn(string $nome): string => (string)($form['dados'][$nome] ?? '');

/** Valor de um campo de data/hora no formato que o <input datetime-local> aceita. */
$vdt = static fn(string $nome): string => str_replace(' ', 'T', mb_substr((string)($form['dados'][$nome] ?? ''), 0, 16));

$fTipo = (string)($form['tipo'] ?? '');
$fId   = (int)($form['id'] ?? 0);
?>

<div class="srv">

  <?php /* ============================================================ INDICADORES ==== */ ?>
  <section class="adm-grid adm-grid--4 srv-kpis" aria-label="Resumo da infraestrutura">
    <?php
      $prox = $resumo['proxima'] ?? null;
      $kpis = [
        [
          'ico' => 'public', 'tom' => ($resumo['regioes'] ?? 0) > 0 && ($resumo['regioes_ok'] ?? 0) === ($resumo['regioes'] ?? 0) ? 'lime' : 'amber',
          'lbl' => 'Regiões online',
          'val' => ($resumo['regioes'] ?? 0) > 0 ? num((int)$resumo['regioes_ok']) . '<small>/' . num((int)$resumo['regioes']) . '</small>' : '—',
          'sub' => 'estado efetivo, com janela ativa contando',
        ],
        [
          'ico' => 'dns', 'tom' => ($resumo['servicos'] ?? 0) > 0 && ($resumo['servicos_ok'] ?? 0) === ($resumo['servicos'] ?? 0) ? 'cyan' : 'amber',
          'lbl' => 'Serviços no ar',
          'val' => ($resumo['servicos'] ?? 0) > 0 ? num((int)$resumo['servicos_ok']) . '<small>/' . num((int)$resumo['servicos']) . '</small>' : '—',
          'sub' => 'coluna state de server_services',
        ],
        [
          'ico' => 'network_ping', 'tom' => 'purple',
          'lbl' => 'Ping médio declarado',
          'val' => ($resumo['regioes'] ?? 0) > 0 ? num((int)$resumo['ping_medio']) . '<small>ms</small>' : '—',
          'sub' => (string)($micro['semente'] ?? ''),
        ],
        [
          'ico' => 'schedule', 'tom' => $prox !== null && $prox['ativa'] ? 'red' : 'gold',
          'lbl' => $prox !== null && $prox['ativa'] ? 'Janela em andamento' : 'Próxima janela',
          'val' => $prox === null ? '—' : e(date('d/m H:i', (int)$prox['inicio'])),
          'sub' => $prox === null
              ? num((int)($resumo['janelas_total'] ?? 0)) . ' janela(s) no histórico'
              : $prox['regiao'] . ' · ' . $conta((int)$prox['inicio'] - $agora),
        ],
      ];
    ?>
    <?php foreach ($kpis as $k): ?>
      <div class="adm-card adm-kpi adm-kpi--<?= e($k['tom']) ?>">
        <span class="adm-kpi__ico"><?= icon($k['ico']) ?></span>
        <span class="adm-kpi__txt">
          <span class="adm-kpi__label"><?= e($k['lbl']) ?></span>
          <span class="adm-kpi__value num"><?= $k['val'] ?></span>
          <span class="srv-kpi__sub"><?= e($k['sub']) ?></span>
        </span>
      </div>
    <?php endforeach ?>
  </section>

  <?php /* ==================================================== CONFIRMAR EXCLUSÃO ==== */ ?>
  <?php if ($exclusao !== null): ?>
    <section class="adm-card srv-danger" id="adm-srv-excluir">
      <div class="adm-card__head">
        <h2 class="adm-card__title"><?= icon('delete_forever') ?> <?= e((string)($cDel['titulo'] ?? 'Confirmar exclusão')) ?></h2>
        <div class="adm-card__tools">
          <a class="adm-card__link" href="<?= e($aqui([], ['excluir'])) ?>">Cancelar<?= icon('close') ?></a>
        </div>
      </div>
      <div class="adm-card__body srv-danger__body">
        <p class="srv-danger__what">
          Você está prestes a excluir
          <strong><?= e(['regiao' => 'a região', 'servico' => 'o serviço', 'janela' => 'a janela'][$exclusao['tipo']] ?? 'o registro') ?>
          <?= e((string)$exclusao['rotulo']) ?></strong>.
        </p>
        <dl class="adm-dl srv-danger__dl">
          <?php foreach ((array)$exclusao['detalhes'] as $rot => $val): ?>
            <dt><?= e((string)$rot) ?></dt><dd><?= e((string)$val) ?></dd>
          <?php endforeach ?>
        </dl>

        <?php if (($exclusao['bloqueio'] ?? null) !== null): ?>
          <?php /* Bloqueio não é um "não pode" seco: diz o número, o motivo e para onde ir. */ ?>
          <p class="srv-block"><?= icon('block') ?><span><?= e((string)$exclusao['bloqueio']) ?></span></p>
          <div class="adm-form__actions">
            <a class="adm-btn adm-btn--ghost" href="<?= e($aqui(['situacao' => null], ['excluir'])) ?>#srv-janelas"><?= icon('event') ?><span>Ver as janelas</span></a>
            <a class="adm-btn" href="<?= e($aqui([], ['excluir'])) ?>">Voltar</a>
          </div>
        <?php else: ?>
          <p class="adm-hint"><?= e((string)($cDel['aviso'] ?? '')) ?></p>
          <form class="adm-form__actions" method="post" action="<?= e(url('admin/servidores/excluir')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="tipo" value="<?= e((string)$exclusao['tipo']) ?>">
            <input type="hidden" name="id" value="<?= e((string)$exclusao['id']) ?>">
            <?= $filtrosOcultos() ?>
            <button class="adm-btn adm-btn--danger" type="submit"><?= icon('delete') ?><span>Excluir definitivamente</span></button>
            <a class="adm-btn adm-btn--ghost" href="<?= e($aqui([], ['excluir'])) ?>">Cancelar</a>
          </form>
        <?php endif ?>
      </div>
    </section>
  <?php endif ?>

  <?php /* ============================================================ FORMULÁRIO ==== */ ?>
  <?php if ($form !== null): ?>
    <?php
      $cf     = (array)($cForm[$fTipo] ?? []);
      $ajuda  = (array)($cf['ajuda'] ?? []);
      $tituloF= (string)($fId > 0 ? ($cf['editar'] ?? 'Editar') : ($cf['novo'] ?? 'Novo'));
      $temErro= ($form['erros'] ?? []) !== [];
    ?>
    <section class="adm-card srv-form<?= $temErro ? ' is-invalid' : '' ?>" id="adm-srv-form">
      <div class="adm-card__head">
        <h2 class="adm-card__title">
          <?= icon(['regiao' => 'public', 'servico' => 'dns', 'janela' => 'schedule'][$fTipo] ?? 'edit') ?>
          <?= e($tituloF) ?>
          <?php if ($fId > 0): ?><small>registro #<?= e((string)$fId) ?></small><?php endif ?>
        </h2>
        <div class="adm-card__tools">
          <a class="adm-card__link" href="<?= e($aqui([], ['novo', 'editar'])) ?>">Fechar<?= icon('close') ?></a>
        </div>
      </div>
      <div class="adm-card__body">
        <?php if ($temErro): ?>
          <p class="srv-block srv-block--warn"><?= icon('error') ?><span>
            <?= e(count((array)$form['erros'])) ?> campo(s) impedem a gravação. Nada foi alterado no banco.
          </span></p>
        <?php endif ?>

        <form class="adm-form" method="post" action="<?= e(url('admin/servidores/salvar')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="tipo" value="<?= e($fTipo) ?>">
          <input type="hidden" name="id" value="<?= e((string)$fId) ?>">
          <?= $filtrosOcultos() ?>

          <?php if ($fTipo === 'regiao'): ?>
            <div class="adm-form__row">
              <?= $campo('label', 'Nome da região',
                    '<input class="adm-input" id="f-label" name="label" maxlength="48" required value="' . e($v('label')) . '">',
                    (string)($ajuda['label'] ?? ''), true) ?>
              <?= $campo('slug', 'Identificador',
                    '<input class="adm-input" id="f-slug" name="slug" maxlength="24" placeholder="gerado do nome" value="' . e($v('slug')) . '">',
                    (string)($ajuda['slug'] ?? '')) ?>
            </div>
            <div class="adm-form__row adm-form__row--3">
              <?= $campo('ping_ms', 'Ping (ms)',
                    '<input class="adm-input num" id="f-ping_ms" name="ping_ms" inputmode="numeric" required value="' . e($v('ping_ms')) . '">',
                    (string)($ajuda['ping'] ?? ''), true) ?>
              <?= $campo('players', 'Jogadores',
                    '<input class="adm-input num" id="f-players" name="players" inputmode="numeric" required value="' . e($v('players')) . '">',
                    (string)($ajuda['players'] ?? ''), true) ?>
              <?= $campo('sort_order', 'Ordem',
                    '<input class="adm-input num" id="f-sort_order" name="sort_order" inputmode="numeric" value="' . e($v('sort_order')) . '">',
                    (string)($ajuda['ordem'] ?? '')) ?>
            </div>
            <?php
              $opts = '';
              foreach ($estados as $k => $rot) {
                  $opts .= '<option value="' . e($k) . '"' . ($v('state') === $k ? ' selected' : '') . '>' . e($rot) . '</option>';
              }
            ?>
            <div class="adm-form__row">
              <?= $campo('state', 'Estado', '<select class="adm-select" id="f-state" name="state">' . $opts . '</select>',
                    'Manutenção de região não se declara aqui: ela vem da janela agendada.', true) ?>
              <div class="adm-field srv-field--note">
                <span class="adm-label">O que isto altera</span>
                <p class="adm-hint"><?= e((string)($alcance['regioes'] ?? '')) ?></p>
              </div>
            </div>

          <?php elseif ($fTipo === 'servico'): ?>
            <div class="adm-form__row">
              <?= $campo('label', 'Nome do serviço',
                    '<input class="adm-input" id="f-label" name="label" maxlength="48" required value="' . e($v('label')) . '">',
                    '', true) ?>
              <?= $campo('slug', 'Identificador',
                    '<input class="adm-input" id="f-slug" name="slug" maxlength="24" placeholder="gerado do nome" value="' . e($v('slug')) . '">') ?>
            </div>
            <?php
              $opts = '';
              foreach ($estados as $k => $rot) {
                  $opts .= '<option value="' . e($k) . '"' . ($v('state') === $k ? ' selected' : '') . '>' . e($rot) . '</option>';
              }
            ?>
            <div class="adm-form__row adm-form__row--3">
              <?= $campo('state', 'Estado', '<select class="adm-select" id="f-state" name="state">' . $opts . '</select>', '', true) ?>
              <?= $campo('status_label', 'Rótulo completo',
                    '<input class="adm-input" id="f-status_label" name="status_label" maxlength="32" required value="' . e($v('status_label')) . '">',
                    (string)($ajuda['status'] ?? ''), true) ?>
              <?= $campo('short_label', 'Rótulo curto',
                    '<input class="adm-input" id="f-short_label" name="short_label" maxlength="24" required value="' . e($v('short_label')) . '">',
                    (string)($ajuda['curto'] ?? ''), true) ?>
            </div>
            <div class="adm-form__row adm-form__row--3">
              <?= $campo('maint_scope', 'Escopo da parada',
                    '<input class="adm-input" id="f-maint_scope" name="maint_scope" maxlength="48" value="' . e($v('maint_scope')) . '">',
                    (string)($ajuda['escopo'] ?? '')) ?>
              <?= $campo('maint_starts_at', 'Início da janela',
                    '<input class="adm-input" id="f-maint_starts_at" name="maint_starts_at" type="datetime-local" value="' . e($vdt('maint_starts_at')) . '">',
                    (string)($ajuda['janela'] ?? '')) ?>
              <?= $campo('maint_minutes', 'Duração (min)',
                    '<input class="adm-input num" id="f-maint_minutes" name="maint_minutes" inputmode="numeric" value="' . e($v('maint_minutes')) . '">') ?>
            </div>
            <div class="adm-form__row">
              <?= $campo('sort_order', 'Ordem',
                    '<input class="adm-input num" id="f-sort_order" name="sort_order" inputmode="numeric" value="' . e($v('sort_order')) . '">') ?>
              <div class="adm-field srv-field--note">
                <span class="adm-label">O que isto altera</span>
                <p class="adm-hint"><?= e((string)($alcance['servicos'] ?? '')) ?></p>
              </div>
            </div>

          <?php else: ?>
            <?php
              $optsReg = '';
              foreach ($regRotulos as $rot) {
                  $optsReg .= '<option value="' . e($rot) . '"' . ($v('region') === $rot ? ' selected' : '') . '>' . e($rot) . '</option>';
              }
              $optsNews = '<option value="">— nenhuma —</option>';
              foreach ($noticias as $n) {
                  $optsNews .= '<option value="' . e((string)$n['slug']) . '"' . ($v('news_slug') === (string)$n['slug'] ? ' selected' : '') . '>'
                             . e((string)$n['title']) . '</option>';
              }
            ?>
            <?php if ($regRotulos === []): ?>
              <p class="srv-block srv-block--warn"><?= icon('warning') ?><span>Não há região cadastrada: uma janela precisa apontar para uma.</span></p>
            <?php endif ?>
            <div class="adm-form__row adm-form__row--3">
              <?= $campo('starts_at', 'Início',
                    '<input class="adm-input" id="f-starts_at" name="starts_at" type="datetime-local" required value="' . e($vdt('starts_at')) . '">',
                    '', true) ?>
              <?= $campo('ends_at', 'Fim',
                    '<input class="adm-input" id="f-ends_at" name="ends_at" type="datetime-local" required value="' . e($vdt('ends_at')) . '">',
                    '', true) ?>
              <?= $campo('region', 'Região',
                    '<select class="adm-select" id="f-region" name="region">' . $optsReg . '</select>',
                    (string)($ajuda['regiao'] ?? ''), true) ?>
            </div>
            <div class="adm-form__row">
              <?= $campo('note', 'Nota para o jogador',
                    '<input class="adm-input" id="f-note" name="note" maxlength="240" value="' . e($v('note')) . '">',
                    (string)($ajuda['nota'] ?? '')) ?>
              <?= $campo('news_slug', 'Notícia relacionada',
                    '<select class="adm-select" id="f-news_slug" name="news_slug">' . $optsNews . '</select>',
                    (string)($ajuda['noticia'] ?? '')) ?>
            </div>
            <p class="adm-hint"><?= e((string)($alcance['janelas'] ?? '')) ?></p>
          <?php endif ?>

          <div class="adm-form__actions">
            <button class="adm-btn adm-btn--primary" type="submit"><?= icon('save') ?><span><?= $fId > 0 ? 'Salvar alterações' : 'Criar' ?></span></button>
            <a class="adm-btn adm-btn--ghost" href="<?= e($aqui([], ['novo', 'editar'])) ?>">Cancelar</a>
            <?php if ($fId > 0): ?>
              <a class="adm-btn adm-btn--danger adm-spacer" href="<?= e($aqui(['excluir' => $fTipo . ':' . $fId], ['novo', 'editar'])) ?>#adm-srv-excluir"><?= icon('delete') ?><span>Excluir</span></a>
            <?php endif ?>
          </div>
        </form>
      </div>
    </section>
  <?php endif ?>

  <?php /* =============================================================== FILTROS ==== */ ?>
  <?php /* Região da lista (App\Core\Fragmento). Aqui ela cobre a barra de filtros E os três
           blocos (regiões, serviços, janelas): o mesmo filtro recorta os três, e trocar só
           um deixaria os outros dois mostrando o recorte anterior. */ ?>
  <!--eh:lista-->
  <section class="adm-card srv-filtros" aria-label="Busca e filtros">
    <?php /* data-adm-preservar: transplantado para dentro do HTML novo em vez de
             substituído — é onde está o cursor de quem digita. */ ?>
    <form class="adm-toolbar" method="get" action="<?= e(url('admin/servidores')) ?>"
          data-adm-preservar="filtros">
      <div class="adm-toolbar__grow">
        <label class="adm-search">
          <?= icon('search') ?>
          <input class="adm-input" type="search" name="q" value="<?= e((string)($filtro['q'] ?? '')) ?>"
                 placeholder="Buscar região, serviço, escopo ou nota" aria-label="Buscar">
        </label>
      </div>

      <label class="srv-sel">
        <span class="adm-label">Estado</span>
        <select class="adm-select" name="estado">
          <option value="">Todos</option>
          <?php foreach ($estados as $k => $rot): ?>
            <option value="<?= e($k) ?>"<?= ($filtro['estado'] ?? '') === $k ? ' selected' : '' ?>><?= e($rot) ?></option>
          <?php endforeach ?>
        </select>
      </label>

      <label class="srv-sel">
        <span class="adm-label">Janelas</span>
        <select class="adm-select" name="situacao">
          <option value="">Todas</option>
          <?php foreach ($situacoes as $k => $rot): ?>
            <option value="<?= e($k) ?>"<?= ($filtro['situacao'] ?? '') === $k ? ' selected' : '' ?>><?= e($rot) ?></option>
          <?php endforeach ?>
        </select>
      </label>

      <?php /* A ordenação corrente viaja junto: filtrar não pode desfazer a ordenação. */ ?>
      <?php foreach (['ord', 'dir', 'sord', 'sdir'] as $k): ?>
        <?php if (($filtro[$k] ?? '') !== ''): ?>
          <input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$filtro[$k]) ?>">
        <?php endif ?>
      <?php endforeach ?>

      <button class="adm-btn" type="submit"><?= icon('filter_alt') ?><span>Filtrar</span></button>
      <?php if ($temFiltro): ?>
        <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/servidores')) ?>"><?= icon('filter_alt_off') ?><span>Limpar</span></a>
      <?php endif ?>
    </form>
  </section>

  <?php /* =============================================================== REGIÕES ==== */ ?>
  <section class="adm-card srv-bloco" id="srv-regioes">
    <div class="adm-card__head">
      <h2 class="adm-card__title"><?= icon('public') ?> <?= e((string)($titulos['regioes'] ?? 'Regiões')) ?>
        <small><?= e((string)($subtits['regioes'] ?? '')) ?></small>
      </h2>
      <div class="adm-card__tools">
        <span class="srv-count num"><?= e(num(count($regioes))) ?></span>
        <a class="adm-card__link" href="<?= e($aqui(['novo' => 'regiao'], ['editar', 'excluir'])) ?>#adm-srv-form">Nova região<?= icon('add') ?></a>
      </div>
    </div>
    <p class="srv-scope"><?= icon('info') ?><span><?= e((string)($alcance['regioes'] ?? '')) ?></span></p>

    <?php if ($regioes === []): ?>
      <div class="adm-card__body">
        <div class="adm-empty">
          <?= icon($temFiltro ? 'search_off' : 'public_off') ?>
          <h3 class="adm-empty__title"><?= $temFiltro ? 'Nada encontrado' : 'Sem regiões' ?></h3>
          <p class="adm-empty__txt"><?= e((string)($vazios[$temFiltro ? 'regioes_filtro' : 'regioes'] ?? '')) ?></p>
          <?php if ($temFiltro): ?>
            <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/servidores')) ?>">Limpar filtros</a>
          <?php else: ?>
            <a class="adm-btn adm-btn--primary" href="<?= e(url('admin/servidores', ['novo' => 'regiao'])) ?>#adm-srv-form"><?= icon('add') ?><span>Cadastrar a primeira</span></a>
          <?php endif ?>
        </div>
      </div>
    <?php else: ?>
      <div class="adm-tablewrap">
        <table class="adm-table srv-table srv-table--reg">
          <thead>
            <tr>
              <?= $th('ordem', '#', 'ord', 'dir', 'srv-c-ord tr') ?>
              <?= $th('regiao', 'Região', 'ord', 'dir', 'srv-c-nome') ?>
              <?= $th('ping', 'Ping', 'ord', 'dir', 'srv-c-num tr') ?>
              <?= $th('jogadores', 'Jogadores', 'ord', 'dir', 'srv-c-num tr') ?>
              <th class="srv-c-ocup">Ocupação</th>
              <?= $th('estado', 'Estado', 'ord', 'dir', 'srv-c-estado') ?>
              <th class="srv-c-troca" title="<?= e((string)($micro['estado_titulo'] ?? '')) ?>"><?= e((string)($micro['estado_col'] ?? 'Trocar')) ?></th>
              <th class="adm-table__acts">Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($regioes as $r): ?>
              <tr>
                <td class="srv-c-ord tr num"><?= e((string)$r['ordem']) ?></td>
                <td class="srv-c-nome">
                  <span class="srv-nome">
                    <strong><?= e((string)$r['label']) ?></strong>
                    <small class="mono"><?= e((string)$r['slug']) ?></small>
                  </span>
                </td>
                <td class="srv-c-num tr num"><?= e(num((int)$r['ping'])) ?><small><?= e((string)($micro['ping_unidade'] ?? 'ms')) ?></small></td>
                <td class="srv-c-num tr num"><?= e(num((int)$r['jogadores'])) ?></td>
                <td class="srv-c-ocup">
                  <span class="srv-bar" title="<?= e((string)($micro['ocupacao'] ?? '')) ?>">
                    <span class="srv-bar__fill" style="width:<?= e((string)round(((float)$r['ocupacao']) * 100)) ?>%"></span>
                  </span>
                  <span class="srv-bar__pct num"><?= e((string)round(((float)$r['ocupacao']) * 100)) ?>%</span>
                </td>
                <td class="srv-c-estado">
                  <span class="adm-badge <?= e($badge((string)$r['estado'])) ?>"><?= e((string)$r['estado_rotulo']) ?></span>
                  <?php if (($r['nota'] ?? null) !== null): ?>
                    <small class="srv-nota"><?= e((string)$r['nota']) ?></small>
                  <?php endif ?>
                  <?php if (in_array($r['estado'], ['manutencao', 'agendada'], true) && $r['state'] !== 'ok'): ?>
                    <?php /* O estado efetivo veio da janela, mas a coluna gravada diz outra
                             coisa: mostrar as duas evita "consertar" o que não está quebrado. */ ?>
                    <small class="srv-nota">gravado: <?= e((string)($estados[$r['state']] ?? $r['state'])) ?></small>
                  <?php endif ?>
                </td>
                <td class="srv-c-troca">
                  <form class="srv-troca" method="post" action="<?= e(url('admin/servidores/salvar')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="tipo" value="regiao">
                    <input type="hidden" name="acao" value="estado">
                    <input type="hidden" name="id" value="<?= e((string)$r['id']) ?>">
                    <?= $filtrosOcultos() ?>
                    <?php foreach (['ok' => 'check_circle', 'warn' => 'warning', 'down' => 'cancel'] as $st => $ic): ?>
                      <button class="srv-troca__b srv-troca__b--<?= e($st) ?><?= $r['state'] === $st ? ' is-on' : '' ?>"
                              type="submit" name="state" value="<?= e($st) ?>"
                              title="<?= e((string)$estados[$st]) ?>"
                              aria-label="Marcar <?= e((string)$r['label']) ?> como <?= e((string)$estados[$st]) ?>"
                              <?= $r['state'] === $st ? 'aria-pressed="true"' : '' ?>><?= icon($ic) ?></button>
                    <?php endforeach ?>
                  </form>
                </td>
                <td class="adm-table__acts">
                  <a class="adm-btn adm-btn--sm adm-btn--icon" title="Editar <?= e((string)$r['label']) ?>"
                     href="<?= e($aqui(['editar' => 'regiao:' . $r['id']], ['novo', 'excluir'])) ?>#adm-srv-form"><?= icon('edit') ?></a>
                  <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--danger" title="Excluir <?= e((string)$r['label']) ?>"
                     href="<?= e($aqui(['excluir' => 'regiao:' . $r['id']], ['novo', 'editar'])) ?>#adm-srv-excluir"><?= icon('delete') ?></a>
                </td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
    <?php endif ?>
  </section>

  <?php /* ============================================================== SERVIÇOS ==== */ ?>
  <section class="adm-card srv-bloco" id="srv-servicos">
    <div class="adm-card__head">
      <h2 class="adm-card__title"><?= icon('dns') ?> <?= e((string)($titulos['servicos'] ?? 'Serviços')) ?>
        <small><?= e((string)($subtits['servicos'] ?? '')) ?></small>
      </h2>
      <div class="adm-card__tools">
        <span class="srv-count num"><?= e(num(count($servicos))) ?></span>
        <a class="adm-card__link" href="<?= e($aqui(['novo' => 'servico'], ['editar', 'excluir'])) ?>#adm-srv-form">Novo serviço<?= icon('add') ?></a>
      </div>
    </div>
    <p class="srv-scope"><?= icon('info') ?><span><?= e((string)($alcance['servicos'] ?? '')) ?></span></p>

    <?php if ($servicos === []): ?>
      <div class="adm-card__body">
        <div class="adm-empty">
          <?= icon($temFiltro ? 'search_off' : 'dns') ?>
          <h3 class="adm-empty__title"><?= $temFiltro ? 'Nada encontrado' : 'Sem serviços' ?></h3>
          <p class="adm-empty__txt"><?= e((string)($vazios[$temFiltro ? 'servicos_filtro' : 'servicos'] ?? '')) ?></p>
          <?php if ($temFiltro): ?>
            <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/servidores')) ?>">Limpar filtros</a>
          <?php else: ?>
            <a class="adm-btn adm-btn--primary" href="<?= e(url('admin/servidores', ['novo' => 'servico'])) ?>#adm-srv-form"><?= icon('add') ?><span>Cadastrar o primeiro</span></a>
          <?php endif ?>
        </div>
      </div>
    <?php else: ?>
      <div class="adm-tablewrap">
        <table class="adm-table srv-table srv-table--srv">
          <thead>
            <tr>
              <?= $th('ordem', '#', 'sord', 'sdir', 'srv-c-ord tr') ?>
              <?= $th('servico', 'Serviço', 'sord', 'sdir', 'srv-c-nome') ?>
              <?= $th('estado', 'Estado', 'sord', 'sdir', 'srv-c-estado') ?>
              <th class="srv-c-rot">Rótulo exibido</th>
              <th class="srv-c-escopo">Escopo</th>
              <?= $th('janela', 'Janela anunciada', 'sord', 'sdir', 'srv-c-janela') ?>
              <th class="srv-c-troca" title="<?= e((string)($micro['estado_titulo'] ?? '')) ?>"><?= e((string)($micro['estado_col'] ?? 'Trocar')) ?></th>
              <th class="adm-table__acts">Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($servicos as $s): ?>
              <tr<?= $s['no_contador'] ? ' class="is-live"' : '' ?>>
                <td class="srv-c-ord tr num"><?= e((string)$s['ordem']) ?></td>
                <td class="srv-c-nome">
                  <span class="srv-nome">
                    <strong><?= e((string)$s['label']) ?></strong>
                    <small class="mono"><?= e((string)$s['slug']) ?></small>
                  </span>
                </td>
                <td class="srv-c-estado">
                  <span class="adm-badge <?= e($badge((string)$s['estado'])) ?>"><?= e((string)($estados[$s['state']] ?? $s['state'])) ?></span>
                </td>
                <td class="srv-c-rot">
                  <span class="srv-nome">
                    <strong><?= e((string)$s['rotulo']) ?></strong>
                    <small>curto: <?= e((string)$s['curto']) ?></small>
                  </span>
                </td>
                <td class="srv-c-escopo"><?= $s['escopo'] !== null ? e((string)$s['escopo']) : '<span class="srv-vazio">—</span>' ?></td>
                <td class="srv-c-janela">
                  <?php if ($s['inicio'] > 0): ?>
                    <span class="srv-nome">
                      <strong><?= e(date('d/m/Y H:i', (int)$s['inicio'])) ?></strong>
                      <small>
                        <?= $s['minutos'] !== null ? e($dur((int)$s['minutos'])) : 'sem duração' ?>
                        · <?= e($conta((int)$s['faltam'])) ?>
                      </small>
                    </span>
                    <?php if ($s['no_contador']): ?>
                      <span class="srv-tag" title="<?= e((string)($micro['no_contador'] ?? '')) ?>"><?= icon('bolt') ?>no ar</span>
                    <?php endif ?>
                  <?php else: ?>
                    <span class="srv-vazio">—</span>
                  <?php endif ?>
                </td>
                <td class="srv-c-troca">
                  <form class="srv-troca" method="post" action="<?= e(url('admin/servidores/salvar')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="tipo" value="servico">
                    <input type="hidden" name="acao" value="estado">
                    <input type="hidden" name="id" value="<?= e((string)$s['id']) ?>">
                    <?= $filtrosOcultos() ?>
                    <?php foreach (['ok' => 'check_circle', 'warn' => 'warning', 'down' => 'cancel'] as $st => $ic): ?>
                      <button class="srv-troca__b srv-troca__b--<?= e($st) ?><?= $s['state'] === $st ? ' is-on' : '' ?>"
                              type="submit" name="state" value="<?= e($st) ?>"
                              title="<?= e((string)$estados[$st]) ?>"
                              aria-label="Marcar <?= e((string)$s['label']) ?> como <?= e((string)$estados[$st]) ?>"
                              <?= $s['state'] === $st ? 'aria-pressed="true"' : '' ?>><?= icon($ic) ?></button>
                    <?php endforeach ?>
                  </form>
                </td>
                <td class="adm-table__acts">
                  <a class="adm-btn adm-btn--sm adm-btn--icon" title="Editar <?= e((string)$s['label']) ?>"
                     href="<?= e($aqui(['editar' => 'servico:' . $s['id']], ['novo', 'excluir'])) ?>#adm-srv-form"><?= icon('edit') ?></a>
                  <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--danger" title="Excluir <?= e((string)$s['label']) ?>"
                     href="<?= e($aqui(['excluir' => 'servico:' . $s['id']], ['novo', 'editar'])) ?>#adm-srv-excluir"><?= icon('delete') ?></a>
                </td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
    <?php endif ?>
  </section>

  <?php /* =============================================================== JANELAS ==== */ ?>
  <section class="adm-card srv-bloco" id="srv-janelas">
    <div class="adm-card__head">
      <h2 class="adm-card__title"><?= icon('schedule') ?> <?= e((string)($titulos['janelas'] ?? 'Janelas de manutenção')) ?>
        <small><?= e((string)($subtits['janelas'] ?? '')) ?></small>
      </h2>
      <div class="adm-card__tools">
        <span class="srv-count num"><?= e(num((int)$janelas['total'])) ?></span>
        <a class="adm-card__link" href="<?= e($aqui(['novo' => 'janela'], ['editar', 'excluir'])) ?>#adm-srv-form">Nova janela<?= icon('add') ?></a>
      </div>
    </div>
    <p class="srv-scope"><?= icon('info') ?><span><?= e((string)($alcance['janelas'] ?? '')) ?></span></p>

    <?php if ((array)$janelas['linhas'] === []): ?>
      <div class="adm-card__body">
        <div class="adm-empty">
          <?= icon(($temFiltro || ($filtro['situacao'] ?? '') !== '') ? 'search_off' : 'event_busy') ?>
          <h3 class="adm-empty__title"><?= ($temFiltro || ($filtro['situacao'] ?? '') !== '') ? 'Nada encontrado' : 'Sem janelas' ?></h3>
          <p class="adm-empty__txt">
            <?= e((string)($vazios[($temFiltro || ($filtro['situacao'] ?? '') !== '') ? 'janelas_filtro' : 'janelas'] ?? '')) ?>
          </p>
          <?php if ($temFiltro): ?>
            <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/servidores')) ?>">Limpar filtros</a>
          <?php else: ?>
            <a class="adm-btn adm-btn--primary" href="<?= e(url('admin/servidores', ['novo' => 'janela'])) ?>#adm-srv-form"><?= icon('add') ?><span>Agendar a primeira</span></a>
          <?php endif ?>
        </div>
      </div>
    <?php else: ?>
      <div class="adm-tablewrap">
        <table class="adm-table srv-table srv-table--jan">
          <thead>
            <tr>
              <th class="srv-c-quando">Início</th>
              <th class="srv-c-quando">Fim</th>
              <th class="srv-c-num tr">Duração</th>
              <th class="srv-c-nome">Região</th>
              <th class="srv-c-estado">Situação</th>
              <th class="srv-c-nota">Nota / notícia</th>
              <th class="adm-table__acts">Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ((array)$janelas['linhas'] as $j): ?>
              <tr<?= $j['situacao'] === 'ativa' ? ' class="is-live"' : '' ?>>
                <td class="srv-c-quando">
                  <span class="srv-nome">
                    <strong><?= e(date('d/m/Y H:i', (int)$j['inicio'])) ?></strong>
                    <small><?= e($conta((int)$j['inicio'] - $agora)) ?></small>
                  </span>
                </td>
                <td class="srv-c-quando"><?= e(date('d/m/Y H:i', (int)$j['fim'])) ?></td>
                <td class="srv-c-num tr num"><?= e($dur((int)$j['minutos'])) ?></td>
                <td class="srv-c-nome">
                  <span class="srv-nome">
                    <strong><?= e((string)$j['regiao']) ?></strong>
                    <?php if ($j['orfa']): ?>
                      <small class="srv-orfa" title="<?= e((string)($micro['orfa'] ?? '')) ?>"><?= icon('link_off') ?>região inexistente</small>
                    <?php endif ?>
                  </span>
                </td>
                <td class="srv-c-estado">
                  <span class="adm-badge <?= e($badge((string)$j['situacao'])) ?>"><?= e((string)($situacoes[$j['situacao']] ?? $j['situacao'])) ?></span>
                </td>
                <td class="srv-c-nota">
                  <?php if ($j['nota'] !== null): ?><span class="srv-nota-txt"><?= e((string)$j['nota']) ?></span><?php endif ?>
                  <?php if ($j['noticia'] !== null): ?>
                    <small class="mono srv-slug"><?= icon('article') ?><?= e((string)$j['noticia']) ?></small>
                  <?php endif ?>
                  <?php if ($j['nota'] === null && $j['noticia'] === null): ?><span class="srv-vazio">—</span><?php endif ?>
                </td>
                <td class="adm-table__acts">
                  <a class="adm-btn adm-btn--sm adm-btn--icon" title="Editar janela"
                     href="<?= e($aqui(['editar' => 'janela:' . $j['id']], ['novo', 'excluir'])) ?>#adm-srv-form"><?= icon('edit') ?></a>
                  <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--danger" title="Excluir janela"
                     href="<?= e($aqui(['excluir' => 'janela:' . $j['id']], ['novo', 'editar'])) ?>#adm-srv-excluir"><?= icon('delete') ?></a>
                </td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>

      <?php if ((int)$janelas['paginas'] > 1 || (int)$janelas['total'] > 0): ?>
        <div class="adm-card__foot">
          <nav class="adm-pager" aria-label="Paginação das janelas">
            <span class="adm-pager__info">
              <?= e(num((int)$janelas['de'])) ?>–<?= e(num((int)$janelas['ate'])) ?> de <?= e(num((int)$janelas['total'])) ?> janela(s)
            </span>
            <?php if ((int)$janelas['paginas'] > 1): ?>
              <?php $pg = (int)$janelas['pagina']; $tot = (int)$janelas['paginas']; ?>
              <a class="adm-pager__btn<?= $pg <= 1 ? ' is-disabled' : '' ?>" aria-label="Página anterior"
                 href="<?= e($aqui(['jp' => max(1, $pg - 1)])) ?>#srv-janelas"><?= icon('chevron_left') ?></a>
              <?php for ($i = 1; $i <= $tot; $i++): ?>
                <?php if ($i === 1 || $i === $tot || abs($i - $pg) <= 1): ?>
                  <a class="adm-pager__btn<?= $i === $pg ? ' is-active' : '' ?>" href="<?= e($aqui(['jp' => $i])) ?>#srv-janelas"><?= e((string)$i) ?></a>
                <?php elseif (abs($i - $pg) === 2): ?>
                  <span class="adm-pager__gap">…</span>
                <?php endif ?>
              <?php endfor ?>
              <a class="adm-pager__btn<?= $pg >= $tot ? ' is-disabled' : '' ?>" aria-label="Próxima página"
                 href="<?= e($aqui(['jp' => min($tot, $pg + 1)])) ?>#srv-janelas"><?= icon('chevron_right') ?></a>
            <?php endif ?>
          </nav>
        </div>
      <?php endif ?>
    <?php endif ?>
  </section>
  <!--/eh:lista-->

</div>
