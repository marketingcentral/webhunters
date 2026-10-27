/**
 * EXTRACT HUNTERS · PAINEL — EDITOR DE MAPA (/admin/mapas/editor).
 *
 * Arrastar um ícone da paleta para cima da arte, movê-lo, ajustar e salvar. A coordenada
 * gravada continua sendo a mesma fração 0..1 de sempre (`map_markers.x/y`); o que muda é que
 * ela nasce de um gesto sobre a própria arte, e não de um número digitado.
 *
 * ---------------------------------------------------------------------------------------
 * AS SEIS DECISÕES QUE ESTE ARQUIVO EXISTE PARA SUSTENTAR
 *
 * 1. ESTADO EM MEMÓRIA, GRAVAÇÃO EM UM SÓ ENVIO. Cada gesto mexe num array aqui; o banco só é
 *    tocado no "Salvar", com o conjunto inteiro. Salvar a cada arrasto encheria o log de
 *    auditoria de uma linha por movimento do mouse e deixaria o mapa meio editado se a rede
 *    caísse no meio. O preço é um aviso de "há alterações não salvas" — que existe logo abaixo.
 *
 * 2. PONTEIRO, NÃO DRAG-AND-DROP NATIVO, PARA MOVER. A API nativa (dragstart/drop) é ótima
 *    para trazer um ícone de FORA do palco, e é o que a paleta usa. Para MOVER um marcador já
 *    posto ela é péssima: não há posição contínua durante o arrasto, o navegador desenha uma
 *    imagem-fantasma que não é o marcador, e o ponto só "anda" quando se solta. Com
 *    pointermove o marcador segue o dedo em tempo real, que é o único jeito de encostar num
 *    píer estreito.
 *
 * 3. O ZOOM MUDA A LARGURA DO PALCO, e quem desloca é a barra de rolagem. Com `transform:
 *    scale` seria preciso reimplementar deslocamento, limites e conversão inversa de
 *    coordenada a cada gesto. Mudando a largura, o navegador cuida da rolagem e a conversão
 *    continua sendo uma regra de três sobre o retângulo do palco.
 *
 * 4. CLIQUE E ARRASTO SÃO O MESMO GESTO ATÉ PROVA EM CONTRÁRIO. Um marcador só começa a se
 *    mover depois de 3 pixels de deslocamento; abaixo disso o gesto termina como seleção. Sem
 *    esse limiar, todo clique moveria o ponto um pouco — e ninguém consegue clicar sem mexer
 *    a mão.
 *
 * 5. DESFAZER GUARDA FOTOGRAFIAS, não operações inversas. O estado é pequeno (centenas de
 *    objetos rasos) e uma pilha de cópias é impossível de dessincronizar. Operações inversas
 *    seriam menos memória e muito mais lugares para errar.
 *
 * 6. NADA DE innerHTML COM DADO DO BANCO. Os nomes vêm de `map_markers.label`, que um
 *    operador digitou. Tudo que é texto entra por textContent; o único innerHTML é o corpo do
 *    <svg> dos vetores, que vem de app/Data/map_glyphs.php — arquivo do projeto, não conteúdo
 *    de usuário.
 * ---------------------------------------------------------------------------------------
 */

const doc = document;
const raiz = doc.querySelector('[data-mape]');

