<?php declare(strict_types=1);

/**
 * Tela ADMIN · JOGADORES (/admin/jogadores).
 *
 * Escopo: $page, $player, $nav, $server, $data, $flash, $csrf. Layout: layouts/admin.php.
 *
 * DOIS MODOS NO MESMO ARQUIVO, decididos por $data['modo']:
 *   'lista' — o cadastro inteiro, com busca, filtros, ordenação e paginação;
 *   'ficha' — o dossiê de uma conta e os formulários que agem sobre ela.
 * Estão juntos porque compartilham TODOS os formatadores (selo de papel, selo de situação,
 * data curta, tempo relativo). Separados, o segundo arquivo seria uma cópia dos helpers.
 *
 * A view não consulta o banco, não decide permissão e não calcula regra: tudo chega pronto
 * de App\Controllers\Admin\PlayersController e App\Repo\AdminPlayersRepo. Aqui só existe
 * apresentação — inclusive as três coisas que a apresentação de um painel de moderação
 * precisa acertar:
 *
 *   1. estado é COR **e** TEXTO. Nenhum selo depende só do verde ou do vermelho;
 *   2. número é alinhado à direita e em fonte tabular (.tr + .num), para as colunas de
 *      nível, XP e dinheiro se lerem de cima para baixo sem a vista escorregar;
 *   3. botão que não pode ser usado não some — aparece desabilitado, com o motivo escrito
 *      do lado. Um botão que desaparece vira um chamado de suporte perguntando cadê.
 *
 * Nenhuma variável desta tela carrega senha: o repositório não seleciona password_hash.
 */

$cromo   = is_array($data['cromo'] ?? null) ? $data['cromo'] : [];
$titulos = (array)($cromo['titulos'] ?? []);
$rPapel  = (array)($cromo['papeis'] ?? []);
$rSituac = (array)($cromo['situacoes'] ?? []);
$rOrdens = (array)($cromo['ordens'] ?? []);
$rTipos  = (array)($cromo['ban_tipos'] ?? []);
$rConts  = (array)($cromo['containers'] ?? []);
$rMissao = (array)($cromo['missao_status'] ?? []);
$rResult = (array)($cromo['partida_result'] ?? []);
$rFaccao = (array)($cromo['faccoes'] ?? []);
$vazios  = (array)($cromo['vazios'] ?? []);
$micro   = (array)($cromo['micro'] ?? []);

$modo     = (string)($data['modo'] ?? 'lista');
$operador = (array)($data['operador'] ?? []);
$erros    = (array)($data['erros'] ?? []);
$old      = (array)($data['old'] ?? []);
$agora    = time();

/* =========================================================================================
   FORMATADORES — só apresentação; nenhum deles decide nada
   ========================================================================================= */

/** Selo com cor E texto. $tom são os sufixos de .adm-badge-- já definidos em admin.css. */
$selo = static function (string $texto, string $tom = 'muted', string $icone = ''): string {
    $tom = preg_replace('/[^a-z]/', '', strtolower($tom)) ?: 'muted';
    return '<span class="adm-badge adm-badge--' . e($tom) . ($icone !== '' ? ' adm-badge--nodot' : '') . '">'
        . ($icone !== '' ? icon($icone) : '') . e($texto) . '</span>';
};

/**
 * Selo do papel/da situação. Quando o banco traz um valor fora do mapa (dado antigo, script
 * de importação), mostra o valor CRU em cinza: rotular de "Ativa" um estado que ninguém
 * previu seria a interface mentindo para esconder um problema de dado.
 */
$seloDe = static function (array $mapa, ?string $valor) use ($selo): string {
    $valor = (string)($valor ?? '');
    $def   = $mapa[$valor] ?? null;
    return $def === null
        ? $selo($valor !== '' ? $valor : '—', 'muted')
        : $selo((string)$def['label'], (string)($def['tom'] ?? 'muted'), (string)($def['icone'] ?? ''));
};

/** 'd/m/Y H:i' ou travessão. Data vazia é ausência de fato, não zero. */
$dataHora = static function (?string $sql): string {
    if ($sql === null || $sql === '' || str_starts_with($sql, '0000')) {
        return '—';
    }
    $ts = strtotime($sql);
    return $ts === false ? '—' : date('d/m/Y H:i', $ts);
};

/** "há 3 dias" / "em 2 h" — a distância no tempo, que é o que o operador realmente lê. */
$relativo = static function (?string $sql) use ($agora): string {
    if ($sql === null || $sql === '') {
        return '';
    }
    $ts = strtotime($sql);
    if ($ts === false) {
        return '';
    }
    $d = abs($agora - $ts);
    $futuro = $ts > $agora;
    $unidade = match (true) {
        $d < 90         => [$d, 's'],
        $d < 5400       => [intdiv($d, 60), 'min'],
        $d < 172800     => [intdiv($d, 3600), 'h'],
        $d < 5184000    => [intdiv($d, 86400), 'd'],
        default         => [intdiv($d, 2592000), 'mes'],
    };
    $txt = $unidade[0] . ($unidade[1] === 'mes' ? ($unidade[0] === 1 ? ' mês' : ' meses') : ' ' . $unidade[1]);
    return ($futuro ? 'em ' : 'há ') . $txt;
};

/** Dinheiro do jogo (créditos/ganhos são inteiros, não centavos). */
$moeda = static fn(int $n): string => num($n);

/** URL da própria listagem preservando os filtros; o que vier null some da query. */
$urlLista = static function (array $troca = []) use ($data): string {
    $f = (array)($data['filtros'] ?? []);
    $q = array_filter([
        'q'        => (string)($f['q'] ?? ''),
        'papel'    => (string)($f['papel'] ?? ''),
        'situacao' => (string)($f['situacao'] ?? ''),
        'ordem'    => ((string)($f['ordem'] ?? 'recentes')) === 'recentes' ? '' : (string)$f['ordem'],
        'p'        => ((int)($f['pagina'] ?? 1)) > 1 ? (string)(int)$f['pagina'] : '',
    ], static fn(string $v): bool => $v !== '');

    foreach ($troca as $k => $v) {
        if ($v === null || $v === '') { unset($q[$k]); } else { $q[$k] = (string)$v; }
    }
    return url('admin/jogadores', $q);
};

