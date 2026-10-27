/**
 * Campo de IMAGEM do painel — app/Views/components/admin-upload.php (component('admin-upload', $p)).
 *
 * Substitui SOMENTE o <input type="text"> do caminho da imagem: o <label>, a .adm-hint e a
 * mensagem de erro continuam na tela que chama. O campo de texto continua sendo o campo (mesmo
 * name, mesma string no banco); o upload apenas ESCREVE nele (assets/js/admin/upload.js, que
 * faz POST em /admin/upload — rota do lote que portar o UploadController, gravando no R2 em
 * media/uploads/<entidade>/...).
 *
 * Uso numa view:  ${adminUpload({ id: 'm-img', name: 'image', entidade: 'missoes', valor: form.image })}
 *
 * props:
 *   id           string  id do input (o <label for> da tela aponta para ele) — obrigatório
 *   name         string  name do input — obrigatório
 *   entidade     string  pasta em media/uploads/ (lista ENTIDADES do UploadController) — obrigatório
 *   valor        string  caminho atual, relativo a /media
 *   placeholder  string
 *   maxlength    number  teto da coluna (padrão 160)
 *   mono         bool    fonte monoespaçada no input
 *   class        string  classes extras no <input>
 *   invalido     bool    aria-invalid="true"
 *   descrito_por string  id para aria-describedby
 *   required     bool
 *
 * O CSS/JS do componente (css/admin/upload.css, js/admin/upload.js) sai UMA vez por página,
 * junto do primeiro campo desenhado (no PHP era um define(); aqui, o cache da requisição).
 */
import { BASE_URL } from '../../core/config';
import { ctx } from '../../core/context';
import { asset, icon, media, url } from '../../core/helpers';
import { html, raw, type SafeHtml } from '../../core/html';
import { nonce } from '../../core/security';
import type { Dict } from '../../core/view';

export function adminUpload(p: Dict = {}): SafeHtml {
  const upId = String(p.id ?? '');
  const upName = String(p.name ?? '');
  const upEnt = String(p.entidade ?? '').trim().toLowerCase();
  const upVal = String(p.valor ?? '');
  const upMax = Number(p.maxlength ?? 160) | 0;
  const upPh = String(p.placeholder ?? '');
  const upCls = ('adm-input adm-up__path ' + (p.mono ? 'mono ' : '') + String(p.class ?? '')).trim();
  const upDesc = String(p.descrito_por ?? '');

  // Sem entidade (ou id) o botão some e o campo de texto continua funcionando.
  const podeEnviar = upEnt !== '' && upId !== '';
  // media() cai para assets/img/ quando o arquivo não está em /media (artes antigas).
  const upSrc = upVal !== '' ? media(upVal) : '';

  let assets: SafeHtml = raw('');
  const cache = ctx().cache;
  if (podeEnviar && !cache.has('admin-upload.assets')) {
    cache.set('admin-upload.assets', true);
    assets = html`<link rel="stylesheet" href="${asset('css/admin/upload.css')}">
<script type="module" nonce="${nonce()}" src="${asset('js/admin/upload.js')}"></script>
`;
  }

  const dataAttrs = podeEnviar
    ? html` data-adm-up data-entidade="${upEnt}" data-url="${url('admin/upload')}" data-media="${BASE_URL + 'media/'}"`
    : '';

  return html`${assets}<div class="adm-up${podeEnviar ? '' : ' adm-up--so-texto'}"${dataAttrs}>

  <span class="adm-up__prev" data-adm-up-prev>
${upSrc !== '' ? html`      <img class="adm-up__img" src="${upSrc}" alt="" loading="lazy" decoding="async">
` : ''}    <span class="adm-up__vazio" aria-hidden="true">${icon('image')}</span>
  </span>

  <span class="adm-up__corpo">
    <input class="${upCls}" id="${upId}" name="${upName}" type="text"
           maxlength="${upMax}" value="${upVal}" spellcheck="false" autocomplete="off"
           ${upPh !== '' ? html`placeholder="${upPh}" ` : ''}
           ${p.required ? raw('required ') : ''}
           ${p.invalido ? raw('aria-invalid="true" ') : ''}
           ${upDesc !== '' ? html`aria-describedby="${upDesc}" ` : ''}data-adm-up-path>
${
  podeEnviar
    ? html`      <span class="adm-up__acoes">
        <input class="adm-up__file" type="file" id="${upId}-file"
               accept="image/svg+xml,image/png,image/webp,image/jpeg" data-adm-up-file>
        <label class="adm-btn adm-btn--sm adm-up__btn" for="${upId}-file">
          ${icon('upload')}<span>Enviar imagem</span>
        </label>
        <span class="adm-up__estado" data-adm-up-estado role="status" aria-live="polite"></span>
      </span>

      <span class="adm-up__barra" data-adm-up-barra hidden><span class="adm-up__barra-in"></span></span>
`
    : ''
}  </span>
</div>
`;
}
