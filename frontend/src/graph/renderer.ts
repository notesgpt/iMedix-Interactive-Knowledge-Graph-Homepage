/**
 * Three.js renderer for the Knowledge Universe constellation.
 *
 * Design choices (see docs/ARCHITECTURE.md):
 *  - one THREE.Points draw call with a custom shader; node type is encoded by
 *    shape (dot / ring / diamond) as well as hue, so meaning never relies on colour;
 *  - one THREE.LineSegments draw call for all edges;
 *  - screen-space picking against projected positions (cheaper and more forgiving
 *    than ray casting for size-attenuated points);
 *  - render-on-demand: the loop sleeps when nothing moves, when the hero is
 *    off-screen or the tab is hidden;
 *  - adaptive pixel ratio and node shedding when frames get slow.
 */
import {
  AdditiveBlending,
  BufferAttribute,
  BufferGeometry,
  Color,
  Group,
  LineSegments,
  PerspectiveCamera,
  Points,
  Scene,
  ShaderMaterial,
  Vector3,
  WebGLRenderer,
} from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import type { KGraph } from '../types';
import { neighbours } from '../data/graph';

export interface RendererOptions {
  positions: Float32Array;
  /** node indices in priority order, grouped into progressive stages */
  stages: number[][];
  reducedMotion: boolean;
  tier: 'high' | 'medium' | 'low';
  touch: boolean;
  onHover: (index: number | null, x: number, y: number) => void;
  onSelect: (index: number | null) => void;
  onWheelHint: () => void;
  onContextLost: () => void;
}

const COLORS: Record<string, string> = { drug: '#5fd4c4', disease: '#f2b880', topic: '#c9d3ff' };
const SIZES: Record<string, number> = { drug: 1.9, disease: 2.6, topic: 4.2 };
const SHAPES: Record<string, number> = { drug: 0, disease: 1, topic: 2 };

/** Horizontal extent the home view must fit (topic orbit + margin). */
const FIT_HALF_WIDTH = 112;

const VERTEX = /* glsl */ `
  attribute vec3 aColor;
  attribute float aSize;
  attribute float aShape;
  attribute float aState;
  attribute float aReveal;
  uniform float uPixelRatio;
  uniform float uScale;
  uniform vec2 uFade;
  varying vec3 vColor;
  varying float vShape;
  varying float vAlpha;
  void main() {
    vec4 mv = modelViewMatrix * vec4(position, 1.0);
    float depth = max(-mv.z, 1.0);
    float fade = smoothstep(uFade.y, uFade.x, depth);
    float grow = 1.0 + 0.7 * max(aState - 1.0, 0.0);
    gl_PointSize = aSize * grow * uPixelRatio * uScale / depth;
    vColor = aColor;
    vShape = aShape;
    vAlpha = aReveal * mix(0.14, 1.0, clamp(aState, 0.0, 1.0)) * (0.32 + 0.68 * fade);
    gl_Position = projectionMatrix * mv;
  }
`;

const FRAGMENT = /* glsl */ `
  varying vec3 vColor;
  varying float vShape;
  varying float vAlpha;
  void main() {
    vec2 p = gl_PointCoord * 2.0 - 1.0;
    float r = length(p);
    float body;
    if (vShape < 0.5) {
      body = 1.0 - smoothstep(0.30, 0.42, r);
    } else if (vShape < 1.5) {
      body = 1.0 - smoothstep(0.06, 0.13, abs(r - 0.33));
    } else {
      float d = abs(p.x) + abs(p.y);
      body = (1.0 - smoothstep(0.05, 0.11, abs(d - 0.42))) + 0.18 * (1.0 - smoothstep(0.3, 0.42, d));
    }
    float halo = exp(-r * r * 5.0) * 0.22;
    float a = (body + halo) * vAlpha;
    if (a < 0.01) discard;
    gl_FragColor = vec4(vColor * a, a);
  }
`;

