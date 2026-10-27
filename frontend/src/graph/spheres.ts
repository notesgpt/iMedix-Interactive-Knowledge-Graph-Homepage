/**
 * "Specimen gallery" — the Knowledge Universe as large, materially distinct 3D
 * spheres floating around the search.
 *
 * Rendering budget: one shared sphere geometry, one InstancedMesh per category
 * (drug / disease / topic) with per-instance colour, one instanced soft-shadow
 * layer and, only when something is selected, a few relationship arcs — about
 * four draw calls regardless of how many spheres are visible. Reflections come
 * from a procedural RoomEnvironment (no HDR download); depth from scene fog in
 * the hero's background colour.
 */
import {
  ACESFilmicToneMapping,
  BufferGeometry,
  Color,
  DirectionalLight,
  DynamicDrawUsage,
  Fog,
  Group,
  HemisphereLight,
  InstancedMesh,
  Line,
  LineBasicMaterial,
  Matrix4,
  MeshBasicMaterial,
  MeshPhysicalMaterial,
  MeshStandardMaterial,
  type Material,
  Object3D,
  PerspectiveCamera,
  PlaneGeometry,
  PMREMGenerator,
  QuadraticBezierCurve3,
  Quaternion,
  Raycaster,
  Scene,
  SphereGeometry,
  SRGBColorSpace,
  Vector2,
  Vector3,
  WebGLRenderer,
} from 'three';
import { RoomEnvironment } from 'three/examples/jsm/environments/RoomEnvironment.js';
import type { KGraph } from '../types';
import { neighbours } from '../data/graph';
import type { GraphPalette } from './palette';
import { colourFor } from './palette';
import { capsuleBands, cells, softShadow } from './textures';
import { layoutSpheres, matchSlots, pickSpheres, toWorld, type SafeBox, type SphereItem, type View } from './sphere-layout';

export interface SphereOptions {
  count: number;
  palette: GraphPalette;
  reducedMotion: boolean;
  tier: 'high' | 'medium' | 'low';
  getSafe: () => SafeBox;
  getAvoid: () => SafeBox[];
  /** Where (normalised stage coords) a selected sphere should sit, clear of the details card. */
  getFocusShift: () => { x: number; y: number };
  onHover: (index: number | null) => void;
  onSelect: (index: number | null) => void;
  onContextLost: () => void;
}

const CATEGORIES = ['topic', 'disease', 'drug'] as const;
type Category = (typeof CATEGORIES)[number];
const SPARE = 10; // per-category capacity for spheres injected by search/selection
const FOV = 38;
const CAM_DIST = 120;

interface Sphere {
  index: number;
  cat: Category;
  instance: number;
  radius: number;
  colour: Color;
  home: Vector3;
  target: Vector3;
  pos: Vector3;
  scale: number;
  scaleTarget: number;
  mix: number; // 0 = full colour, 1 = faded into the background
  mixTarget: number;
  phase: number;
  spin: number;
  injected: boolean;
  featured: boolean;
}

export class SphereScene {
  readonly canvas: HTMLCanvasElement;
  private renderer: WebGLRenderer;
  private scene = new Scene();
  private world = new Group();
  private camera = new PerspectiveCamera(FOV, 1, 1, 600);
  private meshes = new Map<Category, InstancedMesh>();
  private shadows: InstancedMesh;
  private spheres: Sphere[] = [];
  private byEntity = new Map<number, Sphere>();
  private free = new Map<Category, number[]>();
  private items: SphereItem[];
  private view: View = { aspect: 1, fovDeg: FOV, camDist: CAM_DIST };
  private background: Color;
  private arcs: Line[] = [];
  private raycaster = new Raycaster();
  private pointer = new Vector2(2, 2);
  private pointerPx = { x: 0, y: 0, inside: false, down: false, moved: 0, startX: 0, yaw: 0 };
  private yaw = 0;
  private yawTarget = 0;
  private parallax = new Vector2();
  private camTarget = new Vector3();
  private camLook = new Vector3();
  private focusShift = { x: 0, y: 0 };
  private camPos = new Vector3(0, 0, CAM_DIST);
  private zoom = 1;
  private hovered: Sphere | null = null;
  private selected: Sphere | null = null;
  private matches: Sphere[] = [];
  private motion: boolean;
  private running = true;
  private onScreen = true;
  private frame = 0;
  private last = 0;
  private slow = 0;
  private pixelRatio: number;
  private width = 1;
  private height = 1;
  private dummy = new Object3D();
  private tmp = new Vector3();
  private disposed = false;
  private degraded = false;
  private skip = 0;
  private observers: { disconnect(): void }[] = [];

