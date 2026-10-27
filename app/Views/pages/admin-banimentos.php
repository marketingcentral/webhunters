<?php declare(strict_types=1);

/**
 * Tela ADMIN · BANIMENTOS (/admin/banimentos).
 *
 * Escopo: $page, $data, $flash, $csrf. Layout: layouts/admin.php.
 *
 * A view NÃO consulta o banco e NÃO decide o que é permitido: as linhas vêm prontas de
 * App\Repo\BanRepo e as permissões chegam resolvidas de App\Controllers\Admin\BansController
 * ($data['pode_apagar'], $painel['bloqueio']). O que existe aqui é FORMATAÇÃO — data em
 * pt-BR, "faltam 3 dias", o texto do prazo — e a montagem dos endereços de filtro.
 *
 * POR QUE O FORMULÁRIO É ENDEREÇO, E NÃO MODAL
 * Punir e revogar são as duas ações irreversíveis do painel. Aqui a confirmação é uma URL
 * (?painel=revogar&id=12): dá para revisar antes de clicar, dá para voltar, dá para mandar o
 * link para outro operador — e continua funcionando com o JavaScript desligado, que é como
 * esta tela é conferida no `curl`. Nenhuma linha desta tela depende de script.
 *
 * REGRA DE LARGURA: sete colunas em 1366px. Todas as colunas estreitas são `nowrap` com
 * largura fixa e SÓ o motivo é elástico (com reticências em duas linhas) — é ele que absorve
 * a diferença entre um monitor e outro. Nada de rolagem horizontal.
 */

$cromo   = is_array($data['cromo'] ?? null) ? $data['cromo'] : [];
$colunas = (array)($cromo['colunas'] ?? []);
$eCromo  = (array)($cromo['estados'] ?? []);
$vazios  = (array)($cromo['vazios'] ?? []);

$lista     = (array)($data['lista'] ?? []);
$linhas    = (array)($lista['linhas'] ?? []);
$filtros   = (array)($data['filtros'] ?? []);
$contagens = (array)($data['contagens'] ?? []);
$tipos     = (array)($data['tipos'] ?? []);
$painel    = is_array($data['painel'] ?? null) ? $data['painel'] : null;
$form      = (array)($data['form'] ?? []);
$erros     = (array)($form['erros'] ?? []);
$valores   = (array)($form['valores'] ?? []);
$sugestoes = (array)($data['sugestoes'] ?? []);
$podeApagar= !empty($data['pode_apagar']);

$temFiltro = ($filtros['busca'] ?? '') !== '' || ($filtros['estado'] ?? '') !== '' || ($filtros['tipo'] ?? '') !== '';

/** Endereço desta tela com os filtros atuais, trocando só o que vier em $muda. */
$rota = static function (array $muda = []) use ($filtros): string {
    $q = array_filter([
        'q'      => (string)($filtros['busca'] ?? ''),
        'estado' => (string)($filtros['estado'] ?? ''),
        'tipo'   => (string)($filtros['tipo'] ?? ''),
        'ordem'  => ($filtros['ordem'] ?? 'recente') !== 'recente' ? (string)$filtros['ordem'] : '',
        'p'      => (int)($filtros['pagina'] ?? 1) > 1 ? (string)$filtros['pagina'] : '',
    ], static fn(string $v): bool => $v !== '');

    foreach ($muda as $k => $v) {
        if ($v === null || $v === '') { unset($q[$k]); } else { $q[$k] = (string)$v; }
    }
    return url('admin/banimentos', $q);
};

/** 2026-09-20 14:30:00 -> 20/09/2026 14:30. Traço quando não há data. */
$data_br = static function (mixed $v): string {
    $ts = $v === null || $v === '' ? false : strtotime((string)$v);
    return $ts === false ? '—' : date('d/m/Y H:i', $ts);
};

/**
 * Quanto falta (ou quanto passou) em relação a agora, em uma unidade só.
 * "faltam 3 dias" é o que o operador precisa; "faltam 3 dias, 4 horas e 12 minutos" não é.
 */