/** Bloco de erro de um campo + a classe que pinta a borda. Devolve '' quando está tudo bem. */
$erro = static function (string $campo) use ($erros): string {
    return isset($erros[$campo])
        ? '<span class="adm-error">' . icon('error') . e((string)$erros[$campo]) . '</span>'
        : '';
};
$invalido = static fn(string $c): string => isset($erros[$c]) ? ' is-invalid' : '';

/* =========================================================================================
   MODO LISTA
   ========================================================================================= */
if ($modo === 'lista'):

$lista    = (array)($data['lista'] ?? []);
$linhas   = (array)($lista['linhas'] ?? []);
$filtros  = (array)($data['filtros'] ?? []);
$abas     = (array)($data['abas'] ?? []);
$resumo   = (array)($data['resumo'] ?? []);
$filtrado = (bool)($data['filtrado'] ?? false);

$total   = (int)($lista['total'] ?? 0);
$pagina  = (int)($lista['pagina'] ?? 1);
$paginas = (int)($lista['paginas'] ?? 1);
$offset  = (int)($lista['offset'] ?? 0);

/* A lista está vazia por dois motivos MUITO diferentes — não há conta nenhuma, ou o filtro
   não achou nada — e a tela precisa dizer qual dos dois, porque a saída é outra em cada caso. */
$vazio = $linhas === []
    ? ($filtrado ? (array)($vazios['sem_resultado'] ?? []) : (array)($vazios['sem_conta'] ?? []))
    : null;
?>

<section class="pj-sum adm-grid adm-grid--4">
  <?php
    $cartoes = [
      ['ico' => 'group',         'tom' => 'lime',   'lbl' => 'Contas no jogo',      'val' => (int)($resumo['contas'] ?? 0),  'sub' => ''],
      ['ico' => 'shield_person', 'tom' => 'cyan',   'lbl' => 'Com acesso ao painel','val' => (int)($resumo['equipe'] ?? 0),  'sub' => 'admins e dono'],
      ['ico' => 'person_add',    'tom' => 'purple', 'lbl' => 'Novas em 7 dias',     'val' => (int)($resumo['novos7'] ?? 0),  'sub' => ''],
      ['ico' => 'gavel',         'tom' => 'red',    'lbl' => 'Banimentos em vigor', 'val' => (int)($resumo['punidos'] ?? 0), 'sub' => ''],
    ];
  ?>
  <?php foreach ($cartoes as $c): ?>
    <?php /* `adm-kpi` já é o cartão inteiro (fundo, borda e raio vêm dele). Somar `adm-card`
             aqui não é reforço: adm-card é `flex-direction:column` e derruba a linha
             ícone-ao-lado-do-número que o KPI desenha. */ ?>
    <article class="adm-kpi adm-kpi--<?= e($c['tom']) ?>">
      <span class="adm-kpi__ico"><?= icon($c['ico']) ?></span>
      <span class="adm-kpi__txt">
        <span class="adm-kpi__label"><?= e($c['lbl']) ?></span>
        <span class="adm-kpi__value num"><?= e(num($c['val'])) ?></span>
        <?php if ($c['sub'] !== ''): ?><span class="adm-meta"><?= e($c['sub']) ?></span><?php endif ?>
      </span>
    </article>
  <?php endforeach ?>
</section>

<?php /* Daqui até o marcador de fechamento é a REGIÃO DA LISTA: o pedaço que
         App\Core\Fragmento recorta quando o filtro é aplicado sem recarregar a página.
         Os cartões de resumo ficam de fora de propósito — eles contam o cadastro
         inteiro, não o recorte, e não mudam quando o operador filtra. */ ?>