const LINE_VERTEX = /* glsl */ `
  attribute float aAlpha;
  uniform vec2 uFade;
  varying float vAlpha;
  void main() {
    vec4 mv = modelViewMatrix * vec4(position, 1.0);
    vAlpha = aAlpha * (0.35 + 0.65 * smoothstep(uFade.y, uFade.x, -mv.z));
    gl_Position = projectionMatrix * mv;
  }
`;

const LINE_FRAGMENT = /* glsl */ `
  uniform vec3 uColor;
  varying float vAlpha;
  void main() {
    gl_FragColor = vec4(uColor * vAlpha, vAlpha);
  }
`;

interface Tween {
  from: { pos: Vector3; target: Vector3 };
  to: { pos: Vector3; target: Vector3 };
  start: number;
  duration: number;
}

export class GraphRenderer {
  readonly canvas: HTMLCanvasElement;
  /** projected screen coordinates (CSS px) of every node; NaN when hidden */
  readonly screen: Float32Array;

  private renderer: WebGLRenderer;
  private scene = new Scene();
  /** holds points + lines; drifts slowly around the view axis */
  private world = new Group();
  private camera: PerspectiveCamera;
  private controls: OrbitControls;
  private points: Points;
  private lines: LineSegments;
  private geometry = new BufferGeometry();
  private lineGeometry = new BufferGeometry();
  private material: ShaderMaterial;

  private state: Float32Array; // current emphasis per node
  private stateTarget: Float32Array;
  private reveal: Float32Array;
  private revealTarget: Float32Array;
  private lineAlpha: Float32Array;
  private visible: Uint8Array;

  private hovered: number | null = null;
  private selected: number | null = null;
  private matches: number[] = [];
  private focusIndex: number | null = null;
  private pendingStages: number[][];
  private tween: Tween | null = null;
  private frame = 0;
  private tick = 0;
  private wake = 0;
  private running = true;
  private onScreen = true;
  private autoRotate: boolean;
  private exploring = false;
  private slowFrames = 0;
  private lastTime = 0;
  private pixelRatio: number;
  private width = 1;
  private height = 1;
  private disposed = false;
  private pointer = { x: 0, y: 0, inside: false, down: false, moved: 0 };
  private resizeObserver: ResizeObserver | null = null;
  private visibilityObserver: IntersectionObserver | null = null;
  private tmp = new Vector3();
  /** shared depth-fade range (near, far), follows the home distance */
  private fade = { value: [80, 250] as [number, number] };
  private homeDistance = 150;
  private baseRoll = 0;
  private driftAngle = 0;
  private userMoved = false;

