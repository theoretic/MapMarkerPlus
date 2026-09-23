/** Loading of external scripts and of the module's own asset files. */

let assetBase = '';

/** Called by the entry modules with their own URL, so chunks can find assets/dist/ files. */
export function setAssetBase(entryUrl: string): void {
  if (!assetBase) assetBase = new URL('./', entryUrl).href;
}

export function assetUrl(file: string): string {
  return assetBase + file;
}

const scripts = new Map<string, Promise<void>>();

/** Load a classic script once per URL; resolves when `ready()` is true (or on load when omitted). */
export function loadScript(url: string, ready?: () => boolean): Promise<void> {
  if (ready && ready()) return Promise.resolve();
  let p = scripts.get(url);
  if (p) return p;
  p = new Promise<void>((resolve, reject) => {
    const s = document.createElement('script');
    s.src = url;
    s.async = true;
    s.onload = () => resolve();
    s.onerror = () => {
      scripts.delete(url);
      reject(new Error(`Failed to load ${url.replace(/([?&](api)?key=)[^&]+/i, '$1…')}`));
    };
    document.head.appendChild(s);
  });
  scripts.set(url, p);
  return p;
}

const styles = new Set<string>();

/** Add a stylesheet once per URL, resolves when loaded (or failed: styles are not critical). */
export function loadStyle(url: string): Promise<void> {
  if (styles.has(url) || document.querySelector(`link[rel="stylesheet"][href="${CSS.escape(url)}"]`)) {
    styles.add(url);
    return Promise.resolve();
  }
  styles.add(url);
  return new Promise<void>((resolve) => {
    const l = document.createElement('link');
    l.rel = 'stylesheet';
    l.href = url;
    l.onload = () => resolve();
    l.onerror = () => resolve();
    document.head.appendChild(l);
  });
}

/** Add CSS text once per id. */
export function injectCss(id: string, css: string): void {
  if (document.getElementById(id)) return;
  const s = document.createElement('style');
  s.id = id;
  s.textContent = css;
  document.head.appendChild(s);
}