  constructor(
    private container: HTMLElement,
    private graph: KGraph,
    private opts: SphereOptions,
  ) {
    this.motion = !opts.reducedMotion;
    this.background = new Color(opts.palette.background);
    this.pixelRatio = Math.min(window.devicePixelRatio || 1, opts.tier === 'high' ? 1.75 : opts.tier === 'medium' ? 1.5 : 1);

    this.renderer = new WebGLRenderer({ antialias: opts.tier !== 'low', alpha: true, powerPreference: 'high-performance' });
    this.renderer.setPixelRatio(this.pixelRatio);
    this.renderer.setClearColor(0x000000, 0);
    this.renderer.outputColorSpace = SRGBColorSpace;
    this.renderer.toneMapping = ACESFilmicToneMapping;
    this.renderer.toneMappingExposure = 1.05;
    this.canvas = this.renderer.domElement;
    this.canvas.setAttribute('role', 'img');
    this.canvas.setAttribute(
      'aria-label',
      'Interactive 3D view of iMedix drug, disease and topic guides. Use the search field or the library below to open a guide.',
    );
    container.append(this.canvas);

    // Lighting: procedural studio environment for reflections + a soft key light.
    const pmrem = new PMREMGenerator(this.renderer);
    this.scene.environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
    pmrem.dispose();
    this.scene.add(new HemisphereLight(0xffffff, 0xdbe4ed, 0.55));
    const key = new DirectionalLight(0xffffff, 1.35);
    key.position.set(-40, 60, 80);
    this.scene.add(key);
    this.scene.fog = new Fog(this.background, CAM_DIST + 10, CAM_DIST + 120);
    this.scene.add(this.world);

    // One geometry, three material families.
    const segments = opts.tier === 'high' ? 64 : opts.tier === 'medium' ? 40 : 28;
    const geometry = new SphereGeometry(1, segments, Math.round(segments * 0.66));
    const materials = this.buildMaterials(opts.tier);

    this.items = pickSpheres(graph, opts.count);
    const counts: Record<Category, number> = { topic: 0, disease: 0, drug: 0 };
    for (const it of this.items) counts[(it.type as Category) ?? 'drug']++;
    for (const cat of CATEGORIES) {
      const capacity = counts[cat] + SPARE;
      const mesh = new InstancedMesh(geometry, materials[cat], capacity);
      mesh.instanceMatrix.setUsage(DynamicDrawUsage);
      mesh.frustumCulled = false;
      for (let i = 0; i < capacity; i++) mesh.setColorAt(i, new Color(0xffffff));
      mesh.userData.cat = cat;
      this.meshes.set(cat, mesh);
      this.world.add(mesh);
      this.free.set(cat, []);
    }
    const totalCapacity = this.items.length + SPARE * 3;
    this.shadows = new InstancedMesh(
      new PlaneGeometry(1, 1),
      new MeshBasicMaterial({ map: softShadow(), transparent: true, depthWrite: false, opacity: 0.32 }),
      totalCapacity,
    );
    this.shadows.frustumCulled = false;
    this.shadows.renderOrder = -1;
    this.world.add(this.shadows);

    const next: Record<Category, number> = { topic: 0, disease: 0, drug: 0 };
    for (const it of this.items) {
      const cat = it.type as Category;
      this.spheres.push(this.makeSphere(it.index, cat, next[cat]++, it.radius, it.variant, false, it.featured));
    }
    for (const cat of CATEGORIES) {
      const cap = counts[cat] + SPARE;
      for (let i = next[cat]; i < cap; i++) this.free.get(cat)!.push(i);
      this.writeHidden(cat, next[cat], cap);
    }

    this.bind();
    this.resize();
    this.loop = this.loop.bind(this);
    this.frame = requestAnimationFrame(this.loop);
  }

