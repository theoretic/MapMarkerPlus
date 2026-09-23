/**
 * <map-marker-plus> — a map with markers, rendered by MarkupMapMarkerPlus or written by hand.
 *
 *   <map-marker-plus provider="maplibre" map-style="opentopomap" mode="3d" zoom="9" lat="43.35" lng="42.44" style="height:400px">
 *     <script type="application/json">{"markers":[{"lat":43.35,"lng":42.44,"title":"Cheget","url":"/cheget/"}]}</script>
 *   </map-marker-plus>
 *
 * Attributes (override the JSON "options"): provider, map-style, mode, zoom, lat, lng, lang, api-key, map-id,
 * cluster, fit, popup, lazy, scroll-zoom, hover-box, allow-mode-toggle, allow-style-switch, overlays, data-init.
 * Events: mmp:ready, mmp:markerclick (cancelable; detail = marker data), mmp:error.
 */

import type { MapProvider, MarkerHandle, MarkerOptions } from '../core/MapProvider';
import type { ClientConfig, ClusterOptions, LatLng, MarkerData, ProviderId, TerrainDef } from '../core/types';
import { loadProvider } from '../providers/index';
import { el, parseJson, toNumber } from '../core/dom';
import { createToolbar } from '../ui/toolbar';
import { injectBaseStyles } from '../ui/styles';
import { HoverBox } from './hover-box';

export interface ElementOptions extends Partial<ClientConfig> {
  fit?: boolean;
  cluster?: boolean | ClusterOptions;
  popup?: boolean;
  hoverBox?: string;
  icon?: string;
  iconHover?: string;
  scrollZoom?: boolean;
  lazy?: boolean;
  interactive?: boolean;
}

const DEFAULT_TERRAIN: TerrainDef = {
  type: 'raster-dem',
  tiles: ['https://s3.amazonaws.com/elevation-tiles-prod/terrarium/{z}/{x}/{y}.png'],
  encoding: 'terrarium',
  maxzoom: 15,
};

const DEFAULT_STYLES: Record<ProviderId, Record<string, string>> = {
  maplibre: { osm: 'OpenStreetMap', opentopomap: 'OpenTopoMap', s2cloudless: 'Satellite (Sentinel-2)' },
  google: { roadmap: 'Roadmap', satellite: 'Satellite', hybrid: 'Hybrid', terrain: 'Terrain' },
  yandex: { scheme: 'Scheme', satellite: 'Satellite', hybrid: 'Hybrid' },
};

function esc(s: string): string {
  return s.replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);
}

function boolAttr(v: string | null): boolean | undefined {
  if (v === null) return undefined;
  return v !== 'false' && v !== '0';
}

export class MapMarkerPlusElement extends HTMLElement {
  static observedAttributes = ['mode', 'map-style', 'zoom'];

  provider: MapProvider | null = null;
  markers: MarkerHandle[] = [];
  markerData: MarkerData[] = [];
  private started = false;
  private io: IntersectionObserver | null = null;
  private hoverBox: HoverBox | null = null;
  private mapEl: HTMLElement | null = null;

  connectedCallback(): void {
    if (this.started || this.io) return;
    const { options } = this.readConfig();
    if (options.lazy !== false && 'IntersectionObserver' in window) {
      this.io = new IntersectionObserver((entries) => {
        if (entries.some((e) => e.isIntersecting)) {
          this.io?.disconnect();
          this.io = null;
          this.start();
        }
      }, { rootMargin: '200px' });
      this.io.observe(this);
    } else {
      this.start();
    }
  }

  disconnectedCallback(): void {
    this.io?.disconnect();
    this.io = null;
    // moved within the document: keep the map
    queueMicrotask(() => {
      if (!this.isConnected) this.destroy();
    });
  }

  attributeChangedCallback(name: string, old: string | null, value: string | null): void {
    if (!this.provider || old === value || value === null) return;
    if (name === 'mode' && (value === '2d' || value === '3d')) this.provider.setMode(value);
    else if (name === 'map-style') this.provider.setStyle(value);
    else if (name === 'zoom') {
      const z = toNumber(value);
      if (z !== null) this.provider.setZoom(z);
    }
  }

