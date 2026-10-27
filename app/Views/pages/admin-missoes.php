<?php declare(strict_types=1);

/**
 * Tela ADMIN · MISSÕES (/admin/missoes).
 *
 * Escopo: $page, $player, $nav, $server, $data, $flash, $csrf. Layout: layouts/admin.php.
 *
 * A view NÃO consulta o banco e NÃO valida nada: tudo chega pronto de
 * App\Controllers\Admin\MissionsController + App\Repo\MissionRepo. Aqui só existe desenho.
 *
 * A tela tem TRÊS estados, e um só de cada vez ($data['modo']):
 *   ''         lista com busca, filtros, ordenação e paginação;
 *   nova/editar formulário;
 *   excluir     confirmação (ou o bloqueio, quando há jogador em campo).
 *
 * Formulário e confirmação aparecem SOZINHOS, sem a lista embaixo: o formulário tem 22
 * campos e a confirmação é uma pergunta — as duas coisas perdem se dividirem a tela com
 * uma tabela de doze linhas que o operador não vai olhar naquele momento.
 *
 * Sem JavaScript próprio nesta tela. Todo estado é query string (?nova, ?editar=, ?excluir=)
 * e todo envio é <form>: funciona com script desligado, sobrevive ao F5, dá para mandar o
 * link para outra pessoa e sai inteiro no `curl` — que é como esta tela é conferida.
 */

$cromo   = (array)($data['cromo'] ?? []);
$vocab   = (array)($data['vocab'] ?? []);
$resumo  = (array)($data['resumo'] ?? []);
$lista   = (array)($data['lista'] ?? []);
$filtros = (array)($data['filtros'] ?? []);
$modo    = (string)($data['modo'] ?? '');
$edicao  = is_array($data['edicao'] ?? null) ? $data['edicao'] : null;
$alvo    = is_array($data['alvo'] ?? null) ? $data['alvo'] : null;
$itens   = (array)($data['itens'] ?? []);
$sugest  = (array)($data['sugestoes'] ?? []);
$squadMax= (int)($data['squad_max'] ?? 4);
$destaque= (int)($data['destaque'] ?? 0);
$nRec    = (int)($data['linhas_recompensa'] ?? 6);

$colunas = (array)($cromo['colunas'] ?? []);
$micro   = (array)($cromo['micro'] ?? []);
$vazios  = (array)($cromo['vazios'] ?? []);
$campos  = (array)($cromo['campos'] ?? []);
$fTxt    = (array)($cromo['form'] ?? []);
$exTxt   = (array)($cromo['excluir'] ?? []);

$linhas  = (array)($lista['linhas'] ?? []);
$total   = (int)($lista['total'] ?? 0);
$geral   = (int)($lista['total_geral'] ?? 0);
$pagina  = (int)($lista['pagina'] ?? 1);
$paginas = (int)($lista['paginas'] ?? 1);

/* Filtros ativos = o que estreitou a lista. `ordem` e `pagina` não contam: ordenar não
   esconde nada, e a página 3 não é um filtro — dizer "limpe os filtros" para quem só virou
   a página seria mentira. */
$filtrosAtivos = array_filter([
    'q'           => (string)($filtros['q'] ?? ''),
    'tipo'        => (string)($filtros['tipo'] ?? ''),
    'origem'      => (string)($filtros['origem'] ?? ''),
    'dificuldade' => (string)($filtros['dificuldade'] ?? ''),
    'estado'      => (string)($filtros['estado'] ?? ''),
], static fn(string $v): bool => $v !== '');

/** Query string da listagem — mantém o operador no mesmo lugar depois de qualquer desvio. */
$contexto = array_filter([
    'q'           => (string)($filtros['q'] ?? ''),
    'tipo'        => (string)($filtros['tipo'] ?? ''),
    'origem'      => (string)($filtros['origem'] ?? ''),
    'dificuldade' => (string)($filtros['dificuldade'] ?? ''),
    'estado'      => (string)($filtros['estado'] ?? ''),
    'ordem'       => (string)($filtros['ordem'] ?? '') !== 'ordem' ? (string)($filtros['ordem'] ?? '') : '',
    'pagina'      => $pagina > 1 ? (string)$pagina : '',
], static fn(string $v): bool => $v !== '');

/* =========================================================================================
   AUXILIARES DE DESENHO
   ========================================================================================= */

/** Selo de um valor de lista (tipo, origem, dificuldade…), com a cor que o vocabulário deu. */
$badge = static function (?string $valor, array $lista, string $vazio = '—'): string {
    $v = (string)$valor;
    if ($v === '' || !isset($lista[$v])) {
        return $v === ''
            ? '<span class="mis-none">' . e($vazio) . '</span>'
            : '<span class="adm-badge adm-badge--muted" title="Valor fora do vocabulário do jogo">'
              . e(mb_strtoupper($v)) . '</span>';
    }
    $item = (array)$lista[$v];
    $orfao = !empty($item['orfao']);
    return '<span class="adm-badge adm-badge--' . e((string)($item['tom'] ?? 'muted')) . ($orfao ? ' mis-orfao' : '') . '"'
         . ($orfao ? ' title="Valor gravado no banco que não está no vocabulário do jogo"' : '')
         . '>' . e((string)$item['label']) . '</span>';
};

/** Pips de dificuldade: o MESMO desenho de 1 a 4 que o jogador vê no cartão da HUD. */
$pips = static function (?string $dif) use ($vocab, $micro): string {
    $d = (string)$dif;
    $lista = (array)($vocab['dificuldades'] ?? []);
    if ($d === '' || !isset($lista[$d])) {
        return '<span class="mis-none">—</span>';
    }
    $nivel = (int)($lista[$d]['nivel'] ?? 0);
    $out = '<span class="mis-dif mis-dif--' . e((string)($lista[$d]['tom'] ?? 'muted')) . '" title="'
         . e(sprintf((string)($micro['pips'] ?? 'Dificuldade %s de 4'), (string)$nivel)) . '">';
    if ($nivel > 0) {
        $out .= '<span class="mis-dif__pips" aria-hidden="true">';
        for ($i = 1; $i <= 4; $i++) {
            $out .= '<i class="mis-dif__pip' . ($i <= $nivel ? ' is-on' : '') . '"></i>';
        }
        $out .= '</span>';
    }
    return $out . '<span class="mis-dif__txt">' . e((string)$lista[$d]['label']) . '</span></span>';
};

