/**
 * HUD IN-GAME — overlay transparente (app/Views/pages/hud.php).
 *
 * Por enquanto só o bloco de armas no canto inferior direito: bandoleira e coldre, cada
 * uma com a munição do carregador instalado e a reserva do mesmo calibre.
 * O servidor pinta o estado inicial (inventário no banco) e assets/js/pages/hud.js
 * atualiza em tempo real com o que a Unreal manda.
 *
 * data: guns[] (slot, label, name, image, caliber, current, capacity, has_mag, reserve)
 */
import { media, url } from '../../core/helpers';
import { html, join, type SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';

/** str_pad((string)$n, 3, '0', STR_PAD_LEFT) */
const pad3 = (n: number): string => String(n).padStart(3, '0');

function arma(g: Dict): SafeHtml {
  const cur = Number(g.current ?? 0) | 0;
  const cap = Math.max(1, Number(g.capacity ?? 1) | 0);
  const pct = Math.round((cur / cap) * 100);
  const temMag = Boolean(g.has_mag);
  return html`    <article class="ov-gun${temMag ? '' : ' is-empty'}"
             data-gun="${String(g.slot)}"
             data-capacity="${String(g.capacity ?? 0)}">
      <div class="ov-gun__info">
        <span class="ov-gun__slot ov-shadow">${String(g.label)}</span>
        <strong class="ov-gun__name ov-shadow">${String(g.name)}</strong>
      </div>

${g.image ? html`      <img class="ov-gun__art" src="${media(String(g.image))}" alt="" draggable="false">
` : ''}
      <div class="ov-gun__ammo">
${temMag
    ? html`        <span class="ov-gun__cur num ov-shadow" data-ammo-cur>${pad3(cur)}</span>
        <span class="ov-gun__sep" aria-hidden="true">/</span>
        <span class="ov-gun__res num ov-shadow" data-ammo-res>${pad3(Number(g.reserve ?? 0) | 0)}</span>
`
    : html`        <span class="ov-gun__nomag ov-shadow">SEM CARREGADOR</span>
`}      </div>

      <span class="ov-gun__bar" aria-hidden="true"><i style="--v:${String(pct)}%"></i></span>
      <span class="ov-gun__cal ov-shadow">${String(g.caliber ?? '')}</span>
    </article>
`;
}

export function hud(s: Scope): SafeHtml {
  const guns = (Array.isArray(s.data.guns) ? s.data.guns : []) as Dict[];
  return html`<div class="ov" data-hud
     data-endpoint-ammo="${url('hud/municao')}"
     data-endpoint-data="${url('hud/dados')}">

  <section class="ov-guns" aria-label="Armamento">
${guns.length === 0 ? html`    <p class="ov-guns__empty ov-shadow">Nenhuma arma equipada.</p>
` : ''}${join(guns.map(arma))}  </section>
</div>
`;
}
