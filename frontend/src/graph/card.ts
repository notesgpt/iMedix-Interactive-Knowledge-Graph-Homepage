/**
 * Details card for the selected node. Pure DOM (textContent only), so it can
 * never render HTML from data.
 */
import type { KGraph, KNode } from '../types';
import { neighbours } from '../data/graph';

const TYPE_LABEL: Record<string, string> = { drug: 'Drug', disease: 'Disease', topic: 'Editorial topic' };
const MAX_MEMBERS = 14;

export class NodeCard {
  private title: HTMLElement;
  private type: HTMLElement;
  private meta: HTMLElement;
  private desc: HTMLElement;
  private links: HTMLElement;
  private cta: HTMLAnchorElement;

  constructor(
    private el: HTMLElement,
    private graph: KGraph,
    private onPick: (index: number | null) => void,
  ) {
    this.title = el.querySelector('[data-ku-card-title]')!;
    this.type = el.querySelector('[data-ku-card-type]')!;
    this.meta = el.querySelector('[data-ku-card-meta]')!;
    this.desc = el.querySelector('[data-ku-card-desc]')!;
    this.links = el.querySelector('[data-ku-card-links]')!;
    this.cta = el.querySelector('[data-ku-card-cta]')!;
    el.querySelector('[data-ku-card-close]')?.addEventListener('click', () => this.onPick(null));
  }

  get open(): boolean {
    return !this.el.hidden;
  }

  show(index: number | null): void {
    if (index === null) {
      this.el.hidden = true;
      return;
    }
    const node = this.graph.nodes[index];
    this.el.dataset.type = node.type;
    this.type.replaceChildren(glyph(node.type), document.createTextNode(TYPE_LABEL[node.type] ?? node.type));
    this.title.textContent = node.name;
    this.desc.hidden = !node.description;
    this.desc.textContent = node.description ?? '';
    this.links.replaceChildren();

    if (node.type === 'topic') this.renderTopic(node);
    else this.renderEntity(node);

    this.el.hidden = false;
  }

  private renderTopic(node: KNode): void {
    const members = neighbours(this.graph, node.index, 'in_topic')
      .map((i) => this.graph.nodes[i])
      .filter((m) => m.url)
      .sort(rank);
    this.meta.textContent = `${members.length} ${members.length === 1 ? 'guide' : 'guides'} grouped under this editorial topic.`;
    const group = this.group('Guides in this topic');
    const list = group.querySelector('ul')!;
    members.slice(0, MAX_MEMBERS).forEach((m) => list.append(this.linkItem(m)));
    if (members.length > MAX_MEMBERS) {
      const more = document.createElement('p');
      more.className = 'ku-card__group-note';
      more.textContent = `+${members.length - MAX_MEMBERS} more. Use the search to find a specific guide.`;
      group.append(more);
    }
    this.note(group, 'Editorial topics organise the library. They are not treatment recommendations.');
    this.links.append(group);
    this.cta.hidden = true;
  }

  private renderEntity(node: KNode): void {
    const topics = neighbours(this.graph, node.index, 'in_topic').map((i) => this.graph.nodes[i]);
    const out: KNode[] = [];
    const inc: KNode[] = [];
    for (const ei of this.graph.incident[node.index]) {
      const e = this.graph.edges[ei];
      if (e.type !== 'links_to') continue;
      if (e.source === node.index) out.push(this.graph.nodes[e.target]);
      else inc.push(this.graph.nodes[e.source]);
    }

    this.meta.textContent = topics.length
      ? `Part of ${topics.length} editorial ${topics.length === 1 ? 'topic' : 'topics'}.`
      : 'Not grouped under an editorial topic yet.';

    if (topics.length) {
      const g = this.group('Editorial topics');
      const list = g.querySelector('ul')!;
      topics.forEach((t) => list.append(this.topicButton(t)));
      this.note(g, 'Grouping used by the iMedix editorial team. It does not mean this medicine treats these conditions.');
      this.links.append(g);
    }
    if (out.length) {
      const g = this.group('Linked from this guide');
      out.sort(rank).slice(0, 10).forEach((m) => g.querySelector('ul')!.append(this.linkItem(m)));
      this.links.append(g);
    }
    if (inc.length) {
      const g = this.group('Guides that link here');
      inc.sort(rank).slice(0, 10).forEach((m) => g.querySelector('ul')!.append(this.linkItem(m)));
      this.links.append(g);
    }

    this.cta.hidden = !node.url;
    if (node.url) {
      this.cta.href = node.url;
      this.cta.setAttribute('aria-label', `Read the ${node.name} guide`);
    }
  }

  private group(title: string): HTMLElement {
    const div = document.createElement('div');
    div.className = 'ku-card__group';
    const h = document.createElement('p');
    h.className = 'ku-card__group-title';
    h.textContent = title;
    const ul = document.createElement('ul');
    ul.className = 'ku-card__list';
    div.append(h, ul);
    return div;
  }

  private note(group: HTMLElement, text: string): void {
    const p = document.createElement('p');
    p.className = 'ku-card__group-note';
    p.textContent = text;
    group.append(p);
  }

  private linkItem(node: KNode): HTMLLIElement {
    const li = document.createElement('li');
    const a = document.createElement('a');
    a.className = 'ku-card__item';
    a.href = node.url ?? '#';
    a.append(glyph(node.type), document.createTextNode(node.name));
    li.append(a);
    return li;
  }

  private topicButton(node: KNode): HTMLLIElement {
    const li = document.createElement('li');
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'ku-card__item';
    b.append(glyph('topic'), document.createTextNode(node.name));
    b.addEventListener('click', () => this.onPick(node.index));
    li.append(b);
    return li;
  }
}

function glyph(type: string): HTMLSpanElement {
  const s = document.createElement('span');
  s.className = `ku-glyph ku-glyph--${type}`;
  s.setAttribute('aria-hidden', 'true');
  return s;
}

function rank(a: KNode, b: KNode): number {
  return (
    Number(b.type === 'disease') - Number(a.type === 'disease') ||
    Number(b.status === 'completed') - Number(a.status === 'completed') ||
    b.degree - a.degree ||
    a.name.localeCompare(b.name)
  );
}