<!--eh:lista-->
<section class="adm-card pj-list">
  <header class="adm-card__head">
    <h2 class="adm-card__title">
      <?= e((string)($titulos['lista'] ?? 'Contas do jogo')) ?>
      <?php /* data-adm-total: é esta frase que o leitor de tela ouve depois de cada
               atualização — a mesma que quem enxerga lê. */ ?>
      <small data-adm-total><?= e(num($total)) ?> <?= $total === 1 ? 'conta encontrada' : 'contas encontradas' ?><?= $filtrado ? ' com os filtros atuais' : '' ?></small>
    </h2>
  </header>

  <?php /* Abas: filtro de um clique para os três estados que a moderação usa o dia inteiro.
           O contador de cada aba é do cadastro inteiro, e não do resultado filtrado — ver
           AdminPlayersRepo::contagemPorSituacao(). */ ?>
  <nav class="pj-tabs" aria-label="Filtrar por situação da conta">
    <?php foreach ((array)($cromo['abas'] ?? []) as $aba): ?>
      <?php
        $chave = (string)($aba['chave'] ?? '');
        $n     = $chave === '' ? (int)($abas['total'] ?? 0) : (int)($abas[$chave] ?? 0);
        $ativa = $chave === (string)($filtros['situacao'] ?? '');
      ?>
      <a class="pj-tab<?= $ativa ? ' is-active' : '' ?>"
         href="<?= e($urlLista(['situacao' => $chave, 'p' => null])) ?>"<?= $ativa ? ' aria-current="page"' : '' ?>>
        <span><?= e((string)($aba['label'] ?? '')) ?></span>
        <span class="pj-tab__n num"><?= e(num($n)) ?></span>
      </a>
    <?php endforeach ?>
  </nav>

  <?php /* Formulário GET puro: os filtros viram URL, e uma busca útil pode ser copiada,
           salva nos favoritos ou mandada para um colega. Sem JavaScript envolvido.

           data-adm-preservar diz ao assets/js/admin/lista.js que este nó NÃO pode ser
           substituído quando a lista é atualizada: é aqui que está o cursor de quem
           digita. O campo oculto de situação é reconciliado com a URL pelo script — as
           abas acima são links, não controles deste formulário. */ ?>
  <form class="adm-toolbar" method="get" action="<?= e(url('admin/jogadores')) ?>" role="search"
        data-adm-preservar="filtros">
    <?php /* O campo vai SEMPRE, mesmo vazio: a aba escolhida é um link, e é neste campo que
             o estado dela sobrevive ao próximo "Aplicar". Vazio, o navegador manda
             `situacao=` — que o controller trata como "todas", igual a não mandar nada. */ ?>
    <input type="hidden" name="situacao" value="<?= e((string)($filtros['situacao'] ?? '')) ?>">

    <label class="adm-search adm-toolbar__grow">
      <span class="sr-only"><?= e((string)($micro['busca'] ?? 'Buscar')) ?></span>
      <?= icon('search') ?>
      <input class="adm-input" type="search" name="q" value="<?= e((string)($filtros['q'] ?? '')) ?>"
             placeholder="<?= e((string)($micro['busca'] ?? 'Buscar')) ?>" maxlength="60">
    </label>

    <label class="pj-pick">
      <span class="sr-only">Papel</span>
      <select class="adm-select" name="papel">
        <option value="">Todos os papéis</option>
        <?php foreach ($rPapel as $chave => $def): ?>
          <option value="<?= e((string)$chave) ?>"<?= (string)($filtros['papel'] ?? '') === (string)$chave ? ' selected' : '' ?>>
            <?= e((string)$def['label']) ?>
          </option>
        <?php endforeach ?>
      </select>
    </label>

    <label class="pj-pick">
      <span class="sr-only">Ordenar por</span>
      <select class="adm-select" name="ordem">
        <?php foreach ($rOrdens as $chave => $rot): ?>
          <option value="<?= e((string)$chave) ?>"<?= (string)($filtros['ordem'] ?? 'recentes') === (string)$chave ? ' selected' : '' ?>>
            <?= e((string)$rot) ?>
          </option>
        <?php endforeach ?>
      </select>
    </label>

    <button class="adm-btn adm-btn--primary" type="submit"><?= icon('filter_alt') ?><span>Aplicar</span></button>
    <?php if ($filtrado || (string)($filtros['ordem'] ?? 'recentes') !== 'recentes'): ?>
      <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/jogadores')) ?>"><?= icon('backspace') ?><span>Limpar</span></a>
    <?php endif ?>
  </form>

  <?php if ($vazio !== null): ?>
    <div class="adm-empty">
      <?= icon($filtrado ? 'search_off' : 'group_off') ?>
      <h3 class="adm-empty__title"><?= e((string)($vazio['titulo'] ?? 'Sem contas')) ?></h3>
      <p class="adm-empty__txt"><?= e((string)($vazio['txt'] ?? '')) ?></p>
      <?php if ($filtrado): ?>
        <a class="adm-btn adm-btn--ghost" href="<?= e(url('admin/jogadores')) ?>"><?= icon('backspace') ?><span>Limpar filtros</span></a>
      <?php endif ?>
    </div>
  <?php else: ?>
    <div class="adm-tablewrap">
      <table class="adm-table pj-table">
        <caption class="sr-only">Contas do jogo, <?= e(num($total)) ?> no total</caption>
        <thead>
          <tr>
            <th scope="col">Jogador</th>
            <th scope="col">Papel</th>
            <th scope="col">Situação</th>
            <th scope="col" class="tr">Nível</th>
            <th scope="col" class="tr">XP</th>
            <th scope="col">Último acesso</th>
            <th scope="col" class="adm-table__acts"><span class="sr-only">Ações</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($linhas as $j): ?>
            <?php
              $jid    = (int)$j['id'];
              $eu     = $jid === (int)($operador['id'] ?? 0);
              $avatar = trim((string)($j['avatar'] ?? ''));
              $apelido= trim((string)($j['display_name'] ?? ''));
              $email  = trim((string)($j['email'] ?? ''));
              /* A segunda linha da célula do jogador é o e-mail; quando o nome no jogo é
                 diferente do login, ele vem antes — é por ele que o suporte é chamado. */
              $sub    = $apelido !== '' && $apelido !== (string)$j['username'] ? $apelido . ' · ' : '';
              $sub   .= $email !== '' ? $email : (string)($micro['sem_email'] ?? 'sem e-mail');
              $ficha  = url('admin/jogadores', ['id' => $jid]);
            ?>
            <tr>
              <th scope="row">
                <a class="adm-table__who pj-who" href="<?= e($ficha) ?>">
                  <?php if ($avatar !== ''): ?>
                    <img src="<?= e(media($avatar)) ?>" alt="" width="32" height="32" loading="lazy" decoding="async">
                  <?php else: ?>
                    <span class="adm-table__ava pj-ava"><?= icon('person') ?></span>
                  <?php endif ?>
                  <span>
                    <strong><?= e((string)$j['username']) ?><?= $eu ? ' <em class="pj-you">você</em>' : '' ?></strong>
                    <small><?= e($sub) ?></small>
                  </span>
                </a>
              </th>
              <td><?= $seloDe($rPapel, (string)$j['role']) ?></td>
              <td>
                <?= $seloDe($rSituac, (string)$j['status']) ?>
                <?php if (!empty($j['ban_id'])): ?>
                  <?php $ate = (string)($j['ban_kind'] ?? '') === 'perma' ? 'sem prazo' : 'até ' . $dataHora((string)($j['ban_expires'] ?? '')); ?>
                  <small class="adm-meta pj-ban-meta" title="<?= e((string)($j['ban_reason'] ?? '')) ?>"><?= e($ate) ?></small>
                <?php endif ?>
              </td>
              <td class="tr num"><?= e(num((int)($j['level'] ?? 0))) ?></td>
              <td class="tr num"><?= e(num((int)($j['xp'] ?? 0))) ?></td>
              <td>
                <?php $ultimo = (string)($j['last_login'] ?? ''); ?>
                <?php if ($ultimo === ''): ?>
                  <span class="adm-meta"><?= e((string)($micro['nunca_entrou'] ?? 'nunca entrou')) ?></span>
                <?php else: ?>
                  <span class="pj-when" title="<?= e($dataHora($ultimo)) ?>"><?= e($relativo($ultimo)) ?></span>
                  <small class="adm-meta"><?= e($dataHora($ultimo)) ?></small>
                <?php endif ?>
              </td>
              <td class="adm-table__acts">
                <a class="adm-btn adm-btn--sm" href="<?= e($ficha) ?>"><?= icon('badge') ?><span>Abrir ficha</span></a>
              </td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>

    <?php if ($paginas > 1): ?>
      <footer class="adm-card__foot">
        <nav class="adm-pager" aria-label="Paginação da lista de jogadores">
          <span class="adm-pager__info">
            <?= e(num($offset + 1)) ?>–<?= e(num(min($offset + count($linhas), $total))) ?> de <?= e(num($total)) ?>
          </span>
          <?php
            $ant = max(1, $pagina - 1);
            $pro = min($paginas, $pagina + 1);
            /* Uma janela de páginas em volta da atual: com 40 páginas, imprimir as 40 vira
               uma segunda barra de rolagem horizontal — exatamente o que a tela não pode ter. */
            $ini = max(1, min($pagina - 2, $paginas - 4));
            $fim = min($paginas, max($pagina + 2, 5));
          ?>
          <?php if ($pagina > 1): ?>
            <a class="adm-pager__btn" href="<?= e($urlLista(['p' => $ant])) ?>" aria-label="Página anterior"><?= icon('chevron_left') ?></a>
          <?php else: ?>
            <span class="adm-pager__btn is-disabled"><?= icon('chevron_left') ?></span>
          <?php endif ?>

          <?php if ($ini > 1): ?>
            <a class="adm-pager__btn" href="<?= e($urlLista(['p' => 1])) ?>">1</a>
            <?php if ($ini > 2): ?><span class="adm-pager__gap">…</span><?php endif ?>
          <?php endif ?>

          <?php for ($i = $ini; $i <= $fim; $i++): ?>
            <?php if ($i === $pagina): ?>
              <span class="adm-pager__btn is-active" aria-current="page"><?= e((string)$i) ?></span>
            <?php else: ?>
              <a class="adm-pager__btn" href="<?= e($urlLista(['p' => $i])) ?>"><?= e((string)$i) ?></a>
            <?php endif ?>
          <?php endfor ?>

          <?php if ($fim < $paginas): ?>
            <?php if ($fim < $paginas - 1): ?><span class="adm-pager__gap">…</span><?php endif ?>
            <a class="adm-pager__btn" href="<?= e($urlLista(['p' => $paginas])) ?>"><?= e((string)$paginas) ?></a>
          <?php endif ?>

          <?php if ($pagina < $paginas): ?>
            <a class="adm-pager__btn" href="<?= e($urlLista(['p' => $pro])) ?>" aria-label="Próxima página"><?= icon('chevron_right') ?></a>
          <?php else: ?>
            <span class="adm-pager__btn is-disabled"><?= icon('chevron_right') ?></span>
          <?php endif ?>
        </nav>
      </footer>
    <?php endif ?>
  <?php endif ?>