  /* ---------------------------------------------------------------- API */

  /** Visible spheres whose labels should show without interaction. */
  featured(): number[] {
    return this.spheres.filter((s) => s.featured && s.scale > 0.5).map((s) => s.index);
  }

  /** Screen position (CSS px) and projected radius of a visible entity. */
  screenOf(index: number): { x: number; y: number; r: number } | null {
    const s = this.byEntity.get(index);
    if (!s || s.scale < 0.3) return null;
    this.tmp.copy(s.pos).applyMatrix4(this.world.matrixWorld);
    const edge = this.tmp.clone().add(new Vector3(0, s.radius * s.scale, 0));
    this.tmp.project(this.camera);
    edge.project(this.camera);
    if (this.tmp.z > 1) return null;
    const x = (this.tmp.x * 0.5 + 0.5) * this.width;
    const y = (-this.tmp.y * 0.5 + 0.5) * this.height;
    const ey = (-edge.y * 0.5 + 0.5) * this.height;
    return { x, y, r: Math.abs(y - ey) };
  }

  /** Bring matches forward, beside the search block; fade everything else. */
  setMatches(indices: number[]): void {
    for (const m of this.matches) if (!indices.includes(m.index)) this.release(m);
    const top = indices.slice(0, 6);
    const slots = matchSlots(this.opts.getSafe(), this.view, top.length).map((p) => {
      const w = toWorld(p, this.view);
      return new Vector3(w.x, w.y, w.z);
    });
    this.matches = [];
    top.forEach((index, k) => {
      const s = this.ensure(index, slots[k] ?? new Vector3());
      if (!s) return;
      s.target.copy(slots[k] ?? s.home);
      s.scaleTarget = k === 0 ? 1.5 : 1.15;
      s.mixTarget = 0;
      this.matches.push(s);
    });
    this.restyle();
  }

  setFocus(index: number | null): void {
    this.hovered = index === null ? null : (this.byEntity.get(index) ?? null);
    this.restyle();
  }

  select(index: number | null): void {
    this.clearArcs();
    if (this.selected?.injected && this.selected.index !== index && !this.matches.includes(this.selected)) this.release(this.selected);
    if (index === null) {
      this.selected = null;
      this.camTarget.set(0, 0, 0);
      this.focusShift = { x: 0, y: 0 };
      this.restyle();
      this.opts.onSelect(null);
      return;
    }
    const s = this.ensure(index, new Vector3(0, 0, 10));
    if (!s) return;
    this.selected = s;
    // Reveal verified relationships contextually: arcs to related spheres (injecting a few).
    const related = neighbours(this.graph, index).slice(0, 8);
    related.forEach((j, k) => {
      const angle = (k / Math.max(1, related.length)) * Math.PI * 2;
      const near = s.target.clone().add(new Vector3(Math.cos(angle) * s.radius * 3.2, Math.sin(angle) * s.radius * 2.4, -4));
      const r = this.ensure(j, near);
      if (r) this.arc(s, r);
    });
    this.camTarget.copy(s.target);
    this.restyle();
    this.opts.onSelect(index);
    this.focusShift = this.opts.getFocusShift();
  }

  get selection(): number | null {
    return this.selected?.index ?? null;
  }

  zoomBy(factor: number): void {
    this.zoom = Math.min(1.6, Math.max(0.55, this.zoom * factor));
  }

  reset(): void {
    this.zoom = 1;
    this.yawTarget = 0;
    this.select(null);
  }

  setMotion(on: boolean): void {
    this.motion = on && !this.opts.reducedMotion;
  }

  get moving(): boolean {
    return this.motion;
  }

  /** Recompute the composition (safe box or size changed). */
  relayout(): void {
    const placements = layoutSpheres(this.items, this.view, this.opts.getSafe(), this.opts.getAvoid());
    this.items.forEach((it, k) => {
      const s = this.byEntity.get(it.index);
      if (!s || s.injected) return;
      const w = toWorld(placements[k], this.view);
      s.home.set(w.x, w.y, w.z);
      if (!this.matches.includes(s)) s.target.copy(s.home);
    });
  }

