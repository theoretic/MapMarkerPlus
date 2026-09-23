/** DOM helpers */

/** Set an input value and fire bubbling input + change, so PW sees the change (unsaved warning, showIf). */
export function setInputValue(input: HTMLInputElement | null, value: string): void {
  if (!input || input.value === value) return;
  input.value = value;
  input.dispatchEvent(new Event('input', { bubbles: true }));
  input.dispatchEvent(new Event('change', { bubbles: true }));
}

export function parseJson<T>(text: string | null | undefined, fallback: T): T {
  if (!text) return fallback;
  try {
    return JSON.parse(text) as T;
  } catch {
    return fallback;
  }
}

export function el<K extends keyof HTMLElementTagNameMap>(
  tag: K,
  attrs: Record<string, string> = {},
  text = '',
): HTMLElementTagNameMap[K] {
  const e = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v);
  if (text) e.textContent = text;
  return e;
}

export function debounce<A extends unknown[]>(fn: (...a: A) => void, ms: number): (...a: A) => void {
  let t: ReturnType<typeof setTimeout> | undefined;
  return (...a: A) => {
    if (t) clearTimeout(t);
    t = setTimeout(() => fn(...a), ms);
  };
}

/** Coordinates rounded to 7 decimals (the DB precision), as string without trailing zeros. */
export function fmtCoord(n: number): string {
  return String(Math.round(n * 1e7) / 1e7);
}

export function toNumber(v: unknown): number | null {
  if (v === null || v === undefined || v === '') return null;
  const n = typeof v === 'number' ? v : parseFloat(String(v).replace(',', '.'));
  return Number.isFinite(n) ? n : null;
}

/** Default marker pin (for providers without one of their own). */
export function pinElement(color = '#e5484d'): HTMLElement {
  const d = el('div', { class: 'mmp-pin' });
  d.innerHTML =
    `<svg width="27" height="41" viewBox="0 0 27 41" aria-hidden="true"><path fill="${color}" stroke="#fff" stroke-width="1.5" ` +
    'd="M13.5 1C6.6 1 1 6.6 1 13.5 1 23 13.5 40 13.5 40S26 23 26 13.5C26 6.6 20.4 1 13.5 1z"/>' +
    '<circle cx="13.5" cy="13.5" r="4.5" fill="#fff"/></svg>';
  return d;
}

export function iconElement(url: string): HTMLElement {
  const img = el('img', { src: url, alt: '', class: 'mmp-icon' });
  img.draggable = false;
  return img;
}
