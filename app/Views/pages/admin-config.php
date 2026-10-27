<?php declare(strict_types=1);

/**
 * Tela ADMIN · CONFIGURAÇÕES DO JOGO (/admin/configuracoes).
 *
 * Escopo: $page, $player, $nav, $server, $data, $flash, $csrf. Layout: layouts/admin.php.
 *
 * A view NÃO consulta o banco e NÃO valida nada: cada campo chega de
 * App\Controllers\Admin\SettingsController já com valor, limites, dica, procedência e o erro
 * do último envio. O que existe aqui é escolha de WIDGET por tipo e formatação.
 *
 * TRÊS COMPROMISSOS DE APRESENTAÇÃO
 *   1. Estado tem cor E TEXTO. Um interruptor verde sem a palavra "No ar" ao lado é uma
 *      adivinhação para quem não distingue as duas cores — e para quem imprime a tela.
 *   2. Número é tabular e alinhado à direita, com a unidade fora do campo: a unidade dentro
 *      da caixa acabaria colada ao valor no POST e viraria erro de validação.
 *   3. Nada de rolagem horizontal. O campo largo (texto e JSON) ocupa a linha inteira; os
 *      curtos entram numa grade que colapsa para uma coluna quando o cartão aperta.
 */

use App\Repo\AdminSettingsRepo;

$cromo  = is_array($data['cromo'] ?? null) ? $data['cromo'] : [];
$texto  = (array)($cromo['texto'] ?? []);
$tipos  = (array)($cromo['tipos'] ?? []);
$grupos = (array)($data['grupos'] ?? []);
$campos = (array)($data['campos'] ?? []);
$f      = (array)($data['filtros'] ?? []);
$resumo = (array)($data['resumo'] ?? []);
$pag    = (array)($data['paginacao'] ?? ['pagina' => 1, 'paginas' => 1, 'total' => 0]);
$nova   = (array)($data['nova'] ?? []);
$exc    = is_array($data['excluir'] ?? null) ? $data['excluir'] : null;
$falha  = $data['falha'] ?? null;

$acaoSalvar  = url('admin/configuracoes/salvar');
$acaoExcluir = url('admin/configuracoes/excluir');

/* Filtros em vigor: decide se a barra mostra o botão "Limpar" e se o vazio explica o recorte. */
$filtrando = ($f['q'] ?? '') !== '' || ($f['grupo'] ?? '') !== ''
          || ($f['tipo'] ?? '') !== '' || ($f['estado'] ?? 'todos') !== 'todos';

/* "10/09/2026 14:32" a partir do DATETIME do MySQL. String vazia continua vazia. */
$quando = static function (?string $sql): string {
    $sql = trim((string)$sql);
    if ($sql === '') { return ''; }
    $ts = strtotime($sql);
    return $ts === false ? $sql : date('d/m/Y H:i', $ts);
};

/* Valor gravado em UMA linha, para o resumo lateral. JSON e texto longo viram um trecho.
   O `?: $v` é a rede para um valor herdado com byte inválido: preg_replace com /u devolve
   null nesse caso, e o campo apareceria vazio em vez de aparecer feio — vazio mentiria. */
$emUmaLinha = static function (?string $v, int $max = 40): string {
    if ($v === null || trim($v) === '') { return '—'; }
    $v = trim(preg_replace('/\s+/u', ' ', $v) ?: $v);
    return mb_strlen($v) > $max ? mb_substr($v, 0, $max - 1) . '…' : $v;
};

/* Selo do tipo. O ícone sozinho não basta: "Número" e "Texto" precisam estar escritos. */
$seloTipo = static function (string $tipo) use ($tipos): string {
    $m = (array)($tipos[$tipo] ?? []);
    return '<span class="adm-badge adm-badge--nodot cfg-tipo cfg-tipo--' . e($tipo) . '">'
        . icon((string)($m['icon'] ?? 'tune'))
        . e((string)($m['label'] ?? $tipo)) . '</span>';
};