  dispose(): void {
    if (this.disposed) return;
    this.disposed = true;
    cancelAnimationFrame(this.frame);
    this.observers.forEach((o) => o.disconnect());
    this.clearArcs();
    this.meshes.forEach((m) => {
      (m.material as MeshStandardMaterial).map?.dispose();
      (m.material as MeshStandardMaterial).dispose();
    });
    this.meshes.values().next().value?.geometry.dispose();
    this.shadows.geometry.dispose();
    (this.shadows.material as MeshBasicMaterial).map?.dispose();
    (this.shadows.material as MeshBasicMaterial).dispose();
    this.scene.environment?.dispose();
    this.renderer.dispose();
    this.canvas.remove();
  }

  /* ---------------------------------------------------------- internals */

  private makeSphere(index: number, cat: Category, instance: number, radius: number, variant: number, injected: boolean, featured: boolean): Sphere {
    const colour = new Color(colourFor(this.opts.palette, cat, variant));
    const s: Sphere = {
      index,
      cat,
      instance,
      radius,
      colour,
      home: new Vector3(),
      target: new Vector3(),
      pos: new Vector3(),
      scale: injected ? 0 : this.opts.reducedMotion ? 1 : 0,
      scaleTarget: 1,
      mix: 0,
      mixTarget: 0,
      phase: (index * 2.399) % (Math.PI * 2),
      spin: 0.05 + ((index * 7) % 10) * 0.012,
      injected,
      featured,
    };
    this.byEntity.set(index, s);
    return s;
  }

  /** Visible sphere for an entity, injecting one from spare capacity if needed. */
  private ensure(index: number, at: Vector3): Sphere | null {
    const existing = this.byEntity.get(index);
    if (existing) return existing;
    const node = this.graph.nodes[index];
    const cat = (CATEGORIES as readonly string[]).includes(node.type) ? (node.type as Category) : 'drug';
    const instance = this.free.get(cat)!.shift();
    if (instance === undefined) return null;
    const radius = cat === 'topic' ? 6 : cat === 'disease' ? 3.8 : 3.4;
    const s = this.makeSphere(index, cat, instance, radius, index % 3, true, false);
    s.home.copy(at);
    s.target.copy(at);
    s.pos.copy(at).add(new Vector3(0, 0, -30));
    this.spheres.push(s);
    return s;
  }

  private release(s: Sphere): void {
    if (!s.injected) {
      s.target.copy(s.home);
      return;
    }
    // Injected spheres shrink away and their instance is freed in the loop.
    s.scaleTarget = 0;
  }

  private restyle(): void {
    const focus = new Set<Sphere>();
    if (this.selected) {
      focus.add(this.selected);
      neighbours(this.graph, this.selected.index).forEach((j) => {
        const r = this.byEntity.get(j);
        if (r) focus.add(r);
      });
    }
    this.matches.forEach((m) => focus.add(m));
    const dim = focus.size > 0;
    for (const s of this.spheres) {
      const isMatch = this.matches.includes(s);
      if (!isMatch && !(s.injected && s.scaleTarget === 0)) {
        s.scaleTarget = s === this.selected ? 1.25 : 1;
        if (!this.matches.length && !this.selected) s.target.copy(s.home);
      }
      if (s === this.hovered && s.scaleTarget > 0) s.scaleTarget = Math.max(s.scaleTarget, 1.12);
      s.mixTarget = dim && !focus.has(s) ? 0.72 : 0;
      if (dim && !focus.has(s) && s.scaleTarget > 0) s.scaleTarget = Math.min(s.scaleTarget, 0.85);
    }
  }

  private arc(a: Sphere, b: Sphere): void {
    const mid = a.target.clone().add(b.target).multiplyScalar(0.5).add(new Vector3(0, 4, 6));
    const curve = new QuadraticBezierCurve3(a.target.clone(), mid, b.target.clone());
    const geometry = new BufferGeometry().setFromPoints(curve.getPoints(32));
    const line = new Line(geometry, new LineBasicMaterial({ color: this.opts.palette.edge, transparent: true, opacity: 0.85 }));
    line.userData = { a, b, curve };
    this.arcs.push(line);
    this.world.add(line);
  }

