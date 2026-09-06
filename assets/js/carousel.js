/**
 * EXTRACT HUNTERS — carousel (ES module).
 *
 * <div class="carousel" data-carousel data-interval="5000">
 *   <div class="carousel__track"><div class="carousel__slide">…</div>…</div>
 *   <div class="carousel__dots"></div>
 *   [<button class="carousel__prev ms">chevron_left</button> <button class="carousel__next ms">chevron_right</button>]
 * </div>
 *
 * Autoplay (data-interval ms, 0 = off), dots, prev/next, pause on hover/focus,
 * swipe/drag, loop, height auto, reduced motion (no autoplay, no transition).
 * Exposes el.carousel = { next, prev, go, play, pause, destroy }.
 */
const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;

export class Carousel {
  constructor(el) {
    this.el = el;
    this.track = el.querySelector('.carousel__track');
    this.slides = this.track ? Array.from(this.track.children) : [];
    this.dotsWrap = el.querySelector('.carousel__dots');
    this.prevBtn = el.querySelector('.carousel__prev, [data-carousel-prev]');
    this.nextBtn = el.querySelector('.carousel__next, [data-carousel-next]');
    this.index = 0;
    this.interval = Number(el.dataset.interval ?? 5000);
    this.timer = 0;
    this.paused = false;
    this.drag = null;
    if (!this.track || this.slides.length === 0) return;
    el.carousel = this;
    el.setAttribute('role', 'region');
    el.setAttribute('aria-roledescription', 'carrossel');
    this.slides.forEach((s, i) => { s.setAttribute('role', 'group'); s.setAttribute('aria-label', `${i + 1} de ${this.slides.length}`); });
    this.buildDots();
    this.bind();
    this.go(0, false);
    this.updateHeight();
    this.ro = new ResizeObserver(() => this.updateHeight());
    this.slides.forEach((s) => this.ro.observe(s));
    if (this.slides.length > 1 && !reduced) this.play();
  }

  buildDots() {
    if (!this.dotsWrap || this.slides.length < 2) return;
    this.dotsWrap.innerHTML = '';
    this.dots = this.slides.map((_, i) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'carousel__dot';
      b.setAttribute('aria-label', `Ir para o slide ${i + 1}`);
      b.addEventListener('click', () => { this.go(i); this.restart(); });
      this.dotsWrap.appendChild(b);
      return b;
    });
  }

  bind() {
    const el = this.el;
    this.prevBtn?.addEventListener('click', (e) => { e.preventDefault(); this.prev(); this.restart(); });
    this.nextBtn?.addEventListener('click', (e) => { e.preventDefault(); this.next(); this.restart(); });
    el.addEventListener('mouseenter', () => { this.paused = true; });
    el.addEventListener('mouseleave', () => { this.paused = false; });
    el.addEventListener('focusin', () => { this.paused = true; });
    el.addEventListener('focusout', () => { this.paused = false; });
    el.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowRight') { this.next(); this.restart(); }
      if (e.key === 'ArrowLeft') { this.prev(); this.restart(); }
    });
    // drag / swipe
    el.addEventListener('pointerdown', (e) => {
      if (e.button !== 0 || this.slides.length < 2) return;
      if (e.target.closest('button, a, input')) { this.drag = { x: e.clientX, dx: 0, id: e.pointerId, soft: true }; return; }
      this.drag = { x: e.clientX, dx: 0, id: e.pointerId, soft: false };
      el.classList.add('is-dragging');
    });
    el.addEventListener('pointermove', (e) => {
      if (!this.drag || this.drag.id !== e.pointerId) return;
      this.drag.dx = e.clientX - this.drag.x;
      if (this.drag.soft && Math.abs(this.drag.dx) > 8) { this.drag.soft = false; el.classList.add('is-dragging'); try { el.setPointerCapture(e.pointerId); } catch { /* noop */ } }
      if (!this.drag.soft) {
        const w = el.clientWidth || 1;
        this.track.style.transform = `translateX(${-(this.index * 100) + (this.drag.dx / w) * 100}%)`;
      }
    });
    const end = (e) => {
      if (!this.drag || (e && this.drag.id !== e.pointerId)) return;
      const { dx, soft } = this.drag;
      this.drag = null;
      el.classList.remove('is-dragging');
      if (soft) return;
      const w = el.clientWidth || 1;
      if (Math.abs(dx) > w * 0.15 || Math.abs(dx) > 60) { dx < 0 ? this.next() : this.prev(); }
      else this.go(this.index);
      if (Math.abs(dx) > 8) { el.addEventListener('click', (ev) => { ev.preventDefault(); ev.stopPropagation(); }, { capture: true, once: true }); }
      this.restart();
    };
    el.addEventListener('pointerup', end);
    el.addEventListener('pointercancel', end);
    el.addEventListener('lostpointercapture', () => end());
    document.addEventListener('visibilitychange', () => { document.hidden ? this.pause() : this.play(); });
  }

  go(i, animate = true) {
    const n = this.slides.length;
    this.index = ((i % n) + n) % n;
    if (!animate || reduced) this.track.style.transition = 'none';
    this.track.style.transform = `translateX(${-this.index * 100}%)`;
    if (!animate || reduced) { void this.track.offsetWidth; this.track.style.transition = ''; }
    this.dots?.forEach((d, k) => { d.classList.toggle('is-active', k === this.index); d.setAttribute('aria-current', k === this.index ? 'true' : 'false'); });
    this.slides.forEach((s, k) => { s.classList.toggle('is-active', k === this.index); s.setAttribute('aria-hidden', k === this.index ? 'false' : 'true'); });
    this.updateHeight();
    this.el.dispatchEvent(new CustomEvent('carousel:change', { bubbles: true, detail: { index: this.index } }));
  }
  next() { this.go(this.index + 1); }
  prev() { this.go(this.index - 1); }

  updateHeight() {
    if (this.el.dataset.height === 'fixed') return;
    const h = this.slides[this.index]?.offsetHeight;
    if (h) this.track.style.height = `${h}px`;
  }

  play() {
    this.pause();
    if (reduced || this.interval <= 0 || this.slides.length < 2) return;
    this.timer = window.setInterval(() => { if (!this.paused && !document.hidden && !this.drag) this.next(); }, this.interval);
  }
  pause() { if (this.timer) { clearInterval(this.timer); this.timer = 0; } }
  restart() { if (this.timer) this.play(); }
  destroy() { this.pause(); this.ro?.disconnect(); delete this.el.carousel; }
}

export function initCarousels(root = document) {
  return Array.from(root.querySelectorAll('[data-carousel]'))
    .filter((el) => !el.carousel)
    .map((el) => new Carousel(el));
}

// self-init when loaded directly (hud.js also calls initCarousels; guarded by el.carousel)
if (!window.__ehCarouselAuto) {
  window.__ehCarouselAuto = true;
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => initCarousels(), { once: true });
  else queueMicrotask(() => initCarousels());
}