/* A URL da própria tela preservando os filtros — usada pela paginação e pelo link de excluir. */
$comFiltros = static function (array $extra = []) use ($f): string {
    $q = array_filter([
        'q'      => (string)($f['q'] ?? ''),
        'grupo'  => (string)($f['grupo'] ?? ''),
        'tipo'   => (string)($f['tipo'] ?? ''),
        'estado' => ($f['estado'] ?? 'todos') !== 'todos' ? (string)$f['estado'] : '',
        'ordem'  => ($f['ordem'] ?? 'sugerida') !== 'sugerida' ? (string)$f['ordem'] : '',
    ], static fn(string $v): bool => $v !== '');
    return url('admin/configuracoes', array_merge($q, $extra));
};

$rotuloEstado = [
    'todos'         => 'Todos os estados',
    'alterado'      => 'Ajustados pela equipe',
    'padrao'        => 'Ainda no valor semeado',
    'sistema'       => 'Lidos pelo código',
    'personalizada' => 'Criados no painel',
];
$rotuloOrdem = [
    'sugerida' => 'Ordem sugerida',
    'chave'    => 'Chave (A–Z)',
    'recentes' => 'Alterados primeiro',
];
?>

<?php /* ============================================================ resumo do topo */ ?>
<section class="adm-grid adm-grid--4 cfg-kpis">
  <div class="adm-kpi">
    <span class="adm-kpi__ico"><?= icon('tune') ?></span>
    <span class="adm-kpi__txt">
      <span class="adm-kpi__label">Parâmetros</span>
      <span class="adm-kpi__value num"><?= e(num((int)($resumo['total'] ?? 0))) ?></span>
    </span>
  </div>
  <div class="adm-kpi adm-kpi--cyan">
    <span class="adm-kpi__ico"><?= icon('edit_note') ?></span>
    <span class="adm-kpi__txt">
      <span class="adm-kpi__label">Ajustados pela equipe</span>
      <span class="adm-kpi__value num"><?= e(num((int)($resumo['alterados'] ?? 0))) ?><small>de <?= e(num((int)($resumo['total'] ?? 0))) ?></small></span>
    </span>
  </div>
  <div class="adm-kpi adm-kpi--purple">
    <span class="adm-kpi__ico"><?= icon('category') ?></span>
    <span class="adm-kpi__txt">
      <span class="adm-kpi__label">Grupos</span>
      <span class="adm-kpi__value num"><?= e(num((int)($resumo['grupos'] ?? 0))) ?></span>
    </span>
  </div>
  <div class="adm-kpi adm-kpi--amber">
    <span class="adm-kpi__ico"><?= icon('history') ?></span>
    <span class="adm-kpi__txt">
      <span class="adm-kpi__label">Última alteração</span>
      <?php $ult = is_array($resumo['ultima'] ?? null) ? $resumo['ultima'] : null; ?>
      <span class="adm-kpi__value cfg-kpi-data"><?= $ult === null ? '—' : e($quando((string)$ult['quando'])) ?></span>
      <?php if ($ult !== null): ?>
        <span class="adm-kpi__delta"><?= e((string)$ult['chave']) ?><?php if (!empty($ult['quem'])): ?><small>por <?= e((string)$ult['quem']) ?></small><?php endif ?></span>
      <?php endif ?>
    </span>
  </div>
</section>

<?php if ($falha !== null): ?>
  <section class="adm-card">
    <div class="adm-empty">
      <?= icon('cloud_off') ?>
      <h2 class="adm-empty__title">Configurações indisponíveis</h2>
      <p class="adm-empty__txt"><?= e((string)$falha) ?></p>
    </div>
  </section>
<?php else: ?>