  private clearArcs(): void {
    for (const l of this.arcs) {
      this.world.remove(l);
      l.geometry.dispose();
      (l.material as LineBasicMaterial).dispose();
    }
    this.arcs = [];
  }

  private writeHidden(cat: Category, from: number, to: number): void {
    const mesh = this.meshes.get(cat)!;
    const zero = new Matrix4().makeScale(0, 0, 0);
    for (let i = from; i < to; i++) mesh.setMatrixAt(i, zero);
    mesh.instanceMatrix.needsUpdate = true;
  }

  private bind(): void {
    const c = this.canvas;
    c.addEventListener('pointermove', (e) => {
      const r = c.getBoundingClientRect();
      this.pointerPx.x = e.clientX - r.left;
      this.pointerPx.y = e.clientY - r.top;
      this.pointerPx.inside = true;
      this.pointer.set((this.pointerPx.x / r.width) * 2 - 1, -(this.pointerPx.y / r.height) * 2 + 1);
      if (this.pointerPx.down) {
        const dx = e.clientX - this.pointerPx.startX;
        this.pointerPx.moved = Math.max(this.pointerPx.moved, Math.abs(dx));
        this.yawTarget = Math.max(-0.45, Math.min(0.45, this.pointerPx.yaw + dx * 0.004));
      }
    });
    c.addEventListener('pointerleave', () => {
      this.pointerPx.inside = false;
      this.pointer.set(2, 2);
      this.setHoverSphere(null);
    });
    c.addEventListener('pointerdown', (e) => {
      this.pointerPx.down = true;
      this.pointerPx.moved = 0;
      this.pointerPx.startX = e.clientX;
      this.pointerPx.yaw = this.yawTarget;
    });
    c.addEventListener('pointerup', (e) => {
      this.pointerPx.down = false;
      if (this.pointerPx.moved > 6) return;
      const r = c.getBoundingClientRect();
      this.pointer.set(((e.clientX - r.left) / r.width) * 2 - 1, -((e.clientY - r.top) / r.height) * 2 + 1);
      const hit = this.pick();
      if (hit || this.selected) this.select(hit ? hit.index : null);
    });
    c.addEventListener(
      'wheel',
      (e) => {
        if (!(e.ctrlKey || e.metaKey)) return; // never hijack page scroll
        e.preventDefault();
        this.zoomBy(e.deltaY > 0 ? 0.92 : 1.08);
      },
      { passive: false },
    );
    c.addEventListener('webglcontextlost', (e) => {
      e.preventDefault();
      this.opts.onContextLost();
    });
    const ro = new ResizeObserver(() => this.resize());
    ro.observe(this.container);
    const io = new IntersectionObserver(([entry]) => (this.onScreen = entry.isIntersecting));
    io.observe(this.container);
    this.observers.push(ro, io);
    document.addEventListener('visibilitychange', () => {
      this.running = document.visibilityState === 'visible';
      this.last = 0;
    });
  }

  private pick(): Sphere | null {
    this.raycaster.setFromCamera(this.pointer, this.camera);
    // Instances move every frame; InstancedMesh caches its bounds on first raycast.
    for (const m of this.meshes.values()) m.computeBoundingSphere();
    const hits = this.raycaster.intersectObjects([...this.meshes.values()], false);
    for (const h of hits) {
      const cat = h.object.userData.cat as Category;
      const s = this.spheres.find((x) => x.cat === cat && x.instance === h.instanceId && x.scale > 0.3);
      if (s) return s;
    }
    return null;
  }

  private setHoverSphere(s: Sphere | null): void {
    if (s === this.hovered) return;
    this.hovered = s;
    this.canvas.style.cursor = s ? 'pointer' : '';
    this.restyle();
    this.opts.onHover(s ? s.index : null);
  }

  private resize(): void {
    const w = Math.max(1, this.container.clientWidth);
    const h = Math.max(1, this.container.clientHeight);
    this.width = w;
    this.height = h;
    this.renderer.setSize(w, h, false);
    this.camera.aspect = w / h;
    this.camera.updateProjectionMatrix();
    this.view = { aspect: w / h, fovDeg: FOV, camDist: CAM_DIST };
    this.relayout();
    if (this.opts.reducedMotion) this.spheres.forEach((s) => s.pos.copy(s.target));
  }