/** Recompensa -> texto curto do selo (a quantia quando há uma, o nome do item quando é item). */
$premioTxt = static function (array $r) use ($vocab): array {
    $especie = (array)(($vocab['recompensas'] ?? [])[$r['kind']] ?? []);
    $rotulo  = (string)($especie['label'] ?? mb_strtoupper((string)$r['kind']));
    $forma   = (string)($especie['forma'] ?? 'quantia');
    $tom     = (string)($especie['tom'] ?? 'muted');

    $texto = match ($forma) {
        'item'  => (string)($r['item'] ?? 'Item removido do catálogo'),
        'unico' => $rotulo,
        default => num((int)$r['amount']) . ' ' . $rotulo,
    };
    return ['texto' => $texto, 'tom' => $tom, 'titulo' => $rotulo . ' · ' . $texto];
};

/** Campo de formulário com erro por campo. Devolve a classe e o parágrafo de erro. */
$erroDe = static function (string $campo) use ($edicao): array {
    $msg = (string)((($edicao['erros'] ?? [])[$campo]) ?? '');
    return [
        'cls'  => $msg !== '' ? ' is-invalid' : '',
        'html' => $msg !== ''
            ? '<p class="adm-error" id="err-' . e($campo) . '">' . icon('error') . '<span>' . e($msg) . '</span></p>'
            : '',
        'aria' => $msg !== '' ? ' aria-invalid="true" aria-describedby="err-' . e($campo) . '"' : '',
    ];
};

/** Valor atual de um campo do formulário, já escapado. */
$val = static function (string $campo, string $padrao = '') use ($edicao): string {
    $v = ($edicao['valores'] ?? [])[$campo] ?? null;
    return $v === null ? $padrao : (string)$v;
};

/** <option> de uma lista do vocabulário. */
$options = static function (array $lista, string $atual, string $vazio = ''): string {
    $out = $vazio !== '' ? '<option value="">' . e($vazio) . '</option>' : '';
    foreach ($lista as $chave => $item) {
        if ((string)$chave === '') { continue; }
        $rot = (string)($item['label'] ?? $chave);
        if (!empty($item['nota'])) { $rot .= ' — ' . (string)$item['nota']; }
        if (!empty($item['orfao'])) { $rot .= ' (fora do vocabulário)'; }
        $out .= '<option value="' . e((string)$chave) . '"'
              . ((string)$chave === $atual ? ' selected' : '') . '>' . e($rot) . '</option>';
    }
    return $out;
};

/** Campos ocultos que reenviam a listagem: o POST volta para a mesma busca e a mesma página. */
$contextoOculto = static function () use ($contexto): string {
    $out = '';
    foreach ($contexto as $k => $v) {
        $out .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    }
    return $out;
};

?>
<div class="mis">

  <!-- =============================================================== RESUMO DO CATÁLOGO -->
  <?php
    /* Quatro números que respondem "o catálogo está saudável?" antes de qualquer rolagem.
       Os dois últimos são avisos disfarçados de KPI: missão em campo é missão que não se
       pode apagar, e missão sem recompensa é contrato que o jogador cumpre de graça. */
    $kpis = [
        ['icone' => 'flag',          'tom' => 'lime',   'rotulo' => 'Missões cadastradas',
         'valor' => (int)($resumo['total'] ?? 0),
         'sub'   => num((int)($resumo['publicadas'] ?? 0)) . ' no quadro'],
        ['icone' => 'lock',          'tom' => 'amber',  'rotulo' => 'Bloqueadas',
         'valor' => (int)($resumo['bloqueadas'] ?? 0),
         'sub'   => 'fora do quadro'],
        ['icone' => 'groups',        'tom' => 'cyan',   'rotulo' => 'Em campo agora',
         'valor' => (int)($resumo['em_campo'] ?? 0),
         'sub'   => num((int)($resumo['jogadores'] ?? 0)) . ' jogador(es)'],
        ['icone' => 'money_off',     'tom' => 'red',    'rotulo' => 'Sem recompensa',
         'valor' => (int)($resumo['sem_recompensa'] ?? 0),
         'sub'   => 'nada a pagar'],
    ];
  ?>
  <section class="mis-kpis" aria-label="Resumo do catálogo de missões">
    <?php foreach ($kpis as $k): ?>
      <article class="adm-kpi adm-kpi--<?= e((string)$k['tom']) ?>">
        <span class="adm-kpi__ico"><?= icon((string)$k['icone']) ?></span>
        <span class="adm-kpi__txt">
          <span class="adm-kpi__label"><?= e((string)$k['rotulo']) ?></span>
          <span class="adm-kpi__value num"><?= e(num((int)$k['valor'])) ?><small><?= e((string)$k['sub']) ?></small></span>
        </span>
      </article>
    <?php endforeach ?>
  </section>

