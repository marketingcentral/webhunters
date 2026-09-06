/**
 * Tela FACÇÕES — confirmação obrigatória antes de assinar/romper contrato (PRD v3 §7.2).
 *
 * O formulário nasce com confirm=0. Este módulo intercepta o submit, abre o <dialog> com o
 * texto que o servidor já montou (nomes das facções certos) e só então grava confirm=1 e
 * reenvia. Sem JS o servidor recusa com 422: a regra vive lá, aqui é só a porta de entrada.
 *
 * O reenvio usa requestSubmit() para o spa.js (listener em window) tratar o POST sem
 * recarga, como qualquer outro formulário do site.
 */

const modal = document.querySelector('[data-fac-modal]');

function abrir(form) {
  if (!modal || typeof modal.showModal !== 'function') return false;
  modal.querySelector('[data-modal-title]').textContent = form.dataset.confirmTitle || 'CONFIRMAR?';
  modal.querySelector('[data-modal-body]').textContent  = form.dataset.confirmBody || '';
  const ok = modal.querySelector('[data-modal-ok]');
  const cancel = modal.querySelector('[data-modal-cancel]');
  ok.textContent = form.dataset.confirmOk || 'CONFIRMAR';
  cancel.textContent = form.dataset.confirmCancel || 'CANCELAR';

  const fechar = () => { modal.close(); ok.onclick = null; cancel.onclick = null; };
  cancel.onclick = fechar;
  ok.onclick = () => {
    const campo = form.querySelector('[data-confirm-field]');
    if (campo) campo.value = '1';
    fechar();
    // Marca para o interceptador deixar passar desta vez.
    form.dataset.confirmed = '1';
    if (form.requestSubmit) form.requestSubmit(); else form.submit();
  };
  modal.showModal();
  return true;
}

function init() {
  document.querySelectorAll('form[data-needs-confirm]').forEach((form) => {
    form.addEventListener('submit', (ev) => {
      if (form.dataset.confirmed === '1') { form.dataset.confirmed = ''; return; }   // já confirmado
      if (abrir(form)) ev.preventDefault();
      // sem <dialog> (navegador antigo): deixa ir com confirm=0 e o servidor responde 422
    });
  });

  // BLOQUEADO hh:mm:ss — contagem viva até o fim do bloqueio da facção
  document.querySelectorAll('[data-countdown-to]').forEach((el) => {
    const alvo = Date.parse(String(el.dataset.countdownTo).replace(' ', 'T'));
    if (Number.isNaN(alvo)) return;
    const rotulo = el.textContent.split(' ')[0] || 'BLOQUEADO';
    const tick = () => {
      const s = Math.max(0, Math.floor((alvo - Date.now()) / 1000));
      const h = String(Math.floor(s / 3600)).padStart(2, '0');
      const m = String(Math.floor((s % 3600) / 60)).padStart(2, '0');
      const ss = String(s % 60).padStart(2, '0');
      el.textContent = `${rotulo} ${h}:${m}:${ss}`;
      if (s === 0) clearInterval(t);
    };
    const t = setInterval(tick, 1000);   // varrido pela época do spa.js ao sair da tela
    tick();
  });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
else init();
