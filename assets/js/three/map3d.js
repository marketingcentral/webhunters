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

const PLANE_W = 12.155;          // = 1240/1020, a mesma proporção da imagem e do palco
const PLANE_H = 10;
const RELIEF = 0.5;
const ZOOM_MIN = 1, ZOOM_MAX = 3.2;
const START_ZOOM = 1.12;         // enquadra o terreno preenchendo o palco, como na referencia

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
  const view = { zoom: START_ZOOM, cx: 0.5, cy: 0.455 };
  const want = { zoom: START_ZOOM, cx: 0.5, cy: 0.455 };
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

  /* ---- arrastar ----------------------------------------------------------- */
  let dragId = null, lastX = 0, lastY = 0;
  target.addEventListener('pointerdown', (ev) => {
    if (ev.button !== 0 || (ev.target.closest && ev.target.closest('.mctrl'))) return;
    dragId = ev.pointerId; lastX = ev.clientX; lastY = ev.clientY;
    try { target.setPointerCapture(dragId); } catch { /* ignora */ }
    target.classList.add('is-grabbing');
  });
  target.addEventListener('pointermove', (ev) => {
    if (ev.pointerId !== dragId) return;
    want.cx -= (ev.clientX - lastX) / (cw * want.zoom);
    want.cy -= (ev.clientY - lastY) / (ch * want.zoom);
    lastX = ev.clientX; lastY = ev.clientY;
    clampWant(); kick();
  });
  const endDrag = (ev) => {
    if (ev.pointerId !== dragId) return;
    try { target.releasePointerCapture(dragId); } catch { /* ignora */ }
    dragId = null;
    target.classList.remove('is-grabbing');
  };
  target.addEventListener('pointerup', endDrag);
  target.addEventListener('pointercancel', endDrag);

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
