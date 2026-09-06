/**
 * EXTRACT HUNTERS — atmosphere (three.js). Renders into canvas#atmosphere.
 *
 *  - 700..1000 soft dust/pollen particles, additive, size attenuation, slow drift + depth parallax
 *  - 5 large soft fog sprites drifting very slowly (radial-gradient canvas texture)
 *  - 3 volumetric light shafts (additive planes, gradient alpha) from the upper-right light source
 *  - gentle mouse parallax (max 6px), pause when document.hidden
 *  - bails out cleanly (canvas hidden) without WebGL or with prefers-reduced-motion
 *  - pixel ratio capped at 1.5, RAF with delta
 */
import * as THREE from 'three';

const canvas = document.getElementById('atmosphere');
const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;

function hasWebGL() {
  try {
    const c = document.createElement('canvas');
    return !!(window.WebGLRenderingContext && (c.getContext('webgl2') || c.getContext('webgl')));
  } catch { return false; }
}

function makeRadialTexture(size, stops, softness = 1) {
  const c = document.createElement('canvas');
  c.width = c.height = size;
  const ctx = c.getContext('2d');
  const g = ctx.createRadialGradient(size / 2, size / 2, 0, size / 2, size / 2, (size / 2) * softness);
  stops.forEach(([o, col]) => g.addColorStop(o, col));
  ctx.fillStyle = g;
  ctx.fillRect(0, 0, size, size);
  const t = new THREE.CanvasTexture(c);
  t.colorSpace = THREE.SRGBColorSpace;
  return t;
}

function makeShaftTexture() {
  const w = 128, h = 512;
  const c = document.createElement('canvas');
  c.width = w; c.height = h;
  const ctx = c.getContext('2d');
  // vertical falloff
  const v = ctx.createLinearGradient(0, 0, 0, h);
  v.addColorStop(0, 'rgba(255,255,255,0.0)');
  v.addColorStop(0.08, 'rgba(255,255,255,0.85)');
  v.addColorStop(0.55, 'rgba(255,255,255,0.35)');
  v.addColorStop(1, 'rgba(255,255,255,0)');
  ctx.fillStyle = v; ctx.fillRect(0, 0, w, h);
  // horizontal soft edges (multiply)
  ctx.globalCompositeOperation = 'destination-in';
  const hg = ctx.createLinearGradient(0, 0, w, 0);
  hg.addColorStop(0, 'rgba(0,0,0,0)');
  hg.addColorStop(0.3, 'rgba(0,0,0,0.9)');
  hg.addColorStop(0.5, 'rgba(0,0,0,1)');
  hg.addColorStop(0.7, 'rgba(0,0,0,0.9)');
  hg.addColorStop(1, 'rgba(0,0,0,0)');
  ctx.fillStyle = hg; ctx.fillRect(0, 0, w, h);
  const t = new THREE.CanvasTexture(c);
  t.colorSpace = THREE.SRGBColorSpace;
  return t;
}