</section>
<!--/eh:lista-->

<?php
/* =========================================================================================
   MODO FICHA
   ========================================================================================= */
else:

$conta   = (array)($data['conta'] ?? []);
$uid     = (int)($conta['id'] ?? 0);
$eVoce   = (bool)($data['e_voce'] ?? false);
$podeAgir= (bool)($data['pode_agir'] ?? false);
$papeisOk= (array)($data['papeis_ok'] ?? []);
$banAtivo= is_array($data['ban_ativo'] ?? null) ? $data['ban_ativo'] : null;
$bans    = (array)($data['bans'] ?? []);
$inv     = (array)($data['inventario'] ?? []);
$missoes = (array)($data['missoes'] ?? []);
$conq    = (array)($data['conquistas'] ?? []);
$faccoes = (array)($data['faccoes'] ?? []);
$part    = (array)($data['partidas'] ?? []);
$refs    = (array)($data['referencias'] ?? []);

$nome   = (string)($conta['username'] ?? '');
$avatar = trim((string)($conta['avatar'] ?? ''));

/* Uma frase, dita UMA vez, para o motivo de os formulários estarem travados. Repetir a
   explicação em cada cartão viraria três parágrafos dizendo a mesma coisa. */
$travaMotivo = $podeAgir ? ''
    : ($eVoce ? (string)($micro['conta_propria'] ?? '') : (string)($micro['sem_autoridade'] ?? ''));

$xp     = (int)($conta['xp'] ?? 0);
$xpNext = (int)($conta['xp_next'] ?? 0);
$xpPct  = $xpNext > 0 ? max(0, min(100, (int)round($xp / $xpNext * 100))) : 0;
?>

<section class="adm-card pj-id">
  <a class="adm-btn adm-btn--ghost adm-btn--sm pj-id__back" href="<?= e(url('admin/jogadores')) ?>">
    <?= icon('arrow_back') ?><span>Todas as contas</span>
  </a>

  <div class="pj-id__who">
    <?php if ($avatar !== ''): ?>
      <img class="pj-id__ava" src="<?= e(media($avatar)) ?>" alt="" width="64" height="64" decoding="async">
    <?php else: ?>
      <span class="pj-id__ava pj-id__ava--ph"><?= icon('person') ?></span>
    <?php endif ?>
    <div class="pj-id__txt">
      <h2 class="pj-id__name">
        <?= e($nome) ?>
        <?php if ($eVoce): ?><em class="pj-you">você</em><?php endif ?>
      </h2>
      <p class="pj-id__mail"><?= e((string)($conta['email'] ?? '') ?: (string)($micro['sem_email'] ?? '')) ?></p>
      <div class="pj-id__badges">
        <?= $seloDe($rPapel, (string)($conta['role'] ?? '')) ?>
        <?= $seloDe($rSituac, (string)($conta['status'] ?? '')) ?>
        <?php if (($conta['display_name'] ?? '') !== '' && (string)$conta['display_name'] !== $nome): ?>
          <?= $selo('No jogo: ' . (string)$conta['display_name'], 'muted') ?>
        <?php endif ?>
        <?php if (($conta['player_rank'] ?? '') !== ''): ?><?= $selo((string)$conta['player_rank'], 'purple') ?><?php endif ?>
      </div>
    </div>
  </div>

  <dl class="pj-id__facts">
    <div><dt>Conta criada</dt><dd><?= e($dataHora((string)($conta['created_at'] ?? ''))) ?></dd></div>
    <div>
      <dt>Último acesso</dt>
      <dd><?= e($dataHora((string)($conta['last_login'] ?? ''))) ?>
        <?php $r = $relativo((string)($conta['last_login'] ?? '')); ?>
        <?php if ($r !== ''): ?><small class="adm-meta"><?= e($r) ?></small><?php endif ?>
      </dd>
    </div>
    <div><dt>Último IP</dt><dd class="num"><?= e((string)($conta['last_ip'] ?? '') ?: '—') ?></dd></div>
    <div><dt>ID interno</dt><dd class="num">#<?= e((string)$uid) ?></dd></div>
  </dl>