<?php /* ================================================= confirmação de exclusão */ ?>
<?php if ($exc !== null): ?>
  <?php $alvo = (array)$exc['campo']; $bloq = (string)$exc['bloqueio']; ?>
  <section class="adm-card cfg-confirma<?= $bloq !== '' ? ' cfg-confirma--bloqueada' : '' ?>" id="confirmar-exclusao">
    <div class="adm-card__head">
      <?= icon($bloq !== '' ? 'lock' : 'delete_forever', 'cfg-confirma__ico') ?>
      <h2 class="adm-card__title">
        <?= $bloq !== '' ? 'Esta chave não pode ser removida' : 'Confirmar remoção' ?>
        <small><?= e((string)$alvo['chave']) ?> · <?= e((string)$alvo['label']) ?></small>
      </h2>
    </div>
    <div class="adm-card__body">
      <?php if ($bloq !== ''): ?>
        <p class="cfg-confirma__txt"><?= e($bloq) ?></p>
        <div class="adm-btns">
          <a class="adm-btn adm-btn--ghost" href="<?= e($comFiltros()) ?>"><?= icon('arrow_back') ?>Voltar</a>
        </div>
      <?php else: ?>
        <p class="cfg-confirma__txt">
          A chave sai da tabela e o jogo passa a não encontrar valor nenhum para ela.
          O valor gravado agora é <code class="cfg-code"><?= e($emUmaLinha((string)$alvo['gravado'], 120)) ?></code>
          e fica registrado no log de auditoria — é de lá que ele pode ser reconstruído.
        </p>
        <form class="adm-btns" method="post" action="<?= e($acaoExcluir) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="chave" value="<?= e((string)$alvo['chave']) ?>">
          <input type="hidden" name="confirmar" value="1">
          <button class="adm-btn adm-btn--danger" type="submit"><?= icon('delete_forever') ?>Remover definitivamente</button>
          <a class="adm-btn adm-btn--ghost" href="<?= e($comFiltros()) ?>">Cancelar</a>
        </form>
      <?php endif ?>
    </div>
  </section>
<?php endif ?>

<?php /* ============================================================ barra de filtros */ ?>
<section class="adm-card">
  <form class="adm-toolbar" method="get" action="<?= e(url('admin/configuracoes')) ?>">
    <div class="adm-toolbar__grow">
      <label class="adm-search">
        <?= icon('search') ?>
        <input class="adm-input" type="search" name="q" value="<?= e((string)($f['q'] ?? '')) ?>"
               placeholder="Buscar por chave, rótulo ou valor" aria-label="Buscar parâmetro">
      </label>
    </div>

    <label class="cfg-filtro">
      <span class="cfg-filtro__lbl">Grupo</span>
      <select class="adm-select" name="grupo" aria-label="Filtrar por grupo">
        <option value="">Todos (<?= e(num((int)($resumo['total'] ?? 0))) ?>)</option>
        <?php foreach ((array)($f['cat_grupos'] ?? []) as $g => $n): ?>
          <?php $lbl = (string)($cromo['grupos'][$g]['label'] ?? ucfirst(str_replace('_', ' ', (string)$g))); ?>
          <option value="<?= e((string)$g) ?>"<?= ($f['grupo'] ?? '') === (string)$g ? ' selected' : '' ?>><?= e($lbl) ?> (<?= e((string)$n) ?>)</option>
        <?php endforeach ?>
      </select>
    </label>

    <label class="cfg-filtro">
      <span class="cfg-filtro__lbl">Tipo</span>
      <select class="adm-select" name="tipo" aria-label="Filtrar por tipo">
        <option value="">Todos</option>
        <?php foreach ((array)($f['cat_tipos'] ?? []) as $t => $n): ?>
          <option value="<?= e((string)$t) ?>"<?= ($f['tipo'] ?? '') === (string)$t ? ' selected' : '' ?>><?= e((string)($tipos[$t]['label'] ?? $t)) ?> (<?= e((string)$n) ?>)</option>
        <?php endforeach ?>
      </select>
    </label>

    <label class="cfg-filtro">
      <span class="cfg-filtro__lbl">Estado</span>
      <select class="adm-select" name="estado" aria-label="Filtrar por estado">
        <?php foreach ($rotuloEstado as $v => $lbl): ?>
          <option value="<?= e((string)$v) ?>"<?= ($f['estado'] ?? 'todos') === (string)$v ? ' selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach ?>
      </select>
    </label>

    <label class="cfg-filtro">
      <span class="cfg-filtro__lbl">Ordem</span>
      <select class="adm-select" name="ordem" aria-label="Ordenar campos">
        <?php foreach ($rotuloOrdem as $v => $lbl): ?>
          <option value="<?= e((string)$v) ?>"<?= ($f['ordem'] ?? 'sugerida') === (string)$v ? ' selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach ?>
      </select>
    </label>

    <button class="adm-btn" type="submit"><?= icon('filter_alt') ?>Aplicar</button>
    <?php if ($filtrando): ?>
      <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/configuracoes')) ?>"><?= icon('filter_alt_off') ?>Limpar</a>
    <?php endif ?>
  </form>

  <p class="cfg-contagem">
    <?php if ($filtrando): ?>
      Mostrando <strong class="num"><?= e(num((int)($resumo['exibidos'] ?? 0))) ?></strong>
      de <span class="num"><?= e(num((int)($resumo['total'] ?? 0))) ?></span> parâmetros.
    <?php else: ?>
      <span class="num"><?= e(num((int)($resumo['total'] ?? 0))) ?></span> parâmetros em
      <span class="num"><?= e(num((int)($resumo['grupos'] ?? 0))) ?></span> grupos.
      Cada grupo é salvo por inteiro — ou tudo passa, ou nada é gravado.
    <?php endif ?>
  </p>
