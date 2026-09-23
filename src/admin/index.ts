/**
 * Admin entry: initializes every InputfieldMapMarkerPlus map on the page, including the ones
 * added later by ajax (repeaters, ajax-loaded fields, modals), and destroys removed ones.
 * Imported by the classic loader InputfieldMapMarkerPlus.js.
 */

import { setAssetBase } from '../core/assets';
import { MarkerEditor } from './MarkerEditor';

setAssetBase(import.meta.url);

const SELECTOR = '.InputfieldMapMarkerPlusMap';
const editors = new WeakMap<Element, MarkerEditor>();
const live = new Set<Element>();

export function initAll(root: ParentNode = document): void {
  const nodes = root instanceof Element && root.matches(SELECTOR) ? [root] : Array.from(root.querySelectorAll(SELECTOR));
  for (const node of nodes) {
    if (editors.has(node) || !(node instanceof HTMLElement)) continue;
    node.setAttribute('data-mmp-ready', '1');
    const editor = new MarkerEditor(node);
    editors.set(node, editor);
    live.add(node);
    editor.init();
  }
}

function sweep(): void {
  for (const node of live) {
    if (!node.isConnected) {
      editors.get(node)?.destroy();
      editors.delete(node);
      live.delete(node);
    }
  }
}

let observer: MutationObserver | null = null;

export function start(): void {
  initAll();
  if (observer) return;
  observer = new MutationObserver((records) => {
    let removed = false;
    for (const r of records) {
      r.addedNodes.forEach((n) => {
        if (n instanceof Element) initAll(n);
      });
      if (r.removedNodes.length) removed = true;
    }
    if (removed) sweep();
  });
  observer.observe(document.body, { childList: true, subtree: true });
}

export const version = '1.0.0';
