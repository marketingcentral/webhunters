<?php declare(strict_types=1);

/**
 * Tela ADMIN · API (/admin/api).
 *
 * Escopo: $page, $player, $nav, $server, $data, $flash, $csrf. Layout: layouts/admin.php.
 *
 * A view não consulta banco e não decide regra: tudo chega pronto de
 * App\Controllers\Admin\ApiController. Aqui só há apresentação.
 *
 * QUATRO COISAS QUE ESTA TELA FAZ DE PROPÓSITO:
 *
 * 1. O SEGREDO APARECE UMA VEZ, e a tela grita isso — caixa própria, no topo, com a frase
 *    antes do valor. Quem já viu uma chave de API sabe que o erro clássico é fechar a aba
 *    antes de copiar; o aviso vem ANTES para ser lido antes.
 *
 * 2. ESCOPO PERIGOSO TEM AVISO COLADO. `players:write` permite promover alguém a
 *    administrador. Isso está escrito ao lado da caixa de seleção, não num parágrafo de
 *    ajuda no fim da página — a hora de saber é a hora de marcar.
 *
 * 3. A REFERÊNCIA É GERADA PELA PRÓPRIA API. Cada linha aqui é uma rota que o despachante
 *    atende neste minuto. Não há lista escrita à mão que possa envelhecer.
 *
 * 4. TUDO FUNCIONA SEM JAVASCRIPT. O botão de copiar é um enfeite (o valor está num campo
 *    selecionável), "marcar todos" é um <button> que só melhora, e revogar é um formulário
 *    POST comum. É assim que a tela é conferida no `curl`.
 */

$cromo  = (array)($data['cromo'] ?? []);
$micro  = (array)($cromo['micro'] ?? []);
$avisos = (array)($cromo['avisos_escopo'] ?? []);
$cEst   = (array)($cromo['estados'] ?? []);
$cSt    = (array)($cromo['status'] ?? []);
$ajuda  = (array)($cromo['ajuda'] ?? []);

$chaves     = (array)($data['chaves'] ?? ['linhas' => [], 'total' => 0, 'pagina' => 1, 'paginas' => 1, 'falha' => false]);
$contagens  = (array)($data['contagens'] ?? []);
$estado     = (string)($data['estado'] ?? '');
$log        = (array)($data['log'] ?? ['linhas' => [], 'total' => 0, 'pagina' => 1, 'paginas' => 1, 'falha' => false]);
$filtroLog  = (array)($data['filtro_log'] ?? []);
$resumo     = (array)($data['resumo'] ?? []);
$escopos    = (array)($data['escopos'] ?? []);
$referencia = (array)($data['referencia'] ?? []);
$segredo    = is_array($data['segredo'] ?? null) ? $data['segredo'] : null;
$form       = is_array($data['form'] ?? null) ? $data['form'] : null;
$origem     = (string)($data['origem'] ?? '');
$limPadrao  = (int)($data['limite_padrao'] ?? 120);
$limMax     = (int)($data['limite_max'] ?? 6000);

/* ========================================================================================
   FORMATADORES
   ======================================================================================== */

/** 'AAAA-MM-DD HH:MM:SS' -> '12/09 14:33'. Vazio vira travessão: não se inventa data. */
$dh = static fn(string $sql): string => $sql === '' ? '—' : date('d/m/y H:i', (int)strtotime($sql));

/** Badge com cor E texto — a cor sozinha não sobrevive a monitor ruim. */
$tom = static fn(string $t): string => match ($t) {
    'ok'     => 'adm-badge--ok',
    'warn'   => 'adm-badge--warn',
    'danger' => 'adm-badge--danger',
    'info'   => 'adm-badge--info',
    default  => 'adm-badge--muted',
};

/** Cor da faixa de status HTTP no log. */
$tomStatus = static fn(int $s): string => match (true) {
    $s < 400 => 'adm-badge--ok',
    $s === 429 => 'adm-badge--purple',
    $s === 401 || $s === 403 => 'adm-badge--warn',
    $s >= 500 => 'adm-badge--danger',
    default  => 'adm-badge--info',
};

