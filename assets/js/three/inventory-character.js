/** Visualizador do inventário. Materiais originais, órbita e render sob demanda. */
import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { aoSair } from '../spa.js';

const viewer = document.querySelector('[data-character-viewer]');
if (viewer) initCharacter(viewer);

function initCharacter(root) {
  const stage = root.querySelector('.char__stage');
  const canvas = root.querySelector('canvas');
  const status = root.querySelector('.char__status');
  const buttons = [...root.querySelectorAll('[data-character-action]')];
  const autoButton = root.querySelector('[data-character-action="auto"]');
  const fullscreenButton = root.querySelector('[data-character-action="fullscreen"]');
  let renderer, controls, model, observer, visibilityObserver;
  let disposed = false, ready = false, visible = true, failed = false;
  let frameId = 0, previousTime = 0, fitDistance = 3;
  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(32, 1, .01, 100);
  const target = new THREE.Vector3(0, .52, 0);
  const initialDirection = new THREE.Vector3(.15, .04, 1).normalize();

  function fail(message) {
    if (disposed) return;
    failed = true;
    ready = false;
    cancelAnimationFrame(frameId);
    frameId = 0;
    root.classList.remove('is-ready');
    root.setAttribute('aria-busy', 'false');
    status.hidden = false;
    status.textContent = message;
    buttons.forEach(button => { button.disabled = true; });
  }

  // Registrado antes do carregamento assíncrono para pertencer à página correta.
  aoSair(dispose);
  try {
    renderer = new THREE.WebGLRenderer({ canvas, alpha: true, antialias: true, powerPreference: 'low-power' });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5));
    renderer.setClearColor(0x000000, 0);
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.2;
    controls = new OrbitControls(camera, canvas);
    controls.target.copy(target);
    controls.enablePan = false;
    controls.enableDamping = true;
    controls.dampingFactor = .12;
    controls.rotateSpeed = .65;
    controls.zoomSpeed = .7;
    controls.minPolarAngle = Math.PI * .15;
    controls.maxPolarAngle = Math.PI * .85;
    controls.autoRotateSpeed = 1.2;
    controls.addEventListener('change', requestRender);
  } catch (error) {
    renderer?.dispose();
    fail('Visualização 3D indisponível neste navegador.');
    return;
  }

  scene.add(new THREE.HemisphereLight(0xdcecf5, 0x4d5740, 2.5));
  const key = new THREE.DirectionalLight(0xffedda, 3.5);
  key.position.set(3, 4, 5);
  scene.add(key);
  const fill = new THREE.DirectionalLight(0xc3def5, 2);
  fill.position.set(-3, 2, 1);
  scene.add(fill);
  const rim = new THREE.DirectionalLight(0xc5edb0, 2.5);
  rim.position.set(1, 3, -4);
  scene.add(rim);

  function requestRender() {
    if (!frameId && ready && visible && !document.hidden && !disposed) {
      frameId = requestAnimationFrame(render);
    }
  }

  function render(now) {
    frameId = 0;
    if (!ready || !visible || document.hidden || disposed) return;
    const delta = Math.min(.05, (now - previousTime) / 1000 || 0);
    previousTime = now;
    controls.update(delta);
    renderer.render(scene, camera);
    // OrbitControls emite change enquanto há amortecimento; parado não há loop.
    if (controls.autoRotate) requestRender();
  }

  function setAuto(value) {
    controls.autoRotate = value;
    autoButton.setAttribute('aria-pressed', String(value));
    previousTime = performance.now();
    requestRender();
  }

  function reset() {
    setAuto(false);
    // Remove o impulso de uma interação anterior antes de restaurar a câmera.
    controls.enableDamping = false;
    controls.update();
    controls.enableDamping = true;
    controls.target.copy(target);
    camera.position.copy(target).addScaledVector(initialDirection, fitDistance);
    camera.lookAt(target);
    controls.update();
    requestRender();
  }

  function resize() {
    if (disposed) return;
    const { width, height } = stage.getBoundingClientRect();
    if (!width || !height) return;
    const oldFit = fitDistance;
    camera.aspect = width / height;
    camera.updateProjectionMatrix();
    renderer.setSize(width, height, false);
    if (!model) return;
    // Reserva espaço para a maior face do modelo, inclusive no painel estreito.
    const bounds = new THREE.Box3().setFromObject(model);
    const size = bounds.getSize(new THREE.Vector3());
    const vertical = Math.tan(THREE.MathUtils.degToRad(camera.fov / 2));
    fitDistance = Math.max(1.12 / (2 * vertical), Math.max(size.x, size.z) * 1.15 / (2 * vertical * camera.aspect));
    controls.minDistance = fitDistance * .4;
    controls.maxDistance = fitDistance * 1.9;
    if (ready) {
      const offset = camera.position.clone().sub(controls.target).multiplyScalar(fitDistance / oldFit);
      camera.position.copy(controls.target).add(offset);
      controls.update();
    }
    requestRender();
  }

  function orbit(horizontal, vertical = 0) {
    setAuto(false);
    const spherical = new THREE.Spherical().setFromVector3(camera.position.clone().sub(controls.target));
    spherical.theta += horizontal;
    spherical.phi = THREE.MathUtils.clamp(spherical.phi + vertical, controls.minPolarAngle, controls.maxPolarAngle);
    camera.position.copy(controls.target).add(new THREE.Vector3().setFromSpherical(spherical));
    controls.update();
    requestRender();
  }

  function zoom(factor) {
    const offset = camera.position.clone().sub(controls.target);
    offset.setLength(THREE.MathUtils.clamp(offset.length() * factor, controls.minDistance, controls.maxDistance));
    camera.position.copy(controls.target).add(offset);
    controls.update();
    requestRender();
  }

  function onKey(event) {
    if (!ready) return;
    const actions = {
      ArrowLeft: () => orbit(-Math.PI / 12), ArrowRight: () => orbit(Math.PI / 12),
      ArrowUp: () => orbit(0, -Math.PI / 24), ArrowDown: () => orbit(0, Math.PI / 24),
      '+': () => zoom(.85), '=': () => zoom(.85), '-': () => zoom(1.15), Home: reset,
    };
    if (actions[event.key]) {
      event.preventDefault();
      event.stopPropagation();
      actions[event.key]();
    }
  }

  async function onAction(event) {
    const button = event.target.closest('[data-character-action]');
    if (!button || !ready) return;
    switch (button.dataset.characterAction) {
      case 'left': orbit(-Math.PI / 8); break;
      case 'right': orbit(Math.PI / 8); break;
      case 'in': zoom(.8); break;
      case 'out': zoom(1.25); break;
      case 'reset': reset(); break;
      case 'auto': setAuto(!controls.autoRotate); break;
      case 'fullscreen':
        try {
          if (document.fullscreenElement === root) await document.exitFullscreen();
          else await root.requestFullscreen();
        } catch {
          status.hidden = false;
          status.textContent = 'Não foi possível abrir em tela cheia.';
        }
        break;
    }
  }

  function onFullscreen() {
    const expanded = document.fullscreenElement === root;
    fullscreenButton.setAttribute('aria-label', expanded ? 'Sair da tela cheia do personagem' : 'Abrir personagem em tela cheia');
    fullscreenButton.querySelector('.ms').textContent = expanded ? 'fullscreen_exit' : 'fullscreen';
    resize();
  }
  function onVisibility() { previousTime = performance.now(); requestRender(); }
  function onContextLost(event) { event.preventDefault(); fail('A visualização 3D foi interrompida. Recarregue a página para tentar novamente.'); }

  canvas.addEventListener('keydown', onKey);
  canvas.addEventListener('webglcontextlost', onContextLost);
  root.addEventListener('click', onAction);
  document.addEventListener('fullscreenchange', onFullscreen);
  document.addEventListener('visibilitychange', onVisibility);
  observer = new ResizeObserver(resize);
  observer.observe(stage);
  visibilityObserver = new IntersectionObserver(([entry]) => { visible = entry.isIntersecting; onVisibility(); });
  visibilityObserver.observe(stage);
  resize();

  const loader = new GLTFLoader();
  // Texturas embutidas via <img>: a CSP já permite img-src blob:, sem ampliar connect-src.
  loader.register(parser => {
    parser.textureLoader = new THREE.TextureLoader(parser.options.manager);
    parser.textureLoader.setCrossOrigin(parser.options.crossOrigin);
    return { name: 'EH_inventory_textures' };
  });
  let lastPercent = -1;
  loader.load(root.dataset.model, gltf => {
    if (disposed || failed) { releaseModel(gltf.scene); return; }
    model = gltf.scene;
    const bounds = new THREE.Box3().setFromObject(model);
    const size = bounds.getSize(new THREE.Vector3());
    if (!Number.isFinite(size.y) || size.y <= 0) {
      releaseModel(model); model = null;
      fail('Não foi possível exibir este modelo.');
      return;
    }
    const center = bounds.getCenter(new THREE.Vector3());
    const scale = 1 / size.y;
    model.scale.multiplyScalar(scale);
    model.position.set(-center.x * scale, -bounds.min.y * scale, -center.z * scale);
    scene.add(model);
    resize();
    ready = true;
    reset();
    root.classList.add('is-ready');
    root.setAttribute('aria-busy', 'false');
    status.textContent = 'Personagem carregado.';
    status.hidden = true;
    buttons.forEach(button => { button.disabled = false; });
    fullscreenButton.hidden = !document.fullscreenEnabled;
  }, progress => {
    if (disposed || failed || !progress.total) return;
    const percent = Math.floor(progress.loaded / progress.total * 100);
    if (percent !== lastPercent && (percent % 5 === 0 || percent === 100)) {
      status.textContent = percent === 100 ? 'Preparando personagem…' : `Carregando personagem… ${percent}%`;
      lastPercent = percent;
    }
  }, error => {
    console.error('[inventario] Falha ao carregar personagem 3D:', error);
    fail('Não foi possível carregar o personagem. Recarregue a página para tentar novamente.');
  });

  function dispose() {
    disposed = true;
    cancelAnimationFrame(frameId);
    observer?.disconnect();
    visibilityObserver?.disconnect();
    canvas.removeEventListener('keydown', onKey);
    canvas.removeEventListener('webglcontextlost', onContextLost);
    root.removeEventListener('click', onAction);
    document.removeEventListener('fullscreenchange', onFullscreen);
    document.removeEventListener('visibilitychange', onVisibility);
    controls?.dispose();
    if (model) releaseModel(model);
    scene.clear();
    renderer?.dispose();
    renderer?.forceContextLoss();
  }
}

function releaseModel(model) {
  const geometries = new Set(), materials = new Set(), textures = new Set();
  model.traverse(object => {
    if (object.geometry) geometries.add(object.geometry);
    for (const material of (Array.isArray(object.material) ? object.material : [object.material])) {
      if (!material) continue;
      materials.add(material);
      Object.values(material).forEach(value => { if (value?.isTexture) textures.add(value); });
    }
    object.skeleton?.dispose();
  });
  textures.forEach(texture => { texture.source?.data?.close?.(); texture.dispose(); });
  materials.forEach(material => material.dispose());
  geometries.forEach(geometry => geometry.dispose());
}