  private loop(time: number): void {
    if (this.disposed) return;
    this.frame = requestAnimationFrame(this.loop);
    if (!this.running || !this.onScreen) return;
    if ((this.opts.tier === 'low' || this.degraded) && this.skip++ % 2 === 1) return;
    const dt = this.last ? Math.min(0.1, (time - this.last) / 1000) : 1 / 60;
    this.last = time;
    this.adapt(dt);
    const t = time / 1000;
    const k = this.opts.reducedMotion ? 1 : 1 - Math.exp(-dt * 3.2);
    const kFast = this.opts.reducedMotion ? 1 : 1 - Math.exp(-dt * 6);

    // Camera: parallax + drag yaw + focus on the selected sphere.
    if (this.motion && this.pointerPx.inside && !this.selected) this.parallax.lerp(this.pointer, kFast * 0.5);
    else this.parallax.lerp(new Vector2(), kFast * 0.5);
    this.yaw += (this.yawTarget - this.yaw) * kFast;
    this.world.rotation.y = this.yaw;
    this.world.updateMatrixWorld();
    const dist = this.selected ? this.selected.radius * 7 + 18 : CAM_DIST / this.zoom;
    const focus = this.selected ? this.tmp.copy(this.selected.pos).applyMatrix4(this.world.matrixWorld) : this.tmp.set(0, 0, 0);
    if (this.selected) {
      // Look past the sphere so it lands in the free area beside the details card.
      const halfH = dist * Math.tan((FOV * Math.PI) / 360);
      focus.x -= this.focusShift.x * halfH * this.view.aspect;
      focus.y -= this.focusShift.y * halfH;
    }
    this.camLook.lerp(focus, k);
    this.camPos.lerp(new Vector3(this.camLook.x + this.parallax.x * 4, this.camLook.y + this.parallax.y * 2.5, this.camLook.z + dist), k);
    this.camera.position.copy(this.camPos);
    this.camera.lookAt(this.camLook);

    // Hover via ray cast (cheap: ~60 instances).
    if (this.pointerPx.inside && !this.pointerPx.down) this.setHoverSphere(this.pick());

    const toFree: Sphere[] = [];
    let shadowIndex = 0;
    const q = new Quaternion();
    for (const s of this.spheres) {
      s.pos.lerp(s.target, k);
      s.scale += (s.scaleTarget - s.scale) * kFast;
      s.mix += (s.mixTarget - s.mix) * kFast;
      if (s.injected && s.scaleTarget === 0 && s.scale < 0.02) {
        toFree.push(s);
        continue;
      }
      const bob = this.motion ? Math.sin(t * 0.55 + s.phase) * 0.7 : 0;
      const sway = this.motion ? Math.cos(t * 0.37 + s.phase) * 0.35 : 0;
      this.dummy.position.set(s.pos.x + sway, s.pos.y + bob, s.pos.z);
      this.dummy.rotation.set(0.35, this.motion ? t * s.spin + s.phase : s.phase, 0);
      this.dummy.scale.setScalar(Math.max(0.0001, s.radius * s.scale));
      this.dummy.updateMatrix();
      const mesh = this.meshes.get(s.cat)!;
      mesh.setMatrixAt(s.instance, this.dummy.matrix);
      mesh.setColorAt(s.instance, s.colour.clone().lerp(this.background, s.mix));

      // Soft grounding halo, slightly below and behind the sphere.
      const r = s.radius * s.scale;
      this.dummy.position.set(s.pos.x + sway + r * 0.15, s.pos.y + bob - r * 0.55, s.pos.z - r * 1.1);
      this.dummy.rotation.set(0, 0, 0);
      this.dummy.quaternion.copy(q);
      this.dummy.scale.set(r * 2.9 * (1 - s.mix * 0.6), r * 2.2 * (1 - s.mix * 0.6), 1);
      this.dummy.updateMatrix();
      this.shadows.setMatrixAt(shadowIndex++, this.dummy.matrix);
    }
    for (const s of toFree) this.releaseSlot(s);
    const zero = new Matrix4().makeScale(0, 0, 0);
    for (let i = shadowIndex; i < this.shadows.count; i++) this.shadows.setMatrixAt(i, zero);
    this.shadows.instanceMatrix.needsUpdate = true;
    this.meshes.forEach((m) => {
      m.instanceMatrix.needsUpdate = true;
      if (m.instanceColor) m.instanceColor.needsUpdate = true;
    });

    // Arcs follow their spheres.
    for (const l of this.arcs) {
      const { a, b, curve } = l.userData as { a: Sphere; b: Sphere; curve: QuadraticBezierCurve3 };
      curve.v0.copy(a.pos);
      curve.v2.copy(b.pos);
      curve.v1.copy(a.pos).add(b.pos).multiplyScalar(0.5).add(new Vector3(0, 4, 6));
      l.geometry.setFromPoints(curve.getPoints(32));
    }

    this.renderer.render(this.scene, this.camera);
  }