  constructor(
    private container: HTMLElement,
    private graph: KGraph,
    private opts: RendererOptions,
  ) {
    const n = graph.nodes.length;
    this.screen = new Float32Array(n * 2).fill(Number.NaN);
    this.state = new Float32Array(n).fill(1);
    this.stateTarget = new Float32Array(n).fill(1);
    this.reveal = new Float32Array(n);
    this.revealTarget = new Float32Array(n);
    this.visible = new Uint8Array(n);
    this.lineAlpha = new Float32Array(graph.edges.length * 2);
    this.autoRotate = !opts.reducedMotion;
    this.pendingStages = opts.stages.slice(1);

    this.pixelRatio = Math.min(window.devicePixelRatio || 1, opts.tier === 'high' ? 2 : 1.5);
    this.renderer = new WebGLRenderer({
      antialias: opts.tier === 'high',
      alpha: true,
      powerPreference: opts.tier === 'low' ? 'low-power' : 'high-performance',
    });
    this.renderer.setPixelRatio(this.pixelRatio);
    this.renderer.setClearColor(0x000000, 0);
    this.canvas = this.renderer.domElement;
    this.canvas.setAttribute('aria-hidden', 'true');
    this.canvas.tabIndex = -1;
    container.append(this.canvas);

    this.camera = new PerspectiveCamera(50, 1, 1, 900);
    this.camera.position.set(0, 6, this.homeDistance);

    this.controls = new OrbitControls(this.camera, this.canvas);
    this.controls.enableDamping = true;
    this.controls.dampingFactor = 0.07;
    this.controls.enablePan = false;
    this.controls.enableZoom = false; // custom wheel handling: never hijack page scroll
    this.controls.rotateSpeed = 0.55;
    this.controls.minPolarAngle = Math.PI * 0.22;
    this.controls.maxPolarAngle = Math.PI * 0.78;
    this.controls.minDistance = 36;
    this.controls.maxDistance = 460;
    // Ambient motion is a slow drift of the world around the view axis (see loop),
    // not an orbit: the topic ring therefore never sweeps across the centred text.
    this.controls.autoRotate = false;
    this.controls.enabled = !opts.touch;
    this.controls.addEventListener('change', () => this.poke());
    this.controls.addEventListener('start', () => {
      this.tween = null;
      this.userMoved = true;
    });

    // Nodes
    const colors = new Float32Array(n * 3);
    const sizes = new Float32Array(n);
    const shapes = new Float32Array(n);
    const c = new Color();
    graph.nodes.forEach((node, i) => {
      c.set(COLORS[node.type] ?? '#b7c3d6');
      colors.set([c.r, c.g, c.b], i * 3);
      sizes[i] = (SIZES[node.type] ?? 2) * (node.type === 'topic' ? 1 : 1 + Math.min(node.degree, 6) * 0.04);
      shapes[i] = SHAPES[node.type] ?? 0;
    });
    this.geometry.setAttribute('position', new BufferAttribute(opts.positions, 3));
    this.geometry.setAttribute('aColor', new BufferAttribute(colors, 3));
    this.geometry.setAttribute('aSize', new BufferAttribute(sizes, 1));
    this.geometry.setAttribute('aShape', new BufferAttribute(shapes, 1));
    this.geometry.setAttribute('aState', new BufferAttribute(this.state, 1));
    this.geometry.setAttribute('aReveal', new BufferAttribute(this.reveal, 1));
    this.material = new ShaderMaterial({
      vertexShader: VERTEX,
      fragmentShader: FRAGMENT,
      uniforms: { uPixelRatio: { value: this.pixelRatio }, uScale: { value: 400 }, uFade: this.fade },
      transparent: true,
      depthWrite: false,
      blending: AdditiveBlending,
    });
    this.points = new Points(this.geometry, this.material);
    this.points.frustumCulled = false;
    this.world.add(this.points);
    this.scene.add(this.world);

    // Edges
    const linePos = new Float32Array(graph.edges.length * 6);
    graph.edges.forEach((e, i) => {
      linePos.set(opts.positions.subarray(e.source * 3, e.source * 3 + 3), i * 6);
      linePos.set(opts.positions.subarray(e.target * 3, e.target * 3 + 3), i * 6 + 3);
    });
    this.lineGeometry.setAttribute('position', new BufferAttribute(linePos, 3));
    this.lineGeometry.setAttribute('aAlpha', new BufferAttribute(this.lineAlpha, 1));
    this.lines = new LineSegments(
      this.lineGeometry,
      new ShaderMaterial({
        vertexShader: LINE_VERTEX,
        fragmentShader: LINE_FRAGMENT,
        uniforms: { uColor: { value: new Color('#9fb3d9') }, uFade: this.fade },
        transparent: true,
        depthWrite: false,
        blending: AdditiveBlending,
      }),
    );
    this.lines.frustumCulled = false;
    this.world.add(this.lines);

    this.revealIndices(opts.stages[0] ?? []);
    this.updateEmphasis();
    this.bindEvents();
    this.resize();
    this.loop = this.loop.bind(this);
    this.frame = requestAnimationFrame(this.loop);
  }

  /* ------------------------------------------------------------ public API */

