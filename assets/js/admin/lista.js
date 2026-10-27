/**
 * EXTRACT HUNTERS · PAINEL — filtros e busca sem recarregar a página.
 *
 * Carregado em TODA tela do painel (layouts/admin.php). Em telas que não têm lista ele sai
 * em três linhas: sem o par de marcadores no DOM, não há nada para fazer.
 *
 * ---------------------------------------------------------------------------------------
 * MELHORIA PROGRESSIVA, DE VERDADE
 *
 * A tela do servidor não sabe que este arquivo existe. O filtro continua sendo um
 * <form method="get">, a paginação continua sendo <a href>, a ordenação continua sendo link
 * no cabeçalho da coluna. Com o JavaScript desligado (ou quebrado, ou ainda baixando) tudo
 * funciona exatamente como funcionava. O que este módulo faz é INTERCEPTAR esses mesmos
 * caminhos e pedir ao servidor só o pedaço que muda — ver app/Core/Fragmento.php.
 * ---------------------------------------------------------------------------------------
 *
 * AS SEIS DECISÕES QUE ESTE ARQUIVO EXISTE PARA SUSTENTAR:
 *
 * 1. BUSCA COM ATRASO. Digitar "granada" são sete teclas; sem atraso são sete consultas ao
 *    MySQL e seis respostas jogadas fora. 320 ms é o intervalo em que uma digitação normal
 *    não tem pausa — quem para de digitar, parou de pensar, e é aí que a lista vale.
 *
 * 2. CANCELAR A ANTERIOR. Sem AbortController a resposta de "gran" pode chegar DEPOIS da de
 *    "granada" e sobrescrever a lista certa pela errada. Cancelar é mais forte do que
 *    ignorar: além de não pintar, libera a conexão para a requisição que importa.
 *
 * 3. O FORMULÁRIO NÃO É SUBSTITUÍDO. Ele leva data-adm-preservar e é TRANSPLANTADO para
 *    dentro do HTML novo (mesmo truque que o spa.js usa com o canvas do atmosphere). É o que
 *    guarda o cursor, a posição do cursor, o texto ainda não enviado e o foco do operador.
 *    Trocar o <input> embaixo de quem digita é a maneira mais rápida de estragar a tela.
 *
 * 4. A LISTA VELHA FICA NA TELA ATÉ A NOVA CHEGAR. Nada de esvaziar o <tbody> e mostrar um
 *    esqueleto: a altura não muda, a página não pula e o operador continua lendo. O que
 *    indica carregamento é uma barra fina no alto e o <tbody> esmaecido.
 *
 * 5. A URL É O ESTADO. Cada atualização escreve a mesma URL que o formulário escreveria —
 *    recarregar, favoritar ou mandar o link para um colega reproduz a lista. O `voltar` do
 *    navegador refaz o caminho; digitação seguida COALESCE numa entrada só, senão voltar
 *    teria de ser apertado uma vez por letra digitada.
 *
 * 6. ANUNCIAR E DEVOLVER O FOCO. Quem usa leitor de tela não vê a tabela mudar: uma região
 *    aria-live diz quantos resultados sobraram. E o foco, que morreria junto com o link de
 *    paginação clicado, é reencontrado no HTML novo.
 */

const CABECALHO = 'X-EH-Lista';
const ATRASO_BUSCA = 320;      // ms de teclado parado antes de consultar
const LIMITE = 15000;          // ms até desistir e deixar o navegador navegar
const ATRASO_BARRA = 140;      // ms antes de mostrar a barra (resposta rápida não pisca)

/* Nome do parâmetro de página em cada tela — `p`, `pagina`, `pag`, `jp` conforme o autor.
   Todos são zerados ao mudar um filtro: a página 7 do recorte antigo quase nunca existe no
   recorte novo, e cair numa lista vazia depois de filtrar parece defeito. */
const PARAMS_PAGINA = ['p', 'pagina', 'pag', 'page', 'jp'];