<?php if ($modo === 'excluir' && $alvo !== null): ?>
  <!-- ==================================================================== CONFIRMAÇÃO -->
  <?php
    $m       = (array)$alvo['missao'];
    $uso     = (array)$alvo['uso'];
    $premios = (array)$alvo['recompensas'];
    $emCampo = (int)($uso['em_campo'] ?? 0);
    $bloqueado = $emCampo > 0;
  ?>
  <section class="adm-card mis-danger<?= $bloqueado ? ' is-blocked' : '' ?>">
    <div class="adm-card__head">
      <h2 class="adm-card__title">
        <?= icon($bloqueado ? 'block' : 'delete_forever') ?>
        <?= e((string)($exTxt['titulo'] ?? 'Excluir missão')) ?>
        <small><?= e((string)$m['name']) ?> · <?= e((string)$m['slug']) ?></small>
      </h2>
    </div>
    <div class="adm-card__body mis-danger__body">
      <?php if ($bloqueado): ?>
        <?php /* Bloqueio, não aviso: player_missions cai por CASCADE e apagar aqui apagaria
                 o progresso de gente que está jogando agora. A tela diz quantos são e qual
                 é a saída que não destrói nada. */ ?>
        <p class="mis-danger__lead">
          <?= e(sprintf((string)($exTxt['bloqueio'] ?? ''), (string)$emCampo)) ?>
        </p>
        <p class="mis-danger__out"><?= icon('lightbulb') ?><span><?= e((string)($exTxt['saida'] ?? '')) ?></span></p>
        <ul class="mis-danger__facts">
          <?php foreach ((array)($uso['por_status'] ?? []) as $st => $n): ?>
            <li><span class="mis-danger__k"><?= e(mb_strtoupper((string)$st)) ?></span><span class="num"><?= e(num((int)$n)) ?></span></li>
          <?php endforeach ?>
        </ul>
        <div class="adm-form__actions">
          <a class="adm-btn adm-btn--primary" href="<?= e(url('admin/missoes', ['editar' => (string)$m['id']] + $contexto)) ?>">
            <?= icon('edit') ?><span>Abrir para marcar como bloqueada</span>
          </a>
          <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/missoes', $contexto)) ?>"><?= e((string)($exTxt['voltar'] ?? 'Voltar à lista')) ?></a>
        </div>
      <?php else: ?>
        <p class="mis-danger__lead">
          <?= e(sprintf((string)($exTxt['pergunta'] ?? 'Excluir %s?'), '“' . (string)$m['name'] . '”')) ?>
        </p>
        <p class="mis-danger__note">
          <?= e(sprintf((string)($exTxt['consequencia'] ?? ''), (string)count($premios))) ?>
        </p>
        <?php if ((int)($uso['total'] ?? 0) > 0): ?>
          <?php /* Ninguém está EM CAMPO, mas há histórico (concluída/disponível): a exclusão
                   é permitida e o número precisa aparecer, porque esses registros somem junto. */ ?>
          <p class="mis-danger__note">
            <?= e(sprintf('%d registro(s) de progresso já concluído ou disponível também serão apagados.', (int)$uso['total'])) ?>
          </p>
        <?php endif ?>
        <form class="adm-form__actions" method="post" action="<?= e(url('admin/missoes/excluir')) ?>">
          <?= csrf_field() ?>
          <?= $contextoOculto() ?>
          <input type="hidden" name="id" value="<?= e((string)$m['id']) ?>">
          <button class="adm-btn adm-btn--danger" type="submit">
            <?= icon('delete_forever') ?><span><?= e((string)($exTxt['confirmar'] ?? 'Sim, excluir')) ?></span>
          </button>
          <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/missoes', $contexto)) ?>"><?= e((string)($exTxt['voltar'] ?? 'Voltar à lista')) ?></a>
        </form>
      <?php endif ?>
    </div>
  </section>