  setMatches(indices: number[]): void {
    this.matches = indices;
    this.revealIndices(indices);
    this.updateEmphasis();
    if (!indices.length || this.selected !== null || this.width < 1000) return;
    // Pan (not orbit) so the best match sits beside the centred text column, where
    // the view can draw a connector from the search field to it.
    const p = this.position(indices[0]);
    const dist = 125;
    const dir = this.camera.position.clone().sub(this.controls.target).normalize();
    const right = new Vector3().crossVectors(this.camera.up, dir).normalize();
    const halfWidth = dist * Math.tan((this.camera.fov * Math.PI) / 360) * this.camera.aspect;
    const current = this.screen[indices[0] * 2];
    const side = Number.isNaN(current) || current >= this.width / 2 ? 1 : -1;
    const target = p.clone().sub(right.multiplyScalar(side * 0.62 * halfWidth));
    this.animateCamera(target.clone().add(dir.multiplyScalar(dist)), target, 1100);
  }

  setFocus(index: number | null): void {
    this.focusIndex = index;
    if (index !== null) this.revealIndices([index]);
    this.updateEmphasis();
  }

  select(index: number | null, fly = true): void {
    this.selected = index;
    if (index !== null) {
      this.revealIndices([index, ...neighbours(this.graph, index)]);
      if (fly) this.flyTo(this.position(index), this.graph.nodes[index].type === 'topic' ? 95 : 70, 1000);
    }
    this.updateEmphasis();
    this.opts.onSelect(index);
  }

  get selection(): number | null {
    return this.selected;
  }

  zoom(factor: number): void {
    const target = this.controls.target.clone();
    const dir = this.camera.position.clone().sub(target);
    const dist = Math.min(this.controls.maxDistance, Math.max(this.controls.minDistance, dir.length() * factor));
    this.animateCamera(target.clone().add(dir.setLength(dist)), target, 420);
  }

  reset(): void {
    this.select(null, false);
    this.matches = [];
    this.updateEmphasis();
    this.userMoved = false;
    this.animateCamera(this.home(), new Vector3(), 900);
  }

  setAutoRotate(on: boolean): void {
    this.autoRotate = on && !this.opts.reducedMotion;
    this.poke();
  }

  get rotating(): boolean {
    return this.autoRotate;
  }

  setExploring(on: boolean): void {
    this.exploring = on;
    this.controls.enabled = on || !this.opts.touch;
    this.poke();
  }

  dispose(): void {
    if (this.disposed) return;
    this.disposed = true;
    cancelAnimationFrame(this.frame);
    this.resizeObserver?.disconnect();
    this.visibilityObserver?.disconnect();
    this.controls.dispose();
    this.geometry.dispose();
    this.lineGeometry.dispose();
    this.material.dispose();
    (this.lines.material as ShaderMaterial).dispose();
    this.renderer.dispose();
    this.canvas.remove();
  }

  /* ------------------------------------------------------------- internals */

  /** World-space position of node i (after the ambient drift). */
  private position(i: number): Vector3 {
    const p = this.opts.positions;
    return new Vector3(p[i * 3], p[i * 3 + 1], p[i * 3 + 2]).applyMatrix4(this.world.matrixWorld);
  }

  /** Drift only while nothing is highlighted, so results stay where the user looks. */
  private get drifting(): boolean {
    return this.autoRotate && this.matches.length === 0 && this.selected === null && this.focusIndex === null;
  }

  private revealIndices(indices: number[]): void {
    for (const i of indices) {
      this.visible[i] = 1;
      this.revealTarget[i] = 1;
      if (this.opts.reducedMotion) this.reveal[i] = 1;
    }
    this.poke();
  }

  private updateEmphasis(): void {
    const n = this.graph.nodes.length;
    const focus = this.selected ?? this.focusIndex ?? this.hovered;
    const strong = new Set<number>();
    const soft = new Set<number>();
    if (focus !== null) {
      strong.add(focus);
      neighbours(this.graph, focus).forEach((j) => soft.add(j));
    }
    for (const m of this.matches) {
      strong.add(m);
      neighbours(this.graph, m, 'in_topic').forEach((j) => soft.add(j));
    }
    const dimming = strong.size > 0;
    for (let i = 0; i < n; i++) {
      if (!dimming) this.stateTarget[i] = this.graph.nodes[i].degree > 0 ? 1 : 0.62;
      else if (strong.has(i)) this.stateTarget[i] = i === focus ? 1.9 : 1.55;
      else if (soft.has(i)) this.stateTarget[i] = 1.05;
      else this.stateTarget[i] = 0.3;
    }
    this.poke();
  }