</section>

<div class="pj-grid">
  <div class="pj-grid__main">

    <?php /* ------------------------------------------------------------------ progresso */ ?>
    <section class="adm-card">
      <header class="adm-card__head"><h3 class="adm-card__title"><?= e((string)($titulos['progresso'] ?? 'Progresso')) ?></h3></header>
      <div class="adm-card__body">
        <ul class="pj-stats">
          <?php
            $stats = [
              ['Nível',      num((int)($conta['level'] ?? 0)),        ''],
              ['XP',         num($xp),                                $xpNext > 0 ? 'de ' . num($xpNext) : ''],
              ['Passe',      num((int)($conta['pass_level'] ?? 0)),   (string)($conta['pass_name'] ?? '')],
              ['Créditos',   $moeda((int)($conta['credits'] ?? 0)),   ''],
              ['Cash',       $moeda((int)($conta['cash'] ?? 0)),      ''],
              ['Pontos',     num((int)($conta['points'] ?? 0)),       ''],
              ['Habilidade', num((int)($conta['skill_points'] ?? 0)), 'pontos livres'],
              ['Sequência',  num((int)($conta['streak_days'] ?? 0)),  'dias'],
            ];
          ?>
          <?php foreach ($stats as [$rot, $val, $sub]): ?>
            <li class="pj-stat">
              <span class="pj-stat__lbl"><?= e($rot) ?></span>
              <span class="pj-stat__val num"><?= e($val) ?></span>
              <?php if ($sub !== ''): ?><span class="pj-stat__sub"><?= e($sub) ?></span><?php endif ?>
            </li>
          <?php endforeach ?>
        </ul>

        <?php if ($xpNext > 0): ?>
          <div class="pj-bar" role="img"
               aria-label="<?= e($xpPct . '% do XP para o próximo nível') ?>"
               title="<?= e(num($xp) . ' de ' . num($xpNext) . ' XP') ?>">
            <span style="width:<?= e((string)$xpPct) ?>%"></span>
          </div>
          <p class="adm-meta"><?= e($xpPct) ?>% do caminho para o nível <?= e(num((int)($conta['level'] ?? 0) + 1)) ?>.</p>
        <?php else: ?>
          <p class="adm-meta">Sem meta de XP registrada para o nível atual.</p>
        <?php endif ?>
      </div>
    </section>

    <?php /* ------------------------------------------------------------------ inventário */ ?>
    <section class="adm-card">
      <header class="adm-card__head">
        <h3 class="adm-card__title">
          <?= e((string)($titulos['inventario'] ?? 'Inventário')) ?>
          <small><?= e(num((int)($inv['itens'] ?? 0))) ?> itens · <?= e(num((float)($inv['peso'] ?? 0), 2)) ?> kg · <?= e($moeda((int)($inv['valor'] ?? 0))) ?> em valor de base</small>
        </h3>
      </header>
      <?php if ((int)($inv['itens'] ?? 0) === 0): ?>
        <div class="adm-empty"><?= icon('inventory_2') ?><p class="adm-empty__txt"><?= e((string)($vazios['sem_inventario'] ?? '')) ?></p></div>
      <?php else: ?>
        <div class="adm-card__body pj-two">
          <div>
            <h4 class="pj-sub">Onde está guardado</h4>
            <table class="adm-table adm-table--compact">
              <thead><tr><th scope="col">Local</th><th scope="col" class="tr">Itens</th><th scope="col" class="tr">Peso</th></tr></thead>
              <tbody>
                <?php foreach ((array)($inv['containers'] ?? []) as $c): ?>
                  <tr>
                    <th scope="row"><?= e((string)($rConts[(string)$c['container']] ?? $c['container'])) ?></th>
                    <td class="tr num"><?= e(num((int)$c['itens'])) ?></td>
                    <td class="tr num"><?= e(num((float)$c['peso'], 2)) ?> kg</td>
                  </tr>
                <?php endforeach ?>
              </tbody>
            </table>
          </div>
          <div>
            <h4 class="pj-sub">Peças de maior valor</h4>
            <table class="adm-table adm-table--compact">
              <thead><tr><th scope="col">Item</th><th scope="col" class="tr">Qtd</th><th scope="col" class="tr">Valor</th></tr></thead>
              <tbody>
                <?php foreach ((array)($inv['destaques'] ?? []) as $it): ?>
                  <tr>
                    <th scope="row"><strong><?= e((string)$it['name']) ?></strong>
                      <small class="adm-meta"><?= e((string)($rConts[(string)$it['container']] ?? $it['container'])) ?></small></th>
                    <td class="tr num"><?= e(num((int)$it['quantity'])) ?></td>
                    <td class="tr num"><?= e($moeda((int)$it['valor'])) ?></td>
                  </tr>
                <?php endforeach ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif ?>
    </section>

    <?php /* --------------------------------------------------------------------- missões */ ?>
    <section class="adm-card">
      <header class="adm-card__head">
        <h3 class="adm-card__title"><?= e((string)($titulos['missoes'] ?? 'Missões')) ?>
          <small><?= e(num(count($missoes))) ?> com progresso registrado</small></h3>
      </header>
      <?php if ($missoes === []): ?>
        <div class="adm-empty"><?= icon('flag') ?><p class="adm-empty__txt"><?= e((string)($vazios['sem_missao'] ?? '')) ?></p></div>
      <?php else: ?>
        <div class="adm-tablewrap">
          <table class="adm-table adm-table--compact">
            <thead><tr>
              <th scope="col">Missão</th><th scope="col">Tipo</th>
              <th scope="col">Estado</th><th scope="col" class="tr">Progresso</th><th scope="col">Atualizada</th>
            </tr></thead>
            <tbody>
              <?php foreach ($missoes as $m): ?>
                <?php $alvo = (int)($m['alvo'] ?? 0); $prog = (int)($m['progress'] ?? 0); ?>
                <tr>
                  <th scope="row"><strong><?= e((string)$m['name']) ?></strong></th>
                  <td><?= e((string)($m['type'] ?? '')) ?></td>
                  <td><?= $seloDe($rMissao, (string)$m['status']) ?></td>
                  <td class="tr num"><?= e(num($prog)) ?><?= $alvo > 0 ? '<span class="pj-of">/' . e(num($alvo)) . '</span>' : '' ?></td>
                  <td><span class="adm-meta" title="<?= e($dataHora((string)($m['updated_at'] ?? ''))) ?>"><?= e($relativo((string)($m['updated_at'] ?? '')) ?: '—') ?></span></td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
      <?php endif ?>
    </section>

    <?php /* ------------------------------------------------------------------ conquistas */ ?>
    <section class="adm-card">
      <header class="adm-card__head">
        <h3 class="adm-card__title"><?= e((string)($titulos['conquistas'] ?? 'Conquistas')) ?>
          <small><?= e(num((int)($conq['feitas'] ?? 0))) ?> de <?= e(num((int)($conq['total'] ?? 0))) ?> concluídas</small></h3>
      </header>
      <?php if ((array)($conq['linhas'] ?? []) === []): ?>
        <div class="adm-empty"><?= icon('military_tech') ?><p class="adm-empty__txt"><?= e((string)($vazios['sem_conquista'] ?? '')) ?></p></div>
      <?php else: ?>
        <div class="adm-tablewrap">
          <table class="adm-table adm-table--compact">
            <thead><tr>
              <th scope="col">Conquista</th><th scope="col">Categoria</th>
              <th scope="col" class="tr">Progresso</th><th scope="col">Concluída</th>
            </tr></thead>
            <tbody>
              <?php foreach ((array)($conq['linhas'] ?? []) as $a): ?>
                <?php $feita = ($a['completed_at'] ?? null) !== null; ?>
                <tr>
                  <th scope="row"><strong><?= e((string)$a['name']) ?></strong></th>
                  <td><?= e((string)($a['category'] ?? '')) ?></td>
                  <td class="tr num"><?= e(num((int)$a['progress'])) ?><span class="pj-of">/<?= e(num((int)$a['target'])) ?></span></td>
                  <td><?= $feita ? $selo($dataHora((string)$a['completed_at']), 'ok', 'check_circle') : '<span class="adm-meta">em andamento</span>' ?></td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
      <?php endif ?>
    </section>

    <?php /* -------------------------------------------------------------------- partidas */ ?>
    <section class="adm-card">
      <header class="adm-card__head">
        <h3 class="adm-card__title"><?= e((string)($titulos['partidas'] ?? 'Partidas recentes')) ?>
          <small><?= e(num((int)($part['total'] ?? 0))) ?> partidas · <?= e(num((int)($part['extracoes'] ?? 0))) ?> extrações · <?= e(num((int)($part['abates'] ?? 0))) ?> abates</small></h3>
      </header>
      <?php if ((array)($part['linhas'] ?? []) === []): ?>
        <div class="adm-empty"><?= icon('sports_esports') ?><p class="adm-empty__txt"><?= e((string)($vazios['sem_partida'] ?? '')) ?></p></div>
      <?php else: ?>
        <div class="adm-tablewrap">
          <table class="adm-table adm-table--compact">
            <thead><tr>
              <th scope="col">Quando</th><th scope="col">Mapa</th><th scope="col">Desfecho</th>
              <th scope="col" class="tr">Abates</th><th scope="col" class="tr">XP</th><th scope="col" class="tr">Ganhos</th>
            </tr></thead>
            <tbody>
              <?php foreach ((array)($part['linhas'] ?? []) as $pm): ?>
                <tr>
                  <th scope="row"><?= e($dataHora((string)$pm['played_at'])) ?></th>
                  <td><?= e((string)($pm['mapa'] ?? '') ?: '—') ?></td>
                  <td><?= $seloDe($rResult, (string)$pm['result']) ?></td>
                  <td class="tr num"><?= e(num((int)$pm['kills'])) ?></td>
                  <td class="tr num"><?= e(num((int)$pm['xp'])) ?></td>
                  <td class="tr num"><?= e($moeda((int)$pm['earnings'])) ?></td>
                </tr>
              <?php endforeach ?>
            </tbody>
            <tfoot>
              <tr>
                <th scope="row" colspan="3">Carreira inteira</th>
                <td class="tr num"><?= e(num((int)($part['abates'] ?? 0))) ?></td>
                <td class="tr num">—</td>
                <td class="tr num"><?= e($moeda((int)($part['ganhos'] ?? 0))) ?></td>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php endif ?>
    </section>
  </div>

  <?php /* ============================================================ coluna de operação */ ?>
  <div class="pj-grid__side">

    <?php /* ---------------------------------------------------------------- conta/acesso */ ?>
    <section class="adm-card">
      <header class="adm-card__head"><h3 class="adm-card__title"><?= e((string)($titulos['conta'] ?? 'Conta e acesso')) ?></h3></header>
      <div class="adm-card__body">
        <?php if ($travaMotivo !== ''): ?>
          <p class="pj-note pj-note--lock"><?= icon('lock') ?><span><?= e($travaMotivo) ?></span></p>
        <?php endif ?>

        <form class="adm-form" method="post" action="<?= e(url('admin/jogadores/salvar')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="acao" value="conta">
          <input type="hidden" name="id" value="<?= e((string)$uid) ?>">

          <div class="adm-field<?= e($invalido('papel')) ?>">
            <label class="adm-label" for="pj-papel">Papel</label>
            <?php
              /* O select oferece SÓ os papéis que este operador pode conceder. O papel atual
                 entra desabilitado quando está fora dessa lista — some da lista de opções,
                 mas continua visível: o operador precisa ver o que a conta é hoje. */
              $papelAtual = (string)($conta['role'] ?? 'player');
              $papelSel   = (string)($old['papel'] ?? $papelAtual);
              $mostraAtual= !in_array($papelAtual, $papeisOk, true);
            ?>
            <select class="adm-select" id="pj-papel" name="papel"<?= $podeAgir ? '' : ' disabled' ?>>
              <?php if ($mostraAtual): ?>
                <option value="<?= e($papelAtual) ?>" selected disabled>
                  <?= e((string)($rPapel[$papelAtual]['label'] ?? $papelAtual)) ?> (atual)
                </option>
              <?php endif ?>
              <?php foreach ($papeisOk as $p): ?>
                <option value="<?= e($p) ?>"<?= $papelSel === $p ? ' selected' : '' ?>>
                  <?= e((string)($rPapel[$p]['label'] ?? $p)) ?> — <?= e((string)($rPapel[$p]['ajuda'] ?? '')) ?>
                </option>
              <?php endforeach ?>
            </select>
            <?= $erro('papel') ?>
          </div>

          <div class="adm-field<?= e($invalido('situacao')) ?>">
            <label class="adm-label" for="pj-situacao">Situação</label>
            <?php $situAtual = (string)($conta['status'] ?? 'active'); $situSel = (string)($old['situacao'] ?? $situAtual); ?>
            <select class="adm-select" id="pj-situacao" name="situacao"<?= $podeAgir && $situAtual !== 'banned' ? '' : ' disabled' ?>>
              <?php if ($situAtual === 'banned'): ?>
                <option value="banned" selected disabled><?= e((string)($rSituac['banned']['label'] ?? 'Banida')) ?> (por punição)</option>
              <?php endif ?>
              <option value="active"<?= $situSel === 'active' ? ' selected' : '' ?>>Ativa — entra no jogo normalmente</option>
              <option value="suspended"<?= $situSel === 'suspended' ? ' selected' : '' ?>>Suspensa — bloqueada, sem punição registrada</option>
            </select>
            <?= $erro('situacao') ?>
            <?php if ($situAtual === 'banned'): ?>
              <p class="adm-hint"><?= e((string)($micro['ban_ativo'] ?? '')) ?></p>
            <?php endif ?>
          </div>

          <p class="adm-hint"><?= icon('key_off') ?> <?= e((string)($micro['senha'] ?? '')) ?></p>

          <div class="adm-form__actions">
            <button class="adm-btn adm-btn--primary" type="submit"<?= $podeAgir ? '' : ' disabled' ?>>
              <?= icon('save') ?><span>Salvar conta</span>
            </button>
          </div>
        </form>
      </div>
    </section>

    <?php /* -------------------------------------------------------------------- punições */ ?>
    <section class="adm-card pj-ban">
      <header class="adm-card__head">
        <h3 class="adm-card__title"><?= e((string)($titulos['punicao'] ?? 'Punições')) ?></h3>
      </header>
      <div class="adm-card__body">
        <?php if ($banAtivo !== null): ?>
          <?php $perma = (string)($banAtivo['kind'] ?? 'temp') === 'perma'; ?>
          <div class="pj-note pj-note--ban">
            <?= icon('gavel') ?>
            <div>
              <strong><?= $perma ? 'Banimento permanente' : 'Banimento temporário' ?></strong>
              <p><?= e((string)($banAtivo['reason'] ?? '')) ?></p>
              <ul class="pj-note__meta">
                <li>Aplicado por <?= e((string)($banAtivo['admin_nome'] ?? 'sistema')) ?> em <?= e($dataHora((string)$banAtivo['created_at'])) ?></li>
                <li><?= $perma ? 'Sem data de término.'
                       : 'Termina em ' . e($dataHora((string)($banAtivo['expires_at'] ?? ''))) . ' (' . e($relativo((string)($banAtivo['expires_at'] ?? ''))) . ')' ?></li>
              </ul>
            </div>
          </div>
          <form method="post" action="<?= e(url('admin/jogadores/salvar')) ?>"
                data-confirmar="Revogar o banimento de <?= e($nome) ?>? A conta volta a entrar no jogo imediatamente.">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="revogar">
            <input type="hidden" name="id" value="<?= e((string)$uid) ?>">
            <input type="hidden" name="ban_id" value="<?= e((string)$banAtivo['id']) ?>">
            <button class="adm-btn adm-btn--primary" type="submit"<?= $podeAgir ? '' : ' disabled' ?>>
              <?= icon('lock_open') ?><span>Revogar banimento</span>
            </button>
          </form>
        <?php else: ?>
          <?php if ($travaMotivo !== ''): ?>
            <p class="pj-note pj-note--lock"><?= icon('lock') ?><span><?= e($travaMotivo) ?></span></p>
          <?php endif ?>
          <form class="adm-form" method="post" action="<?= e(url('admin/jogadores/salvar')) ?>"
                data-confirmar="Banir <?= e($nome) ?>? A conta é bloqueada na hora e a punição fica registrada com o seu nome.">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="banir">
            <input type="hidden" name="id" value="<?= e((string)$uid) ?>">

            <div class="adm-field<?= e($invalido('motivo')) ?>">
              <label class="adm-label" for="pj-motivo">Motivo<span class="adm-label__req">*</span></label>
              <textarea class="adm-textarea" id="pj-motivo" name="motivo" maxlength="500" rows="3"
                        placeholder="O que aconteceu, com data e evidência se houver."
                        <?= $podeAgir ? '' : 'disabled' ?>><?= e((string)($old['motivo'] ?? '')) ?></textarea>
              <?= $erro('motivo') ?>
              <p class="adm-hint">Fica gravado no histórico e é o que sustenta a decisão numa revisão.</p>
            </div>

            <div class="adm-form__row">
              <div class="adm-field<?= e($invalido('tipo')) ?>">
                <label class="adm-label" for="pj-tipo">Tipo</label>
                <select class="adm-select" id="pj-tipo" name="tipo"<?= $podeAgir ? '' : ' disabled' ?>>
                  <?php foreach ($rTipos as $chave => $rot): ?>
                    <option value="<?= e((string)$chave) ?>"<?= (string)($old['tipo'] ?? 'temp') === (string)$chave ? ' selected' : '' ?>><?= e((string)$rot) ?></option>
                  <?php endforeach ?>
                </select>
                <?= $erro('tipo') ?>
              </div>
              <div class="adm-field<?= e($invalido('expira')) ?>">
                <label class="adm-label" for="pj-expira">Vale até</label>
                <?php
                  /* Sugestão de 7 dias: é o prazo que a moderação mais usa e evita que o campo
                     comece vazio, obrigando a digitar data e hora inteiras a cada punição. */
                  $sugestao = (string)($old['expira'] ?? date('Y-m-d\TH:i', $agora + 7 * 86400));
                ?>
                <input class="adm-input" type="datetime-local" id="pj-expira" name="expira"
                       value="<?= e($sugestao) ?>"<?= $podeAgir ? '' : ' disabled' ?>>
                <?= $erro('expira') ?>
                <p class="adm-hint">Ignorado no banimento permanente.</p>
              </div>
            </div>

            <div class="adm-form__actions">
              <button class="adm-btn adm-btn--danger" type="submit"<?= $podeAgir ? '' : ' disabled' ?>>
                <?= icon('gavel') ?><span>Aplicar banimento</span>
              </button>
            </div>
          </form>
        <?php endif ?>

        <?php if ($bans === []): ?>
          <p class="adm-meta pj-hist-vazio"><?= e((string)($vazios['sem_punicao'] ?? '')) ?></p>
        <?php else: ?>
          <h4 class="pj-sub">Histórico</h4>
          <ul class="pj-hist">
            <?php foreach ($bans as $b): ?>
              <?php
                $revogado = ($b['revoked_at'] ?? null) !== null;
                $vencido  = !$revogado && (string)$b['kind'] !== 'perma'
                            && ($b['expires_at'] ?? null) !== null && strtotime((string)$b['expires_at']) <= $agora;
                [$rot, $tom] = $revogado ? ['Revogado', 'muted'] : ($vencido ? ['Cumprido', 'muted'] : ['Em vigor', 'danger']);
              ?>
              <li class="pj-hist__item">
                <div class="pj-hist__top">
                  <?= $selo($rot, $tom) ?>
                  <span class="adm-meta"><?= e((string)($rTipos[(string)$b['kind']] ?? $b['kind'])) ?> · <?= e($dataHora((string)$b['created_at'])) ?></span>
                </div>
                <p class="pj-hist__why"><?= e((string)($b['reason'] ?? '')) ?></p>
                <p class="adm-meta">
                  por <?= e((string)($b['admin_nome'] ?? 'sistema')) ?>
                  <?php if ($revogado): ?> · revogado por <?= e((string)($b['revogou_nome'] ?? 'sistema')) ?> em <?= e($dataHora((string)$b['revoked_at'])) ?><?php endif ?>
                </p>
              </li>
            <?php endforeach ?>
          </ul>
        <?php endif ?>
      </div>
    </section>

    <?php /* -------------------------------------------------------------------- facções */ ?>
    <section class="adm-card">
      <header class="adm-card__head"><h3 class="adm-card__title"><?= e((string)($titulos['faccoes'] ?? 'Facções')) ?></h3></header>
      <?php if ($faccoes === []): ?>
        <div class="adm-empty"><?= icon('handshake') ?><p class="adm-empty__txt"><?= e((string)($vazios['sem_faccao'] ?? '')) ?></p></div>
      <?php else: ?>
        <div class="adm-card__body">
          <ul class="pj-fac">
            <?php foreach ($faccoes as $f): ?>
              <li class="pj-fac__item">
                <span class="pj-fac__name"><?= e((string)($rFaccao[(string)$f['faction']] ?? $f['faction'])) ?></span>
                <span class="pj-fac__tier"><?= e((string)($f['tier'] ?? '—')) ?></span>
                <span class="pj-fac__rep num"><?= e(num((int)$f['reputation'])) ?></span>
                <?php if ((int)($f['contract_active'] ?? 0) === 1): ?><?= $selo('Contrato ativo', 'ok') ?><?php endif ?>
              </li>
            <?php endforeach ?>
          </ul>
        </div>
      <?php endif ?>
    </section>

    <?php /* ------------------------------------------------------------- zona de perigo */ ?>
    <section class="adm-card pj-danger">
      <header class="adm-card__head"><h3 class="adm-card__title"><?= e((string)($titulos['perigo'] ?? 'Excluir conta')) ?></h3></header>
      <div class="adm-card__body">
        <p class="adm-hint"><?= e((string)($micro['excluir_ajuda'] ?? '')) ?></p>

        <?php if ($refs !== []): ?>
          <?php
            /* Bloqueio EXPLICADO, item a item: "não é possível excluir" sem dizer o que
               segura obriga o operador a adivinhar — e a tentar de novo amanhã. */
            $rotulos = ['partidas' => 'partidas', 'compras' => 'compras', 'punicoes' => 'punições',
                        'itens' => 'itens no inventário', 'missoes' => 'missões', 'conquistas' => 'conquistas'];
          ?>
          <div class="pj-note pj-note--lock">
            <?= icon('block') ?>
            <div>
              <strong><?= e((string)($micro['excluir_travado'] ?? '')) ?></strong>
              <ul class="pj-note__meta">
                <?php foreach ($refs as $chave => $n): ?>
                  <li><span class="num"><?= e(num((int)$n)) ?></span> <?= e((string)($rotulos[$chave] ?? $chave)) ?></li>
                <?php endforeach ?>
              </ul>
            </div>
          </div>
          <button class="adm-btn adm-btn--danger" type="button" disabled><?= icon('delete_forever') ?><span>Excluir conta</span></button>
        <?php else: ?>
          <?php if ($travaMotivo !== ''): ?>
            <p class="pj-note pj-note--lock"><?= icon('lock') ?><span><?= e($travaMotivo) ?></span></p>
          <?php endif ?>
          <form class="adm-form" method="post" action="<?= e(url('admin/jogadores/excluir')) ?>"
                data-confirmar="Apagar a conta <?= e($nome) ?> para sempre? Não há como desfazer.">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e((string)$uid) ?>">
            <div class="adm-field<?= e($invalido('confirmar')) ?>">
              <label class="adm-label" for="pj-confirmar"><?= e((string)($micro['excluir_confir'] ?? 'Confirme')) ?></label>
              <input class="adm-input" type="text" id="pj-confirmar" name="confirmar" autocomplete="off"
                     placeholder="<?= e($nome) ?>"<?= $podeAgir ? '' : ' disabled' ?>>
              <?= $erro('confirmar') ?>
            </div>
            <div class="adm-form__actions">
              <button class="adm-btn adm-btn--danger" type="submit"<?= $podeAgir ? '' : ' disabled' ?>>
                <?= icon('delete_forever') ?><span>Excluir conta</span>
              </button>
            </div>
          </form>
        <?php endif ?>
      </div>
    </section>
  </div>
</div>

<?php endif ?>