<?php elseif (($modo === 'nova' || $modo === 'editar') && $edicao !== null): ?>
  <!-- ====================================================================== FORMULÁRIO -->
  <?php
    $novo   = (int)($edicao['id'] ?? 0) === 0;
    $erros  = (array)($edicao['erros'] ?? []);
    $usoEd  = is_array($edicao['uso'] ?? null) ? $edicao['uso'] : null;
    $premEd = array_values((array)($edicao['recompensas'] ?? []));
  ?>
  <form class="adm-card mis-form" method="post" action="<?= e(url('admin/missoes/salvar')) ?>" novalidate>
    <?= csrf_field() ?>
    <?= $contextoOculto() ?>
    <input type="hidden" name="id" value="<?= e((string)($edicao['id'] ?? 0)) ?>">

    <div class="adm-card__head">
      <h2 class="adm-card__title">
        <?= icon($novo ? 'add_circle' : 'edit') ?>
        <?= e((string)($novo ? ($fTxt['nova'] ?? 'Nova missão') : ($fTxt['editar'] ?? 'Editar missão'))) ?>
        <?php if (!$novo): ?><small><?= e($val('slug')) ?></small><?php endif ?>
      </h2>
      <div class="adm-card__tools">
        <?php if ($usoEd !== null && (int)$usoEd['em_campo'] > 0): ?>
          <?php /* O aviso mora no topo do formulário, e não só na exclusão: mudar o slug ou a
                   meta de uma missão que está em campo desalinha o progresso de quem já a
                   aceitou. Quem edita precisa saber disso ANTES de digitar. */ ?>
          <span class="adm-badge adm-badge--warn" title="<?= e(sprintf((string)($micro['em_campo'] ?? '%s'), (string)$usoEd['em_campo'])) ?>">
            <?= e(num((int)$usoEd['em_campo'])) ?> EM CAMPO
          </span>
        <?php endif ?>
        <a class="adm-card__link" href="<?= e(url('admin/missoes', $contexto)) ?>"><?= icon('arrow_back') ?>Voltar à lista</a>
      </div>
    </div>

    <?php if ($erros !== []): ?>
      <p class="mis-form__alerta" role="alert">
        <?= icon('error') ?><span><?= e(sprintf('%d campo(s) precisam de correção. Nada foi gravado.', count($erros))) ?></span>
      </p>
    <?php endif ?>

    <div class="adm-card__body adm-form mis-form__body">

      <!-- ------------------------------------------------------------------ identidade -->
      <fieldset class="mis-fs">
        <legend class="mis-fs__leg"><?= icon('badge') ?><span><?= e((string)($fTxt['identidade'] ?? 'Identidade')) ?></span></legend>

        <div class="adm-form__row">
          <?php $er = $erroDe('slug'); ?>
          <div class="adm-field<?= $er['cls'] ?>">
            <label class="adm-label" for="f-slug"><?= e((string)($campos['slug']['label'] ?? 'Slug')) ?><span class="adm-label__req">*</span></label>
            <input class="adm-input" id="f-slug" name="slug" type="text" maxlength="64" required
                   value="<?= e($val('slug')) ?>" placeholder="sinal-perdido" spellcheck="false"<?= $er['aria'] ?>>
            <?= $er['html'] ?>
            <p class="adm-hint"><?= e((string)($campos['slug']['dica'] ?? '')) ?></p>
          </div>

          <?php $er = $erroDe('name'); ?>
          <div class="adm-field<?= $er['cls'] ?>">
            <label class="adm-label" for="f-name"><?= e((string)($campos['name']['label'] ?? 'Nome')) ?><span class="adm-label__req">*</span></label>
            <input class="adm-input" id="f-name" name="name" type="text" maxlength="120" required
                   value="<?= e($val('name')) ?>" placeholder="SINAL PERDIDO"<?= $er['aria'] ?>>
            <?= $er['html'] ?>
            <p class="adm-hint"><?= e((string)($campos['name']['dica'] ?? '')) ?></p>
          </div>
        </div>

        <div class="adm-form__row">
          <div class="adm-field">
            <label class="adm-label" for="f-giver"><?= e((string)($campos['giver']['label'] ?? 'Contratante')) ?></label>
            <input class="adm-input" id="f-giver" name="giver" type="text" maxlength="80" list="mis-givers" value="<?= e($val('giver')) ?>">
            <p class="adm-hint"><?= e((string)($campos['giver']['dica'] ?? '')) ?></p>
          </div>
          <div class="adm-field">
            <label class="adm-label" for="f-region"><?= e((string)($campos['region']['label'] ?? 'Região')) ?></label>
            <input class="adm-input" id="f-region" name="region" type="text" maxlength="80" list="mis-regioes" value="<?= e($val('region')) ?>">
            <p class="adm-hint"><?= e((string)($campos['region']['dica'] ?? '')) ?></p>
          </div>
        </div>

        <?php /* Datalist, e não select: região e contratante são texto livre no banco. O que
                 já existe é oferecido para o operador não criar "FLORESTA VERDE " com espaço
                 no fim e dividir o catálogo em dois. */ ?>
        <datalist id="mis-regioes"><?php foreach ((array)($sugest['regioes'] ?? []) as $r): ?><option value="<?= e((string)$r) ?>"></option><?php endforeach ?></datalist>
        <datalist id="mis-givers"><?php foreach ((array)($sugest['contratantes'] ?? []) as $g): ?><option value="<?= e((string)$g) ?>"></option><?php endforeach ?></datalist>

        <div class="adm-form__row adm-form__row--3">
          <div class="adm-field">
            <label class="adm-label" for="f-image"><?= e((string)($campos['image']['label'] ?? 'Imagem')) ?></label>
            <?php /* Campo de caminho + envio de arquivo (components/admin-upload.php). O
                     <input name="image"> continua sendo o mesmo de antes — quem já sabe o
                     caminho digita; quem tem o arquivo na mão sobe e o caminho se preenche. */ ?>
            <?php component('admin-upload', [
                'id'          => 'f-image',
                'name'        => 'image',
                'entidade'    => 'missoes',
                'valor'       => $val('image'),
                'maxlength'   => 160,
                'placeholder' => 'missions/mission-sinal-perdido.jpg',
            ]) ?>
            <p class="adm-hint"><?= e((string)($campos['image']['dica'] ?? '')) ?></p>
          </div>
          <?php $er = $erroDe('sort_order'); ?>
          <div class="adm-field<?= $er['cls'] ?>">
            <label class="adm-label" for="f-sort"><?= e((string)($campos['sort_order']['label'] ?? 'Ordem')) ?></label>
            <input class="adm-input num" id="f-sort" name="sort_order" type="number" min="0" max="65535" step="1"
                   value="<?= e($val('sort_order', '0')) ?>"<?= $er['aria'] ?>>
            <?= $er['html'] ?>
            <p class="adm-hint"><?= e((string)($campos['sort_order']['dica'] ?? '')) ?></p>
          </div>
          <div class="adm-field mis-field--switch">
            <span class="adm-label"><?= e((string)($campos['locked']['label'] ?? 'Bloqueada')) ?></span>
            <label class="adm-switch">
              <input class="adm-switch__in" type="checkbox" name="locked" value="1"<?= (int)$val('locked', '0') === 1 ? ' checked' : '' ?>>
              <span class="adm-switch__ui"></span>
              <span><?= (int)$val('locked', '0') === 1 ? 'Fora do quadro' : 'No quadro de contratos' ?></span>
            </label>
            <p class="adm-hint"><?= e((string)($campos['locked']['dica'] ?? '')) ?></p>
          </div>
        </div>
      </fieldset>

      <!-- -------------------------------------------------------------------- operação -->
      <fieldset class="mis-fs">
        <legend class="mis-fs__leg"><?= icon('explore') ?><span><?= e((string)($fTxt['operacao'] ?? 'Operação')) ?></span></legend>

        <div class="adm-form__row adm-form__row--3">
          <?php $er = $erroDe('type'); ?>
          <div class="adm-field<?= $er['cls'] ?>">
            <label class="adm-label" for="f-type"><?= e((string)($campos['type']['label'] ?? 'Tipo')) ?><span class="adm-label__req">*</span></label>
            <select class="adm-select" id="f-type" name="type"<?= $er['aria'] ?>>
              <?= $options((array)($vocab['tipos'] ?? []), $val('type', 'contrato')) ?>
            </select>
            <?= $er['html'] ?>
          </div>
          <?php $er = $erroDe('origin'); ?>
          <div class="adm-field<?= $er['cls'] ?>">
            <label class="adm-label" for="f-origin"><?= e((string)($campos['origin']['label'] ?? 'Origem')) ?><span class="adm-label__req">*</span></label>
            <select class="adm-select" id="f-origin" name="origin"<?= $er['aria'] ?>>
              <?= $options((array)($vocab['origens'] ?? []), $val('origin', 'hunter_command')) ?>
            </select>
            <?= $er['html'] ?>
            <p class="adm-hint"><?= e((string)($campos['origin']['dica'] ?? '')) ?></p>
          </div>
          <?php $er = $erroDe('act'); ?>
          <div class="adm-field<?= $er['cls'] ?>">
            <label class="adm-label" for="f-act"><?= e((string)($campos['act']['label'] ?? 'Ato')) ?></label>
            <input class="adm-input num" id="f-act" name="act" type="number" min="1" max="4" step="1" value="<?= e($val('act')) ?>"<?= $er['aria'] ?>>
            <?= $er['html'] ?>
            <p class="adm-hint"><?= e((string)($campos['act']['dica'] ?? '')) ?></p>
          </div>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <?php $er = $erroDe('difficulty'); ?>
          <div class="adm-field<?= $er['cls'] ?>">
            <label class="adm-label" for="f-dif"><?= e((string)($campos['difficulty']['label'] ?? 'Dificuldade')) ?></label>
            <select class="adm-select" id="f-dif" name="difficulty"<?= $er['aria'] ?>>
              <?= $options((array)($vocab['dificuldades'] ?? []), $val('difficulty'), 'Não definida') ?>
            </select>
            <?= $er['html'] ?>
          </div>
          <?php $er = $erroDe('duration_min'); ?>
          <div class="adm-field<?= $er['cls'] ?>">
            <label class="adm-label" for="f-dur"><?= e((string)($campos['duration_min']['label'] ?? 'Duração (min)')) ?></label>
            <input class="adm-input num" id="f-dur" name="duration_min" type="number" min="1" max="600" step="1" value="<?= e($val('duration_min')) ?>"<?= $er['aria'] ?>>
            <?= $er['html'] ?>
          </div>
          <?php $er = $erroDe('human_activity'); ?>
          <div class="adm-field<?= $er['cls'] ?>">
            <label class="adm-label" for="f-ha"><?= e((string)($campos['human_activity']['label'] ?? 'Atividade humana')) ?></label>
            <select class="adm-select" id="f-ha" name="human_activity"<?= $er['aria'] ?>>
              <?= $options((array)($vocab['atividades'] ?? []), $val('human_activity'), 'Não informada') ?>
            </select>
            <?= $er['html'] ?>
            <p class="adm-hint"><?= e((string)($campos['human_activity']['dica'] ?? '')) ?></p>
          </div>
        </div>

        <div class="adm-form__row adm-form__row--3">
          <?php $er = $erroDe('level_req'); ?>
          <div class="adm-field<?= $er['cls'] ?>">
            <label class="adm-label" for="f-lvl"><?= e((string)($campos['level_req']['label'] ?? 'Nível mínimo')) ?></label>
            <input class="adm-input num" id="f-lvl" name="level_req" type="number" min="0" max="999" step="1" value="<?= e($val('level_req')) ?>"<?= $er['aria'] ?>>
            <?= $er['html'] ?>
            <p class="adm-hint"><?= e((string)($campos['level_req']['dica'] ?? '')) ?></p>
          </div>
          <?php $er = $erroDe('squad_min'); ?>
          <div class="adm-field<?= $er['cls'] ?>">
            <label class="adm-label" for="f-sqmin"><?= e((string)($campos['squad_min']['label'] ?? 'Esquadrão mín.')) ?></label>
            <input class="adm-input num" id="f-sqmin" name="squad_min" type="number" min="1" max="<?= e((string)$squadMax) ?>" step="1" value="<?= e($val('squad_min', '1')) ?>"<?= $er['aria'] ?>>
            <?= $er['html'] ?>
          </div>
          <?php $er = $erroDe('squad_max'); ?>
          <div class="adm-field<?= $er['cls'] ?>">
            <label class="adm-label" for="f-sqmax"><?= e((string)($campos['squad_max']['label'] ?? 'Esquadrão máx.')) ?></label>
            <input class="adm-input num" id="f-sqmax" name="squad_max" type="number" min="1" max="<?= e((string)$squadMax) ?>" step="1" value="<?= e($val('squad_max', (string)$squadMax)) ?>"<?= $er['aria'] ?>>
            <?= $er['html'] ?>
            <p class="adm-hint"><?= e(sprintf((string)($campos['squad_max']['dica'] ?? '%s'), (string)$squadMax)) ?></p>
          </div>
        </div>

        <div class="adm-form__row mis-row--objetivo">
          <div class="adm-field">
            <label class="adm-label" for="f-obj"><?= e((string)($campos['objective']['label'] ?? 'Objetivo')) ?></label>
            <textarea class="adm-textarea" id="f-obj" name="objective" maxlength="400" rows="4"><?= e($val('objective')) ?></textarea>
            <p class="adm-hint"><?= e((string)($campos['objective']['dica'] ?? '')) ?></p>
          </div>
          <?php $er = $erroDe('target'); ?>
          <div class="adm-field<?= $er['cls'] ?>">
            <label class="adm-label" for="f-target"><?= e((string)($campos['target']['label'] ?? 'Meta')) ?></label>
            <input class="adm-input num" id="f-target" name="target" type="number" min="0" max="32767" step="1" value="<?= e($val('target')) ?>"<?= $er['aria'] ?>>
            <?= $er['html'] ?>
            <p class="adm-hint"><?= e((string)($campos['target']['dica'] ?? '')) ?></p>
          </div>
        </div>
      </fieldset>

      <!-- ------------------------------------------------------------------ requisitos -->
      <fieldset class="mis-fs">
        <legend class="mis-fs__leg"><?= icon('verified_user') ?><span><?= e((string)($fTxt['requisitos'] ?? 'Requisitos de acesso')) ?></span></legend>

        <div class="adm-form__row adm-form__row--3">
          <?php $er = $erroDe('requires_faction'); ?>
          <div class="adm-field<?= $er['cls'] ?>">
            <label class="adm-label" for="f-fac"><?= e((string)($campos['requires_faction']['label'] ?? 'Exige facção')) ?></label>
            <select class="adm-select" id="f-fac" name="requires_faction"<?= $er['aria'] ?>>
              <?= $options((array)($vocab['faccoes'] ?? []), $val('requires_faction'), 'Sem exigência de facção') ?>
            </select>
            <?= $er['html'] ?>
            <p class="adm-hint"><?= e((string)($campos['requires_faction']['dica'] ?? '')) ?></p>
          </div>
          <?php $er = $erroDe('requires_tier'); ?>
          <div class="adm-field<?= $er['cls'] ?>">
            <label class="adm-label" for="f-tier"><?= e((string)($campos['requires_tier']['label'] ?? 'Faixa mínima')) ?></label>
            <select class="adm-select" id="f-tier" name="requires_tier"<?= $er['aria'] ?>>
              <?= $options((array)($vocab['faixas'] ?? []), $val('requires_tier'), 'Sem faixa mínima') ?>
            </select>
            <?= $er['html'] ?>
          </div>
          <?php $er = $erroDe('requires_rep'); ?>
          <div class="adm-field<?= $er['cls'] ?>">
            <label class="adm-label" for="f-rep"><?= e((string)($campos['requires_rep']['label'] ?? 'Reputação mínima')) ?></label>
            <input class="adm-input num" id="f-rep" name="requires_rep" type="number" min="0" max="999999" step="1" value="<?= e($val('requires_rep')) ?>"<?= $er['aria'] ?>>
            <?= $er['html'] ?>
            <p class="adm-hint"><?= e((string)($campos['requires_rep']['dica'] ?? '')) ?></p>
          </div>
        </div>
      </fieldset>

      <!-- ----------------------------------------------------------------- recompensas -->
      <fieldset class="mis-fs">
        <legend class="mis-fs__leg"><?= icon('redeem') ?><span><?= e((string)($fTxt['recompensas'] ?? 'Recompensas')) ?></span></legend>

        <?php if ($itens === []): ?>
          <p class="adm-hint mis-rw__aviso"><?= icon('info') ?><span><?= e((string)($vazios['sem_item'] ?? '')) ?></span></p>
        <?php endif ?>

        <?php /* Linhas fixas em vez de "adicionar linha": sem JavaScript próprio, um botão de
                 adicionar seria um recarregamento por linha. Seis linhas cobrem com folga a
                 missão mais rica do catálogo, e linha em branco não é erro — é linha vazia. */ ?>
        <div class="mis-rw">
          <div class="mis-rw__head">
            <span>Espécie</span><span class="tr">Quantidade</span><span>Item do catálogo</span>
          </div>
          <?php for ($i = 0; $i < $nRec; $i++): ?>
            <?php
              $r    = $premEd[$i] ?? ['kind' => '', 'amount' => '', 'item_id' => null];
              $er   = $erroDe('rec_' . $i);
              $kind = (string)($r['kind'] ?? '');
            ?>
            <div class="mis-rw__row<?= $er['cls'] !== '' ? ' is-invalid' : '' ?>">
              <select class="adm-select" name="rec_kind[]" aria-label="Espécie da recompensa <?= e((string)($i + 1)) ?>">
                <option value="">— vazia —</option>
                <?php foreach ((array)($vocab['recompensas'] ?? []) as $k => $esp): ?>
                  <option value="<?= e((string)$k) ?>"<?= $k === $kind ? ' selected' : '' ?>><?= e((string)$esp['label']) ?></option>
                <?php endforeach ?>
              </select>
              <input class="adm-input num tr" name="rec_amount[]" type="number" min="0" step="1"
                     aria-label="Quantidade da recompensa <?= e((string)($i + 1)) ?>"
                     value="<?= e((string)($r['amount'] ?? '')) ?>">
              <select class="adm-select" name="rec_item[]" aria-label="Item da recompensa <?= e((string)($i + 1)) ?>">
                <option value="">— nenhum —</option>
                <?php foreach ($itens as $it): ?>
                  <option value="<?= e((string)$it['id']) ?>"<?= (int)($r['item_id'] ?? 0) === (int)$it['id'] ? ' selected' : '' ?>>
                    <?= e((string)$it['name']) ?> · <?= e((string)$it['category']) ?>
                  </option>
                <?php endforeach ?>
              </select>
              <?= $er['html'] ?>
            </div>
          <?php endfor ?>
        </div>
        <p class="adm-hint">A quantidade vale para XP, créditos, dinheiro, pontos e reputação. <strong>Item do catálogo</strong> ignora a quantidade (é um item). <strong>Acesso</strong> e <strong>Intel</strong> existem ou não existem — também ignoram.</p>
      </fieldset>

      <div class="adm-form__actions">
        <button class="adm-btn adm-btn--primary" type="submit"><?= icon('save') ?><span><?= e((string)($fTxt['salvar'] ?? 'Salvar missão')) ?></span></button>
        <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/missoes', $contexto)) ?>"><?= e((string)($fTxt['cancelar'] ?? 'Cancelar')) ?></a>
        <?php if (!$novo): ?>
          <a class="adm-btn adm-btn--danger adm-spacer" href="<?= e(url('admin/missoes', ['excluir' => (string)$edicao['id']] + $contexto)) ?>">
            <?= icon('delete') ?><span>Excluir</span>
          </a>
        <?php endif ?>
      </div>
    </div>
  </form>

