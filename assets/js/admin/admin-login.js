/**
 * Tela ADMIN · LOGIN. Só o olho da senha — o resto do formulário é HTML puro, para
 * que a porta do painel continue funcionando mesmo se este arquivo não carregar.
 */

const botao = document.querySelector('[data-adm-eye]');
const campo = botao && document.getElementById(botao.dataset.admEye);

botao?.addEventListener('click', () => {
  if (!campo) return;
  const mostrando = campo.type === 'text';
  campo.type = mostrando ? 'password' : 'text';
  botao.setAttribute('aria-pressed', mostrando ? 'false' : 'true');
  botao.setAttribute('aria-label', mostrando ? 'Mostrar senha' : 'Ocultar senha');
  const icone = botao.querySelector('.ms');
  if (icone) icone.textContent = mostrando ? 'visibility_off' : 'visibility';
  campo.focus();
});
