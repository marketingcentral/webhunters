/**
 * Envio de imagem dos campos de caminho do painel (app/Views/components/admin-upload.php).
 *
 * Carregado uma vez por página, pelo próprio componente, só nas telas que têm campo de imagem.
 *
 * TRÊS DECISÕES
 * -------------
 * 1. XMLHttpRequest, e não fetch. É a única API do navegador que reporta PROGRESSO DE UPLOAD
 *    (xhr.upload.onprogress). Numa conexão ruim, subir 2 MB sem barra é indistinguível de
 *    "travou" — e o operador clica de novo.
 * 2. O envio é SEPARADO do formulário. Nada é gravado no banco aqui: o arquivo sobe, o
 *    servidor devolve o caminho, o caminho cai no <input> e o operador ainda precisa clicar
 *    em salvar. Assim o formulário inteiro (que é grande) nunca é perdido por causa de uma foto.
 * 3. A validação aqui é de CONVENIÊNCIA — corta o envio óbvio antes de gastar a rede. Quem
 *    decide é o servidor: qualquer coisa checada neste arquivo é checada de novo lá, porque
 *    isto roda no navegador do operador e pode ser desligado.
 */

const doc = document;

/** Token de sessão: o mesmo <meta> que o layout do painel escreve. */
const CSRF = doc.querySelector('meta[name="csrf-token"]')?.content ?? '';

/** Espelha o teto do servidor (UploadController::MAX_BYTES) para recusar antes de subir. */
const MAX_BYTES = 2 * 1024 * 1024;
const TIPOS_OK = ['image/svg+xml', 'image/png', 'image/webp', 'image/jpeg'];

