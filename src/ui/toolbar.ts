import type { MapProvider } from '../core/MapProvider';
import { el } from '../core/dom';

export interface ToolbarOptions {
  allowModeToggle: boolean;
  allowStyleSwitch: boolean;
  styles: Record<string, string>;
  labels?: { mode2d?: string; mode3d?: string; style?: string };
  onModeChange?: (mode: '2d' | '3d') => void;
  onStyleChange?: (style: string) => void;
}

/** 2D/3D switch and style select on top of the map. Returns the toolbar element or null when nothing to show. */
export function createToolbar(root: HTMLElement, provider: MapProvider, o: ToolbarOptions): HTMLElement | null {
  const showMode = o.allowModeToggle && provider.supports3d();
  const styleIds = Object.keys(o.styles);
  const showStyle = o.allowStyleSwitch && styleIds.length > 1;
  if (!showMode && !showStyle) return null;

  const bar = el('div', { class: 'mmp-toolbar' });

  if (showMode) {
    const btn = el('button', { type: 'button', class: 'mmp-mode' });
    const sync = () => {
      const is3d = provider.getMode() === '3d';
      btn.textContent = is3d ? (o.labels?.mode2d ?? '2D') : (o.labels?.mode3d ?? '3D');
      btn.setAttribute('aria-pressed', String(is3d));
    };
    btn.addEventListener('click', () => {
      const mode = provider.getMode() === '3d' ? '2d' : '3d';
      provider.setMode(mode);
      sync();
      o.onModeChange?.(mode);
    });
    sync();
    bar.appendChild(btn);
  }

  if (showStyle) {
    const select = el('select', { class: 'mmp-style', 'aria-label': o.labels?.style ?? 'Map style' });
    for (const id of styleIds) {
      const opt = el('option', { value: id }, o.styles[id]);
      if (id === provider.getStyle()) opt.selected = true;
      select.appendChild(opt);
    }
    select.addEventListener('change', () => {
      provider.setStyle(select.value);
      o.onStyleChange?.(select.value);
    });
    bar.appendChild(select);
  }

  // keep clicks on the toolbar from reaching the map (placing markers etc.)
  for (const ev of ['click', 'dblclick', 'pointerdown', 'mousedown', 'touchstart', 'wheel']) {
    bar.addEventListener(ev, (e) => e.stopPropagation());
  }
  root.appendChild(bar);
  return bar;
}