</section>

<?php /* ================================================================== grupos */ ?>
<?php if ($grupos === []): ?>
  <section class="adm-card">
    <div class="adm-empty">
      <?= icon($campos === [] ? 'database_off' : 'search_off') ?>
      <h2 class="adm-empty__title">
        <?= e((string)($campos === [] ? ($texto['vazio_tabela'] ?? 'Tabela vazia') : ($texto['vazio_titulo'] ?? 'Nada encontrado'))) ?>
      </h2>
      <p class="adm-empty__txt">
        <?= e((string)($campos === [] ? ($texto['vazio_tabela_txt'] ?? '') : ($texto['vazio_txt'] ?? ''))) ?>
      </p>
      <?php if ($campos !== []): ?>
        <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/configuracoes')) ?>"><?= icon('filter_alt_off') ?>Limpar filtros</a>
      <?php endif ?>
    </div>
  </section>
<?php endif ?>

<?php foreach ($grupos as $grupo): ?>
  <?php $itens = (array)$grupo['itens']; if ($itens === []) { continue; } ?>
  <section class="adm-card cfg-grupo" id="grupo-<?= e((string)$grupo['chave']) ?>">
    <form method="post" action="<?= e($acaoSalvar) ?>" data-cfg-form>
      <?= csrf_field() ?>
      <input type="hidden" name="acao" value="grupo">
      <input type="hidden" name="grupo" value="<?= e((string)$grupo['chave']) ?>">

      <div class="adm-card__head">
        <span class="cfg-grupo__ico"><?= icon((string)$grupo['icon']) ?></span>
        <h2 class="adm-card__title">
          <?= e((string)$grupo['label']) ?>
          <?php if ((string)$grupo['desc'] !== ''): ?><small><?= e((string)$grupo['desc']) ?></small><?php endif ?>
        </h2>
        <div class="adm-card__tools">
          <span class="cfg-grupo__conta num"><?= e((string)count($itens)) ?> <?= count($itens) === 1 ? 'parâmetro' : 'parâmetros' ?></span>
        </div>
      </div>

      <div class="adm-card__body cfg-lista">
        <?php foreach ($itens as $c): ?>
          <?php
            $chave   = (string)$c['chave'];
            $tipo    = (string)$c['tipo'];
            $erro    = (string)$c['erro'];
            $id      = 'cfg-' . preg_replace('/[^a-z0-9_-]/', '', $chave);
            $largo   = $tipo === 'json' || ($tipo === 'string' && (int)$c['linhas'] > 1);
            $classes = 'cfg-item' . ($largo ? ' cfg-item--largo' : '')
                     . ($erro !== '' ? ' is-invalid' : '')
                     . (!empty($c['perigo']) ? ' cfg-item--perigo' : '');
          ?>
          <div class="<?= e($classes) ?>" id="<?= e($id) ?>-linha">
            <input type="hidden" name="campos[]" value="<?= e($chave) ?>">

            <div class="cfg-item__id">
              <label class="adm-label cfg-item__label" for="<?= e($id) ?>"><?= e((string)$c['label']) ?></label>
              <code class="cfg-item__chave"><?= e($chave) ?></code>
              <?php if ((string)$c['dica'] !== ''): ?>
                <p class="adm-hint cfg-item__dica"><?= e((string)$c['dica']) ?></p>
              <?php endif ?>
            </div>

            <div class="cfg-item__campo">
              <?php if ($tipo === 'bool'): ?>
                <?php
                  /* O checkbox só é enviado quando marcado. O `campos[]` acima é quem informa
                     que este parâmetro estava na tela — é assim que "desligar" chega ao
                     servidor como uma decisão, e não como um campo que sumiu. */
                  $ligadoAgora = $erro !== '' ? ((string)$c['valor'] === '1') : (bool)$c['ligado'];
                ?>
                <label class="adm-switch cfg-switch">
                  <input class="adm-switch__in" type="checkbox" id="<?= e($id) ?>"
                         name="campo[<?= e($chave) ?>]" value="1"<?= $ligadoAgora ? ' checked' : '' ?>>
                  <span class="adm-switch__ui"></span>
                  <span class="cfg-switch__txt cfg-switch__txt--<?= $ligadoAgora ? 'on' : 'off' ?>">
                    <?= e($ligadoAgora ? (string)$c['rot_on'] : (string)$c['rot_off']) ?>
                  </span>
                </label>

              <?php elseif ($tipo === 'int'): ?>
                <div class="cfg-num">
                  <input class="adm-input num cfg-num__in" type="text" inputmode="numeric"
                         id="<?= e($id) ?>" name="campo[<?= e($chave) ?>]"
                         value="<?= e((string)$c['valor']) ?>"
                         <?= $c['min'] !== null ? 'data-min="' . e((string)$c['min']) . '"' : '' ?>
                         <?= $c['max'] !== null ? 'data-max="' . e((string)$c['max']) . '"' : '' ?>
                         aria-describedby="<?= e($id) ?>-faixa">
                  <?php if ((string)$c['unidade'] !== ''): ?>
                    <span class="cfg-num__un"><?= e((string)$c['unidade']) ?></span>
                  <?php endif ?>
                </div>
                <?php if ($c['min'] !== null || $c['max'] !== null): ?>
                  <p class="adm-hint cfg-faixa" id="<?= e($id) ?>-faixa">
                    Aceito: <span class="num"><?= e((string)($c['min'] ?? '—')) ?></span>
                    a <span class="num"><?= e((string)($c['max'] ?? '—')) ?></span>
                  </p>
                <?php endif ?>

              <?php elseif ($tipo === 'json'): ?>
                <textarea class="adm-textarea cfg-json" id="<?= e($id) ?>" name="campo[<?= e($chave) ?>]"
                          rows="7" spellcheck="false" data-cfg-json
                          aria-describedby="<?= e($id) ?>-ajuda"><?= e((string)$c['valor']) ?></textarea>
                <p class="adm-hint" id="<?= e($id) ?>-ajuda"><?= e((string)($texto['json_ajuda'] ?? '')) ?></p>

              <?php elseif ((int)$c['linhas'] > 1): ?>
                <textarea class="adm-textarea" id="<?= e($id) ?>" name="campo[<?= e($chave) ?>]"
                          rows="<?= e((string)max(2, (int)$c['linhas'])) ?>"
                          <?= $c['max_len'] !== null ? 'maxlength="' . e((string)$c['max_len']) . '"' : '' ?>><?= e((string)$c['valor']) ?></textarea>
                <?php if ($c['max_len'] !== null): ?>
                  <p class="adm-hint">Até <span class="num"><?= e((string)$c['max_len']) ?></span> caracteres.</p>
                <?php endif ?>

              <?php else: ?>
                <input class="adm-input" type="text" id="<?= e($id) ?>" name="campo[<?= e($chave) ?>]"
                       value="<?= e((string)$c['valor']) ?>"
                       <?= $c['max_len'] !== null ? 'maxlength="' . e((string)$c['max_len']) . '"' : '' ?>>
              <?php endif ?>

              <?php if ($erro !== ''): ?>
                <p class="adm-error" role="alert"><?= icon('error') ?><?= e($erro) ?></p>
              <?php endif ?>
            </div>

            <div class="cfg-item__meta">
              <?= $seloTipo($tipo) ?>
              <?php if (!empty($c['alterada'])): ?>
                <span class="cfg-meta__linha" title="Gravado em <?= e($quando((string)$c['quando'])) ?>">
                  <?= icon('history') ?><?= e($quando((string)$c['quando'])) ?><?php if (!empty($c['quem'])): ?> · <?= e((string)$c['quem']) ?><?php endif ?>
                </span>
              <?php else: ?>
                <span class="cfg-meta__linha cfg-meta__linha--semente" title="Valor como veio do patch db/patch_v6_admin.php">
                  <?= icon('spa') ?>Valor semeado
                </span>
              <?php endif ?>

              <?php if ($c['anterior'] !== null && (string)$c['anterior'] !== (string)$c['gravado']): ?>
                <span class="cfg-antes" title="<?= e((string)($texto['anterior'] ?? 'Valor anterior')) ?>: <?= e((string)$c['anterior']) ?>">
                  <?= icon('undo') ?>era <code class="cfg-code"><?= e($emUmaLinha((string)$c['anterior'])) ?></code>
                </span>
              <?php endif ?>

              <?php if (!empty($c['protegida'])): ?>
                <span class="cfg-meta__linha cfg-meta__linha--lock" title="<?= e((string)($texto['protegida'] ?? '')) ?>">
                  <?= icon('lock') ?>Lida pelo código
                </span>
              <?php else: ?>
                <a class="cfg-remover" href="<?= e($comFiltros(['excluir' => $chave])) ?>#confirmar-exclusao">
                  <?= icon('delete') ?>Remover
                </a>
              <?php endif ?>
            </div>
          </div>
        <?php endforeach ?>
      </div>

      <div class="adm-card__foot cfg-foot">
        <span class="cfg-foot__aviso"><?= icon('info') ?>O jogo passa a ler o novo valor na requisição seguinte.</span>
        <button class="adm-btn adm-btn--primary" type="submit" data-cfg-salvar>
          <?= icon('save') ?>Salvar <?= e(mb_strtolower((string)$grupo['label'])) ?>
        </button>
      </div>
    </form>
  </section>