  private flyTo(point: Vector3, distance: number, duration: number): void {
    const dir = this.camera.position.clone().sub(this.controls.target).normalize();
    this.animateCamera(point.clone().add(dir.multiplyScalar(distance)), point, duration);
  }

  private animateCamera(pos: Vector3, target: Vector3, duration: number): void {
    if (this.opts.reducedMotion) {
      this.camera.position.copy(pos);
      this.controls.target.copy(target);
      this.controls.update();
      this.poke();
      return;
    }
    this.tween = {
      from: { pos: this.camera.position.clone(), target: this.controls.target.clone() },
      to: { pos, target },
      start: performance.now(),
      duration,
    };
    this.poke();
  }

  /** Keep rendering for a while (damping, fades, tweens). */
  private poke(frames = 90): void {
    this.wake = Math.max(this.wake, frames);
  }

  private bindEvents(): void {
    const c = this.canvas;
    c.addEventListener('pointermove', (e) => {
      const r = c.getBoundingClientRect();
      this.pointer.x = e.clientX - r.left;
      this.pointer.y = e.clientY - r.top;
      this.pointer.inside = true;
      if (this.pointer.down) this.pointer.moved += Math.abs(e.movementX) + Math.abs(e.movementY);
      this.poke(2);
    });
    c.addEventListener('pointerleave', () => {
      this.pointer.inside = false;
      this.setHover(null);
    });
    c.addEventListener('pointerdown', () => {
      this.pointer.down = true;
      this.pointer.moved = 0;
    });
    c.addEventListener('pointerup', (e) => {
      this.pointer.down = false;
      if (this.pointer.moved > 6) return; // that was a drag
      const r = c.getBoundingClientRect();
      const hit = this.pick(e.clientX - r.left, e.clientY - r.top, e.pointerType === 'touch' ? 26 : 14);
      if (hit !== null || this.selected !== null) this.select(hit);
    });
    c.addEventListener(
      'wheel',
      (e) => {
        if (!(e.ctrlKey || e.metaKey || this.exploring)) {
          this.opts.onWheelHint();
          return; // let the page scroll
        }
        e.preventDefault();
        this.zoom(e.deltaY > 0 ? 1.12 : 0.89);
      },
      { passive: false },
    );
    c.addEventListener('webglcontextlost', (e) => {
      e.preventDefault();
      this.opts.onContextLost();
    });

    this.resizeObserver = new ResizeObserver(() => this.resize());
    this.resizeObserver.observe(this.container);

    this.visibilityObserver = new IntersectionObserver(([entry]) => {
      this.onScreen = entry.isIntersecting;
      if (this.onScreen) this.poke();
    });
    this.visibilityObserver.observe(this.container);

    document.addEventListener('visibilitychange', () => {
      this.running = document.visibilityState === 'visible';
      if (this.running) {
        this.lastTime = 0;
        this.poke();
      }
    });
  }