/** URL desta tela preservando os filtros correntes. @param array<string,mixed> $mais */
$aqui = static function (array $mais = []) use ($estado, $filtroLog): string {
    $q = [];
    if ($estado !== '') { $q['estado'] = $estado; }
    if ((int)($filtroLog['chave'] ?? 0) > 0) { $q['chave'] = (string)$filtroLog['chave']; }
    if (($filtroLog['status'] ?? '') !== '') { $q['status'] = (string)$filtroLog['status']; }
    foreach ($mais as $k => $v) {
        if ($v === null || $v === '') { unset($q[$k]); } else { $q[$k] = (string)$v; }
    }
    return url('admin/api', $q);
};

/** Valor que o operador digitou e que voltou com o erro. */
$v = static fn(string $nome): string => (string)($form['valores'][$nome] ?? '');
$erro = static fn(string $nome): string => (string)($form['erros'][$nome] ?? '');

/** Escopos que estavam marcados quando o formulário foi recusado. @var list<string> */
$marcados = (array)($form['valores']['scopes'] ?? []);
?>

<div class="api">

  <?php /* ======================================================= SEGREDO (uma vez só) === */ ?>
  <?php if ($segredo !== null && $segredo['chave'] !== ''): ?>
    <section class="api-segredo" role="alert" aria-labelledby="api-segredo-t">
      <div class="api-segredo__head">
        <?= icon('key', 'api-segredo__ico') ?>
        <div>
          <h2 class="api-segredo__t" id="api-segredo-t">Chave criada: <?= e($segredo['nome']) ?></h2>
          <p class="api-segredo__aviso"><?= e((string)($micro['uma_vez'] ?? '')) ?></p>
        </div>
      </div>
      <?php /* <input readonly> e não <code>: o valor precisa ser selecionável de uma vez
               (clique + Ctrl+C) mesmo sem o botão de copiar funcionar. */ ?>
      <div class="api-segredo__val">
        <input class="adm-input api-segredo__campo" type="text" readonly
               id="api-nova-chave" value="<?= e($segredo['chave']) ?>"
               aria-label="Chave da API recém-criada"
               data-api-copiar-alvo>
        <button class="adm-btn adm-btn--primary" type="button" data-api-copiar="#api-nova-chave">
          <?= icon('content_copy') ?><span>Copiar</span>
        </button>
      </div>
      <p class="api-segredo__pe"><?= e((string)($micro['perdeu'] ?? '')) ?></p>
    </section>
  <?php endif ?>

  <?php /* ================================================================ INDICADORES === */ ?>
  <section class="adm-grid adm-grid--4 api-kpis" aria-label="Resumo da API">
    <?php
      $cards = [
        ['ico' => 'key',        'tom' => 'lime',  'lbl' => 'Chaves ativas',
         'val' => num((int)($contagens['ativas'] ?? 0)), 'sub' => num((int)($contagens['total'] ?? 0)) . ' no total'],
        ['ico' => 'block',      'tom' => 'red',   'lbl' => 'Revogadas',
         'val' => num((int)($contagens['revogadas'] ?? 0)), 'sub' => 'não voltam a funcionar'],
        ['ico' => 'swap_horiz', 'tom' => 'cyan',  'lbl' => 'Chamadas (24 h)',
         'val' => num((int)($resumo['total'] ?? 0)), 'sub' => num((int)($resumo['ms_medio'] ?? 0)) . ' ms em média'],
        ['ico' => 'gpp_maybe',  'tom' => ((int)($resumo['recusadas'] ?? 0)) > 0 ? 'amber' : 'lime', 'lbl' => 'Recusadas (24 h)',
         'val' => num((int)($resumo['recusadas'] ?? 0)), 'sub' => '401, 403 e 429'],
      ];
      foreach ($cards as $c):
    ?>
      <div class="adm-kpi adm-kpi--<?= e($c['tom']) ?>">
        <?= icon($c['ico'], 'adm-kpi__ico') ?>
        <div class="adm-kpi__txt">
          <span class="adm-kpi__label"><?= e($c['lbl']) ?></span>
          <span class="adm-kpi__value num"><?= e($c['val']) ?></span>
          <span class="adm-kpi__delta"><?= e($c['sub']) ?></span>
        </div>
      </div>
    <?php endforeach ?>
  </section>

  <div class="api-cols">

    <?php /* ============================================================ NOVA CHAVE ===== */ ?>
    <section class="adm-card api-nova">
      <header class="adm-card__head">
        <h2 class="adm-card__title"><?= icon('add_moderator') ?> Nova chave</h2>
      </header>
      <div class="adm-card__body">
        <form class="adm-form" method="post" action="<?= e(url('admin/api/criar')) ?>">
          <?= csrf_field() ?>

          <div class="adm-field<?= $erro('name') !== '' ? ' is-invalid' : '' ?>">
            <label class="adm-label" for="f-name">Nome<span class="adm-label__req">*</span></label>
            <input class="adm-input" type="text" id="f-name" name="name" maxlength="80" required
                   placeholder="Integração Unreal — servidor de produção" value="<?= e($v('name')) ?>">
            <?php if ($erro('name') !== ''): ?>
              <p class="adm-error"><?= icon('error') ?><span><?= e($erro('name')) ?></span></p>
            <?php endif ?>
            <p class="adm-hint">É por este nome que você vai reconhecer a chave daqui a seis meses.</p>
          </div>

          <fieldset class="api-escopos<?= $erro('scopes') !== '' ? ' is-invalid' : '' ?>">
            <legend class="adm-label">Escopos<span class="adm-label__req">*</span></legend>
            <?php if ($erro('scopes') !== ''): ?>
              <p class="adm-error"><?= icon('error') ?><span><?= e($erro('scopes')) ?></span></p>
            <?php endif ?>
            <p class="adm-hint">Marque só o que a integração precisa. Ler e escrever são concedidos separadamente.</p>

            <?php foreach ($escopos as $g): ?>
              <div class="api-escopos__grupo">
                <p class="api-escopos__rec">
                  <?= icon((string)$g['icone'], 'api-escopos__ico') ?>
                  <span class="api-escopos__nome"><?= e((string)$g['rotulo']) ?></span>
                  <span class="api-escopos__desc"><?= e((string)$g['descricao']) ?></span>
                </p>
                <div class="api-escopos__linha">
                  <?php foreach ((array)$g['escopos'] as $esc): ?>
                    <?php $valor = (string)$esc['valor']; $aviso = (string)($avisos[$valor] ?? ''); ?>
                    <label class="api-escopo<?= $aviso !== '' ? ' api-escopo--perigo' : '' ?>">
                      <input type="checkbox" name="scopes[]" value="<?= e($valor) ?>"
                             data-api-escopo
                             <?= in_array($valor, $marcados, true) ? 'checked' : '' ?>>
                      <span class="api-escopo__cod"><?= e($valor) ?></span>
                      <?php if ($aviso !== ''): ?>
                        <span class="api-escopo__aviso"><?= icon('warning') ?><?= e($aviso) ?></span>
                      <?php endif ?>
                    </label>
                  <?php endforeach ?>
                </div>
              </div>
            <?php endforeach ?>

            <?php /* Só melhora a vida: sem JavaScript o botão some (o módulo o revela). */ ?>
            <button class="adm-btn adm-btn--ghost adm-btn--sm api-escopos__todos" type="button"
                    data-api-todos hidden><?= icon('done_all') ?><span>Marcar todos</span></button>
          </fieldset>

          <div class="adm-form__row">
            <div class="adm-field<?= $erro('rate_limit_per_min') !== '' ? ' is-invalid' : '' ?>">
              <label class="adm-label" for="f-lim">Limite por minuto</label>
              <input class="adm-input" type="number" id="f-lim" name="rate_limit_per_min"
                     min="0" max="<?= e((string)$limMax) ?>" value="<?= e($v('rate_limit_per_min')) ?>"
                     placeholder="<?= e((string)$limPadrao) ?>">
              <?php if ($erro('rate_limit_per_min') !== ''): ?>
                <p class="adm-error"><?= icon('error') ?><span><?= e($erro('rate_limit_per_min')) ?></span></p>
              <?php endif ?>
              <p class="adm-hint">Vazio usa o padrão (<?= e((string)$limPadrao) ?>/min). Acima disso, a chamada recebe 429.</p>
            </div>

            <div class="adm-field<?= $erro('expires_at') !== '' ? ' is-invalid' : '' ?>">
              <label class="adm-label" for="f-exp">Vence em</label>
              <input class="adm-input" type="date" id="f-exp" name="expires_at" value="<?= e($v('expires_at')) ?>">
              <?php if ($erro('expires_at') !== ''): ?>
                <p class="adm-error"><?= icon('error') ?><span><?= e($erro('expires_at')) ?></span></p>
              <?php endif ?>
              <p class="adm-hint">Vazio = não vence. Chave de fornecedor deveria ter prazo.</p>
            </div>
          </div>

          <div class="adm-field">
            <label class="adm-label" for="f-note">Observação</label>
            <input class="adm-input" type="text" id="f-note" name="note" maxlength="240"
                   placeholder="Quem pediu, para quê, e onde ela está configurada." value="<?= e($v('note')) ?>">
          </div>

          <div class="adm-form__actions">
            <button class="adm-btn adm-btn--primary" type="submit"><?= icon('key') ?><span>Criar chave</span></button>
          </div>
        </form>
      </div>
    </section>

    <?php /* ================================================================== AJUDA ==== */ ?>
    <section class="adm-card api-ajuda">
      <header class="adm-card__head">
        <h2 class="adm-card__title"><?= icon('terminal') ?> <?= e((string)($ajuda['titulo'] ?? 'Como chamar')) ?></h2>
      </header>
      <div class="adm-card__body">
        <ol class="api-passos">
          <?php foreach ((array)($ajuda['passos'] ?? []) as $passo): ?>
            <li><?= e((string)$passo) ?></li>
          <?php endforeach ?>
        </ol>
        <pre class="api-cmd"><code>curl -s <?= e($origem . url('api/v1/health')) ?>