$distancia = static function (mixed $v): string {
    $ts = $v === null || $v === '' ? false : strtotime((string)$v);
    if ($ts === false) { return ''; }
    $s     = abs($ts - time());
    $passou = $ts < time();
    $u = match (true) {
        $s < 3600    => [max(1, (int)round($s / 60)), 'min'],
        $s < 86400   => [(int)round($s / 3600), 'h'],
        $s < 2592000 => [(int)round($s / 86400), 'd'],
        default      => [(int)round($s / 2592000), 'meses'],
    };
    return ($passou ? 'há ' : 'faltam ') . $u[0] . ' ' . $u[1];
};

/** Estado derivado -> rótulo + tom + ícone, tudo vindo de app/Data/admin-banimentos.php. */
$selo = static function (string $estado) use ($eCromo): string {
    $e = (array)($eCromo[$estado] ?? []);
    return '<span class="adm-badge adm-badge--' . e((string)($e['tom'] ?? 'muted'))
        . '" title="' . e((string)($e['ajuda'] ?? '')) . '">'
        . e((string)($e['rotulo'] ?? $estado)) . '</span>';
};

/** Campo em erro ganha a classe do sistema E a frase — cor sozinha não é mensagem. */
$erroDe = static function (string $campo) use ($erros): string {
    return isset($erros[$campo])
        ? '<span class="adm-error">' . icon('error') . '<span>' . e((string)$erros[$campo]) . '</span></span>'
        : '';
};

/** Valor que o operador digitou e o servidor recusou — o formulário reabre com ele. */
$antigo = static function (string $campo, string $padrao = '') use ($valores): string {
    return (string)($valores[$campo] ?? $padrao);
};

/* Os campos ocultos que levam os filtros atuais pelo POST: é assim que o operador volta
   exatamente para a linha em que estava depois de punir, revogar ou apagar. */
$ocultosFiltro = ''
    . '<input type="hidden" name="f_q" value="' . e((string)($filtros['busca'] ?? '')) . '">'
    . '<input type="hidden" name="f_estado" value="' . e((string)($filtros['estado'] ?? '')) . '">'
    . '<input type="hidden" name="f_tipo" value="' . e((string)($filtros['tipo'] ?? '')) . '">'
    . '<input type="hidden" name="f_ordem" value="' . e((string)($filtros['ordem'] ?? '')) . '">'
    . '<input type="hidden" name="f_p" value="' . e((string)($filtros['pagina'] ?? 1)) . '">';