  private resize(): void {
    const w = Math.max(1, this.container.clientWidth);
    const h = Math.max(1, this.container.clientHeight);
    this.width = w;
    this.height = h;
    this.renderer.setSize(w, h, false);
    this.camera.aspect = w / h;
    // Narrow screens: widen the field of view a little so the constellation fits.
    this.camera.fov = w < 700 ? 62 : 50;
    this.camera.updateProjectionMatrix();
    // Portrait screens: stand the orbit upright so it frames a tall content column.
    const portrait = this.camera.aspect < 0.9;
    this.baseRoll = portrait ? Math.PI / 2 : 0;
    this.world.rotation.z = this.baseRoll + this.driftAngle;
    this.world.updateMatrixWorld();
    // Fit the orbit's horizontal extent.
    const tanHalf = Math.tan((this.camera.fov * Math.PI) / 360);
    const half = portrait ? FIT_HALF_WIDTH * 0.62 : FIT_HALF_WIDTH;
    this.homeDistance = Math.min(420, Math.max(120, half / (tanHalf * this.camera.aspect)));
    this.fade.value = [this.homeDistance - 70, this.homeDistance + 110];
    if (!this.userMoved && !this.tween) {
      this.camera.position.copy(this.home());
      this.controls.target.set(0, 0, 0);
      this.controls.update();
    }
    this.material.uniforms.uScale.value = h / 2 / Math.tan((this.camera.fov * Math.PI) / 360);
    this.poke();
  }

  private home(): Vector3 {
    return new Vector3(0, 6, this.homeDistance);
  }

  private setHover(index: number | null): void {
    if (index === this.hovered) return;
    this.hovered = index;
    this.canvas.style.cursor = index === null ? '' : 'pointer';
    if (this.selected === null && this.focusIndex === null) this.updateEmphasis();
  }

  /** Nearest visible node within `radius` CSS px of (x, y). */
  pick(x: number, y: number, radius: number): number | null {
    let best: number | null = null;
    let bestD = radius * radius;
    for (let i = 0; i < this.graph.nodes.length; i++) {
      if (!this.visible[i] || this.reveal[i] < 0.5) continue;
      const sx = this.screen[i * 2];
      if (Number.isNaN(sx)) continue;
      const dx = sx - x;
      const dy = this.screen[i * 2 + 1] - y;
      // Favour emphasised nodes when dimmed.
      const d = (dx * dx + dy * dy) / (this.state[i] < 0.5 ? 0.5 : 1);
      if (d < bestD) {
        bestD = d;
        best = i;
      }
    }
    return best;
  }

  private loop(time: number): void {
    if (this.disposed) return;
    this.frame = requestAnimationFrame(this.loop);
    if (!this.running || !this.onScreen) return;

    const continuous = this.drifting || this.tween !== null;
    if (!continuous && this.wake <= 0) return;
    // Idle auto-rotation is gentle: 30 fps is plenty and halves the GPU cost.
    this.tick++;
    if (this.drifting && this.wake <= 0 && this.tween === null && this.tick % 2 === 1) return;
    this.wake--;

    const dt = this.lastTime ? Math.min(time - this.lastTime, 100) : 16;
    this.lastTime = time;
    this.adapt(dt);

    if (this.tween) {
      const t = Math.min(1, (time - this.tween.start) / this.tween.duration);
      const k = t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
      this.camera.position.lerpVectors(this.tween.from.pos, this.tween.to.pos, k);
      this.controls.target.lerpVectors(this.tween.from.target, this.tween.to.target, k);
      if (t >= 1) this.tween = null;
    }
    this.controls.update();
    if (this.drifting) {
      this.driftAngle += dt * 0.000035;
      this.world.rotation.z = this.baseRoll + this.driftAngle;
      this.world.rotation.y = Math.sin(time * 0.00009) * 0.2;
      this.world.updateMatrixWorld();
    }

    // Progressive loading: reveal one more chunk every ~6 frames.
    if (this.pendingStages.length && this.tick % 6 === 0) {
      this.revealIndices(this.pendingStages.shift() ?? []);
    }

    const ease = this.opts.reducedMotion ? 1 : 1 - Math.pow(0.001, dt / 1000);
    let animating = false;
    for (let i = 0; i < this.state.length; i++) {
      const ds = this.stateTarget[i] - this.state[i];
      if (Math.abs(ds) > 0.002) {
        this.state[i] += ds * Math.min(1, ease * 4);
        animating = true;
      } else this.state[i] = this.stateTarget[i];
      const dr = this.revealTarget[i] - this.reveal[i];
      if (Math.abs(dr) > 0.002) {
        this.reveal[i] += dr * Math.min(1, ease * 2.2);
        animating = true;
      } else this.reveal[i] = this.revealTarget[i];
    }
    (this.geometry.getAttribute('aState') as BufferAttribute).needsUpdate = true;
    (this.geometry.getAttribute('aReveal') as BufferAttribute).needsUpdate = true;

    this.graph.edges.forEach((e, i) => {
      const vis = Math.min(this.reveal[e.source], this.reveal[e.target]);
      const emph = Math.min(this.state[e.source], this.state[e.target]);
      const base = e.type === 'in_topic' ? 0.075 : 0.05;
      const a = vis * (emph > 1.2 ? 0.5 : base * Math.min(1, emph));
      this.lineAlpha[i * 2] = a;
      this.lineAlpha[i * 2 + 1] = a;
    });
    (this.lineGeometry.getAttribute('aAlpha') as BufferAttribute).needsUpdate = true;

    this.renderer.render(this.scene, this.camera);
    this.project();

    if (this.pointer.inside && !this.pointer.down) {
      this.setHover(this.pick(this.pointer.x, this.pointer.y, 14));
    }
    if (this.hovered !== null) {
      this.opts.onHover(this.hovered, this.screen[this.hovered * 2], this.screen[this.hovered * 2 + 1]);
    } else {
      this.opts.onHover(null, 0, 0);
    }
    if (animating) this.poke(2);
  }

