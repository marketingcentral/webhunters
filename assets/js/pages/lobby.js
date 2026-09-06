/**
 * EXTRACT HUNTERS — tela LOBBY.
 *
 * Toda a interação pesada já vem do hud.js (steppers, tooltips, boot, transição)
 * e do carousel.js (notícias). Aqui só o que é específico do lobby:
 *  - stagger das mensagens do chat (--m) e rolagem para a última;
 *  - foco no campo de mensagem depois de enviar (flash 'ok');
 *  - relógio das mensagens novas (marca a última como recente).
 */

const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

function chat() {
  const log = $('#lobby-chat');
  if (!log) return;
  const msgs = $$('.chat__msg', log);
  msgs.forEach((m, i) => m.style.setProperty('--m', String(i)));
  const toBottom = () => { log.scrollTop = log.scrollHeight; };
  toBottom();
  requestAnimationFrame(toBottom);

  const input = $('.chat__input');
  const sent = document.querySelector('.flash--ok');
  if (input && sent) input.focus({ preventScroll: true });
}

/** Mantém o rótulo do stepper acessível (aria-live discreto) nas configurações. */
function settings() {
  $$('.set-row').forEach((row) => {
    const val = $('.stepper__val', row);
    if (val) val.setAttribute('aria-live', 'polite');
  });
}

function init() {
  chat();
  settings();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
  init();
}
