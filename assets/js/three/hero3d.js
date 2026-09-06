/**
 * EXTRACT HUNTERS — hero3d (three.js). Soldado 3D da Home (reusado pelo lobby).
 *
 * Renderiza `assets/models/soldier.glb` (clipe "Idle") como um **holograma
 * rim-lit aditivo** sobre o soldado fotográfico do fundo: só as bordas do
 * volume acendem (fresnel), com uma varredura horizontal lenta. A opacidade é
 * baixa de propósito — a foto continua sendo a imagem principal e o 3D lê como
 * uma luz de contorno viva, não como um segundo personagem.
 *
 * Markup esperado (a página cria o canvas):
 *   <canvas id="hero3d" data-model="…/soldier.glb"
 *           data-anchor="0.754 0.571"   <- centro do modelo em fração do canvas
 *           data-height="0.78"          <- altura do modelo em fração do canvas
 *           data-opacity="0.17" data-turn="180" data-clip="Idle"></canvas>
 *
 * Sai limpo (canvas escondido) sem WebGL, sem o modelo ou com prefers-reduced-motion.
 * Pausa quando a aba está oculta. Pixel ratio limitado a 1.5.
 */
import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';

const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;

function hasWebGL() {
  try {
    const c = document.createElement('canvas');
    return !!(window.WebGLRenderingContext && (c.getContext('webgl2') || c.getContext('webgl')));
  } catch { return false; }
}

const num = (v, d) => { const n = Number(v); return Number.isFinite(n) ? n : d; };