curl -s <?= e($origem . url('api/v1/players')) ?>?limit=5 \
  -H "Authorization: Bearer SUA_CHAVE"</code></pre>
        <p class="adm-hint api-ajuda__pe"><?= icon('lock') ?> <?= e((string)($micro['bearer'] ?? '')) ?></p>
      </div>
    </section>
  </div>

  <?php /* ================================================================== CHAVES ===== */ ?>
  <section class="adm-card api-chaves">
    <header class="adm-card__head">
      <h2 class="adm-card__title"><?= icon('vpn_key') ?> Chaves</h2>
      <div class="adm-card__tools">
        <a class="adm-btn adm-btn--sm<?= $estado === '' ? ' is-active' : '' ?>" href="<?= e($aqui(['estado' => null, 'pag' => null])) ?>">Todas</a>
        <?php foreach (['ativa' => 'Ativas', 'revogada' => 'Revogadas', 'vencida' => 'Vencidas'] as $k => $rot): ?>
          <a class="adm-btn adm-btn--sm<?= $estado === $k ? ' is-active' : '' ?>" href="<?= e($aqui(['estado' => $k, 'pag' => null])) ?>"><?= e($rot) ?></a>
        <?php endforeach ?>
      </div>
    </header>

    <div class="adm-card__body adm-card__body--flush">
      <?php if (!empty($chaves['falha'])): ?>
        <div class="adm-empty">
          <p class="adm-empty__title"><?= icon('cloud_off') ?> Leitura indisponível</p>
          <p class="adm-empty__txt"><?= e((string)($micro['falha_leitura'] ?? '')) ?></p>
        </div>
      <?php elseif ($chaves['linhas'] === []): ?>
        <div class="adm-empty">
          <p class="adm-empty__title"><?= icon('key_off') ?> <?= e((string)($micro['sem_chaves'] ?? 'Nenhuma chave.')) ?></p>
          <p class="adm-empty__txt">Crie a primeira no formulário acima.</p>
        </div>
      <?php else: ?>
        <div class="adm-table__wrap">
          <table class="adm-table api-tbl">
            <thead>
              <tr>
                <th scope="col">Chave</th>
                <th scope="col">Escopos</th>
                <th scope="col">Limite</th>
                <th scope="col">Último uso</th>
                <th scope="col">Estado</th>
                <th scope="col" class="adm-table__acts">Ações</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ((array)$chaves['linhas'] as $k): ?>
                <?php $est = (array)($cEst[$k['estado']] ?? ['rotulo' => $k['estado'], 'tom' => 'muted']); ?>
                <tr class="<?= $k['estado'] !== 'ativa' ? 'is-off' : '' ?>">
                  <td>
                    <span class="api-tbl__nome"><?= e((string)$k['nome']) ?></span>
                    <code class="api-tbl__pref"><?= e((string)$k['prefixo']) ?>…</code>
                    <?php if ((string)$k['nota'] !== ''): ?>
                      <span class="api-tbl__nota"><?= e((string)$k['nota']) ?></span>
                    <?php endif ?>
                    <span class="api-tbl__meta">
                      criada <?= e($dh((string)$k['criada_em'])) ?>
                      <?= (string)$k['criador'] !== '' ? 'por ' . e((string)$k['criador']) : '' ?>
                    </span>
                  </td>
                  <td>
                    <?php if ((array)$k['escopos'] === []): ?>
                      <span class="adm-badge adm-badge--muted">nenhum</span>
                    <?php else: ?>
                      <div class="api-tbl__escopos">
                        <?php foreach ((array)$k['escopos'] as $esc): ?>
                          <span class="api-chip<?= isset($avisos[$esc]) ? ' api-chip--perigo' : '' ?>"
                                <?= isset($avisos[$esc]) ? 'title="' . e((string)$avisos[$esc]) . '"' : '' ?>><?= e((string)$esc) ?></span>
                        <?php endforeach ?>
                      </div>
                    <?php endif ?>
                  </td>
                  <td class="num">
                    <?= e(num((int)$k['limite'])) ?><small>/min</small>
                    <?php if (!$k['limite_proprio']): ?><span class="api-tbl__meta">padrão</span><?php endif ?>
                  </td>
                  <td>
                    <?= e($dh((string)$k['usada_em'])) ?>
                    <span class="api-tbl__meta">
                      <?= e(num((int)$k['usos'])) ?> chamada(s)<?= (string)$k['ultimo_ip'] !== '' ? ' · ' . e((string)$k['ultimo_ip']) : '' ?>
                    </span>
                  </td>
                  <td>
                    <span class="adm-badge <?= e($tom((string)$est['tom'])) ?>"><?= e((string)$est['rotulo']) ?></span>
                    <?php if ((string)$k['expira_em'] !== ''): ?>
                      <span class="api-tbl__meta">vence <?= e($dh((string)$k['expira_em'])) ?></span>
                    <?php endif ?>
                    <?php if ((string)$k['revogada_em'] !== ''): ?>
                      <span class="api-tbl__meta">
                        em <?= e($dh((string)$k['revogada_em'])) ?><?= (string)$k['revogador'] !== '' ? ' por ' . e((string)$k['revogador']) : '' ?>
                      </span>
                    <?php endif ?>
                  </td>
                  <td class="adm-table__acts">
                    <a class="adm-btn adm-btn--sm adm-btn--ghost"
                       href="<?= e($aqui(['chave' => (string)$k['id'], 'lpag' => null])) ?>#api-log">
                      <?= icon('receipt_long') ?><span>Chamadas</span>
                    </a>
                    <?php if ($k['estado'] !== 'revogada'): ?>
                      <?php /* Sem confirm() de JavaScript: o botão diz o que acontece e o
                               aviso está ao lado. A tela funciona inteira sem script. */ ?>
                      <form method="post" action="<?= e(url('admin/api/revogar')) ?>" class="api-revogar">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= e((string)$k['id']) ?>">
                        <button class="adm-btn adm-btn--sm adm-btn--danger" type="submit"
                                title="<?= e((string)($micro['revogar_aviso'] ?? '')) ?>">
                          <?= icon('block') ?><span>Revogar</span>
                        </button>
                      </form>
                    <?php endif ?>
                  </td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>

        <?php if ((int)$chaves['paginas'] > 1): ?>
          <nav class="adm-pager" aria-label="Páginas de chaves">
            <?php for ($i = 1; $i <= (int)$chaves['paginas']; $i++): ?>
              <a class="adm-pager__btn<?= $i === (int)$chaves['pagina'] ? ' is-active' : '' ?>"
                 href="<?= e($aqui(['pag' => (string)$i])) ?>"><?= e((string)$i) ?></a>
            <?php endfor ?>
            <span class="adm-pager__info"><?= e(num((int)$chaves['total'])) ?> chave(s)</span>
          </nav>
        <?php endif ?>
      <?php endif ?>
    </div>
  </section>

  <?php /* ============================================================== REFERÊNCIA ===== */ ?>
  <section class="adm-card api-ref">
    <header class="adm-card__head">
      <h2 class="adm-card__title"><?= icon('menu_book') ?> Referência dos endpoints</h2>
      <p class="adm-meta">Gerada pela própria API — é a mesma tabela que o despachante usa para atender.</p>
    </header>
    <div class="adm-card__body adm-card__body--flush">
      <?php foreach ($referencia as $grupo): ?>
        <details class="api-ref__grupo">
          <summary class="api-ref__sum">
            <?= icon((string)$grupo['icone'], 'api-ref__ico') ?>
            <span class="api-ref__nome"><?= e((string)$grupo['rotulo']) ?></span>
            <code class="api-ref__base">/api/v1/<?= e((string)$grupo['recurso']) ?></code>
            <span class="api-ref__desc"><?= e((string)$grupo['descricao']) ?></span>
            <span class="api-ref__n"><?= e((string)count((array)$grupo['rotas'])) ?></span>
          </summary>
          <div class="adm-table__wrap">
            <table class="adm-table adm-table--compact api-ref__tbl">
              <thead>
                <tr>
                  <th scope="col">Método</th>
                  <th scope="col">Caminho</th>
                  <th scope="col">Escopo</th>
                  <th scope="col">O que faz</th>
                  <th scope="col">Exemplo</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ((array)$grupo['rotas'] as $r): ?>
                  <tr>
                    <td><span class="api-verbo api-verbo--<?= e(strtolower((string)$r['metodo'])) ?>"><?= e((string)$r['metodo']) ?></span></td>
                    <td><code class="api-ref__path"><?= e((string)$r['caminho']) ?></code></td>
                    <td><span class="api-chip<?= isset($avisos[$r['escopo']]) ? ' api-chip--perigo' : '' ?>"><?= e((string)$r['escopo']) ?></span></td>
                    <td><?= e((string)$r['resumo']) ?></td>
                    <td>
                      <?php if ((string)$r['exemplo'] !== ''): ?>
                        <code class="api-ref__ex"><?= e((string)$r['exemplo']) ?></code>
                      <?php else: ?>
                        <span class="api-tbl__meta">—</span>
                      <?php endif ?>
                    </td>
                  </tr>
                <?php endforeach ?>
              </tbody>
            </table>
          </div>
        </details>
      <?php endforeach ?>
    </div>
  </section>

  <?php /* ================================================================= LOG ========= */ ?>
  <section class="adm-card api-log" id="api-log">
    <header class="adm-card__head">
      <h2 class="adm-card__title"><?= icon('receipt_long') ?> Log de requisições</h2>
      <div class="adm-card__tools">
        <a class="adm-btn adm-btn--sm<?= ($filtroLog['status'] ?? '') === '' ? ' is-active' : '' ?>"
           href="<?= e($aqui(['status' => null, 'lpag' => null])) ?>#api-log">Todas</a>
        <?php foreach ($cSt as $k => $meta): ?>
          <a class="adm-btn adm-btn--sm<?= ($filtroLog['status'] ?? '') === $k ? ' is-active' : '' ?>"
             href="<?= e($aqui(['status' => (string)$k, 'lpag' => null])) ?>#api-log"><?= e((string)$meta['rotulo']) ?></a>
        <?php endforeach ?>
        <?php if ((int)($filtroLog['chave'] ?? 0) > 0): ?>
          <a class="adm-btn adm-btn--sm adm-btn--ghost" href="<?= e($aqui(['chave' => null, 'lpag' => null])) ?>#api-log">
            <?= icon('filter_alt_off') ?><span>Todas as chaves</span>
          </a>
        <?php endif ?>
      </div>
    </header>

    <div class="adm-card__body adm-card__body--flush">
      <?php if (!empty($log['falha'])): ?>
        <div class="adm-empty">
          <p class="adm-empty__title"><?= icon('cloud_off') ?> Leitura indisponível</p>
          <p class="adm-empty__txt"><?= e((string)($micro['falha_leitura'] ?? '')) ?></p>
        </div>
      <?php elseif ($log['linhas'] === []): ?>
        <div class="adm-empty">
          <p class="adm-empty__title"><?= icon('inbox') ?> <?= e((string)($micro['sem_log'] ?? 'Nada aqui.')) ?></p>
          <p class="adm-empty__txt">Toda chamada entra aqui, inclusive as recusadas.</p>
        </div>
      <?php else: ?>
        <div class="adm-table__wrap">
          <table class="adm-table adm-table--compact api-logtbl">
            <thead>
              <tr>
                <th scope="col">Quando</th>
                <th scope="col">Chave</th>
                <th scope="col">Chamada</th>
                <th scope="col">Resposta</th>
                <th scope="col">IP</th>
                <th scope="col" class="num">ms</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ((array)$log['linhas'] as $l): ?>
                <tr>
                  <td class="num"><?= e($dh((string)$l['quando'])) ?></td>
                  <td>
                    <?php if ((string)$l['chave'] !== ''): ?>
                      <span class="api-tbl__nome"><?= e((string)$l['chave']) ?></span>
                      <code class="api-tbl__pref"><?= e((string)$l['prefixo']) ?></code>
                    <?php elseif ((string)$l['prefixo'] !== ''): ?>
                      <?php /* Prefixo sem chave = ninguém reconheceu o que foi apresentado.
                               É o rastro de quem está tentando adivinhar uma credencial. */ ?>
                      <code class="api-tbl__pref"><?= e((string)$l['prefixo']) ?></code>
                      <span class="api-tbl__meta">não reconhecida</span>
                    <?php else: ?>
                      <span class="api-tbl__meta">sem chave</span>
                    <?php endif ?>
                  </td>
                  <td>
                    <span class="api-verbo api-verbo--<?= e(strtolower((string)$l['metodo'])) ?>"><?= e((string)$l['metodo']) ?></span>
                    <code class="api-logtbl__path"><?= e((string)$l['caminho']) ?></code>
                  </td>
                  <td>
                    <span class="adm-badge <?= e($tomStatus((int)$l['status'])) ?>"><?= e((string)$l['status']) ?></span>
                    <?php if ((string)$l['erro'] !== ''): ?>
                      <span class="api-tbl__meta"><?= e((string)$l['erro']) ?></span>
                    <?php endif ?>
                  </td>
                  <td class="num"><?= e((string)$l['ip']) ?></td>
                  <td class="num"><?= e(num((int)$l['ms'])) ?></td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>

        <?php if ((int)$log['paginas'] > 1): ?>
          <nav class="adm-pager" aria-label="Páginas do log">
            <?php
              $p = (int)$log['pagina'];
              $ini = max(1, $p - 3);
              $fim = min((int)$log['paginas'], $p + 3);
            ?>
            <?php if ($ini > 1): ?><span class="adm-pager__gap">…</span><?php endif ?>
            <?php for ($i = $ini; $i <= $fim; $i++): ?>
              <a class="adm-pager__btn<?= $i === $p ? ' is-active' : '' ?>"
                 href="<?= e($aqui(['lpag' => (string)$i])) ?>#api-log"><?= e((string)$i) ?></a>
            <?php endfor ?>
            <?php if ($fim < (int)$log['paginas']): ?><span class="adm-pager__gap">…</span><?php endif ?>
            <span class="adm-pager__info"><?= e(num((int)$log['total'])) ?> registro(s)</span>
          </nav>
        <?php endif ?>
      <?php endif ?>
    </div>
  </section>
</div>