const doc = document;

/* ====================================================================== marcadores */

/**
 * O par de comentários que delimita a lista, e o pai comum dos dois.
 * Sem par completo (ou com as bordas em pais diferentes) devolve null e o módulo não liga.
 */
function acharRegiao() {
  const raiz = doc.getElementById('main');
  if (!raiz) return null;

  const passo = doc.createTreeWalker(raiz, NodeFilter.SHOW_COMMENT);
  let abre = null;
  let fecha = null;
  let no;
  while ((no = passo.nextNode())) {
    const txt = (no.nodeValue || '').trim();
    if (txt === 'eh:lista' && !abre) abre = no;
    else if (txt === '/eh:lista' && abre && !fecha) fecha = no;
  }
  if (!abre || !fecha || abre.parentNode !== fecha.parentNode) return null;
  return { abre, fecha, pai: abre.parentNode };
}

/** Os nós que estão HOJE entre as bordas. Recalculado a cada uso: o DOM muda. */
function nosDaRegiao(reg) {
  const fora = [];
  for (let n = reg.abre.nextSibling; n && n !== reg.fecha; n = n.nextSibling) fora.push(n);
  return fora;
}

/** Os elementos (só eles) da região — é neles que a classe de "carregando" entra. */
function elementosDaRegiao(reg) {
  return nosDaRegiao(reg).filter((n) => n.nodeType === 1);
}

/** Procura dentro da região, sem depender de um elemento embrulhando tudo. */
function buscarNaRegiao(reg, seletor) {
  for (const el of elementosDaRegiao(reg)) {
    if (el.matches(seletor)) return el;
    const achado = el.querySelector(seletor);
    if (achado) return achado;
  }
  return null;
}

/* ================================================================== peças da tela */

/** Barra de progresso indeterminada. Fica FIXA no alto: não ocupa espaço no fluxo. */
let barra = null;
function obterBarra() {
  if (barra) return barra;
  barra = doc.createElement('div');
  barra.className = 'adm-lista-barra';
  barra.setAttribute('aria-hidden', 'true');   // o anúncio de verdade é o aria-live
  barra.innerHTML = '<i></i>';
  doc.body.appendChild(barra);
  return barra;
}

/** Região só para leitor de tela. role=status = polido: nunca interrompe quem está lendo. */
let vozinha = null;
function anunciar(texto) {
  if (!vozinha) {
    vozinha = doc.createElement('p');
    vozinha.className = 'sr-only';
    vozinha.setAttribute('role', 'status');
    vozinha.setAttribute('aria-live', 'polite');
    doc.body.appendChild(vozinha);
  }
  // Repetir o MESMO texto não dispara o anúncio de novo; o espaço force a diferença.
  vozinha.textContent = vozinha.textContent === texto ? texto + ' ' : texto;
}

/* ====================================================================== a instalação */

const regiao = acharRegiao();
if (regiao) instalar(regiao);