function humano(bytes) {
  return bytes >= 1048576
    ? `${(bytes / 1048576).toFixed(1).replace('.', ',')} MB`
    : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

/** Escreve o estado abaixo do botão. tom: '' | 'ok' | 'erro' | 'andando'. */
function estado(campo, texto, tom = '') {
  const alvo = campo.querySelector('[data-adm-up-estado]');
  if (!alvo) return;
  alvo.textContent = texto;
  alvo.className = 'adm-up__estado' + (tom ? ` is-${tom}` : '');
}

function progresso(campo, pct) {
  const barra = campo.querySelector('[data-adm-up-barra]');
  if (!barra) return;
  barra.hidden = pct === null;
  if (pct !== null) barra.style.setProperty('--v', `${pct}%`);
}

/**
 * Redesenha a miniatura. `src` vazio = volta para a moldura vazia.
 * A <img> é criada/removida em vez de ficar com src=""; um src vazio no Chrome
 * recarrega a própria página como imagem e polui o log do servidor.
 */
function preview(campo, src) {
  const caixa = campo.querySelector('[data-adm-up-prev]');
  if (!caixa) return;
  let img = caixa.querySelector('.adm-up__img');
  if (!src) {
    img?.remove();
    return;
  }
  if (!img) {
    img = doc.createElement('img');
    img.className = 'adm-up__img';
    img.alt = '';
    img.loading = 'lazy';
    img.decoding = 'async';
    caixa.prepend(img);
  }
  // Caminho quebrado tem de aparecer como moldura vazia, não como ícone de imagem partida.
  img.onerror = () => img.remove();
  img.src = src;
}

/** Prévia do caminho DIGITADO à mão (sem upload nenhum). */
function previewDoCaminho(campo, caminho) {
  const base = campo.dataset.media || '';
  const limpo = String(caminho || '').trim().replace(/^\/+/, '');
  preview(campo, limpo ? base + encodeURI(limpo) : '');
}

function enviar(campo, arquivo) {
  const path = campo.querySelector('[data-adm-up-path]');
  const file = campo.querySelector('[data-adm-up-file]');
  const btn = campo.querySelector('.adm-up__btn');

  if (!arquivo) return;

  if (!TIPOS_OK.includes(arquivo.type)) {
    estado(campo, 'Só SVG, PNG, WEBP ou JPG.', 'erro');
    return;
  }
  if (arquivo.size > MAX_BYTES) {
    estado(campo, `Imagem de ${humano(arquivo.size)} — o limite é ${humano(MAX_BYTES)}.`, 'erro');
    return;
  }

  /* Prévia local imediata (blob:), antes de o arquivo sair da máquina. O operador confirma
     que escolheu a imagem certa enquanto ela ainda está subindo. A CSP do projeto já libera
     blob: em img-src exatamente para isto. */
  const local = URL.createObjectURL(arquivo);
  preview(campo, local);

  const corpo = new FormData();
  corpo.append('_csrf', CSRF);
  corpo.append('entidade', campo.dataset.entidade || '');
  corpo.append('arquivo', arquivo);

  const xhr = new XMLHttpRequest();
  xhr.open('POST', campo.dataset.url || '', true);
  // O endpoint checa este cabeçalho? Não — mas ele impede que uma resposta HTML de erro
  // (proxy, tela de login) seja confundida com sucesso: o parse abaixo exige JSON.
  xhr.setRequestHeader('Accept', 'application/json');
  xhr.setRequestHeader('X-CSRF-Token', CSRF);

  campo.classList.add('is-enviando');
  if (btn) btn.setAttribute('aria-disabled', 'true');
  estado(campo, 'Enviando…', 'andando');
  progresso(campo, 0);

  xhr.upload.addEventListener('progress', (ev) => {
    if (!ev.lengthComputable) return;
    const pct = Math.round((ev.loaded / ev.total) * 100);
    progresso(campo, pct);
    // 100% do upload não é 100% do trabalho: o servidor ainda vai validar e gravar.
    estado(campo, pct < 100 ? `Enviando… ${pct}%` : 'Verificando no servidor…', 'andando');
  });

  const terminar = () => {
    URL.revokeObjectURL(local);
    campo.classList.remove('is-enviando');
    btn?.removeAttribute('aria-disabled');
    progresso(campo, null);
    if (file) file.value = '';   // permite reenviar o MESMO arquivo depois de um erro
  };

  xhr.addEventListener('load', () => {
    terminar();
    let resp = null;
    try { resp = JSON.parse(xhr.responseText); } catch { /* não é JSON: cai no else abaixo */ }

    if (xhr.status === 200 && resp?.ok) {
      if (path) {
        path.value = resp.caminho;
        // input + change: o campo passou a valer outra coisa; qualquer validação da tela
        // (ou o aviso de "há alterações não salvas") precisa enxergar isso.
        path.dispatchEvent(new Event('input', { bubbles: true }));
        path.dispatchEvent(new Event('change', { bubbles: true }));
      }
      preview(campo, resp.url);
      estado(campo, `Enviada — ${resp.largura}×${resp.altura}, ${resp.tamanho}. Clique em salvar para gravar.`, 'ok');
      return;
    }

    previewDoCaminho(campo, path?.value);
    estado(campo, resp?.erro || `Falha no envio (HTTP ${xhr.status}).`, 'erro');
  });

  xhr.addEventListener('error', () => {
    terminar();
    previewDoCaminho(campo, path?.value);
    estado(campo, 'Sem resposta do servidor. Verifique a conexão e tente de novo.', 'erro');
  });

  xhr.addEventListener('abort', () => {
    terminar();
    previewDoCaminho(campo, path?.value);
    estado(campo, 'Envio cancelado.', 'erro');
  });

  xhr.send(corpo);
}

/* ---- ligação -------------------------------------------------------------------------
   Delegado no documento: as telas do painel trocam o formulário por estado de query string
   (?nova, ?editar=), então o campo pode nascer depois desta linha rodar. */
doc.addEventListener('change', (ev) => {
  const file = ev.target.closest?.('[data-adm-up-file]');
  if (!file) return;
  const campo = file.closest('[data-adm-up]');
  if (campo) enviar(campo, file.files?.[0]);
});

/* Caminho digitado à mão: a prévia acompanha. debounce curto para não pedir uma imagem
   ao servidor a cada tecla. */
let timer = 0;
doc.addEventListener('input', (ev) => {
  const path = ev.target.closest?.('[data-adm-up-path]');
  if (!path) return;
  const campo = path.closest('[data-adm-up]');
  if (!campo || campo.classList.contains('is-enviando')) return;
  clearTimeout(timer);
  timer = setTimeout(() => previewDoCaminho(campo, path.value), 400);
});

/* Arrastar e soltar sobre a moldura. Atalho puro: passa pelo mesmo enviar(). */
for (const evt of ['dragenter', 'dragover']) {
  doc.addEventListener(evt, (ev) => {
    const campo = ev.target.closest?.('[data-adm-up]');
    if (!campo) return;
    ev.preventDefault();
    campo.classList.add('is-sobre');
  });
}
doc.addEventListener('dragleave', (ev) => ev.target.closest?.('[data-adm-up]')?.classList.remove('is-sobre'));
doc.addEventListener('drop', (ev) => {
  const campo = ev.target.closest?.('[data-adm-up]');
  if (!campo) return;
  ev.preventDefault();
  campo.classList.remove('is-sobre');
  enviar(campo, ev.dataTransfer?.files?.[0]);
});
