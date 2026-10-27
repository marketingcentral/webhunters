/**
 * EXTRACT HUNTERS — mapa do mundo (tela `mapa`).
 *
 * A base do palco é SEMPRE a imagem `bg/map-island-hud.jpg` (`.map3d__img`): ela e a
 * camada de marcadores recebem exatamente a mesma transformação CSS, então cada
 * marcador fica colado à feição do terreno em qualquer zoom ou deslocamento.
 *
 * O Three.js entra só como uma camada de LUZ: um plano com o relevo derivado da
 * luminância da própria imagem, iluminado por uma direcional fria, desenhado em
 * cinza neutro e mesclado por cima em `overlay`. Câmera ORTOGRÁFICA, sem
 * inclinação e sem órbita — o enquadramento do canvas é idêntico ao da imagem,
 * portanto nada "flutua" nem deriva entre frames.
 *
 * Sem WebGL: a imagem e os marcadores continuam valendo, só falta o relevo.
 */
import * as THREE from 'three';
import { aoSair } from '../spa.js';

const root = document.getElementById('map3d');

const PLANE_W = 5.628;           // = 941/1672: a arte do mundo é RETRATO, o plano segue a arte
const PLANE_H = 10;
const RELIEF = 0.5;
/* A ilha em pé cabe pela ALTURA num palco deitado, então na abertura ela ocupa pouco mais de
   metade da largura disponível. Daí o teto de zoom mais alto que o antigo (3.2): é assim que
   se lê o nome de uma rua do porto sem sair da tela. A arte entregue tem 1600px de largura,
   resolução de sobra para 5x. */
const ZOOM_MIN = 1, ZOOM_MAX = 5;
const START_ZOOM = 1;            // abre mostrando a ilha inteira

function hasWebGL() {
  try {
    const c = document.createElement('canvas');
    return !!(window.WebGLRenderingContext && (c.getContext('webgl2') || c.getContext('webgl')));
  } catch { return false; }
}

/** Mapa de altura (luminância) a partir da própria textura já carregada. */
function heightSampler(image) {
  const w = 200, h = 165;
  try {
    const c = document.createElement('canvas');
    c.width = w; c.height = h;
    const ctx = c.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(image, 0, 0, w, h);
    const d = ctx.getImageData(0, 0, w, h).data;
    return (u, v) => {
      const x = Math.min(w - 1, Math.max(0, Math.round(u * (w - 1))));
      const y = Math.min(h - 1, Math.max(0, Math.round(v * (h - 1))));
      const i = (y * w + x) * 4;
      return (0.2126 * d[i] + 0.7152 * d[i + 1] + 0.0722 * d[i + 2]) / 255;
    };
  } catch {
    return () => 0;
  }
}