<?php else: ?>
  <!-- =========================================================================== LISTA -->
  <?php /* Região da lista (App\Core\Fragmento): é este pedaço que volta do servidor quando
           o filtro é aplicado sem recarregar a página. */ ?>
  <!--eh:lista-->
  <section class="adm-card mis-list">

    <?php /* GET, e não POST: o resultado de uma busca é um endereço. Assim o operador
             favorita "as bloqueadas da Floresta Verde" e o F5 não repete envio nenhum.

             data-adm-preservar: este formulário é transplantado para dentro do HTML novo em
             vez de ser substituído — é onde está o cursor de quem digita. */ ?>
    <form class="adm-toolbar" method="get" action="<?= e(url('admin/missoes')) ?>"
          data-adm-preservar="filtros">
      <label class="adm-search adm-toolbar__grow">
        <?= icon('search') ?>
        <span class="sr-only"><?= e((string)($micro['busca'] ?? 'Buscar')) ?></span>
        <input class="adm-input" type="search" name="q" value="<?= e((string)($filtros['q'] ?? '')) ?>"
               placeholder="<?= e((string)($micro['busca'] ?? 'Buscar')) ?>">
      </label>

      <select class="adm-select mis-filtro" name="tipo" aria-label="Filtrar por tipo">
        <option value="">Todos os tipos</option>
        <?= $options((array)($vocab['tipos'] ?? []), (string)($filtros['tipo'] ?? '')) ?>
      </select>

      <select class="adm-select mis-filtro" name="origem" aria-label="Filtrar por origem">
        <option value="">Todas as origens</option>
        <?= $options((array)($vocab['origens'] ?? []), (string)($filtros['origem'] ?? '')) ?>
      </select>

      <select class="adm-select mis-filtro" name="dificuldade" aria-label="Filtrar por dificuldade">
        <option value="">Todas as dificuldades</option>
        <?= $options((array)($vocab['dificuldades'] ?? []), (string)($filtros['dificuldade'] ?? '')) ?>
      </select>

      <select class="adm-select mis-filtro" name="estado" aria-label="Filtrar por estado">
        <?php foreach ((array)($cromo['estados'] ?? []) as $k => $rot): ?>
          <option value="<?= e((string)$k) ?>"<?= (string)$k === (string)($filtros['estado'] ?? '') ? ' selected' : '' ?>><?= e((string)$rot) ?></option>
        <?php endforeach ?>
      </select>

      <select class="adm-select mis-filtro" name="ordem" aria-label="Ordenar">
        <?php foreach ((array)($cromo['ordenacoes'] ?? []) as $k => $rot): ?>
          <option value="<?= e((string)$k) ?>"<?= (string)$k === (string)($filtros['ordem'] ?? '') ? ' selected' : '' ?>><?= e((string)$rot) ?></option>
        <?php endforeach ?>
      </select>

      <button class="adm-btn" type="submit"><?= icon('filter_alt') ?><span>Aplicar</span></button>
      <?php if ($filtrosAtivos !== []): ?>
        <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/missoes')) ?>"><?= icon('backspace') ?><span><?= e((string)($micro['limpar'] ?? 'Limpar filtros')) ?></span></a>
      <?php endif ?>
      <a class="adm-btn adm-btn--primary mis-toolbar__nova" href="<?= e(url('admin/missoes', ['nova' => '1'] + $contexto)) ?>">
        <?= icon('add') ?><span><?= e((string)($micro['nova'] ?? 'Nova missão')) ?></span>
      </a>
    </form>

    <div class="adm-card__body adm-card__body--flush">
      <?php if ($linhas === []): ?>
        <div class="adm-empty">
          <?= icon($geral === 0 ? 'flag' : 'search_off') ?>
          <p class="adm-empty__title"><?= e($geral === 0 ? 'Catálogo vazio' : 'Nada com estes filtros') ?></p>
          <p class="adm-empty__txt">
            <?= e($geral === 0
                ? (string)($vazios['sem_missao'] ?? '')
                : sprintf((string)($vazios['sem_filtro'] ?? ''), num($geral))) ?>
          </p>
          <?php if ($geral === 0): ?>
            <a class="adm-btn adm-btn--primary" href="<?= e(url('admin/missoes', ['nova' => '1'])) ?>"><?= icon('add') ?><span><?= e((string)($micro['nova'] ?? 'Nova missão')) ?></span></a>
          <?php else: ?>
            <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/missoes')) ?>"><?= icon('backspace') ?><span><?= e((string)($micro['limpar'] ?? 'Limpar filtros')) ?></span></a>
          <?php endif ?>
        </div>
      <?php else: ?>
        <div class="adm-tablewrap">
          <table class="adm-table adm-table--compact mis-table">
            <thead>
              <tr>
                <th class="tc mis-c-ord"><?= e((string)($colunas['ordem'] ?? '#')) ?></th>
                <th><?= e((string)($colunas['missao'] ?? 'Missão')) ?></th>
                <th class="mis-c-tipo"><?= e((string)($colunas['tipo'] ?? 'Tipo')) ?></th>
                <th class="mis-c-orig"><?= e((string)($colunas['origem'] ?? 'Origem')) ?></th>
                <th class="mis-c-dif"><?= e((string)($colunas['dificuldade'] ?? 'Dificuldade')) ?></th>
                <th class="tr mis-c-nvl"><?= e((string)($colunas['nivel'] ?? 'Nível')) ?></th>
                <th class="mis-c-rec"><?= e((string)($colunas['recompensas'] ?? 'Recompensas')) ?></th>
                <th class="tr mis-c-cmp"><?= e((string)($colunas['ativas'] ?? 'Em jogo')) ?></th>
                <th class="mis-c-est"><?= e((string)($colunas['estado'] ?? 'Estado')) ?></th>
                <th class="adm-table__acts"><span class="sr-only"><?= e((string)($colunas['acoes'] ?? 'Ações')) ?></span></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($linhas as $l): ?>
                <?php
                  $id      = (int)$l['id'];
                  $ativas  = (int)($l['ativas'] ?? 0);
                  $premios = (array)($l['premios'] ?? []);
                  $locked  = (int)($l['locked'] ?? 0) === 1;
                  $sqMin   = (int)($l['squad_min'] ?? 1);
                  $sqMax   = (int)($l['squad_max'] ?? $squadMax);
                ?>
                <tr class="<?= $locked ? 'mis-row--locked' : '' ?><?= $id === $destaque ? ' mis-row--novo' : '' ?>">
                  <td class="tc num mis-c-ord"><?= e(num((int)($l['sort_order'] ?? 0))) ?></td>

                  <td>
                    <?php /* Nome, e por baixo o que identifica a missão sem ocupar coluna:
                             slug (a chave que a HUD usa) e região. O contratante, que é o
                             texto mais longo da tabela, vive no title da célula. */ ?>
                    <span class="mis-name" title="<?= e((string)$l['name'] . ($l['giver'] ? ' · ' . (string)$l['giver'] : '')) ?>">
                      <strong><?= e((string)$l['name']) ?></strong>
                      <small>
                        <code class="mis-slug"><?= e((string)$l['slug']) ?></code>
                        <?php if (!empty($l['region'])): ?> · <?= e((string)$l['region']) ?><?php endif ?>
                        <?php if (!empty($l['act'])): ?> · ATO <?= e((string)$l['act']) ?><?php endif ?>
                      </small>
                    </span>
                  </td>

                  <td class="mis-c-tipo"><?= $badge((string)($l['type'] ?? ''), (array)($vocab['tipos'] ?? [])) ?></td>
                  <td class="mis-c-orig"><?= $badge((string)($l['origin'] ?? ''), (array)($vocab['origens'] ?? [])) ?></td>
                  <td class="mis-c-dif"><?= $pips($l['difficulty'] ?? null) ?></td>

                  <td class="tr mis-c-nvl">
                    <span class="mis-num">
                      <span class="num"><?= e($l['level_req'] !== null ? num((int)$l['level_req']) : '—') ?></span>
                      <small class="num" title="<?= e(sprintf((string)($micro['esquadrao'] ?? '%s a %s'), (string)$sqMin, (string)$sqMax)) ?>"><?= e($sqMin . '–' . $sqMax) ?> op</small>
                    </span>
                  </td>

                  <td class="mis-c-rec">
                    <?php if ($premios === []): ?>
                      <span class="adm-badge adm-badge--danger" title="<?= e((string)($micro['sem_recompensa'] ?? '')) ?>">SEM RECOMPENSA</span>
                    <?php else: ?>
                      <span class="mis-prem">
                        <?php foreach (array_slice($premios, 0, 3) as $p): ?>
                          <?php $pt = $premioTxt($p); ?>
                          <span class="mis-prem__chip mis-prem__chip--<?= e($pt['tom']) ?>" title="<?= e($pt['titulo']) ?>"><?= e($pt['texto']) ?></span>
                        <?php endforeach ?>
                        <?php if (count($premios) > 3): ?>
                          <?php
                            $resto = array_map(
                                static fn(array $p): string => $premioTxt($p)['texto'],
                                array_slice($premios, 3)
                            );
                          ?>
                          <span class="mis-prem__mais" title="<?= e(implode(' · ', $resto)) ?>">+<?= e((string)(count($premios) - 3)) ?></span>
                        <?php endif ?>
                      </span>
                    <?php endif ?>
                  </td>

                  <td class="tr mis-c-cmp">
                    <?php /* O número que decide se a linha pode ser apagada. Zero fica cinza e
                             mudo; qualquer coisa acima de zero fica âmbar, porque é um aviso. */ ?>
                    <span class="mis-campo<?= $ativas > 0 ? ' is-on' : '' ?>"
                          title="<?= e($ativas > 0
                              ? sprintf((string)($micro['em_campo'] ?? '%s'), (string)$ativas)
                              : (string)($micro['sem_jogadores'] ?? '')) ?>">
                      <span class="num"><?= e(num($ativas)) ?></span>
                    </span>
                  </td>

                  <td class="mis-c-est">
                    <span class="adm-badge adm-badge--<?= $locked ? 'warn' : 'ok' ?>"><?= $locked ? 'BLOQUEADA' : 'PUBLICADA' ?></span>
                  </td>

                  <td class="adm-table__acts">
                    <a class="adm-btn adm-btn--sm adm-btn--icon" title="Editar" aria-label="Editar <?= e((string)$l['name']) ?>"
                       href="<?= e(url('admin/missoes', ['editar' => (string)$id] + $contexto)) ?>"><?= icon('edit') ?></a>
                    <?php if ($ativas > 0): ?>
                      <?php /* Não é um botão desligado por estilo: com jogador em campo a
                               exclusão é recusada no servidor, então o gesto não existe aqui.
                               O motivo vai no title e a saída (bloquear) está a um clique. */ ?>
                      <span class="adm-btn adm-btn--sm adm-btn--icon is-disabled"
                            title="<?= e(sprintf((string)($exTxt['bloqueio'] ?? ''), (string)$ativas)) ?>"
                            aria-disabled="true"><?= icon('lock') ?></span>
                    <?php else: ?>
                      <a class="adm-btn adm-btn--sm adm-btn--icon adm-btn--danger" title="Excluir" aria-label="Excluir <?= e((string)$l['name']) ?>"
                         href="<?= e(url('admin/missoes', ['excluir' => (string)$id] + $contexto)) ?>"><?= icon('delete') ?></a>
                    <?php endif ?>
                  </td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
      <?php endif ?>
    </div>

    <?php if ($linhas !== []): ?>
      <div class="adm-card__foot adm-pager">
        <?php /* data-adm-total: a contagem que o leitor de tela ouve a cada atualização. */ ?>
        <span class="adm-pager__info" data-adm-total>
          <?= e($filtrosAtivos === []
              ? sprintf((string)($micro['sem_filtro'] ?? '%s missões cadastradas'), num($geral))
              : sprintf((string)($micro['resultado'] ?? '%s de %s missões'), num($total), num($geral))) ?>
        </span>
        <?php if ($paginas > 1): ?>
          <?php $lk = static fn(int $p): string => url('admin/missoes', array_filter(['q' => (string)($filtros['q'] ?? ''),
                'tipo' => (string)($filtros['tipo'] ?? ''), 'origem' => (string)($filtros['origem'] ?? ''),
                'dificuldade' => (string)($filtros['dificuldade'] ?? ''), 'estado' => (string)($filtros['estado'] ?? ''),
                'ordem' => (string)($filtros['ordem'] ?? ''), 'pagina' => (string)$p], static fn(string $v): bool => $v !== '')); ?>
          <a class="adm-pager__btn<?= $pagina <= 1 ? ' is-disabled' : '' ?>" href="<?= e($lk(max(1, $pagina - 1))) ?>" aria-label="Página anterior"><?= icon('chevron_left') ?></a>
          <?php for ($p = 1; $p <= $paginas; $p++): ?>
            <?php /* Sem reticências: a lista é de dezenas, não de milhares — todas as páginas
                     cabem na linha e um número visível é mais rápido que um "…" que esconde. */ ?>
            <a class="adm-pager__btn<?= $p === $pagina ? ' is-active' : '' ?>" href="<?= e($lk($p)) ?>"<?= $p === $pagina ? ' aria-current="page"' : '' ?>><?= e((string)$p) ?></a>
          <?php endfor ?>
          <a class="adm-pager__btn<?= $pagina >= $paginas ? ' is-disabled' : '' ?>" href="<?= e($lk(min($paginas, $pagina + 1))) ?>" aria-label="Próxima página"><?= icon('chevron_right') ?></a>
        <?php endif ?>
      </div>
    <?php endif ?>
  </section>
  <!--/eh:lista-->
<?php endif ?>
</div>
