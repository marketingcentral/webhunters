/**
 * Ícone de um PONTO DO MAPA, venha ele de onde vier — app/Views/components/map-point-icon.php.
 *
 * props:
 *   icon   string  valor cru de `map_point_types.icon` — "glyph:fuel" | "symbol:local_cafe" | "image:uploads/mapas/x.webp"
 *   color  string  cor do mapa (white|lime|cyan|amber|gold|red|purple|muted)
 *   size   string  sm | md | lg
 *   class  string  classes extras
 *
 * O mesmo ícone é desenhado na ficha do catálogo, na pré-visualização do formulário e na
 * paleta do editor (/admin/mapas/pontos e /admin/mapas/editor). As três origens ocupam a
 * MESMA caixa de propósito: o que faz uma paleta legível é todo ícone ter o mesmo peso.
 *
 * Diferente de helpers.mapPointIcon() (o do mapa do jogador, que devolve só o miolo com as
 * classes mk__*): este é o componente do painel, com a caixa .mpt-ic e o ícone de falta.
 */
import { icon, media } from '../../core/helpers';
import { DATA } from '../../generated/data-index';
import { html, raw, type SafeHtml } from '../../core/html';
import type { Dict } from '../../core/view';
import { icone } from '../../repo/admin-map-points-repo';

export function mapPointIconCmp(p: Dict = {}): SafeHtml {
  const ic = String(p.icon ?? 'symbol:place');
  const cor = String(p.color ?? 'white').toLowerCase().replace(/[^a-z]/g, '') || 'white';
  const size = ['sm', 'md', 'lg'].includes(String(p.size ?? 'md')) ? String(p.size ?? 'md') : 'md';
  const extra = String(p.class ?? '').trim();

  const [origem, valor] = icone(ic);
  const cls = 'mpt-ic mpt-ic--' + size + ' mpt-ic--' + cor + (extra !== '' ? ' ' + extra : '');

  let miolo: SafeHtml;
  if (origem === 'image') {
    miolo = html`<img src="${media(valor)}" alt="" loading="lazy" decoding="async">`;
  } else if (origem === 'glyph') {
    // leitura direta (sem a cópia do dataMock): a paleta desenha dezenas destes por página
    const g = (DATA as Record<string, unknown>)['map_glyphs'] as Record<string, string>;
    miolo = Object.prototype.hasOwnProperty.call(g, valor)
      ? // conteúdo confiável: a tabela é do próprio projeto, nunca de requisição
        html`<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="currentColor">${raw(g[valor])}</svg>`
      : // vetor removido da tabela depois que alguém já o escolheu: mostra a falta
        icon('help', 'mpt-ic__faltando');
  } else {
    miolo = icon(valor);
  }

  return html`<span class="${cls}" data-icon="${ic}">
  ${miolo}
</span>
`;
}