  private releaseSlot(s: Sphere): void {
    const mesh = this.meshes.get(s.cat)!;
    mesh.setMatrixAt(s.instance, new Matrix4().makeScale(0, 0, 0));
    this.free.get(s.cat)!.push(s.instance);
    this.spheres.splice(this.spheres.indexOf(s), 1);
    if (this.byEntity.get(s.index) === s) this.byEntity.delete(s.index);
    this.matches = this.matches.filter((m) => m !== s);
  }

  /**
   * Material families per quality tier. High: polished ceramic, satin sheen and
   * iridescent glass. Medium drops sheen/iridescence. Low (software GL, weak
   * devices): standard PBR with environment reflections only.
   */
  private buildMaterials(tier: 'high' | 'medium' | 'low'): Record<Category, MeshStandardMaterial> {
    const bands = capsuleBands();
    const cellMap = cells();
    if (tier === 'low') {
      return {
        drug: new MeshStandardMaterial({ map: bands, roughness: 0.25, metalness: 0.05, envMapIntensity: 1.1 }),
        disease: new MeshStandardMaterial({ map: cellMap, roughness: 0.55, metalness: 0, envMapIntensity: 0.9 }),
        topic: new MeshStandardMaterial({ roughness: 0.08, metalness: 0.35, envMapIntensity: 1.7 }),
      };
    }
    const rich = tier === 'high';
    return {
      drug: new MeshPhysicalMaterial({ map: bands, roughness: 0.22, metalness: 0, clearcoat: 1, clearcoatRoughness: 0.08, envMapIntensity: 1.1 }),
      disease: new MeshPhysicalMaterial({ map: cellMap, roughness: 0.5, metalness: 0, sheen: rich ? 1 : 0, sheenRoughness: 0.45, sheenColor: new Color('#ffd9cf'), clearcoat: 0.35, clearcoatRoughness: 0.4, envMapIntensity: 0.9 }),
      topic: new MeshPhysicalMaterial({ roughness: 0.04, metalness: 0.18, clearcoat: 1, clearcoatRoughness: 0.02, iridescence: rich ? 1 : 0, iridescenceIOR: 1.4, iridescenceThicknessRange: [220, 640], envMapIntensity: 1.6 }),
    };
  }

  /** Swap every family to the cheapest materials (runtime safety net). */
  private downgrade(): void {
    if (this.degraded) return;
    this.degraded = true;
    const low = this.buildMaterials('low');
    this.meshes.forEach((mesh, cat) => {
      const old = mesh.material as Material;
      mesh.material = low[cat];
      old.dispose();
    });
  }

  /** Adaptive quality: sustained slow frames lower the pixel ratio. */
  private adapt(dt: number): void {
    if (dt > 0.028) this.slow++;
    else this.slow = Math.max(0, this.slow - 1);
    if (this.slow < 40) return;
    this.slow = 0;
    if (this.pixelRatio <= 0.85) {
      this.downgrade();
      return;
    }
    this.pixelRatio = Math.max(0.75, this.pixelRatio - 0.25);
    this.renderer.setPixelRatio(this.pixelRatio);
    this.resize();
  }
}
