/**
 * ADMIN · JOGADORES (/admin/jogadores) — melhoria progressiva, nada essencial.
 *
 * Carregado pelo slug da página em app/Views/layouts/admin.php, com nonce (a CSP do projeto
 * é `script-src 'self' 'nonce-…'`, então handler inline no HTML NÃO roda — é por isso que a
 * confirmação mora aqui e não num onsubmit).
 *
 * O que faz: pede confirmação antes de enviar um formulário destrutivo, e desliga o campo
 * de prazo quando o banimento é permanente.
 *
 * O que NÃO faz: validar. Toda regra é conferida no servidor
 * (App\Controllers\Admin\PlayersController). Com este arquivo bloqueado, a tela continua
 * inteira: banir ainda exige motivo escrito e excluir ainda exige digitar o nome da conta —
 * confirmações que o servidor cobra, e não o navegador.
 */

const doc = document;

/* Confirmação das ações que não têm volta. */
for (const form of doc.querySelectorAll('form[data-confirmar]')) {
  form.addEventListener('submit', (ev) => {
    if (!window.confirm(form.dataset.confirmar || 'Confirmar esta ação?')) {
      ev.preventDefault();
    }
  });
}

/* Banimento permanente não tem prazo: em vez de deixar um campo de data pedindo valor que
   será ignorado, o campo apaga e diz por quê. O servidor ignora o valor de qualquer forma. */
const tipo = doc.getElementById('pj-tipo');
const expira = doc.getElementById('pj-expira');

/* `tipo.disabled` significa que o operador não tem autoridade sobre esta conta e o servidor
   já travou o formulário inteiro — reabilitar o campo de prazo aqui seria o JavaScript
   desfazendo uma decisão de permissão tomada no PHP. */
if (tipo && expira && !tipo.disabled) {
  const campo = expira.closest('.adm-field');
  const sincronizar = () => {
    const perma = tipo.value === 'perma';
    expira.disabled = perma;
    campo?.classList.toggle('is-off', perma);
  };
  tipo.addEventListener('change', sincronizar);
  sincronizar();
}