  private project(): void {
    const p = this.opts.positions;
    const m = this.world.matrixWorld;
    for (let i = 0; i < this.graph.nodes.length; i++) {
      if (!this.visible[i]) {
        this.screen[i * 2] = Number.NaN;
        continue;
      }
      this.tmp.set(p[i * 3], p[i * 3 + 1], p[i * 3 + 2]).applyMatrix4(m).project(this.camera);
      if (this.tmp.z > 1 || this.tmp.z < -1) {
        this.screen[i * 2] = Number.NaN;
        continue;
      }
      this.screen[i * 2] = (this.tmp.x * 0.5 + 0.5) * this.width;
      this.screen[i * 2 + 1] = (-this.tmp.y * 0.5 + 0.5) * this.height;
    }
  }

  /** Adaptive quality: lower the pixel ratio, then shed low-priority nodes. */
  private adapt(dt: number): void {
    if (dt > 26) this.slowFrames++;
    else this.slowFrames = Math.max(0, this.slowFrames - 1);
    if (this.slowFrames < 45) return;
    this.slowFrames = 0;
    if (this.pixelRatio > 0.8) {
      this.pixelRatio = Math.max(0.75, this.pixelRatio - 0.25);
      this.renderer.setPixelRatio(this.pixelRatio);
      this.material.uniforms.uPixelRatio.value = this.pixelRatio;
      this.resize();
      return;
    }
    // Hide the last 25 % of the sampled order (lowest priority), keep emphasised nodes.
    const order = this.opts.stages.flat();
    const keep = Math.floor(order.length * 0.75);
    for (const i of order.slice(keep)) {
      if (this.stateTarget[i] <= 1 && i !== this.selected) this.revealTarget[i] = 0;
    }
  }

  /** Projected screen position of node i, or null if not visible. */
  screenOf(i: number): { x: number; y: number } | null {
    const x = this.screen[i * 2];
    return Number.isNaN(x) || this.reveal[i] < 0.3 ? null : { x, y: this.screen[i * 2 + 1] };
  }

  /** Normalised depth fade for label opacity (0 far … 1 near). */
  nearness(i: number): number {
    const p = this.opts.positions;
    const d = this.tmp.set(p[i * 3], p[i * 3 + 1], p[i * 3 + 2]).applyMatrix4(this.world.matrixWorld).distanceTo(this.camera.position);
    const [near, far] = this.fade.value;
    return Math.max(0, Math.min(1, (far - d) / (far - near)));
  }
}
