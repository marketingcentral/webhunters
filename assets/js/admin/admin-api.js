/**
 * Tela ADMIN · API (/admin/api) — dois confortos, zero funcionalidade.
 *
 * Esta é a regra do arquivo: NADA aqui é necessário para a tela funcionar. O valor da chave
 * está num <input readonly> selecionável, "marcar todos" só poupa cliques, e revogar é um
 * formulário POST comum. Sem JavaScript a tela inteira continua de pé — é assim que ela é
 * conferida no `curl`, e é assim que ela sobrevive a um bloqueio de script no navegador do
 * operador.
 *
 * Por isso o botão "Marcar todos" nasce com `hidden` no HTML e é REVELADO aqui: um botão
 * inerte na tela é pior que botão nenhum.
 */

/**
 * Copia o conteúdo de um campo.
 *
 * Duas tentativas de propósito. `navigator.clipboard` só existe em contexto seguro
 * (https ou localhost) — e o painel roda em http://localhost:8080 no desenvolvimento e em
 * https na produção, mas pode ser aberto por IP na rede local, onde a API não existe. Nesse
 * caso cai para selecionar + execCommand, que é obsoleto e continua sendo o único que
 * funciona lá. Se as duas falharem, o texto do botão diz para copiar à mão — nunca fingimos
 * que copiou.
 */
async function copiar(campo, botao) {
  const rotulo = botao.querySelector('span');
  const original = rotulo ? rotulo.textContent : '';

  const avisar = (texto, ok) => {
    if (!rotulo) return;
    rotulo.textContent = texto;
    botao.classList.toggle('is-ok', ok === true);
    botao.classList.toggle('is-falhou', ok === false);
    setTimeout(() => {
      rotulo.textContent = original;
      botao.classList.remove('is-ok', 'is-falhou');
    }, 2400);
  };

  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(campo.value);
      avisar('Copiada', true);
      return;
    }
  } catch (e) {
    /* cai para o caminho antigo abaixo */
  }

  try {
    campo.focus();
    campo.select();
    campo.setSelectionRange(0, campo.value.length);
    if (document.execCommand('copy')) {
      avisar('Copiada', true);
      return;
    }
  } catch (e) {
    /* nada a fazer: a mensagem abaixo é honesta */
  }

  // O campo fica selecionado, então "Ctrl+C" é literalmente o próximo passo.
  campo.select();
  avisar('Copie com Ctrl+C', false);
}

function ligar() {
  document.querySelectorAll('[data-api-copiar]').forEach((botao) => {
    const campo = document.querySelector(botao.getAttribute('data-api-copiar'));
    if (!campo) return;
    botao.addEventListener('click', () => copiar(campo, botao));
  });

  const todos = document.querySelector('[data-api-todos]');
  const caixas = Array.from(document.querySelectorAll('[data-api-escopo]'));
  if (todos && caixas.length) {
    todos.hidden = false;
    todos.addEventListener('click', () => {
      // Alterna: se já está tudo marcado, o mesmo botão desmarca. Um botão que só marca
      // obriga a desfazer 22 cliques na mão quando a pessoa se arrepende.
      const marcarTudo = caixas.some((c) => !c.checked);
      caixas.forEach((c) => { c.checked = marcarTudo; });
      const rotulo = todos.querySelector('span');
      if (rotulo) rotulo.textContent = marcarTudo ? 'Desmarcar todos' : 'Marcar todos';
    });
  }

  // A chave recém-criada já nasce selecionada: quem chegou aqui veio para copiá-la.
  const nova = document.querySelector('[data-api-copiar-alvo]');
  if (nova) {
    nova.focus();
    nova.select();
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', ligar);
} else {
  ligar();
}