  /** Options from the JSON child, overridden by attributes */
  readConfig(): { options: ElementOptions; markers: MarkerData[] } {
    const script = this.querySelector(':scope > script[type="application/json"]');
    const data = parseJson<{ options?: ElementOptions; markers?: MarkerData[] }>(script?.textContent, {});
    const o: ElementOptions = { ...(data.options ?? {}) };
    const a = (n: string) => this.getAttribute(n);
    if (a('provider')) o.provider = a('provider') as ProviderId;
    if (a('map-style')) o.style = a('map-style')!;
    if (a('mode')) o.mode = a('mode') === '3d' ? '3d' : '2d';
    if (a('lang')) o.lang = a('lang')!;
    const lat = toNumber(a('lat'));
    const lng = toNumber(a('lng'));
    if (lat !== null && lng !== null) o.center = { lat, lng };
    const zoom = toNumber(a('zoom'));
    if (zoom !== null) o.zoom = zoom;
    if (a('api-key') || a('map-id')) {
      o.keys = { ...(o.keys ?? {}) };
      const p = o.provider ?? 'maplibre';
      if (a('api-key')) {
        if (p === 'google') o.keys.google = a('api-key')!;
        if (p === 'yandex') o.keys.yandex = a('api-key')!;
      }
      if (a('map-id')) o.keys.googleMapId = a('map-id')!;
    }
    if (a('overlays') !== null) o.overlays = a('overlays')!.split(/[\s,]+/).filter(Boolean);
    const flags: Array<[string, keyof ElementOptions]> = [
      ['fit', 'fit'],
      ['popup', 'popup'],
      ['lazy', 'lazy'],
      ['scroll-zoom', 'scrollZoom'],
      ['allow-mode-toggle', 'allowModeToggle'],
      ['allow-style-switch', 'allowStyleSwitch'],
      ['interactive', 'interactive'],
    ];
    for (const [attr, key] of flags) {
      const v = boolAttr(a(attr));
      if (v !== undefined) (o as Record<string, unknown>)[key] = v;
    }
    const cluster = a('cluster');
    if (cluster !== null) o.cluster = cluster.trim().startsWith('{') ? parseJson<ClusterOptions>(cluster, {}) : boolAttr(cluster);
    if (a('hover-box') !== null) o.hoverBox = a('hover-box') || '<div style="background:#000;color:#fff;padding:.25em .5em;border-radius:3px" data-top="-10" data-left="15"></div>';
    const markers = a('markers') !== null ? parseJson<MarkerData[]>(a('markers'), []) : (data.markers ?? []);
    return { options: o, markers };
  }

  async start(): Promise<void> {
    if (this.started) return;
    this.started = true;
    injectBaseStyles();
    const { options: o, markers } = this.readConfig();
    this.markerData = markers.filter((m) => toNumber(m.lat) !== null && toNumber(m.lng) !== null);
    const provider = (o.provider ?? 'maplibre') as ProviderId;

    if (getComputedStyle(this).display === 'inline') this.style.display = 'block';
    if (this.clientHeight === 0 && !this.style.height) this.style.height = '300px';
    this.classList.add('mmp-root');
    this.style.position ||= 'relative';
    this.mapEl = el('div', { class: 'mmp-map' });
    this.mapEl.style.cssText = 'position:absolute;inset:0';
    this.appendChild(this.mapEl);

    const first = this.markerData[0];
    const center: LatLng = o.center ?? (first ? { lat: Number(first.lat), lng: Number(first.lng) } : { lat: o.defaultLat ?? 20, lng: o.defaultLng ?? 0 });
    const zoom = o.zoom ?? (first ? 12 : (o.defaultZoom ?? 2));
    const styles = o.styles ?? DEFAULT_STYLES[provider] ?? {};

    try {
      this.provider = await loadProvider(provider);
      await this.provider.init({
        container: this.mapEl,
        center,
        zoom,
        style: o.style ?? Object.keys(styles)[0] ?? '',
        mode: o.mode ?? '2d',
        lang: o.lang ?? document.documentElement.lang.slice(0, 2) ?? 'en',
        keys: o.keys ?? {},
        sources: o.sources,
        overlaySources: o.overlaySources,
        overlays: o.overlays,
        terrain: o.terrain ?? DEFAULT_TERRAIN,
        scrollZoom: o.scrollZoom ? true : 'cooperative',
        interactive: o.interactive !== false,
      });
    } catch (e) {
      console.error('[MapMarkerPlus]', e);
      this.mapEl.replaceChildren(el('div', { class: 'mmp-error' }, `Map error: ${(e as Error)?.message ?? e}`));
      this.dispatchEvent(new CustomEvent('mmp:error', { detail: e, bubbles: true }));
      return;
    }
    if (!this.isConnected) {
      this.destroy();
      return;
    }

    if (o.hoverBox) this.hoverBox = new HoverBox(o.hoverBox);
    this.addMarkers(o);

    if (o.fit && this.markerData.length > 1) {
      this.fitToMarkers();
    }

    createToolbar(this, this.provider, {
      allowModeToggle: !!o.allowModeToggle,
      allowStyleSwitch: !!o.allowStyleSwitch,
      styles,
    });

    this.dispatchEvent(new CustomEvent('mmp:ready', { detail: { provider: this.provider }, bubbles: true }));
    const init = this.getAttribute('data-init');
    if (init) {
      const fn = init.split('.').reduce<unknown>((obj, key) => (obj as Record<string, unknown> | undefined)?.[key], window);
      if (typeof fn === 'function') fn.call(this, this);
    }
  }

