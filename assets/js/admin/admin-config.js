/**
 * Tela ADMIN · CONFIGURAÇÕES DO JOGO — melhorias sobre HTML que já funciona sozinho.
 *
 * Nada aqui é a validação: quem decide o que entra em `admin_settings` é o servidor
 * (App\Repo\AdminSettingsRepo::validar). Se este arquivo não carregar, a tela continua
 * salvando, criando e excluindo — só fica menos falante. Por isso não existe `confirm()`
 * de exclusão neste arquivo: a confirmação é uma etapa do servidor, e uma confirmação que
 * some com o JavaScript desligado não confirma nada.
 *
 * O que ele faz:
 *   1. o texto ao lado do interruptor acompanha o estado (a cor sozinha não informa);
 *   2. o botão Salvar avisa, apagando, quando o grupo não tem alteração pendente;
 *   3. o editor de JSON marca sintaxe quebrada ENQUANTO se digita, como aviso.
 */

/* ------------------------------------------------------------------ interruptores */

for (const entrada of document.querySelectorAll('.cfg-switch .adm-switch__in')) {
  const texto = entrada.closest('.cfg-switch')?.querySelector('.cfg-switch__txt');
  if (!texto) continue;

  // Os dois rótulos são lidos do HTML que o servidor já desenhou, para o vocabulário
  // ("No ar" / "Em manutenção") viver num lugar só: app/Data/admin-config.php.
  const ligado = texto.classList.contains('cfg-switch__txt--on');
  const rotulos = { on: ligado ? texto.textContent.trim() : '', off: ligado ? '' : texto.textContent.trim() };

  entrada.addEventListener('change', () => {
    const on = entrada.checked;
    texto.classList.toggle('cfg-switch__txt--on', on);
    texto.classList.toggle('cfg-switch__txt--off', !on);
    // Só troca a palavra quando ela é conhecida: inventar "Ligado" por cima de um rótulo
    // específico seria pior do que deixar o texto anterior e mudar apenas a cor.
    const novo = on ? rotulos.on : rotulos.off;
    if (novo) texto.textContent = novo;
  });
}

/* ------------------------------------------------------- alterações não salvas */

for (const form of document.querySelectorAll('form[data-cfg-form]')) {
  const salvar = form.querySelector('[data-cfg-salvar]');
  if (!salvar) continue;

  const inicial = instantaneo(form);
  const conferir = () => salvar.classList.toggle('is-limpo', instantaneo(form) === inicial);

  form.addEventListener('input', conferir);
  form.addEventListener('change', conferir);
  conferir();
}

/** Assinatura dos valores editáveis do formulário — barata e suficiente para comparar. */
function instantaneo(form) {
  const partes = [];
  for (const campo of form.querySelectorAll('input[name^="campo["], textarea[name^="campo["]')) {
    partes.push(campo.name + '=' + (campo.type === 'checkbox' ? campo.checked : campo.value));
  }
  return partes.join('');
}

/* ------------------------------------------------------------------- editor JSON */

for (const area of document.querySelectorAll('[data-cfg-json]')) {
  const aviso = document.createElement('p');
  aviso.className = 'cfg-json-aviso';
  aviso.hidden = true;
  aviso.innerHTML = '<span class="ms" aria-hidden="true">warning</span><span></span>';
  area.insertAdjacentElement('afterend', aviso);

  const conferir = () => {
    const txt = area.value.trim();
    let erro = '';
    if (txt !== '') {
      try {
        const dado = JSON.parse(txt);
        if (dado === null || typeof dado !== 'object') erro = 'A raiz precisa ser um objeto ou uma lista';
      } catch (e) {
        erro = String(e.message || 'JSON inválido');
      }
    }
    area.classList.toggle('is-quebrado', erro !== '');
    aviso.hidden = erro === '';
    aviso.lastElementChild.textContent = erro;
  };

  area.addEventListener('input', conferir);
  conferir();
}
