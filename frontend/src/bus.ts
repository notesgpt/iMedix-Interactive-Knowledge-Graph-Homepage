/**
 * Tiny typed event bus. Search, ticker and graph never import each other:
 * search keeps working when the graph (WebGL) is unavailable.
 */
export interface BusEvents {
  'search:results': { query: string; ids: string[] };
  'search:active': { id: string | null };
  'search:clear': Record<string, never>;
  'graph:ready': { mode: 'webgl' | 'fallback' };
  'graph:select': { id: string | null };
  'chip:hover': { id: string | null };
  'map:show': { id: string };
}

type Handler<K extends keyof BusEvents> = (detail: BusEvents[K]) => void;

export class Bus {
  private target = new EventTarget();

  emit<K extends keyof BusEvents>(type: K, detail: BusEvents[K]): void {
    this.target.dispatchEvent(new CustomEvent(type, { detail }));
  }

  on<K extends keyof BusEvents>(type: K, handler: Handler<K>): () => void {
    const listener = (e: Event) => handler((e as CustomEvent<BusEvents[K]>).detail);
    this.target.addEventListener(type, listener);
    return () => this.target.removeEventListener(type, listener);
  }
}