  private addMarkers(o: ElementOptions): void {
    const p = this.provider!;
    const opts: MarkerOptions[] = this.markerData.map((m) => {
      const title = typeof m.title === 'string' ? m.title : '';
      const url = typeof m.url === 'string' ? m.url : '';
      let popupHtml = typeof m.popup === 'string' && m.popup !== '' ? m.popup : undefined;
      if (!popupHtml && o.popup && title) {
        popupHtml = url ? `<a href="${esc(url)}"><strong>${esc(title)}</strong></a>` : `<strong>${esc(title)}</strong>`;
      }
      return {
        lat: Number(m.lat),
        lng: Number(m.lng),
        title: o.hoverBox ? undefined : title,
        icon: (m.icon as string) || o.icon || undefined,
        popupHtml,
      };
    });

    const cluster = o.cluster ? (typeof o.cluster === 'object' ? o.cluster : {}) : null;
    this.markers = cluster && opts.length > 1 ? p.addClusteredMarkers(opts, cluster) : opts.map((mo) => p.addMarker(mo));

    this.markers.forEach((h, i) => {
      const data = this.markerData[i];
      const mo = opts[i];
      const iconHover = (data.iconHover as string) || o.iconHover;
      h.on('click', () => {
        const ev = new CustomEvent('mmp:markerclick', { detail: data, bubbles: true, cancelable: true });
        if (!this.dispatchEvent(ev)) return;
        if (mo.popupHtml) h.openPopup();
        else if (typeof data.url === 'string' && data.url) window.location.href = data.url;
      });
      if (iconHover && mo.icon) {
        h.on('mouseenter', () => h.setIcon(iconHover));
        h.on('mouseleave', () => h.setIcon());
      }
      if (this.hoverBox && typeof data.title === 'string') {
        h.on('mouseenter', (e) => this.hoverBox?.show(data.title as string, e));
        h.on('mouseleave', () => this.hoverBox?.hide());
      }
    });
  }

  /** Fit the map to all markers (min zoom 2, like MarkupGoogleMap) */
  fitToMarkers(): void {
    this.provider?.fitBounds(
      this.markerData.map((m) => ({ lat: Number(m.lat), lng: Number(m.lng) })),
      { padding: 50, maxZoom: 16, minZoom: 2 },
    );
  }

  /** Add a marker at runtime */
  addMarker(m: MarkerData): MarkerHandle | null {
    if (!this.provider) return null;
    const h = this.provider.addMarker({ lat: Number(m.lat), lng: Number(m.lng), title: m.title, icon: m.icon, popupHtml: m.popup });
    this.markerData.push(m);
    this.markers.push(h);
    return h;
  }

  destroy(): void {
    this.hoverBox?.destroy();
    this.hoverBox = null;
    this.provider?.destroy();
    this.provider = null;
    this.markers = [];
    this.mapEl?.remove();
    this.mapEl = null;
    this.querySelector(':scope > .mmp-toolbar')?.remove();
    this.started = false;
  }
}
