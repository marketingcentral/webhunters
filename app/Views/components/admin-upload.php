<?php declare(strict_types=1);
/**
 * Campo de IMAGEM do painel — component('admin-upload', $props)
 *
 * Substitui SOMENTE o <input type="text"> do caminho da imagem. O <label>, a <p class="adm-hint">
 * e a mensagem de erro continuam na tela que chama — por isso trocar o campo numa view é
 * trocar uma linha, e cada tela mantém o texto e o estado de erro que já tinha.
 *
 * O CAMPO DE TEXTO CONTINUA SENDO O CAMPO. Quem já sabe o caminho ("bg/map-world.jpg",
 * uma arte que veio junto com o jogo) digita e pronto — enviar arquivo é um atalho, não uma
 * obrigação. É o mesmo <input name="..."> de antes: o formulário não muda, o controller da
 * tela não muda, o banco recebe a mesma string. O upload apenas ESCREVE nesse input.
 *
 * props:
 *   id          : string   id do input (o <label for> da tela aponta para ele) — obrigatório
 *   name        : string   name do input (ex.: 'image') — obrigatório
 *   entidade    : string   pasta de destino em media/uploads/ (ex.: 'missoes') — obrigatório;
 *                          precisa estar na lista de UploadController::ENTIDADES
 *   valor       : string   caminho atual, relativo a /media
 *   placeholder : string
 *   maxlength   : int      teto da coluna no banco (padrão 160)
 *   mono        : bool     usa a fonte monoespaçada no input (telas de mapas usam)
 *   class       : string   classes extras no <input>
 *   invalido    : bool     marca o input como aria-invalid (a tela já pinta o .adm-field)
 *   descrito_por: string   id do parágrafo de erro/dica, para aria-describedby
 *   required    : bool
 */

use App\Core\Config;

$p = $props ?? [];

$upId    = (string)($p['id'] ?? '');
$upName  = (string)($p['name'] ?? '');
$upEnt   = strtolower(trim((string)($p['entidade'] ?? '')));
$upVal   = (string)($p['valor'] ?? '');
$upMax   = (int)($p['maxlength'] ?? 160);
$upPh    = (string)($p['placeholder'] ?? '');
$upCls   = trim('adm-input adm-up__path ' . (!empty($p['mono']) ? 'mono ' : '') . (string)($p['class'] ?? ''));
$upDesc  = (string)($p['descrito_por'] ?? '');

/* Sem entidade o componente não teria para onde mandar o arquivo. Em vez de inventar uma
   pasta (o que seria o cliente escolhendo destino), o campo de texto continua funcionando e
   só o botão some — a tela não quebra por um prop esquecido. */
$upPodeEnviar = $upEnt !== '' && $upId !== '';

/* URL da prévia. media() cai para assets/img/ quando o arquivo ainda não foi copiado para
   /media, que é justamente o caso das artes antigas do jogo. Caminho vazio = sem prévia. */
$upSrc = $upVal !== '' ? media($upVal) : '';

/* CSS e JS entram UMA vez por página, na primeira vez que um campo desses é desenhado.
   Não vão no layout do painel porque só três telas usam — e o layout é compartilhado com as
   outras onze. O <script> leva nonce (a CSP do projeto exige). */
if ($upPodeEnviar && !defined('EH_ADM_UPLOAD_ASSETS')) {
    define('EH_ADM_UPLOAD_ASSETS', true);
    echo '<link rel="stylesheet" href="' . e(asset('css/admin/upload.css')) . '">' . "\n";
    echo '<script type="module" nonce="' . e(nonce()) . '" src="' . e(asset('js/admin/upload.js')) . '"></script>' . "\n";
}
?>
<div class="adm-up<?= $upPodeEnviar ? '' : ' adm-up--so-texto' ?>"<?= $upPodeEnviar ? ' data-adm-up data-entidade="' . e($upEnt) . '" data-url="' . e(url('admin/upload')) . '" data-media="' . e(Config::baseUrl() . 'media/') . '"' : '' ?>>

  <?php /* A prévia é o que o jogador vai ver. Um caminho errado digitado à mão aparece aqui
           como moldura vazia na hora, antes de salvar — é o teste mais barato que existe. */ ?>
  <span class="adm-up__prev" data-adm-up-prev>
    <?php if ($upSrc !== ''): ?>
      <img class="adm-up__img" src="<?= e($upSrc) ?>" alt="" loading="lazy" decoding="async">
    <?php endif ?>
    <span class="adm-up__vazio" aria-hidden="true"><?= icon('image') ?></span>
  </span>

  <span class="adm-up__corpo">
    <input class="<?= e($upCls) ?>" id="<?= e($upId) ?>" name="<?= e($upName) ?>" type="text"
           maxlength="<?= $upMax ?>" value="<?= e($upVal) ?>" spellcheck="false" autocomplete="off"
           <?= $upPh !== '' ? 'placeholder="' . e($upPh) . '" ' : '' ?>
           <?= !empty($p['required']) ? 'required ' : '' ?>
           <?= !empty($p['invalido']) ? 'aria-invalid="true" ' : '' ?>
           <?= $upDesc !== '' ? 'aria-describedby="' . e($upDesc) . '" ' : '' ?>data-adm-up-path>

    <?php if ($upPodeEnviar): ?>
      <span class="adm-up__acoes">
        <?php /* O <input type=file> fica escondido e o <label> é o botão: é o único jeito de
                 ter um botão com a aparência do painel que ainda abra o seletor de arquivos
                 sem JavaScript no meio do caminho. accept só filtra o diálogo — quem manda é
                 a validação do servidor. */ ?>
        <input class="adm-up__file" type="file" id="<?= e($upId) ?>-file"
               accept="image/svg+xml,image/png,image/webp,image/jpeg" data-adm-up-file>
        <label class="adm-btn adm-btn--sm adm-up__btn" for="<?= e($upId) ?>-file">
          <?= icon('upload') ?><span>Enviar imagem</span>
        </label>

        <?php /* role="status" + aria-live: o leitor de tela anuncia "enviando 40%", "pronto"
                 e o motivo da recusa sem o operador precisar caçar o texto na tela. */ ?>
        <span class="adm-up__estado" data-adm-up-estado role="status" aria-live="polite"></span>
      </span>

      <span class="adm-up__barra" data-adm-up-barra hidden><span class="adm-up__barra-in"></span></span>
    <?php endif ?>
  </span>
</div>