<?php endforeach ?>

<?php /* ============================================================== paginação */ ?>
<?php if ((int)($pag['paginas'] ?? 1) > 1): ?>
  <nav class="adm-card cfg-pager" aria-label="Paginação dos grupos">
    <div class="adm-card__body adm-pager">
      <span class="adm-pager__info">
        Grupos <span class="num"><?= e((string)((((int)$pag['pagina'] - 1) * (int)$pag['por']) + 1)) ?></span>–<span class="num"><?= e((string)min((int)$pag['total'], (int)$pag['pagina'] * (int)$pag['por'])) ?></span>
        de <span class="num"><?= e((string)$pag['total']) ?></span>
      </span>
      <?php $atual = (int)$pag['pagina']; ?>
      <a class="adm-pager__btn<?= $atual <= 1 ? ' is-disabled' : '' ?>" href="<?= e($comFiltros(['p' => max(1, $atual - 1)])) ?>" aria-label="Página anterior"><?= icon('chevron_left') ?></a>
      <?php for ($i = 1; $i <= (int)$pag['paginas']; $i++): ?>
        <a class="adm-pager__btn<?= $i === $atual ? ' is-active' : '' ?>" href="<?= e($comFiltros(['p' => $i])) ?>"<?= $i === $atual ? ' aria-current="page"' : '' ?>><?= e((string)$i) ?></a>
      <?php endfor ?>
      <a class="adm-pager__btn<?= $atual >= (int)$pag['paginas'] ? ' is-disabled' : '' ?>" href="<?= e($comFiltros(['p' => min((int)$pag['paginas'], $atual + 1)])) ?>" aria-label="Próxima página"><?= icon('chevron_right') ?></a>
    </div>
  </nav>