if (raiz) {
  /* ====================================================================== dados */
  let DADOS = {};
  try {
    DADOS = JSON.parse(doc.querySelector('[data-mape-dados]')?.textContent || '{}');
  } catch { DADOS = {}; }

  const TIPOS   = DADOS.tipos || {};
  const GLIFOS  = DADOS.glifos || {};
  const ROTULOS = DADOS.rotulos || {};
  const MEDIA   = DADOS.media || '';
  const MAX     = DADOS.max || 400;

  /** Estado vivo. `id` 0 = marcador que ainda não existe no banco. */
  let pontos = (DADOS.marcadores || []).map((m) => ({ ...m }));
  let selecionado = null;          // índice em `pontos`
  let sujo = false;
  const historico = [];
  const HISTORICO_MAX = 40;

  /* ================================================================== elementos */
  const palco    = raiz.querySelector('[data-mape-stage]');
  const canvas   = raiz.querySelector('[data-mape-canvas]');
  const arte     = raiz.querySelector('.mape__art');
  const camada   = raiz.querySelector('[data-mape-layer]');
  const props    = raiz.querySelector('[data-mape-props]');
  const semSel   = raiz.querySelector('[data-mape-vazio-sel]');
  const estado   = raiz.querySelector('[data-mape-estado]');
  const payload  = raiz.querySelector('[data-mape-payload]');
  const formSalv = raiz.querySelector('[data-mape-form]');
  const btnUndo  = raiz.querySelector('[data-mape-desfazer]');
  const chkNomes = raiz.querySelector('[data-mape-nomes]');
  const busca    = raiz.querySelector('[data-mape-busca]');
  const zoomVal  = raiz.querySelector('[data-mape-zoom-v]');
  const seletor  = raiz.querySelector('[data-mape-mapa]');

  /* ======================================================================= zoom */
  const ZOOM_MIN = 0.5, ZOOM_MAX = 6;
  let zoom = 1;
  let larguraBase = 0;             // largura do palco que equivale a 100%

  function medirBase() {
    // 100% = a arte inteira visível na altura do palco. É o enquadramento em que se decide
    // "onde fica", antes de aproximar para acertar o detalhe.
    const alt = palco.clientHeight || 1;
    const prop = (arte.naturalWidth || 941) / (arte.naturalHeight || 1672);
    larguraBase = Math.max(120, Math.round(alt * prop));
  }

  function aplicarZoom(z) {
    zoom = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, z));
    canvas.style.width = `${Math.round(larguraBase * zoom)}px`;
    if (zoomVal) zoomVal.textContent = `${Math.round(zoom * 100)}%`;
  }

  /** Zoom mantendo fixo o ponto sob o cursor — sem isto, aproximar joga o alvo para fora. */
  function zoomEm(fator, cliente) {
    const r = canvas.getBoundingClientRect();
    const u = cliente ? (cliente.x - r.left) / r.width : 0.5;
    const v = cliente ? (cliente.y - r.top) / r.height : 0.5;
    const antes = { x: cliente ? cliente.x - palco.getBoundingClientRect().left : palco.clientWidth / 2,
                    y: cliente ? cliente.y - palco.getBoundingClientRect().top : palco.clientHeight / 2 };
    aplicarZoom(zoom * fator);
    const d = canvas.getBoundingClientRect();
    palco.scrollLeft = u * d.width - antes.x;
    palco.scrollTop  = v * d.height - antes.y;
  }

  raiz.querySelectorAll('[data-mape-zoom]').forEach((b) => {
    b.addEventListener('click', () => {
      const a = b.dataset.mapeZoom;
      if (a === '+') zoomEm(1.25, null);
      else if (a === '-') zoomEm(1 / 1.25, null);
      else { aplicarZoom(1); palco.scrollTo({ left: 0, top: 0 }); }
    });
  });

  /* A roda AMPLIA, sem exigir Ctrl. Num mapa, girar a roda significa aproximar — foi o que o
     operador tentou fazer primeiro. E aqui não há o conflito de sempre: a página do editor não
     rola (a área de conteúdo tem overflow:hidden só nesta tela), então não existe rolagem para
     roubar. Shift+roda continua deslocando na horizontal, que é o gesto de quem tem trackpad. */
  palco.addEventListener('wheel', (ev) => {
    if (ev.shiftKey) { return; }
    ev.preventDefault();
    zoomEm(ev.deltaY < 0 ? 1.12 : 1 / 1.12, { x: ev.clientX, y: ev.clientY });
  }, { passive: false });

  /* ---- arrastar o mapa (pan) ----------------------------------------------
   * Barra de rolagem não é como se navega um mapa: a mão vai ao ponto e puxa.
   * O gesto começa no PALCO (não num marcador — lá o arrasto move o ponto), e
   * também com o botão do meio em qualquer lugar, que é o atalho de quem já
   * usa editor de mapa. Mover é mexer em scrollLeft/scrollTop: assim o zoom, os
   * limites e a rolagem por teclado continuam sendo do navegador. */
  let pan = null;
  palco.addEventListener('pointerdown', (ev) => {
    const noMarcador = ev.target.closest && ev.target.closest('.mape__mk');
    const meio = ev.button === 1;
    if (!meio && (ev.button !== 0 || noMarcador)) return;
    ev.preventDefault();
    pan = { id: ev.pointerId, x: ev.clientX, y: ev.clientY, sl: palco.scrollLeft, st: palco.scrollTop };
    try { palco.setPointerCapture(ev.pointerId); } catch { /* ignora */ }
    palco.classList.add('is-pan');
  });
  palco.addEventListener('pointermove', (ev) => {
    if (!pan || ev.pointerId !== pan.id) return;
    palco.scrollLeft = pan.sl - (ev.clientX - pan.x);
    palco.scrollTop  = pan.st - (ev.clientY - pan.y);
  });
  const soltarPan = (ev) => {
    if (!pan || ev.pointerId !== pan.id) return;
    try { palco.releasePointerCapture(ev.pointerId); } catch { /* ignora */ }
    pan = null;
    palco.classList.remove('is-pan');
  };
  palco.addEventListener('pointerup', soltarPan);
  palco.addEventListener('pointercancel', soltarPan);
  // o botão do meio abre a rolagem automática do Windows se o padrão não for barrado
  palco.addEventListener('auxclick', (ev) => { if (ev.button === 1) ev.preventDefault(); });

  /* ---- opacidade da arte ---------------------------------------------------
   * Regulada olhando para o resultado: o palco mostra a arte já recuada, com os
   * marcadores por cima, e o valor só vai para o banco no "Salvar mapa". */
  const slider = raiz.querySelector('[data-mape-opacidade]');
  const opVal  = raiz.querySelector('[data-mape-opacidade-v]');
  const opPay  = raiz.querySelector('[data-mape-op-payload]');
  const opInicial = slider ? Number(slider.value) : 78;

  slider?.addEventListener('input', () => {
    const v = Number(slider.value);
    canvas.style.setProperty('--mape-art-opacity', String(v / 100));
    if (opVal) opVal.textContent = `${v}%`;
    if (opPay) opPay.value = (v / 100).toFixed(2);
    if (v !== opInicial) marcarSujo(true);
  });

  /* ================================================================== desenho */

  /** Conteúdo do ícone de um ponto, pelo `icon` do catálogo. Ver decisão 6 no topo. */
  function icone(spec) {
    const caixa = doc.createElement('span');
    caixa.className = 'mape__mk-ic';
    const [origem, valor] = String(spec || 'symbol:place').split(/:(.*)/s);
    if (origem === 'image' && valor) {
      const img = doc.createElement('img');
      img.src = MEDIA + encodeURI(valor);
      img.alt = '';
      caixa.append(img);
    } else if (origem === 'glyph' && GLIFOS[valor]) {
      const svg = doc.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('viewBox', '0 0 24 24');
      svg.setAttribute('fill', 'currentColor');
      svg.setAttribute('aria-hidden', 'true');
      svg.innerHTML = GLIFOS[valor];      // tabela do projeto, não dado de usuário
      caixa.append(svg);
    } else {
      const ms = doc.createElement('span');
      ms.className = 'ms';
      ms.setAttribute('aria-hidden', 'true');
      ms.textContent = (origem === 'symbol' ? valor : 'place') || 'place';
      caixa.append(ms);
    }
    return caixa;
  }

  function desenhar() {
    camada.textContent = '';
    pontos.forEach((p, i) => {
      const el = doc.createElement('div');
      el.className = `mape__mk mape__mk--${p.color || 'white'}${i === selecionado ? ' is-sel' : ''}`;
      el.style.left = `${p.x * 100}%`;
      el.style.top = `${p.y * 100}%`;
      el.dataset.i = String(i);
      el.tabIndex = 0;
      el.setAttribute('role', 'button');
      el.setAttribute('aria-label', p.name || 'marcador');

      // raio de posição aproximada: desenhado atrás do ícone, na mesma escala do mapa
      if (p.radius > 0) {
        const z = doc.createElement('span');
        z.className = 'mape__mk-zone';
        z.style.width = `${p.radius * 2}%`;
        el.append(z);
      }

      // o ícone do próprio marcador vence o do tipo — a mesma ordem que o mapa do jogador usa
      const tipo = TIPOS[p.point_type];
      el.append(icone(p.icon || (tipo ? tipo.icon : `glyph:${p.kind}`)));

      const nome = doc.createElement('span');
      nome.className = 'mape__mk-n';
      nome.textContent = p.name || '';    // decisão 6: nome do banco nunca por innerHTML
      el.append(nome);

      camada.append(el);
    });
    raiz.classList.toggle('is-nomes', !!chkNomes?.checked);
    marcarSujo(sujo);
  }

  /* =================================================================== estado */

  function fotografar() {
    historico.push(JSON.stringify(pontos));
    if (historico.length > HISTORICO_MAX) historico.shift();
    if (btnUndo) btnUndo.disabled = false;
  }

  function desfazer() {
    const f = historico.pop();
    if (!f) return;
    pontos = JSON.parse(f);
    selecionado = null;
    if (btnUndo) btnUndo.disabled = historico.length === 0;
    desenhar();
    abrirProps();
    marcarSujo(true);
  }

  function marcarSujo(v) {
    sujo = v;
    if (estado) estado.textContent = v ? 'Alterações não salvas' : '';
    raiz.classList.toggle('is-sujo', v);
  }

  /* O navegador só deixa avisar no fechamento se houver mudança real — e é exatamente o
     caso em que o aviso importa: o editor guarda tudo em memória até o salvamento. */
  window.addEventListener('beforeunload', (ev) => {
    if (!sujo) return;
    ev.preventDefault();
    ev.returnValue = '';
  });

  /* ============================================================ criar e mover */

  /** Converte um ponto da tela em fração 0..1 da arte, já limitada às bordas. */
  function paraFracao(clientX, clientY) {
    const r = canvas.getBoundingClientRect();
    return {
      x: Math.min(1, Math.max(0, (clientX - r.left) / r.width)),
      y: Math.min(1, Math.max(0, (clientY - r.top) / r.height)),
    };
  }

  function criar(tipoSlug, nome, missaoId, x, y) {
    if (pontos.length >= MAX) {
      if (estado) estado.textContent = `Limite de ${MAX} marcadores neste mapa.`;
      return;
    }
    const t = TIPOS[tipoSlug];
    fotografar();
    pontos.push({
      id: 0,
      kind: t ? t.kind : 'loot',
      name: nome || (t ? t.label : 'PONTO'),
      x, y,
      point_type: tipoSlug,
      color: t ? t.color : 'white',
      label: t && t.show_label ? 'right' : '',
      icon: '',                    // vazio = herda o desenho do tipo
      radius: 0,
      mission_id: missaoId || 0,
      extras: [],
    });
    selecionado = pontos.length - 1;
    desenhar();
    abrirProps();
    marcarSujo(true);
  }

  /* ---- paleta: arrastar para dentro (drag-and-drop nativo) ----------------- */
  let chipArrastado = null;

  raiz.querySelectorAll('[data-mape-chip]').forEach((chip) => {
    chip.addEventListener('dragstart', (ev) => {
      chipArrastado = chip;
      // o payload real viaja em `chipArrastado`; o dataTransfer existe só para o
      // navegador aceitar o arrasto (e para não vazar nada para fora da página)
      ev.dataTransfer.setData('text/plain', chip.dataset.tipo || '');
      ev.dataTransfer.effectAllowed = 'copy';
    });
    chip.addEventListener('dragend', () => { chipArrastado = null; palco.classList.remove('is-drop'); });
    // clique no chip = pôr no centro do que está visível, para quem não arrasta
    chip.addEventListener('click', () => {
      const r = palco.getBoundingClientRect();
      const { x, y } = paraFracao(r.left + r.width / 2, r.top + r.height / 2);
      criar(chip.dataset.tipo, chip.dataset.nome, Number(chip.dataset.missao || 0), x, y);
    });
  });

  palco.addEventListener('dragover', (ev) => {
    if (!chipArrastado) return;
    ev.preventDefault();
    ev.dataTransfer.dropEffect = 'copy';
    palco.classList.add('is-drop');
  });
  palco.addEventListener('dragleave', (ev) => {
    if (ev.target === palco) palco.classList.remove('is-drop');
  });
  palco.addEventListener('drop', (ev) => {
    if (!chipArrastado) return;
    ev.preventDefault();
    palco.classList.remove('is-drop');
    const { x, y } = paraFracao(ev.clientX, ev.clientY);
    criar(chipArrastado.dataset.tipo, chipArrastado.dataset.nome, Number(chipArrastado.dataset.missao || 0), x, y);
    chipArrastado = null;
  });

  /* ---- mover um marcador já posto (ponteiro — ver decisão 2) ---------------- */
  const LIMIAR = 3;                 // px antes de um clique virar arrasto
  let arrasto = null;

  camada.addEventListener('pointerdown', (ev) => {
    const el = ev.target.closest('.mape__mk');
    if (!el || ev.button !== 0) return;
    ev.preventDefault();
    const i = Number(el.dataset.i);
    arrasto = { i, el, x0: ev.clientX, y0: ev.clientY, movendo: false, ponteiro: ev.pointerId };
    selecionar(i);
    try { el.setPointerCapture(ev.pointerId); } catch { /* ignora */ }
  });

  camada.addEventListener('pointermove', (ev) => {
    if (!arrasto || ev.pointerId !== arrasto.ponteiro) return;
    if (!arrasto.movendo) {
      if (Math.hypot(ev.clientX - arrasto.x0, ev.clientY - arrasto.y0) < LIMIAR) return;
      arrasto.movendo = true;
      fotografar();                 // uma foto por ARRASTO, não por pixel percorrido
      raiz.classList.add('is-movendo');
    }
    const { x, y } = paraFracao(ev.clientX, ev.clientY);
    const p = pontos[arrasto.i];
    p.x = x; p.y = y;
    arrasto.el.style.left = `${x * 100}%`;
    arrasto.el.style.top = `${y * 100}%`;
    escreverProps();
    marcarSujo(true);
  });

  const soltar = (ev) => {
    if (!arrasto || ev.pointerId !== arrasto.ponteiro) return;
    try { arrasto.el.releasePointerCapture(ev.pointerId); } catch { /* ignora */ }
    raiz.classList.remove('is-movendo');
    arrasto = null;
  };
  camada.addEventListener('pointerup', soltar);
  camada.addEventListener('pointercancel', soltar);

  /* ================================================================= seleção */

  function selecionar(i) {
    selecionado = i;
    camada.querySelectorAll('.mape__mk').forEach((el, k) => el.classList.toggle('is-sel', k === i));
    abrirProps();
  }

  function abrirProps() {
    const p = selecionado !== null ? pontos[selecionado] : null;
    if (props) props.hidden = !p;
    if (semSel) semSel.hidden = !!p;
    if (!p) return;
    escreverProps();
    const extras = raiz.querySelector('[data-mape-extras]');
    if (extras) {
      const lista = (p.extras || []).join(', ');
      extras.hidden = !lista;
      extras.textContent = lista
        ? `Este marcador tem dados que o editor não mostra (${lista}). Eles são preservados ao salvar.`
        : '';
    }
  }

  /** Espelha o ponto selecionado nos campos (sem disparar os próprios ouvintes). */
  function escreverProps() {
    const p = selecionado !== null ? pontos[selecionado] : null;
    if (!p || !props) return;
    props.querySelectorAll('[data-mape-p]').forEach((campo) => {
      const k = campo.dataset.mapeP;
      const v = p[k];
      campo.value = (k === 'x' || k === 'y') ? Number(v).toFixed(4) : (v ?? '');
    });
  }

  props?.querySelectorAll('[data-mape-p]').forEach((campo) => {
    const evento = campo.tagName === 'SELECT' ? 'change' : 'input';
    campo.addEventListener(evento, () => {
      if (selecionado === null) return;
      const p = pontos[selecionado];
      const k = campo.dataset.mapeP;
      if (k === 'x' || k === 'y') {
        p[k] = Math.min(1, Math.max(0, Number(campo.value) || 0));
      } else if (k === 'radius') {
        p[k] = Math.min(40, Math.max(0, Number(campo.value) || 0));
      } else {
        p[k] = campo.value;
      }
      desenhar();
      marcarSujo(true);
    });
  });

  raiz.querySelector('[data-mape-remover]')?.addEventListener('click', () => {
    if (selecionado === null) return;
    fotografar();
    pontos.splice(selecionado, 1);
    selecionado = null;
    desenhar();
    abrirProps();
    marcarSujo(true);
  });

  raiz.querySelector('[data-mape-duplicar]')?.addEventListener('click', () => {
    if (selecionado === null) return;
    fotografar();
    const p = pontos[selecionado];
    // a cópia nasce ao lado, e sem id: é um marcador NOVO, não o mesmo em dois lugares
    pontos.push({ ...p, id: 0, x: Math.min(1, p.x + 0.02), y: Math.min(1, p.y + 0.02) });
    selecionado = pontos.length - 1;
    desenhar();
    abrirProps();
    marcarSujo(true);
  });

  btnUndo?.addEventListener('click', desfazer);
  chkNomes?.addEventListener('change', () => raiz.classList.toggle('is-nomes', chkNomes.checked));

  /* ================================================================== teclado */
  doc.addEventListener('keydown', (ev) => {
    const alvo = ev.target;
    const digitando = alvo && (alvo.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(alvo.tagName || ''));

    if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'z') { ev.preventDefault(); desfazer(); return; }
    if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 's') { ev.preventDefault(); formSalv?.requestSubmit(); return; }
    if (digitando || selecionado === null) return;

    if (ev.key === 'Delete' || ev.key === 'Backspace') {
      ev.preventDefault();
      raiz.querySelector('[data-mape-remover]')?.click();
      return;
    }
    // setas fazem o ajuste fino que o mouse não faz: 0,1% por toque, 1% com Shift
    const passo = (ev.shiftKey ? 0.01 : 0.001);
    const mover = { ArrowLeft: [-passo, 0], ArrowRight: [passo, 0], ArrowUp: [0, -passo], ArrowDown: [0, passo] }[ev.key];
    if (!mover) return;
    ev.preventDefault();
    if (!arrasto) fotografar();
    const p = pontos[selecionado];
    p.x = Math.min(1, Math.max(0, p.x + mover[0]));
    p.y = Math.min(1, Math.max(0, p.y + mover[1]));
    desenhar();
    escreverProps();
    marcarSujo(true);
  });

  /* ================================================================== paleta */
  busca?.addEventListener('input', () => {
    const q = busca.value.trim().toLowerCase();
    let achou = 0;
    raiz.querySelectorAll('[data-mape-chip]').forEach((chip) => {
      const ok = !q || (chip.dataset.busca || '').includes(q);
      chip.hidden = !ok;
      if (ok) achou++;
    });
    // com busca ativa toda gaveta abre: esconder um resultado atrás de um <details>
    // fechado seria o mesmo que não encontrá-lo
    raiz.querySelectorAll('.mape__group').forEach((g) => {
      if (q) g.open = true;
      g.hidden = q ? !g.querySelector('[data-mape-chip]:not([hidden])') : false;
    });
    const vazio = raiz.querySelector('[data-mape-vazio]');
    if (vazio) vazio.hidden = achou > 0;
  });

  /* ================================================================== salvar */
  formSalv?.addEventListener('submit', () => {
    if (payload) {
      payload.value = JSON.stringify(pontos.map((p) => ({
        id: p.id, kind: p.kind, name: p.name, x: p.x, y: p.y,
        point_type: p.point_type, icon: p.icon, color: p.color, label: p.label,
        radius: p.radius, mission_id: p.mission_id,
      })));
    }
    // o aviso de saída não pode disparar no próprio salvamento
    marcarSujo(false);
  });

  /* Trocar de mapa com alterações pendentes perderia o trabalho em silêncio. */
  seletor?.addEventListener('change', (ev) => {
    if (sujo && !window.confirm('Há alterações não salvas neste mapa. Trocar de mapa vai descartá-las.')) {
      ev.target.value = String(DADOS.mapaId);
      return;
    }
    marcarSujo(false);
    ev.target.form?.requestSubmit();
  });

  /* ==================================================================== boot */
  function iniciar() {
    medirBase();
    aplicarZoom(1);
    desenhar();
    abrirProps();
    marcarSujo(false);
  }
  if (arte.complete && arte.naturalWidth) iniciar();
  else arte.addEventListener('load', iniciar, { once: true });

  if (window.ResizeObserver) {
    new ResizeObserver(() => { medirBase(); aplicarZoom(zoom); }).observe(palco);
  }
}