export function initMap3d(target = root) {
  if (!target) return null;

  const imgEl = target.querySelector('.map3d__img');
  const canvas = target.querySelector('.map3d__canvas');
  if (!imgEl) return null;

  /* ---- estado do enquadramento ------------------------------------------- */
  /* zoom = escala; (cx,cy) = ponto da imagem, em 0..1, que fica no centro do palco */
  const view = { zoom: START_ZOOM, cx: 0.5, cy: 0.5 };
  const want = { zoom: START_ZOOM, cx: 0.5, cy: 0.5 };
  let cw = 1, ch = 1, dirty = true, running = true, raf = 0;

  const clamp = (v, a, b) => (v < a ? a : v > b ? b : v);

  function clampWant() {
    want.zoom = clamp(want.zoom, ZOOM_MIN, ZOOM_MAX);
    const half = 0.5 / want.zoom;
    want.cx = clamp(want.cx, half, 1 - half);
    want.cy = clamp(want.cy, half, 1 - half);
  }

  /* ---- Three.js: camada de relevo (opcional) ------------------------------ */
  let renderer = null, scene = null, camera = null, ready = false;

  if (canvas && hasWebGL()) {
    try {
      renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true, powerPreference: 'high-performance' });
      renderer.setPixelRatio(Math.min(2, window.devicePixelRatio || 1));
      renderer.setClearColor(0x000000, 0);
    } catch { renderer = null; }
  }

  if (renderer) {
    scene = new THREE.Scene();
    camera = new THREE.OrthographicCamera(-1, 1, 1, -1, 0.1, 100);
    camera.position.set(0, 30, 0);
    camera.up.set(0, 0, -1);
    camera.lookAt(0, 0, 0);

    /* luz fria: cinza médio no plano, encostas mais claras/escuras */
    scene.add(new THREE.AmbientLight(0xffffff, 1.05));
    const sun = new THREE.DirectionalLight(0xdfeaf2, 1.15);
    sun.position.set(-6, 7, -4);
    scene.add(sun);

    const build = () => {
      const geo = new THREE.PlaneGeometry(PLANE_W, PLANE_H, 200, 165);
      geo.rotateX(-Math.PI / 2);
      const sample = heightSampler(imgEl);
      const pos = geo.attributes.position;
      for (let i = 0; i < pos.count; i++) {
        const u = pos.getX(i) / PLANE_W + 0.5;
        const v = pos.getZ(i) / PLANE_H + 0.5;
        pos.setY(i, Math.pow(sample(u, v), 1.7) * RELIEF);
      }
      pos.needsUpdate = true;
      geo.computeVertexNormals();
      scene.add(new THREE.Mesh(geo, new THREE.MeshLambertMaterial({ color: 0x6b6b6b })));
      ready = true;
      target.classList.add('is-3d');
      dirty = true;
    };

    if (imgEl.complete && imgEl.naturalWidth) { build(); }
    else { imgEl.addEventListener('load', build, { once: true }); }
  }

  /* ---- aplica o enquadramento -------------------------------------------- */
  function apply() {
    const z = view.zoom;
    const tx = -(view.cx - 0.5) * cw * z;
    const ty = -(view.cy - 0.5) * ch * z;
    const st = target.style;
    st.setProperty('--mz', String(z));
    st.setProperty('--mkz', String(1 / z));
    st.setProperty('--mx', tx.toFixed(2) + 'px');
    st.setProperty('--my', ty.toFixed(2) + 'px');

    if (ready && camera) {
      const hw = PLANE_W / (2 * z), hh = PLANE_H / (2 * z);
      camera.left = -hw; camera.right = hw;
      camera.top = hh; camera.bottom = -hh;
      camera.position.x = (view.cx - 0.5) * PLANE_W;
      camera.position.z = (view.cy - 0.5) * PLANE_H;
      camera.updateProjectionMatrix();
      renderer.render(scene, camera);
    }
  }

  /* ---- tamanho ------------------------------------------------------------ */
  function resize() {
    const r = target.getBoundingClientRect();
    cw = Math.max(1, Math.round(r.width));
    ch = Math.max(1, Math.round(r.height));
    if (renderer) renderer.setSize(cw, ch, false);
    clampWant();
    dirty = true;
    apply();
  }
  resize();
  if (window.ResizeObserver) new ResizeObserver(resize).observe(target);
  else window.addEventListener('resize', resize);

  target.classList.add('is-grab');

  /* ---- arrastar ------------------------------------------------------------
   * DOIS TRANSPORTES, UMA LÓGICA. O gesto é sempre o mesmo — anotar onde o dedo
   * estava, medir quanto andou, mover o enquadramento —, mas ele chega por dois
   * caminhos diferentes conforme onde a HUD está rodando:
   *
   *   - pointer events, o caminho normal do navegador (e o único que atende
   *     toque e caneta);
   *   - mouse events, o caminho antigo, para embutidos que não sintetizam
   *     pointer events a partir do mouse.
   *
   * Isto importa porque este site não é só um site: ele é a HUD dentro do jogo,
   * desenhada pelo CEF embarcado na Unreal. Depender de um transporte só é
   * apostar que o embutido implementa exatamente aquele — e quando a aposta dá
   * errado o mapa simplesmente não arrasta, sem erro nenhum no console.
   *
   * `pointerMandou` evita o gesto dobrado: o navegador que entrega pointer events
   * entrega TAMBÉM os mouse events equivalentes, e sem essa trava cada pixel de
   * arrasto seria contado duas vezes.
   * -------------------------------------------------------------------------- */
  let arrastando = false, lastX = 0, lastY = 0, pointerMandou = false, dragId = null;

  /** O gesto começa aqui? Fora dos controles, botão esquerdo, e só. */
  const podeArrastar = (ev) =>
    ev.button === 0 && !(ev.target.closest && ev.target.closest('.map3d__controls'));

  function comecar(x, y) {
    arrastando = true;
    lastX = x; lastY = y;
    target.classList.add('is-grabbing');
  }

  function mover(x, y) {
    if (!arrastando) return;
    want.cx -= (x - lastX) / (cw * want.zoom);
    want.cy -= (y - lastY) / (ch * want.zoom);
    lastX = x; lastY = y;
    clampWant(); kick();
  }

  function terminar() {
    if (!arrastando) return;
    arrastando = false;
    target.classList.remove('is-grabbing');
  }

  /* --- caminho 1: pointer events --- */
  target.addEventListener('pointerdown', (ev) => {
    /* A BARRA DE CONTROLES INTEIRA fica de fora do arrasto, e não só os botões `.mctrl`.
     *
     * Enquanto a exceção era só `.mctrl`, qualquer controle novo naquela coluna nascia morto:
     * o palco capturava o ponteiro no `pointerdown` e o controle nunca via o arrasto. Foi o
     * que aconteceu com o controle de opacidade — ele desenhava certo, respondia ao teclado e
     * não respondia ao mouse. Perguntar pela BARRA, e não pelo botão, impede que o próximo
     * controle repita a história. */
    if (!podeArrastar(ev)) return;
    pointerMandou = true;
    dragId = ev.pointerId;
    /* A captura é um CONFORTO (seguir o cursor para fora do palco), não um requisito:
       os ouvintes de movimento estão na janela, então o arrasto continua valendo se o
       embutido não implementar captura de ponteiro. */
    try { target.setPointerCapture(dragId); } catch { /* ignora */ }
    comecar(ev.clientX, ev.clientY);
  });
  window.addEventListener('pointermove', (ev) => {
    if (dragId !== null && ev.pointerId !== dragId) return;
    mover(ev.clientX, ev.clientY);
  });
  const soltarPonteiro = (ev) => {
    if (dragId !== null && ev.pointerId !== dragId) return;
    try { target.releasePointerCapture(dragId); } catch { /* ignora */ }
    dragId = null;
    terminar();
  };
  window.addEventListener('pointerup', soltarPonteiro);
  window.addEventListener('pointercancel', soltarPonteiro);

  /* --- caminho 2: mouse events, para quem não entrega pointer events --- */
  target.addEventListener('mousedown', (ev) => {
    if (pointerMandou || !podeArrastar(ev)) return;
    ev.preventDefault();               // sem isto o navegador tenta arrastar a imagem
    comecar(ev.clientX, ev.clientY);
  });
  window.addEventListener('mousemove', (ev) => {
    if (pointerMandou) return;
    mover(ev.clientX, ev.clientY);
  });
  window.addEventListener('mouseup', () => {
    if (pointerMandou) return;
    terminar();
  });
  /* ---- roda / botões ------------------------------------------------------ */
  function zoomAt(factor, ox, oy) {
    const before = want.zoom;
    want.zoom = clamp(want.zoom * factor, ZOOM_MIN, ZOOM_MAX);
    const k = 1 / before - 1 / want.zoom;
    want.cx += ox * k;
    want.cy += oy * k;
    clampWant(); kick();
  }

  target.addEventListener('wheel', (ev) => {
    // mesma razão do arrasto: a roda sobre um controle é do controle, não do mapa
    if (ev.target.closest && ev.target.closest('.map3d__controls')) return;
    ev.preventDefault();
    const r = target.getBoundingClientRect();
    zoomAt(ev.deltaY < 0 ? 1.16 : 1 / 1.16,
      (ev.clientX - r.left) / cw - 0.5,
      (ev.clientY - r.top) / ch - 0.5);
  }, { passive: false });

  target.querySelectorAll('[data-map-action]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const a = btn.dataset.mapAction;
      if (a === 'zoom-in') zoomAt(1.4, 0, 0);
      else if (a === 'zoom-out') zoomAt(1 / 1.4, 0, 0);
      else { want.zoom = 1; want.cx = 0.5; want.cy = 0.5; clampWant(); kick(); }
    });
  });

  /* ---- laço --------------------------------------------------------------- */
  document.addEventListener('visibilitychange', () => { running = !document.hidden; if (running) kick(); });

  function frame() {
    raf = 0;
    if (!running) return;
    const k = 0.2;
    const dz = want.zoom - view.zoom, dx = want.cx - view.cx, dy = want.cy - view.cy;
    if (Math.abs(dz) < 1e-4 && Math.abs(dx) < 1e-5 && Math.abs(dy) < 1e-5) {
      view.zoom = want.zoom; view.cx = want.cx; view.cy = want.cy;
      apply();
      dirty = false;
      return;
    }
    view.zoom += dz * k; view.cx += dx * k; view.cy += dy * k;
    apply();
    raf = requestAnimationFrame(frame);
  }
  function kick() {
    dirty = true;
    if (!raf) raf = requestAnimationFrame(frame);
  }

  apply();

  /*
   * Navegação sem recarga: sair do mapa sem liberar isto deixa um contexto WebGL preso.
   * O navegador só tolera ~16 ao mesmo tempo, então em algumas idas e vindas o mapa
   * pararia de desenhar sem erro nenhum. Os timers/rAF/listeners de window e document já
   * são varridos pelo spa.js — o contexto é o que ele não tem como adivinhar.
   */
  aoSair(() => {
    if (raf) cancelAnimationFrame(raf);
    running = false;
    renderer?.dispose();
    scene?.traverse((o) => {
      o.geometry?.dispose();
      if (Array.isArray(o.material)) o.material.forEach((m) => m.dispose());
      else o.material?.dispose();
    });
  });

  return { apply, view, want };
}

initMap3d();