?>
<div class="ban">

  <!-- ================================================================ 1. NÚMEROS -->
  <section class="adm-grid adm-grid--4 ban-kpis" aria-label="Resumo das punições">
    <?php foreach ((array)($cromo['kpis'] ?? []) as $kpi): ?>
      <?php
        $chave = (string)($kpi['chave'] ?? '');
        $tom   = preg_replace('/[^a-z]/', '', (string)($kpi['tom'] ?? 'lime')) ?: 'lime';
        $valor = (int)($contagens[$chave] ?? 0);
        /* O KPI é um FILTRO: clicar em "Prazos vencidos" mostra os vencidos. Só os três
           estados viram link — "contas fora do ar" conta contas, não linhas da tabela, e
           filtrar por ele daria um número diferente do que o cartão anuncia. */
        $filtravel = in_array($chave, ['ativo', 'expirado', 'revogado'], true);
        $ligado    = $filtravel && (string)($filtros['estado'] ?? '') === $chave;
      ?>
      <?php if ($filtravel): ?>
        <a class="adm-kpi adm-kpi--<?= e($tom) ?> ban-kpi<?= $ligado ? ' is-active' : '' ?>"
           href="<?= e($rota(['estado' => $ligado ? null : $chave, 'p' => null])) ?>"
           <?= $ligado ? 'aria-current="true"' : '' ?>>
      <?php else: ?>
        <div class="adm-kpi adm-kpi--<?= e($tom) ?> ban-kpi ban-kpi--fixo">
      <?php endif ?>
          <span class="adm-kpi__ico"><?= icon((string)($kpi['icone'] ?? 'gavel')) ?></span>
          <span class="adm-kpi__txt">
            <span class="adm-kpi__label"><?= e((string)($kpi['rotulo'] ?? '')) ?></span>
            <span class="adm-kpi__value num"><?= e(num($valor)) ?></span>
          </span>
          <?php if ($filtravel): ?>
            <span class="ban-kpi__acao"><?= $ligado ? 'filtrando' : 'filtrar' ?></span>
          <?php endif ?>
      <?php if ($filtravel): ?></a><?php else: ?></div><?php endif ?>
    <?php endforeach ?>
  </section>

  <!-- ================================================ 2. PAINEL DE AÇÃO (pela URL) -->
  <?php if ($painel !== null): ?>
    <?php $modo = (string)$painel['modo']; $alvo = $painel['ban']; ?>

    <?php /* ---------------------------------------------------- 2a. aplicar punição */ ?>
    <?php if ($modo === 'novo'): ?>
      <?php $f = (array)($cromo['form'] ?? []); ?>
      <section class="adm-card ban-painel ban-painel--novo" id="painel">
        <div class="adm-card__head">
          <h2 class="adm-card__title"><?= icon('gavel', 'ban-painel__ico') ?><?= e((string)($f['titulo'] ?? 'Aplicar punição')) ?>
            <small><?= e((string)($f['ajuda'] ?? '')) ?></small>
          </h2>
          <div class="adm-card__tools">
            <a class="adm-btn adm-btn--sm adm-btn--ghost" href="<?= e($rota()) ?>"><?= icon('close') ?><span>Fechar</span></a>
          </div>
        </div>
        <div class="adm-card__body">
          <form class="adm-form ban-form" method="post" action="<?= e(url('admin/banimentos/salvar')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="aplicar">
            <?= $ocultosFiltro ?>

            <div class="adm-form__row">
              <div class="adm-field<?= isset($erros['alvo']) ? ' is-invalid' : '' ?>">
                <label class="adm-label" for="ban-alvo"><?= e((string)($f['alvo']['rotulo'] ?? 'Conta')) ?><span class="adm-label__req">*</span></label>
                <input class="adm-input" id="ban-alvo" name="alvo" list="ban-contas" autocomplete="off"
                       maxlength="160" required
                       placeholder="usuário, e-mail ou ID"
                       value="<?= e($antigo('alvo')) ?>">
                <?php /* datalist é sugestão nativa: sem script, e o operador continua livre
                         para digitar um e-mail que não esteja na lista curta. */ ?>
                <datalist id="ban-contas">
                  <?php foreach ($sugestoes as $s): ?>
                    <option value="<?= e((string)$s['username']) ?>">#<?= e((string)$s['id']) ?> · <?= e((string)($s['email'] ?? '')) ?><?= (string)$s['role'] !== 'player' ? ' · ' . e((string)$s['role']) : '' ?></option>
                  <?php endforeach ?>
                </datalist>
                <?= $erroDe('alvo') ?: '<span class="adm-hint">' . e((string)($f['alvo']['ajuda'] ?? '')) . '</span>' ?>
              </div>

              <div class="adm-field ban-field--prazo<?= isset($erros['prazo']) ? ' is-invalid' : '' ?>">
                <label class="adm-label" for="ban-prazo"><?= e((string)($f['prazo']['rotulo'] ?? 'Termina em')) ?></label>
                <input class="adm-input" id="ban-prazo" name="prazo" type="datetime-local"
                       min="<?= e(date('Y-m-d\TH:i')) ?>"
                       value="<?= e($antigo('prazo')) ?>">
                <?= $erroDe('prazo') ?: '<span class="adm-hint">' . e((string)($f['prazo']['ajuda'] ?? '')) . '</span>' ?>
              </div>
            </div>

            <div class="adm-field<?= isset($erros['tipo']) ? ' is-invalid' : '' ?>">
              <span class="adm-label"><?= e((string)($f['tipo']['rotulo'] ?? 'Tipo de punição')) ?><span class="adm-label__req">*</span></span>
              <div class="ban-tipos" role="radiogroup" aria-label="<?= e((string)($f['tipo']['rotulo'] ?? 'Tipo')) ?>">
                <?php $tipoAtual = $antigo('tipo', 'temp'); ?>
                <?php foreach ($tipos as $chave => $t): ?>
                  <label class="ban-tipo ban-tipo--<?= e((string)$t['tom']) ?>" for="ban-tipo-<?= e((string)$chave) ?>">
                    <input type="radio" id="ban-tipo-<?= e((string)$chave) ?>" name="tipo"
                           value="<?= e((string)$chave) ?>"<?= $tipoAtual === (string)$chave ? ' checked' : '' ?>>
                    <span class="ban-tipo__box">
                      <strong><?= e((string)$t['rotulo']) ?></strong>
                      <small><?= empty($t['prazo']) ? 'Sem prazo — vale até alguém revogar.' : 'Acaba sozinho na data escolhida.' ?></small>
                      <em><?= (string)$t['status'] === 'suspended' ? 'users.status = suspended' : 'users.status = banned' ?></em>
                    </span>
                  </label>
                <?php endforeach ?>
              </div>
              <?= $erroDe('tipo') ?>
            </div>

            <div class="adm-field<?= isset($erros['motivo']) ? ' is-invalid' : '' ?>">
              <label class="adm-label" for="ban-motivo"><?= e((string)($f['motivo']['rotulo'] ?? 'Motivo')) ?><span class="adm-label__req">*</span></label>
              <textarea class="adm-textarea" id="ban-motivo" name="motivo" maxlength="500" required
                        placeholder="Ex.: uso de terceiros na Floresta Verde, confirmado por três denúncias e replay."><?= e($antigo('motivo')) ?></textarea>
              <?= $erroDe('motivo') ?: '<span class="adm-hint">' . e((string)($f['motivo']['ajuda'] ?? '')) . '</span>' ?>
            </div>

            <div class="adm-form__actions">
              <button class="adm-btn adm-btn--primary" type="submit"><?= icon('gavel') ?><span><?= e((string)($f['enviar'] ?? 'Aplicar punição')) ?></span></button>
              <a class="adm-btn adm-btn--ghost" href="<?= e($rota()) ?>"><?= e((string)($f['cancelar'] ?? 'Cancelar')) ?></a>
            </div>
          </form>
        </div>
      </section>

    <?php /* ---------------------------------------------------------- 2b. revogar */ ?>
    <?php elseif ($modo === 'revogar' && is_array($alvo)): ?>
      <?php $r = (array)($cromo['revogar'] ?? []); ?>
      <section class="adm-card ban-painel ban-painel--revogar" id="painel">
        <div class="adm-card__head">
          <h2 class="adm-card__title"><?= icon('undo', 'ban-painel__ico') ?><?= e((string)($r['titulo'] ?? 'Revogar punição')) ?>
            <small><?= e((string)($r['ajuda'] ?? '')) ?></small>
          </h2>
          <div class="adm-card__tools">
            <a class="adm-btn adm-btn--sm adm-btn--ghost" href="<?= e($rota()) ?>"><?= icon('close') ?><span>Fechar</span></a>
          </div>
        </div>
        <div class="adm-card__body ban-painel__corpo">
          <dl class="adm-dl ban-resumo">
            <dt>Registro</dt><dd class="num">#<?= e((string)$alvo['id']) ?></dd>
            <dt>Conta</dt><dd><strong><?= e((string)$alvo['alvo_nome']) ?></strong> <span class="adm-meta"><?= e((string)($alvo['alvo_email'] ?? '')) ?></span></dd>
            <dt>Tipo</dt><dd><?= e((string)($tipos[(string)$alvo['kind']]['rotulo'] ?? $alvo['kind'])) ?></dd>
            <dt>Aplicada</dt><dd><?= e($data_br($alvo['created_at'])) ?> por <?= e((string)($alvo['admin_nome'] ?? 'conta removida')) ?></dd>
            <dt>Prazo</dt><dd><?= $alvo['expires_at'] === null ? 'sem prazo' : e($data_br($alvo['expires_at']) . ' · ' . $distancia($alvo['expires_at'])) ?></dd>
            <dt>Motivo</dt><dd class="ban-resumo__motivo"><?= e((string)($alvo['reason'] ?? '')) ?: '<span class="adm-meta">sem motivo registrado</span>' ?></dd>
          </dl>

          <form class="adm-form ban-form" method="post" action="<?= e(url('admin/banimentos/salvar')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="revogar">
            <input type="hidden" name="id" value="<?= e((string)$alvo['id']) ?>">
            <?= $ocultosFiltro ?>

            <div class="adm-field<?= isset($erros['motivo']) ? ' is-invalid' : '' ?>">
              <label class="adm-label" for="ban-rev-motivo"><?= e((string)($r['motivo']['rotulo'] ?? 'Motivo da revogação')) ?><span class="adm-label__req">*</span></label>
              <textarea class="adm-textarea" id="ban-rev-motivo" name="motivo" maxlength="500" required
                        placeholder="Ex.: recurso aceito — o replay mostra falha de rede, não trapaça."><?= e($antigo('motivo')) ?></textarea>
              <?= $erroDe('motivo') ?: '<span class="adm-hint">' . e((string)($r['motivo']['ajuda'] ?? '')) . '</span>' ?>
            </div>

            <div class="adm-form__actions">
              <button class="adm-btn adm-btn--primary" type="submit"><?= icon('lock_open') ?><span><?= e((string)($r['enviar'] ?? 'Revogar agora')) ?></span></button>
              <a class="adm-btn adm-btn--ghost" href="<?= e($rota()) ?>"><?= e((string)($r['cancelar'] ?? 'Manter punição')) ?></a>
            </div>
          </form>
        </div>
      </section>

    <?php /* ----------------------------------------------------------- 2c. apagar */ ?>
    <?php elseif ($modo === 'remover' && is_array($alvo)): ?>
      <?php $x = (array)($cromo['remover'] ?? []); $trava = (string)$painel['bloqueio']; ?>
      <section class="adm-card ban-painel ban-painel--remover" id="painel">
        <div class="adm-card__head">
          <h2 class="adm-card__title"><?= icon('delete_forever', 'ban-painel__ico') ?><?= e((string)($x['titulo'] ?? 'Apagar do histórico')) ?>
            <small><?= e((string)($x['ajuda'] ?? '')) ?></small>
          </h2>
          <div class="adm-card__tools">
            <a class="adm-btn adm-btn--sm adm-btn--ghost" href="<?= e($rota()) ?>"><?= icon('close') ?><span>Fechar</span></a>
          </div>
        </div>
        <div class="adm-card__body ban-painel__corpo">
          <dl class="adm-dl ban-resumo">
            <dt>Registro</dt><dd class="num">#<?= e((string)$alvo['id']) ?></dd>
            <dt>Conta</dt><dd><strong><?= e((string)$alvo['alvo_nome']) ?></strong></dd>
            <dt>Estado</dt><dd><?= $selo((string)$alvo['estado']) ?></dd>
            <dt>Motivo</dt><dd class="ban-resumo__motivo"><?= e((string)($alvo['reason'] ?? '')) ?></dd>
          </dl>

          <?php if ($trava !== ''): ?>
            <p class="ban-trava"><?= icon('block') ?><span><?= e($trava) ?></span></p>
            <div class="adm-form__actions">
              <?php if ((string)$alvo['estado'] === 'ativo' && $podeApagar): ?>
                <a class="adm-btn adm-btn--primary" href="<?= e($rota(['painel' => 'revogar', 'id' => (string)$alvo['id']])) ?>"><?= icon('undo') ?><span>Revogar primeiro</span></a>
              <?php endif ?>
              <a class="adm-btn adm-btn--ghost" href="<?= e($rota()) ?>"><?= e((string)($x['cancelar'] ?? 'Manter registro')) ?></a>
            </div>
          <?php else: ?>
            <form class="adm-form" method="post" action="<?= e(url('admin/banimentos/excluir')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= e((string)$alvo['id']) ?>">
              <?= $ocultosFiltro ?>
              <div class="adm-form__actions">
                <button class="adm-btn adm-btn--danger" type="submit"><?= icon('delete_forever') ?><span><?= e((string)($x['enviar'] ?? 'Apagar definitivamente')) ?></span></button>
                <a class="adm-btn adm-btn--ghost" href="<?= e($rota()) ?>"><?= e((string)($x['cancelar'] ?? 'Manter registro')) ?></a>
              </div>
            </form>
          <?php endif ?>
        </div>
      </section>
    <?php endif ?>
  <?php endif ?>

  <!-- ================================================================ 3. HISTÓRICO -->
  <?php /* Região da lista (App\Core\Fragmento): o pedaço que volta do servidor quando o
           filtro é aplicado sem recarregar. Os cartões de cima ficam de fora DE PROPÓSITO —
           entre eles e a tabela mora o painel de aplicar punição, e trocar essa faixa
           apagaria um motivo de banimento pela metade de quem estivesse escrevendo. Clicar
           num cartão continua recarregando a tela, e aí tudo volta consistente. */ ?>
  <!--eh:lista-->
  <section class="adm-card ban-lista">
    <div class="adm-card__head">
      <h2 class="adm-card__title">Histórico de punições
        <small data-adm-total><?= e(num((int)($lista['total'] ?? 0))) ?> registro<?= (int)($lista['total'] ?? 0) === 1 ? '' : 's' ?><?= $temFiltro ? ' com os filtros atuais' : ' no total' ?></small>
      </h2>
      <div class="adm-card__tools">
        <a class="adm-btn adm-btn--primary adm-btn--sm" href="<?= e($rota(['painel' => 'novo'])) ?>#painel"><?= icon('gavel') ?><span>Aplicar punição</span></a>
      </div>
    </div>

    <?php /* data-adm-preservar: transplantado para dentro do HTML novo em vez de
             substituído — é onde está o cursor de quem digita. */ ?>
    <form class="adm-toolbar" method="get" action="<?= e(url('admin/banimentos')) ?>"
          data-adm-preservar="filtros">
      <?php /* O estado escolhido nos cartões viaja escondido: buscar não pode desfazer o
               filtro que o operador acabou de aplicar logo acima. */ ?>
      <input type="hidden" name="estado" value="<?= e((string)($filtros['estado'] ?? '')) ?>">
      <label class="adm-search adm-toolbar__grow">
        <span class="sr-only">Buscar punição</span>
        <?= icon('search') ?>
        <input class="adm-input" type="search" name="q" maxlength="80"
               value="<?= e((string)($filtros['busca'] ?? '')) ?>"
               placeholder="Conta, e-mail, motivo — ou #12 para um registro">
      </label>

      <label class="ban-campo">
        <span class="sr-only">Tipo</span>
        <select class="adm-select" name="tipo">
          <option value="">Todos os tipos</option>
          <?php foreach ($tipos as $chave => $t): ?>
            <option value="<?= e((string)$chave) ?>"<?= (string)($filtros['tipo'] ?? '') === (string)$chave ? ' selected' : '' ?>><?= e((string)$t['rotulo']) ?></option>
          <?php endforeach ?>
        </select>
      </label>

      <label class="ban-campo">
        <span class="sr-only">Ordenar</span>
        <select class="adm-select" name="ordem">
          <?php foreach ((array)($cromo['ordens'] ?? []) as $chave => $rotulo): ?>
            <option value="<?= e((string)$chave) ?>"<?= (string)($filtros['ordem'] ?? 'recente') === (string)$chave ? ' selected' : '' ?>><?= e((string)$rotulo) ?></option>
          <?php endforeach ?>
        </select>
      </label>

      <button class="adm-btn adm-btn--ghost" type="submit"><?= icon('filter_alt') ?><span>Filtrar</span></button>
      <?php if ($temFiltro || ($filtros['ordem'] ?? 'recente') !== 'recente'): ?>
        <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/banimentos')) ?>"><?= icon('filter_alt_off') ?><span>Limpar</span></a>
      <?php endif ?>
    </form>

    <div class="adm-card__body adm-card__body--flush">
      <?php if ($linhas === []): ?>
        <?php $v = (array)($vazios[$temFiltro ? 'filtro' : 'limpo'] ?? []); ?>
        <div class="adm-empty">
          <?= icon((string)($v['icone'] ?? 'inbox')) ?>
          <p class="adm-empty__title"><?= e((string)($v['titulo'] ?? '')) ?></p>
          <p class="adm-empty__txt"><?= e((string)($v['texto'] ?? '')) ?></p>
          <?php if ($temFiltro): ?>
            <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/banimentos')) ?>"><?= icon('filter_alt_off') ?><span>Limpar filtros</span></a>
          <?php else: ?>
            <a class="adm-btn adm-btn--ghost" href="<?= e($rota(['painel' => 'novo'])) ?>#painel"><?= icon('gavel') ?><span>Aplicar a primeira punição</span></a>
          <?php endif ?>
        </div>
      <?php else: ?>
        <div class="adm-tablewrap">
          <table class="adm-table ban-t">
            <thead>
              <tr>
                <th scope="col"><?= e((string)($colunas['conta'] ?? 'Conta')) ?></th>
                <th scope="col"><?= e((string)($colunas['tipo'] ?? 'Tipo')) ?></th>
                <th scope="col"><?= e((string)($colunas['motivo'] ?? 'Motivo')) ?></th>
                <th scope="col"><?= e((string)($colunas['aplicado'] ?? 'Aplicado por')) ?></th>
                <th scope="col"><?= e((string)($colunas['prazo'] ?? 'Prazo')) ?></th>
                <th scope="col"><?= e((string)($colunas['estado'] ?? 'Estado')) ?></th>
                <th scope="col" class="adm-table__acts"><span class="sr-only">Ações</span></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($linhas as $b): ?>
                <?php
                  $estado  = (string)$b['estado'];
                  $tipo    = (array)($tipos[(string)$b['kind']] ?? []);
                  $motivo  = trim((string)($b['reason'] ?? ''));
                  $ehAdmin = in_array((string)($b['alvo_papel'] ?? ''), ['admin', 'owner'], true);
                ?>
                <tr class="ban-t__linha ban-t__linha--<?= e($estado) ?>">
                  <td class="ban-t__conta">
                    <span class="adm-table__who">
                      <span class="adm-table__ava"><?= icon($ehAdmin ? 'shield_person' : 'person') ?></span>
                      <span>
                        <strong><?= e((string)$b['alvo_nome']) ?></strong>
                        <small><?= e((string)($b['alvo_email'] ?? '')) ?: 'sem e-mail' ?></small>
                      </span>
                    </span>
                  </td>
                  <td class="ban-t__tipo">
                    <span class="adm-badge adm-badge--<?= e((string)($tipo['tom'] ?? 'muted')) ?> adm-badge--nodot"><?= e((string)($tipo['rotulo'] ?? $b['kind'])) ?></span>
                  </td>
                  <td class="ban-t__motivo">
                    <?php /* Duas linhas com reticências; o texto inteiro fica no title. Cortar
                             no PHP jogaria fora o resto — aqui o motivo continua todo no HTML. */ ?>
                    <span class="ban-t__clamp" title="<?= e($motivo) ?>"><?= e($motivo) ?: '—' ?></span>
                    <?php if ($estado === 'revogado' && trim((string)($b['revoke_reason'] ?? '')) !== ''): ?>
                      <span class="ban-t__revmotivo" title="<?= e((string)$b['revoke_reason']) ?>"><?= icon('undo') ?><?= e((string)$b['revoke_reason']) ?></span>
                    <?php endif ?>
                  </td>
                  <td class="ban-t__admin">
                    <strong><?= e((string)($b['admin_nome'] ?? '')) ?: 'sistema' ?></strong>
                    <small><?= e($data_br($b['created_at'])) ?></small>
                  </td>
                  <td class="ban-t__prazo num">
                    <?php if ($b['expires_at'] === null): ?>
                      <span class="ban-t__semprazo">definitivo</span>
                    <?php else: ?>
                      <strong><?= e($data_br($b['expires_at'])) ?></strong>
                      <small><?= e($distancia($b['expires_at'])) ?></small>
                    <?php endif ?>
                  </td>
                  <td class="ban-t__estado">
                    <?= $selo($estado) ?>
                    <?php if ($estado === 'revogado'): ?>
                      <small class="adm-meta">por <?= e((string)($b['revogador_nome'] ?? '—')) ?></small>
                    <?php endif ?>
                  </td>
                  <td class="adm-table__acts">
                    <?php if ($estado === 'ativo'): ?>
                      <a class="adm-btn adm-btn--sm adm-btn--ghost" href="<?= e($rota(['painel' => 'revogar', 'id' => (string)$b['id']])) ?>#painel"
                         title="Revogar a punição de <?= e((string)$b['alvo_nome']) ?>"><?= icon('undo') ?><span>Revogar</span></a>
                    <?php elseif ($podeApagar): ?>
                      <a class="adm-btn adm-btn--sm adm-btn--danger" href="<?= e($rota(['painel' => 'remover', 'id' => (string)$b['id']])) ?>#painel"
                         title="Apagar o registro #<?= e((string)$b['id']) ?> do histórico"><?= icon('delete') ?><span>Apagar</span></a>
                    <?php else: ?>
                      <span class="ban-t__encerrado" title="Punição encerrada — não há ação pendente">encerrado</span>
                    <?php endif ?>
                  </td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
      <?php endif ?>
    </div>

    <?php if ((int)($lista['paginas'] ?? 1) > 1): ?>
      <div class="adm-card__foot">
        <nav class="adm-pager" aria-label="Paginação do histórico">
          <span class="adm-pager__info">
            <?= e(num((int)$lista['primeiro'])) ?>–<?= e(num((int)$lista['ultimo'])) ?> de <?= e(num((int)$lista['total'])) ?>
          </span>
          <?php
            $pag = (int)$lista['pagina'];
            $max = (int)$lista['paginas'];
            /* Janela de 5 páginas em volta da atual: uma tabela de punições pode ter dezenas
               de páginas e uma régua inteira quebraria o rodapé em duas linhas. */
            $de  = max(1, min($pag - 2, $max - 4));
            $ate = min($max, max($pag + 2, 5));
          ?>
          <a class="adm-pager__btn<?= $pag <= 1 ? ' is-disabled' : '' ?>" href="<?= e($rota(['p' => (string)max(1, $pag - 1)])) ?>"
             aria-label="Página anterior"><?= icon('chevron_left') ?></a>
          <?php if ($de > 1): ?>
            <a class="adm-pager__btn" href="<?= e($rota(['p' => null])) ?>">1</a>
            <?php if ($de > 2): ?><span class="adm-pager__gap">…</span><?php endif ?>
          <?php endif ?>
          <?php for ($i = $de; $i <= $ate; $i++): ?>
            <a class="adm-pager__btn<?= $i === $pag ? ' is-active' : '' ?>" href="<?= e($rota(['p' => $i > 1 ? (string)$i : null])) ?>"
               <?= $i === $pag ? 'aria-current="page"' : '' ?>><?= e((string)$i) ?></a>
          <?php endfor ?>
          <?php if ($ate < $max): ?>
            <?php if ($ate < $max - 1): ?><span class="adm-pager__gap">…</span><?php endif ?>
            <a class="adm-pager__btn" href="<?= e($rota(['p' => (string)$max])) ?>"><?= e((string)$max) ?></a>
          <?php endif ?>
          <a class="adm-pager__btn<?= $pag >= $max ? ' is-disabled' : '' ?>" href="<?= e($rota(['p' => (string)min($max, $pag + 1)])) ?>"
             aria-label="Próxima página"><?= icon('chevron_right') ?></a>
        </nav>
      </div>
    <?php endif ?>
  </section>
  <!--/eh:lista-->
</div>