export function initAtmosphere(target = canvas) {
  if (!target) return null;
  if (reduced || !hasWebGL()) { target.hidden = true; return null; }

  let renderer;
  try {
    renderer = new THREE.WebGLRenderer({ canvas: target, alpha: true, antialias: false, powerPreference: 'low-power', premultipliedAlpha: true });
  } catch { target.hidden = true; return null; }
  renderer.setPixelRatio(Math.min(1.5, window.devicePixelRatio || 1));
  renderer.setClearColor(0x000000, 0);
  renderer.outputColorSpace = THREE.SRGBColorSpace;

  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(55, 1, 1, 400);
  camera.position.set(0, 0, 90);
  const basePos = camera.position.clone();

  const W = 200, H = 120, D = 120; // drift volume

  /* ---- dust / pollen ---- */
  const COUNT = 860;
  const pos = new Float32Array(COUNT * 3);
  const vel = new Float32Array(COUNT * 3);
  const phase = new Float32Array(COUNT);
  const sizes = new Float32Array(COUNT);
  for (let i = 0; i < COUNT; i++) {
    // biased to mid/upper area
    const y = (Math.random() ** 0.75) * H - H * 0.35;
    pos[i * 3] = (Math.random() - 0.5) * W;
    pos[i * 3 + 1] = y;
    pos[i * 3 + 2] = (Math.random() - 0.5) * D;
    vel[i * 3] = (Math.random() - 0.4) * 1.6;      // slow drift, slightly rightwards
    vel[i * 3 + 1] = (Math.random() - 0.55) * 0.9;  // mostly gently falling
    vel[i * 3 + 2] = (Math.random() - 0.5) * 0.4;
    phase[i] = Math.random() * Math.PI * 2;
    sizes[i] = 1.0 + Math.random() * 2.6;
  }
  const geo = new THREE.BufferGeometry();
  geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
  geo.setAttribute('aSize', new THREE.BufferAttribute(sizes, 1));
  geo.setAttribute('aPhase', new THREE.BufferAttribute(phase, 1));
  const dustTex = makeRadialTexture(64, [[0, 'rgba(255,255,255,1)'], [0.25, 'rgba(255,255,255,.55)'], [0.6, 'rgba(255,255,255,.12)'], [1, 'rgba(255,255,255,0)']]);
  const dustMat = new THREE.ShaderMaterial({
    uniforms: { uTex: { value: dustTex }, uTime: { value: 0 }, uPR: { value: renderer.getPixelRatio() }, uColor: { value: new THREE.Color(0xe4f5c4) }, uOpacity: { value: 0.9 } },
    vertexShader: /* glsl */`
      attribute float aSize; attribute float aPhase; uniform float uTime; uniform float uPR; varying float vA;
      void main(){
        vec3 p = position;
        p.x += sin(uTime * 0.35 + aPhase) * 1.2;
        p.y += cos(uTime * 0.27 + aPhase * 1.7) * 0.8;
        vec4 mv = modelViewMatrix * vec4(p, 1.0);
        float tw = 0.6 + 0.4 * sin(uTime * (0.8 + fract(aPhase) * 0.9) + aPhase * 3.0);
        vA = tw * clamp(1.0 - (-mv.z - 40.0) / 140.0, 0.25, 1.0);
        gl_PointSize = aSize * uPR * (300.0 / -mv.z);
        gl_Position = projectionMatrix * mv;
      }`,
    fragmentShader: /* glsl */`
      uniform sampler2D uTex; uniform vec3 uColor; uniform float uOpacity; varying float vA;
      void main(){
        vec4 t = texture2D(uTex, gl_PointCoord);
        gl_FragColor = vec4(uColor, t.a * vA * uOpacity);
      }`,
    transparent: true, depthWrite: false, depthTest: false, blending: THREE.AdditiveBlending,
  });
  const dust = new THREE.Points(geo, dustMat);
  scene.add(dust);

  /* ---- fog sprites ---- */
  const fogTex = makeRadialTexture(256, [[0, 'rgba(140,175,105,.6)'], [0.35, 'rgba(105,140,80,.26)'], [0.7, 'rgba(70,105,58,.07)'], [1, 'rgba(0,0,0,0)']]);
  const fogs = [];
  for (let i = 0; i < 5; i++) {
    const m = new THREE.SpriteMaterial({ map: fogTex, transparent: true, depthWrite: false, depthTest: false, opacity: 0.22 + Math.random() * 0.1, blending: THREE.NormalBlending, color: 0xffffff });
    const s = new THREE.Sprite(m);
    const sc = 70 + Math.random() * 60;
    s.scale.set(sc * 1.6, sc, 1);
    s.position.set((Math.random() - 0.5) * W * 1.1, (Math.random() - 0.7) * H * 0.9, -20 - Math.random() * 40);
    s.userData = { vx: (Math.random() - 0.5) * 0.9 + 0.3, vy: (Math.random() - 0.5) * 0.15, rot: (Math.random() - 0.5) * 0.02 };
    scene.add(s); fogs.push(s);
  }

  /* ---- light shafts from the upper-right ---- */
  const shaftTex = makeShaftTexture();
  const shafts = [];
  const shaftDefs = [
    { x: 62, y: 75, w: 26, h: 200, rot: 0.58, op: 0.17 },
    { x: 84, y: 82, w: 40, h: 220, rot: 0.50, op: 0.13 },
    { x: 44, y: 70, w: 16, h: 180, rot: 0.66, op: 0.14 },
  ];
  shaftDefs.forEach((d, i) => {
    const m = new THREE.MeshBasicMaterial({ map: shaftTex, transparent: true, depthWrite: false, depthTest: false, blending: THREE.AdditiveBlending, opacity: d.op, color: new THREE.Color(0xcfe8a0) });
    const mesh = new THREE.Mesh(new THREE.PlaneGeometry(d.w, d.h), m);
    mesh.position.set(d.x, d.y - d.h / 2 * Math.cos(d.rot) * 0.4, -15);
    mesh.rotation.z = d.rot; // tilt so it falls towards lower-left
    mesh.userData = { base: d.op, ph: i * 2.1, x0: d.x };
    scene.add(mesh); shafts.push(mesh);
  });

  /* ---- sizing ---- */
  let vw = 1, vh = 1;
  function resize() {
    vw = target.clientWidth || window.innerWidth;
    vh = target.clientHeight || window.innerHeight;
    renderer.setSize(vw, vh, false);
    camera.aspect = vw / vh;
    camera.updateProjectionMatrix();
    dustMat.uniforms.uPR.value = renderer.getPixelRatio();
  }
  resize();
  window.addEventListener('resize', resize, { passive: true });

  /* ---- mouse parallax (max 6px) ---- */
  const mouse = { x: 0, y: 0, tx: 0, ty: 0 };
  window.addEventListener('pointermove', (e) => {
    mouse.tx = (e.clientX / vw - 0.5) * 2;
    mouse.ty = (e.clientY / vh - 0.5) * 2;
  }, { passive: true });
  function worldPerPx() {
    const visibleH = 2 * Math.tan((camera.fov * Math.PI) / 360) * camera.position.z;
    return visibleH / vh;
  }

  /* ---- loop ---- */
  let raf = 0, last = performance.now(), t = 0, running = false;
  function frame(now) {
    if (!running) return;
    raf = requestAnimationFrame(frame);
    const dt = Math.min(0.05, (now - last) / 1000);
    last = now; t += dt;

    // particles
    const p = geo.attributes.position.array;
    for (let i = 0; i < COUNT; i++) {
      const k = i * 3;
      p[k] += vel[k] * dt; p[k + 1] += vel[k + 1] * dt; p[k + 2] += vel[k + 2] * dt;
      if (p[k] > W / 2) p[k] = -W / 2; else if (p[k] < -W / 2) p[k] = W / 2;
      if (p[k + 1] < -H * 0.45) p[k + 1] = H * 0.65; else if (p[k + 1] > H * 0.7) p[k + 1] = -H * 0.4;
      if (p[k + 2] > D / 2) p[k + 2] = -D / 2; else if (p[k + 2] < -D / 2) p[k + 2] = D / 2;
    }
    geo.attributes.position.needsUpdate = true;
    dustMat.uniforms.uTime.value = t;

    // fog
    for (const f of fogs) {
      f.position.x += f.userData.vx * dt; f.position.y += f.userData.vy * dt;
      f.material.rotation += f.userData.rot * dt;
      if (f.position.x > W * 0.75) f.position.x = -W * 0.75;
      if (f.position.x < -W * 0.75) f.position.x = W * 0.75;
    }
    // shafts breathe
    for (const s of shafts) {
      s.material.opacity = s.userData.base * (0.8 + 0.2 * Math.sin(t * 0.35 + s.userData.ph));
      s.position.x = s.userData.x0 + Math.sin(t * 0.12 + s.userData.ph) * 1.5;
    }
    // parallax
    mouse.x += (mouse.tx - mouse.x) * Math.min(1, dt * 3);
    mouse.y += (mouse.ty - mouse.y) * Math.min(1, dt * 3);
    const maxW = 6 * worldPerPx();
    camera.position.x = basePos.x + mouse.x * maxW;
    camera.position.y = basePos.y - mouse.y * maxW;
    dust.position.x = -mouse.x * maxW * 0.6; // depth parallax: near dust moves more than fog
    renderer.render(scene, camera);
  }
  function start() { if (running) return; running = true; last = performance.now(); raf = requestAnimationFrame(frame); }
  function stop() { running = false; if (raf) cancelAnimationFrame(raf); raf = 0; }
  document.addEventListener('visibilitychange', () => { document.hidden ? stop() : start(); });
  start();

  return {
    stop, start,
    dispose() {
      stop();
      geo.dispose(); dustMat.dispose(); dustTex.dispose(); fogTex.dispose(); shaftTex.dispose();
      fogs.forEach((f) => f.material.dispose());
      shafts.forEach((s) => { s.geometry.dispose(); s.material.dispose(); });
      renderer.dispose();
    },
  };
}

if (canvas) window.__ehAtmosphere = initAtmosphere(canvas);