function instalar(reg) {
  /* O formulário de filtros. É o elemento preservado: nunca é substituído, só transplantado
     para dentro do HTML novo. Uma tela pode não ter nenhum (lista sem filtro) e ainda assim
     ganhar paginação sem recarga. */
  const formulario = () => buscarNaRegiao(reg, 'form[data-adm-preservar]');

  /**
   * As chaves de query que ESTA lista entende: os campos do próprio formulário (inclusive os
   * <input type=hidden> que guardam ordenação), mais os nomes de página, mais o que o autor
   * da tela declarar em data-adm-chaves.
   *
   * É essa lista que separa "link que muda a lista" de "link que muda de tela". O botão
   * "Abrir ficha" de /admin/jogadores aponta para o MESMO caminho com `?id=` — e `id` não é
   * campo do filtro, então ele não é interceptado e navega de verdade, como deve.
   */
  function chavesConhecidas() {
    const chaves = new Set(PARAMS_PAGINA);
    const form = formulario();
    if (form) {
      for (const campo of form.elements) {
        if (campo.name) chaves.add(campo.name);
      }
      for (const extra of (form.dataset.admChaves || '').split(/\s+/)) {
        if (extra) chaves.add(extra);
      }
    }
    return chaves;
  }

  /**
   * A URL que este formulário produziria se fosse enviado agora — a MESMA que o navegador
   * montaria sem JavaScript nenhum. A paginação não é copiada de propósito: mudar o filtro
   * recomeça na primeira página (ver PARAMS_PAGINA no topo).
   */
  function urlDoFormulario() {
    const form = formulario();
    const alvo = new URL(form ? (form.getAttribute('action') || location.href) : location.href, location.href);

    const dados = new URLSearchParams();
    if (form) {
      for (const [chave, valor] of new FormData(form)) {
        // Campo vazio não vira `?q=&papel=`: a URL compartilhada tem de ser legível.
        if (String(valor) !== '') dados.append(chave, String(valor));
      }
    }
    alvo.search = dados.toString();
    return alvo.href;
  }

  /* -------------------------------------------------------------- a requisição */

  let emVoo = null;          // AbortController do pedido em curso
  let timerBusca = 0;
  let timerBarra = 0;
  let ultimoPorDigitacao = false;   // a entrada de histórico atual nasceu de digitação?

  function marcarOcupado(ligado) {
    for (const el of elementosDaRegiao(reg)) {
      el.classList.toggle('is-lista-ocupada', ligado);
      if (ligado) el.setAttribute('aria-busy', 'true');
      else el.removeAttribute('aria-busy');
    }
  }

  /**
   * Guarda quem está com o foco, para devolvê-lo depois da troca.
   * Dentro do formulário preservado não há o que guardar: aquele nó continua sendo o mesmo.
   */
  function guardarFoco() {
    const ativo = doc.activeElement;
    if (!ativo || ativo === doc.body) return null;
    const form = formulario();
    if (form && form.contains(ativo)) return null;
    if (!elementosDaRegiao(reg).some((el) => el === ativo || el.contains(ativo))) return null;
    return {
      href: ativo.getAttribute && ativo.getAttribute('href'),
      // O <nav> de onde o elemento saiu: é dele que vem o plano B (a página atual).
      nav: ativo.closest ? ativo.closest('nav[class]') : null,
      classe: ativo.className || '',
    };
  }

  /**
   * Devolve o foco ao equivalente no HTML novo.
   *
   * O caso difícil é a paginação: ao ir para a página 2, o link "2" que foi clicado vira um
   * <span> sem href — o elemento de origem literalmente não existe mais. Por isso a cadeia:
   * 1) o mesmo href;  2) a página atual dentro do mesmo tipo de nav;  3) o começo da região.
   */
  function devolverFoco(guardado) {
    if (!guardado) return;

    if (guardado.href) {
      const igual = buscarNaRegiao(reg, 'a[href="' + CSS.escape(guardado.href) + '"]');
      if (igual) { igual.focus(); return; }
    }
    if (guardado.nav && guardado.nav.className) {
      const navCls = guardado.nav.className.trim().split(/\s+/)[0];
      const atual = navCls ? buscarNaRegiao(reg, '.' + CSS.escape(navCls) + ' [aria-current]') : null;
      if (atual) {
        // O marcador da página atual costuma ser <span>: sem tabindex ele não recebe foco.
        if (!atual.hasAttribute('tabindex')) atual.setAttribute('tabindex', '-1');
        atual.focus();
        return;
      }
    }
    const primeiro = elementosDaRegiao(reg)[0];
    if (!primeiro) return;
    if (!primeiro.hasAttribute('tabindex')) {
      primeiro.setAttribute('tabindex', '-1');
      // -1 só serve para receber este foco; deixá-lo cria uma parada morta no Tab.
      primeiro.addEventListener('blur', () => primeiro.removeAttribute('tabindex'), { once: true });
    }
    primeiro.focus({ preventScroll: true });
  }

  /** Troca os nós da região, transplantando o que está marcado para preservar. */
  function trocar(html) {
    const molde = doc.createElement('template');
    molde.innerHTML = html;

    /* Mover um nó para dentro de um DocumentFragment o TIRA do documento, e todo elemento
       que sai do documento perde o foco — inclusive o campo em que o operador está
       digitando. O navegador não devolve esse foco quando o nó volta, então ele é anotado
       aqui, com a posição do cursor, e reposto depois da inserção. */
    const ativo = doc.activeElement;
    const cursor = ativo && typeof ativo.selectionStart === 'number'
      ? [ativo.selectionStart, ativo.selectionEnd]
      : null;
    let devolverAoCampo = null;

    /* O transplante (decisão 3 do cabeçalho): o formulário que veio do servidor é jogado
       fora e o VIVO — com o texto digitado, o cursor e o foco — ocupa o lugar dele. */
    for (const recem of molde.content.querySelectorAll('[data-adm-preservar]')) {
      const nome = recem.getAttribute('data-adm-preservar');
      const vivo = buscarNaRegiao(reg, '[data-adm-preservar="' + CSS.escape(nome) + '"]');
      if (!vivo) continue;
      if (ativo && vivo.contains(ativo)) devolverAoCampo = ativo;
      recem.replaceWith(vivo);
    }

    for (const velho of nosDaRegiao(reg)) velho.remove();
    reg.pai.insertBefore(molde.content, reg.fecha);

    if (devolverAoCampo && devolverAoCampo.isConnected) {
      devolverAoCampo.focus({ preventScroll: true });
      // O cursor volta para onde estava: sem isto ele salta para o fim a cada resposta, e
      // quem corrige uma letra no meio da palavra perde o lugar.
      if (cursor) {
        try { devolverAoCampo.setSelectionRange(cursor[0], cursor[1]); } catch { /* campo sem seleção */ }
      }
    }
  }

  /**
   * Reconcilia o formulário PRESERVADO com a URL que acabou de ser aplicada.
   *
   * Existe por causa dos filtros que NÃO são um controle do formulário. As abas de situação
   * de /admin/jogadores e a ordenação de /admin/logs são LINKS; o valor correspondente mora
   * num <input type=hidden> dentro do formulário, e o servidor mandou esse campo atualizado
   * no HTML novo — que a gente jogou fora para não perder o que o operador digitou. Sem
   * este acerto, a busca seguinte reconstruiria a URL a partir do campo velho e desfaria a
   * aba (ou a ordenação) que acabou de ser escolhida.
   *
   * Um <select> só é acertado quando a chave existe mesmo na URL — atribuir um valor que não
   * está entre as <option> deixaria a caixa em branco, pior do que deixá-la como estava.
   *
   * `incluirTexto` só é verdade no VOLTAR do navegador, e aí é obrigatório: quem aperta
   * voltar está pedindo um estado anterior inteiro, não só a tabela. Sem isto a caixa
   * continuaria dizendo "extract" sobre uma lista sem filtro nenhum — a tela mentindo sobre
   * o próprio recorte. Fora do voltar o campo de texto é intocável: aquele valor pertence a
   * quem está digitando, e o servidor não tem nada mais novo do que ele.
   */
  function sincronizarFormulario(url, incluirTexto) {
    const form = formulario();
    if (!form) return;
    const params = new URL(url, location.href).searchParams;

    for (const campo of form.elements) {
      if (!campo.name) continue;
      const ehTexto = campo instanceof HTMLInputElement
        && (campo.type === 'search' || campo.type === 'text');

      if (campo instanceof HTMLInputElement && campo.type === 'hidden') {
        campo.value = params.get(campo.name) ?? '';
      } else if (ehTexto) {
        if (incluirTexto) campo.value = params.get(campo.name) ?? '';
      } else if (campo === doc.activeElement) {
        continue;   // caixa aberta debaixo do dedo do operador: não se mexe
      } else if (campo instanceof HTMLSelectElement && params.has(campo.name)) {
        campo.value = params.get(campo.name);
      }
    }
  }

  /** A frase que o leitor de tela ouve: a mesma contagem que a tela mostra escrita. */
  function fraseDoResultado() {
    const total = buscarNaRegiao(reg, '[data-adm-total]');
    if (total) return total.textContent.replace(/\s+/g, ' ').trim();

    const vazio = buscarNaRegiao(reg, '.adm-empty__title');
    if (vazio) return vazio.textContent.trim();

    const linhas = elementosDaRegiao(reg)
      .reduce((n, el) => n + el.querySelectorAll('.adm-table tbody tr').length, 0);
    return linhas === 1 ? '1 resultado' : linhas + ' resultados';
  }

  /**
   * Carrega uma URL e troca só a região.
   * `modo`: 'empurrar' cria entrada no histórico, 'digitando' funde com a anterior e
   * 'voltar' não mexe no histórico (já foi o navegador que mexeu).
   */
  async function carregar(url, modo) {
    if (emVoo) emVoo.abort('substituida');
    const controle = new AbortController();
    emVoo = controle;

    const guardado = guardarFoco();
    marcarOcupado(true);
    clearTimeout(timerBarra);
    timerBarra = setTimeout(() => obterBarra().classList.add('is-on'), ATRASO_BARRA);
    const expira = setTimeout(() => controle.abort('tempo'), LIMITE);

    try {
      const resp = await fetch(url, {
        headers: { [CABECALHO]: '1', 'Accept': 'application/json' },
        credentials: 'same-origin',
        signal: controle.signal,
      });

      // Não é JSON: sessão caiu (veio o /admin/login), 403, 404, erro do PHP. Quem resolve
      // isso é o navegador indo até lá de verdade — e o operador vê a mensagem inteira.
      if (!(resp.headers.get('Content-Type') || '').includes('application/json')) {
        location.href = resp.url || url;
        return;
      }

      const dados = await resp.json();
      // ok:false = a resposta existe mas não é uma lista (dossiê, confirmação de exclusão).
      if (!dados || !dados.ok || typeof dados.regiao !== 'string') {
        location.href = resp.url || url;
        return;
      }
      if (controle.signal.aborted) return;

      const final = resp.url || url;
      trocar(dados.regiao);
      sincronizarFormulario(final, modo === 'voltar');
      if (dados.titulo) doc.title = dados.titulo;

      if (modo === 'empurrar') {
        history.pushState({ lista: true, digitando: false }, '', final);
        ultimoPorDigitacao = false;
      } else if (modo === 'digitando') {
        /* Coalescência: enquanto o operador digita, a URL acompanha letra a letra mas o
           histórico ganha UMA entrada só — senão "granada" custaria sete cliques no voltar
           para chegar de volta à lista sem filtro. */
        if (ultimoPorDigitacao) history.replaceState({ lista: true, digitando: true }, '', final);
        else history.pushState({ lista: true, digitando: true }, '', final);
        ultimoPorDigitacao = true;
      }

      devolverFoco(guardado);
      anunciar(fraseDoResultado());
    } catch (err) {
      if (controle.signal.aborted && controle.signal.reason === 'substituida') return;
      console.error('[admin/lista] falhou', err);
      location.href = url;   // último recurso: a navegação que sempre funcionou
    } finally {
      clearTimeout(expira);
      if (emVoo === controle) {
        emVoo = null;
        clearTimeout(timerBarra);
        obterBarra().classList.remove('is-on');
        marcarOcupado(false);
      }
    }
  }

  /* --------------------------------------------------------------- os gatilhos */

  /* Delegação no pai da região: os controles são substituídos a cada troca, então ligar
     ouvinte em cada <a> obrigaria a religar tudo depois de cada resposta. */

  reg.pai.addEventListener('input', (ev) => {
    const campo = ev.target;
    const form = formulario();
    if (!form || !form.contains(campo)) return;
    if (!(campo instanceof HTMLInputElement)) return;
    /* Só texto livre tem atraso. Data, número e caixa de seleção viram consulta no `change`
       logo abaixo: neles não existe "meio do caminho" para esperar. */
    if (campo.type !== 'search' && campo.type !== 'text') return;

    clearTimeout(timerBusca);
    timerBusca = setTimeout(() => carregar(urlDoFormulario(), 'digitando'), ATRASO_BUSCA);
  });

  reg.pai.addEventListener('change', (ev) => {
    const form = formulario();
    if (!form || !form.contains(ev.target)) return;

    /* Campo de texto NÃO entra aqui: ele já é servido pelo `input` com atraso. Além de
       duplicar a consulta, o `change` dele chega numa hora traiçoeira — tirar o campo do
       documento durante o transplante conta como "saiu do campo", e o navegador dispara a
       mudança sozinho, repetindo exatamente a requisição que acabou de ser feita. */
    const alvo = ev.target;
    if (alvo instanceof HTMLInputElement && (alvo.type === 'search' || alvo.type === 'text')) return;

    // Escolher num <select> ou marcar uma caixa é decisão tomada: aplica na hora.
    clearTimeout(timerBusca);
    carregar(urlDoFormulario(), 'empurrar');
  });

  reg.pai.addEventListener('submit', (ev) => {
    const form = formulario();
    if (!form || ev.target !== form) return;
    ev.preventDefault();
    clearTimeout(timerBusca);
    carregar(urlDoFormulario(), 'empurrar');
  });

  reg.pai.addEventListener('click', (ev) => {
    if (ev.defaultPrevented || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;

    const a = ev.target.closest ? ev.target.closest('a[href]') : null;
    if (!a || (a.target && a.target !== '_self') || a.hasAttribute('download')) return;
    /* Escape para o autor da tela: link que muda MAIS do que a lista (as abas de
       /admin/eventos trocam colunas, painéis e formulários de uma vez) pede recarga. */
    if (a.hasAttribute('data-adm-recarrega')) return;
    if (!elementosDaRegiao(reg).some((el) => el.contains(a))) return;

    let alvo;
    try { alvo = new URL(a.href, location.href); } catch { return; }
    if (alvo.origin !== location.origin || alvo.pathname !== location.pathname) return;
    // Âncora pura (#detalhe) continua sendo trabalho do navegador.
    if (alvo.search === location.search && alvo.hash) return;

    const conhecidas = chavesConhecidas();
    for (const chave of alvo.searchParams.keys()) {
      if (!conhecidas.has(chave)) return;   // ?id=, ?excluir=, ?ver= mudam de tela, não de lista
    }

    ev.preventDefault();
    clearTimeout(timerBusca);
    carregar(alvo.href, 'empurrar');
  });

  /* O voltar/avançar do navegador. `popstate` também dispara em âncora da mesma página;
     recarregar a mesma lista nesse caso é inofensivo e mais simples que adivinhar. */
  window.addEventListener('popstate', (ev) => {
    clearTimeout(timerBusca);
    // A entrada restaurada volta a valer para a coalescência: se ela nasceu de digitação,
    // continuar digitando deve seguir fundindo nela em vez de empilhar uma entrada nova.
    ultimoPorDigitacao = !!(ev.state && ev.state.digitando);
    carregar(location.href, 'voltar');
  });

  // A entrada atual passa a ser nossa: sem isto, o primeiro `voltar` depois de um pushState
  // devolveria um state nulo e o navegador recarregaria a página inteira.
  history.replaceState({ lista: true, digitando: false }, '', location.href);
}