export function initHero3d(canvas) {
  if (!canvas) return null;
  const url = canvas.dataset.model;
  if (!url || reduced || !hasWebGL()) { canvas.hidden = true; return null; }

  const anchor = String(canvas.dataset.anchor || '0.754 0.571').trim().split(/\s+/);
  const AX = num(anchor[0], 0.754);
  const AY = num(anchor[1], 0.571);
  const HFRAC = num(canvas.dataset.height, 0.78);
  const OPACITY = num(canvas.dataset.opacity, 0.17);
  const TURN = (num(canvas.dataset.turn, 180) * Math.PI) / 180;
  const CLIP = canvas.dataset.clip || 'Idle';

  let renderer;
  try {
    renderer = new THREE.WebGLRenderer({ canvas, alpha: true, antialias: true, powerPreference: 'low-power', premultipliedAlpha: true });
  } catch { canvas.hidden = true; return null; }
  renderer.setPixelRatio(Math.min(1.5, window.devicePixelRatio || 1));
  renderer.setClearColor(0x000000, 0);
  renderer.outputColorSpace = THREE.SRGBColorSpace;

  const scene = new THREE.Scene();
  const FOV = 26;
  const camera = new THREE.PerspectiveCamera(FOV, 1, 0.1, 200);
  const root = new THREE.Group();
  scene.add(root);

  const uTime = { value: 0 };
  const uOpacity = { value: 0 };
  const uColor = { value: new THREE.Color(0xd6f7a4) };
  const uBot = { value: -1 };   // y (NDC) da base do modelo: as pernas somem na neblina

  /** Rim-lit holográfico preservando o skinning do glTF (onBeforeCompile). */
  function holoMaterial() {
    const m = new THREE.MeshStandardMaterial({
      color: 0xffffff, roughness: 1, metalness: 0,
      transparent: true, depthWrite: false, depthTest: false,
      blending: THREE.AdditiveBlending, side: THREE.FrontSide, toneMapped: false,
    });
    m.onBeforeCompile = (shader) => {
      shader.uniforms.uTime = uTime;
      shader.uniforms.uOpacity = uOpacity;
      shader.uniforms.uColor = uColor;
      shader.uniforms.uBot = uBot;
      shader.vertexShader = shader.vertexShader
        .replace('#include <common>', '#include <common>\nvarying float vHY;')
        .replace('#include <project_vertex>', '#include <project_vertex>\n vHY = gl_Position.y / max( 0.0001, gl_Position.w );');
      shader.fragmentShader = shader.fragmentShader
        .replace('#include <common>', '#include <common>\nuniform float uTime;\nuniform float uOpacity;\nuniform vec3 uColor;\nuniform float uBot;\nvarying float vHY;')
        .replace(
          '#include <dithering_fragment>',
          `#include <dithering_fragment>
           vec3 hN = normalize( vNormal );
           vec3 hV = normalize( vViewPosition );
           float fres = 1.0 - abs( dot( hN, hV ) );
           fres = pow( clamp( fres, 0.0, 1.0 ), 2.6 );
           float scan = 0.72 + 0.28 * sin( gl_FragCoord.y * 0.055 - uTime * 1.15 );
           float band = 0.85 + 0.15 * sin( gl_FragCoord.y * 0.011 + uTime * 0.35 );
           float rise = smoothstep( uBot, uBot + 0.30, vHY );
           gl_FragColor = vec4( uColor * scan * band, fres * uOpacity * rise );`
        );
    };
    m.customProgramCacheKey = () => 'eh-holo';
    return m;
  }

  let mixer = null;
  let model = null;
  let ready = false;
  let disposed = false;
  const mats = [];

  /* O soldier.glb traz texturas embutidas: o GLTFLoader as transforma em
     blob: URLs e as busca com fetch — o que a CSP (`connect-src 'self'`)
     bloqueia. Como o holograma substitui todos os materiais, nada de imagem é
     necessário: este plugin devolve uma textura vazia e o loader nunca sai
     para a rede. */
  const loader = new GLTFLoader();
  loader.register(() => ({
    name: 'EH_skip_textures',
    loadTexture: () => Promise.resolve(new THREE.Texture()),
  }));

  loader.load(
    url,
    (gltf) => {
      if (disposed) return;
      model = gltf.scene;
      model.traverse((o) => {
        if (o.isMesh || o.isSkinnedMesh) {
          o.castShadow = o.receiveShadow = false;
          o.frustumCulled = false;
          const old = Array.isArray(o.material) ? o.material : [o.material];
          old.forEach((mm) => { Object.values(mm || {}).forEach((v) => { if (v && v.isTexture) v.dispose(); }); mm?.dispose?.(); });
          const mat = holoMaterial();
          mats.push(mat);
          o.material = mat;
        }
      });

      // normaliza: pés na origem, altura 1 unidade
      const box = new THREE.Box3().setFromObject(model);
      const size = new THREE.Vector3(); box.getSize(size);
      const center = new THREE.Vector3(); box.getCenter(center);
      const s = size.y > 0 ? 1 / size.y : 1;
      model.scale.setScalar(s);
      model.position.set(-center.x * s, -center.y * s, -center.z * s);
      model.rotation.y = TURN;
      root.add(model);

      const clips = gltf.animations || [];
      const clip = THREE.AnimationClip.findByName(clips, CLIP) || clips[0];
      if (clip) {
        mixer = new THREE.AnimationMixer(model);
        mixer.clipAction(clip).reset().play();
      }
      ready = true;
      resize();
      canvas.classList.add('is-ready');
      start();
    },
    undefined,
    () => { canvas.hidden = true; }
  );

  /* ---- enquadramento: coloca o modelo na fração pedida do canvas ---- */
  let vw = 1, vh = 1;
  function resize() {
    vw = canvas.clientWidth || 1;
    vh = canvas.clientHeight || 1;
    renderer.setSize(vw, vh, false);
    camera.aspect = vw / vh;
    camera.updateProjectionMatrix();
    if (!ready) return;
    // distância para que 1 unidade (altura do modelo) ocupe HFRAC da altura visível
    const visibleH = 1 / Math.max(0.05, HFRAC);
    const dist = visibleH / (2 * Math.tan((FOV * Math.PI) / 360));
    const visibleW = visibleH * camera.aspect;
    camera.position.set(0, 0, dist);
    camera.lookAt(0, 0, 0);
    root.position.set((AX - 0.5) * visibleW, (0.5 - AY) * visibleH, 0);
    uBot.value = 2 * (0.5 - AY) - HFRAC;
  }
  resize();
  window.addEventListener('resize', resize, { passive: true });

  /* ---- parallax discreto do mouse (máx. ~5px) ---- */
  const m = { x: 0, y: 0, tx: 0, ty: 0 };
  window.addEventListener('pointermove', (e) => {
    m.tx = (e.clientX / (window.innerWidth || 1) - 0.5) * 2;
    m.ty = (e.clientY / (window.innerHeight || 1) - 0.5) * 2;
  }, { passive: true });

  let raf = 0, last = performance.now(), running = false, fade = 0;
  function frame(now) {
    if (!running) return;
    raf = requestAnimationFrame(frame);
    const dt = Math.min(0.05, (now - last) / 1000);
    last = now;
    uTime.value += dt;
    if (mixer) mixer.update(dt);
    fade = Math.min(1, fade + dt / 1.6);
    uOpacity.value = OPACITY * fade;
    m.x += (m.tx - m.x) * Math.min(1, dt * 2.4);
    m.y += (m.ty - m.y) * Math.min(1, dt * 2.4);
    root.rotation.y = m.x * 0.055;
    root.rotation.x = -m.y * 0.02;
    renderer.render(scene, camera);
  }
  function start() { if (running || !ready) return; running = true; last = performance.now(); raf = requestAnimationFrame(frame); }
  function stop() { running = false; if (raf) cancelAnimationFrame(raf); raf = 0; }
  document.addEventListener('visibilitychange', () => { document.hidden ? stop() : start(); });

  return {
    start, stop,
    dispose() {
      disposed = true;
      stop();
      mats.forEach((x) => x.dispose());
      model?.traverse((o) => { if (o.geometry) o.geometry.dispose(); });
      renderer.dispose();
    },
  };
}

const el = document.getElementById('hero3d');
if (el && !window.__ehHero3d) window.__ehHero3d = initHero3d(el);
