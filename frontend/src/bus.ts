/**
 * Tiny typed event bus. Search, ticker and the floating labels never import
 * each other: each keeps working when another fails.
 */
export interface BusEvents {
  'search:results': { query: string; ids: string[] };
  'search:active': { id: string | null };
  'search:clear': Record<string, never>;
  'chip:hover': { id: string | null };
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