<?php endif ?>

<?php /* ============================================================ nova chave */ ?>
<?php
  $errosNova  = (array)($nova['erros'] ?? []);
  $valoresNova = (array)($nova['valores'] ?? []);
  $abrirNova  = $errosNova !== [] || !empty($f['abrir_nova']);
?>
<section class="adm-card cfg-nova">
  <details<?= $abrirNova ? ' open' : '' ?>>
    <summary class="adm-card__head cfg-nova__head">
      <?= icon('add_circle', 'cfg-nova__ico') ?>
      <span class="adm-card__title">
        Criar chave
        <small>Um parâmetro novo só é lido pelo jogo depois que o código pedir por ele.</small>
      </span>
      <?= icon('expand_more', 'cfg-nova__chev') ?>
    </summary>

    <form class="adm-card__body adm-form" method="post" action="<?= e($acaoSalvar) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="acao" value="criar">

      <div class="adm-form__row adm-form__row--3">
        <div class="adm-field<?= isset($errosNova['key']) ? ' is-invalid' : '' ?>">
          <label class="adm-label" for="nova-key">Chave <span class="adm-label__req">*</span></label>
          <input class="adm-input" type="text" id="nova-key" name="key" value="<?= e((string)($valoresNova['key'] ?? '')) ?>"
                 placeholder="ex.: loot_multiplier" maxlength="64" spellcheck="false">
          <?php if (isset($errosNova['key'])): ?>
            <p class="adm-error" role="alert"><?= icon('error') ?><?= e((string)$errosNova['key']) ?></p>
          <?php else: ?>
            <p class="adm-hint">Minúsculas, números e _ . É o nome que o código vai procurar.</p>
          <?php endif ?>
        </div>

        <div class="adm-field<?= isset($errosNova['kind']) ? ' is-invalid' : '' ?>">
          <label class="adm-label" for="nova-kind">Tipo <span class="adm-label__req">*</span></label>
          <select class="adm-select" id="nova-kind" name="kind">
            <?php foreach (AdminSettingsRepo::TIPOS as $t): ?>
              <option value="<?= e($t) ?>"<?= ($valoresNova['kind'] ?? 'string') === $t ? ' selected' : '' ?>>
                <?= e((string)($tipos[$t]['label'] ?? $t)) ?>
              </option>
            <?php endforeach ?>
          </select>
          <?php if (isset($errosNova['kind'])): ?>
            <p class="adm-error" role="alert"><?= icon('error') ?><?= e((string)$errosNova['kind']) ?></p>
          <?php endif ?>
        </div>

        <div class="adm-field<?= isset($errosNova['grupo']) ? ' is-invalid' : '' ?>">
          <label class="adm-label" for="nova-grupo">Grupo</label>
          <input class="adm-input" type="text" id="nova-grupo" name="grupo_novo" list="cfg-grupos"
                 value="<?= e((string)($valoresNova['grupo'] ?? '')) ?>" placeholder="geral" maxlength="32" spellcheck="false">
          <datalist id="cfg-grupos">
            <?php foreach (array_keys((array)($f['cat_grupos'] ?? [])) as $g): ?>
              <option value="<?= e((string)$g) ?>"></option>
            <?php endforeach ?>
          </datalist>
          <?php if (isset($errosNova['grupo'])): ?>
            <p class="adm-error" role="alert"><?= icon('error') ?><?= e((string)$errosNova['grupo']) ?></p>
          <?php else: ?>
            <p class="adm-hint">Em branco vira <code class="cfg-code">geral</code>.</p>
          <?php endif ?>
        </div>
      </div>

      <div class="adm-field<?= isset($errosNova['label']) ? ' is-invalid' : '' ?>">
        <label class="adm-label" for="nova-label">Rótulo <span class="adm-label__req">*</span></label>
        <input class="adm-input" type="text" id="nova-label" name="label" value="<?= e((string)($valoresNova['label'] ?? '')) ?>"
               placeholder="O que este parâmetro controla, em uma linha" maxlength="120">
        <?php if (isset($errosNova['label'])): ?>
          <p class="adm-error" role="alert"><?= icon('error') ?><?= e((string)$errosNova['label']) ?></p>
        <?php endif ?>
      </div>

      <div class="adm-field<?= isset($errosNova['value']) ? ' is-invalid' : '' ?>">
        <label class="adm-label" for="nova-value">Valor inicial</label>
        <textarea class="adm-textarea cfg-nova__valor" id="nova-value" name="novo[value]" rows="3"
                  spellcheck="false" placeholder="1 para um interruptor ligado; {} para um JSON vazio"><?= e((string)($valoresNova['value'] ?? '')) ?></textarea>
        <?php if (isset($errosNova['value'])): ?>
          <p class="adm-error" role="alert"><?= icon('error') ?><?= e((string)$errosNova['value']) ?></p>
        <?php else: ?>
          <p class="adm-hint">Validado pelo tipo escolhido acima, com as mesmas regras da edição.</p>
        <?php endif ?>
      </div>

      <div class="adm-form__actions">
        <button class="adm-btn adm-btn--primary" type="submit"><?= icon('add') ?>Criar chave</button>
        <span class="adm-hint adm-spacer">Chaves criadas aqui podem ser removidas; as que o código lê, não.</span>
      </div>
    </form>
  </details>
</section>

<?php endif ?>
