/**
 * Tela LOJA — app/Views/pages/loja.php.
 * Comprar é um POST real para /loja/comprar (com csrf); loja.js intercepta o submit, envia
 * por fetch e atualiza a carteira sem recarregar. Sem JS, o POST recarrega a tela.
 */
import { csrfField, icon, media, num, svg, url } from '../../core/helpers';
import { e, html, join, raw, SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { chip } from '../components';
import { tabs } from '../components/tabs';

const int = (v: unknown): number => Math.trunc(Number(v) || 0);

/* moeda -> glifo do sprite + classe de cor (SPEC §5) */
const CUR_META: Record<string, { svg: string; cls: string }> = {
  credits: { svg: 'coin-credit', cls: 'lime' },
  cash: { svg: 'coin-cash', cls: 'cyan' },
  points: { svg: 'coin-points', cls: 'amber' },
};

/** Cor do selo da vitrine por etiqueta. */
const TAG_COR: Record<string, string> = { NOVO: 'lime', POPULAR: 'cyan', RARO: 'purple', ÉPICO: 'gold', LIMITADO: 'amber' };

/** Chip de preço na cor da moeda. */
function priceChip(value: number, currency: string, extra = ''): SafeHtml {
  const m = CUR_META[currency] ?? CUR_META.credits!;
  return raw(
    '<span class="price price--' + e(m.cls) + (extra !== '' ? ' ' + e(extra) : '') + '">' +
      svg(m.svg, 'price__coin').value +
      '<span class="price__val num">' + e(num(value)) + '</span></span>',
  );
}

export function loja(s: Scope): SafeHtml {
  const d = s.data;
  const shopTip = String(d.tip ?? '');
  const tipLow = String(d.tip_low ?? 'Saldo insuficiente');
  const ctaBuy = String(d.cta_buy ?? 'COMPRAR');
  const ctaLocked = String(d.cta_locked ?? 'SEM SALDO');
  const ctaBusy = String(d.cta_busy ?? 'COMPRANDO');
  const ctaOwned = String(d.cta_owned ?? 'NO STASH');
  const cats = (d.categories ?? []) as Dict[];
  const active = String(d.active_category ?? 'destaques');
  const allItems = (d.items ?? []) as Dict[];
  const featured: Dict = d.featured ?? {};
  const wallet = (d.wallet ?? []) as Dict[];
  const purse: Dict = d.balance ?? s.player.currencies ?? {};
  const buyUrl = url('loja/comprar');

  /** Formulário de compra (POST real + csrf). */
  const buyForm = (it: Dict, btnClass: string, formClass: string): SafeHtml => {
    const ok = Boolean(it.affordable);
    const tip = ok ? shopTip : tipLow;
    return raw(
      '<form class="' + e(formClass) + '" method="post" action="' + e(buyUrl) + '" data-buy' +
        ' data-tip="' + e(tip) + '">' +
        csrfField().value +
        '<input type="hidden" name="offer_id" value="' + e(String(int(it.offer_id ?? 0))) + '">' +
        '<input type="hidden" name="categoria" value="' + e(active) + '">' +
        '<button class="' + e(btnClass) + '" type="submit" data-buy-btn' + (ok ? '' : ' disabled') + '>' +
        icon(ok ? 'shopping_cart' : 'lock').value +
        '<span data-buy-label>' + e(ok ? ctaBuy : ctaLocked) + '</span>' +
        '</button></form>',
    );
  };

  /* itens da categoria ativa (DESTAQUES = vitrine curada) */
  const items = allItems.filter((it) => (active === 'destaques' ? Boolean(it.featured) : String(it.category ?? '') === active));

  /* contagem por aba */
  const counts: Record<string, number> = { destaques: 0 };
  for (const it of allItems) {
    const c = String(it.category ?? '');
    counts[c] = (counts[c] ?? 0) + 1;
    if (it.featured) counts.destaques!++;
  }

  const catLabel: Record<string, string> = {};
  for (const c of cats) catLabel[String(c.slug ?? '')] = String(c.label ?? '');

  const tabItems = cats.map((c) => {
    const slug = String(c.slug ?? '');
    return {
      label: String(c.label ?? ''),
      icon: String(c.icon ?? ''),
      href: url('loja', slug === 'destaques' ? {} : { categoria: slug }),
      count: String(counts[slug] ?? 0),
      active: slug === active,
    };
  });

  const promo: Dict = featured.offer && typeof featured.offer === 'object' ? featured.offer : {};
  const fPrice = int(featured.price ?? 0);
  const fOld = int(featured.old_price ?? 0);
  const fCur = String(featured.currency ?? 'cash');
  const saldo = JSON.stringify({ credits: int(purse.credits ?? 0), cash: int(purse.cash ?? 0), points: int(purse.points ?? 0) });

  let grade: SafeHtml;
  if (items.length === 0) {
    grade = html`  <p class="shop__empty muted" data-boot>${String(d.empty ?? '')}</p>
`;
  } else {
    const nichos: SafeHtml[] = [];
    /* a grade é sempre 6x2: as vagas que sobram viram nichos vazios do mostruário */
    for (let i = items.length; i < 12; i++) {
      nichos.push(html`    <div class="item item--soon" aria-hidden="true">
      <span class="item__soon">${icon('lock', 'item__soon-ico')}<span class="item__soon-txt">${String(d.soon ?? 'EM BREVE')}</span></span>
    </div>
`);
    }
    grade = html`  <div class="shop__grid" data-stagger>
${join(
  items.map((it) => {
    const iCur = String(it.currency ?? 'credits');
    const iOwned = Boolean(it.owned);
    const iPoor = !it.affordable;
    const iTag = String(it.tag ?? '');
    const tagCol = TAG_COR[iTag] ?? 'red';
    return html`    <article class="item${iOwned ? ' is-owned' : ''}${iPoor ? ' is-locked' : ''}"
             data-slug="${String(it.slug ?? '')}"
             data-offer="${int(it.offer_id ?? 0)}"
             data-price="${int(it.price ?? 0)}"
             data-currency="${iCur}">
      <div class="item__media">
        <span class="item__glow" aria-hidden="true"></span>
        <img class="item__img" src="${media(String(it.image ?? ''))}" alt="${String(it.name ?? '')}" loading="lazy" decoding="async">
${iTag !== '' ? html`        <span class="item__tag">${chip({ label: iTag, color: tagCol })}</span>
` : ''}        <span class="item__owned"${iOwned ? '' : raw(' hidden')} data-owned-badge>${icon('check_circle', 'fill')}${ctaOwned}</span>
        ${buyForm(it, 'btn btn--primary btn--sm item__buy', 'item__hover')}
      </div>
      <div class="item__body">
        <h3 class="item__name">${String(it.name ?? '')}</h3>
        <p class="item__desc">${String(it.desc ?? '')}</p>
        <div class="item__foot">
          ${priceChip(int(it.price ?? 0), iCur)}
          <span class="item__cat">${catLabel[String(it.category ?? '')] ?? ''}</span>
        </div>
      </div>
    </article>
`;
  }),
)}${join(nichos)}  </div>
`;
  }

  return html`<div class="shop"
     data-shop
     data-cta-buy="${ctaBuy}"
     data-cta-locked="${ctaLocked}"
     data-cta-busy="${ctaBusy}"
     data-tip-buy="${shopTip}"
     data-tip-low="${tipLow}"
     data-balance="${saldo}">

  <!-- ============================ cabeçalho + carteira ============================ -->
  <header class="shop__head" data-boot>
    <div class="shop__id">
      <p class="shop__kicker">${icon('local_fire_department', 'shop__kicker-ico')}${String(d.kicker ?? '')}</p>
      <h1 class="page-title shop__title">${String(d.title ?? 'LOJA')}<small>${String(d.season ?? '')}</small></h1>
      <p class="page-subtitle shop__intro">${String(d.intro ?? '')}</p>
    </div>

    <div class="wallet" aria-label="${String(d.wallet_label ?? 'Carteira')}">
      <span class="wallet__label">${icon('account_balance_wallet', 'wallet__label-ico')}${String(d.wallet_label ?? 'CARTEIRA')}</span>
      <ul class="wallet__list">
${join(
  wallet.map((w) => {
    const wKey = String(w.key ?? '');
    const wVal = int(purse[wKey] ?? 0);
    return html`        <li class="wallet__coin wallet__coin--${String(w.color ?? 'lime')}">
          ${svg(String(w.svg ?? 'coin-credit'), 'wallet__ico')}
          <span class="wallet__txt">
            <span class="wallet__val num" data-wallet="${wKey}" data-count="${wVal}" data-dur="1100">${num(wVal)}</span>
            <span class="wallet__name">${String(w.label ?? '')}</span>
          </span>
        </li>
`;
  }),
)}      </ul>
    </div>
  </header>

  <!-- ============================== abas de categoria ============================= -->
  <div class="shop__tabs" data-boot>
    ${tabs({ items: tabItems, label: 'Categorias da loja', class: 'shop__tabnav' })}    <p class="shop__note">${icon(String(d.tip_icon ?? 'bolt'), 'shop__note-ico')}${shopTip}</p>
  </div>

  <!-- ============================== pacote em destaque ============================ -->
  <section class="panel promo${!promo.affordable ? ' is-locked' : ''}" data-boot
           data-offer="${int(promo.offer_id ?? 0)}"
           data-price="${fPrice}"
           data-currency="${fCur}">
    <div class="promo__art" aria-hidden="true">
      <img class="promo__bg" src="${media(String(featured.image ?? 'ui/season-pass.jpg'))}" alt="" decoding="async">
      <img class="promo__product float" src="${media(String(featured.product ?? 'items/weapon-case.png'))}" alt="" decoding="async">
    </div>

    <div class="promo__info">
      <p class="promo__kicker">
        <span class="chip chip--gold chip--solid promo__tag">${String(featured.tag ?? 'OFERTA')}</span>
        <span class="promo__kicker-txt">${String(featured.kicker ?? '')}</span>
      </p>
      <h2 class="promo__title">${String(featured.title ?? '')}</h2>
      <p class="promo__desc">${String(featured.desc ?? '')}</p>
      <ul class="promo__bullets">
${join(((featured.bullets ?? []) as unknown[]).map((b) => html`        <li class="promo__bullet">${icon('check_circle', 'fill')}${String(b)}</li>
`))}      </ul>
    </div>

    <div class="promo__buy">
      <p class="promo__timer">
        ${icon('schedule', 'promo__timer-ico')}
        <span class="promo__timer-lbl">${String(featured.ends_label ?? 'TERMINA EM')}</span>
        <span class="promo__clock num" data-countdown="${String(featured.ends_at ?? '')}">--</span>
      </p>
${fOld > 0
  ? html`      <p class="promo__prices">
        <span class="promo__old num">${num(fOld)}</span>
        <span class="chip chip--red promo__disc">${String(featured.discount ?? '')}</span>
      </p>
`
  : ''}      ${priceChip(fPrice, fCur, 'price--lg promo__price')}
${promo.offer_id ? html`      ${buyForm(promo, 'btn btn--gold btn--lg promo__cta', 'promo__form')}
` : ''}    </div>
  </section>

  <!-- ================================ grade de itens ============================== -->
${grade}</div>
`;
}
