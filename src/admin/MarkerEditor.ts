/**
 * Map editor of InputfieldMapMarkerPlus.
 *
 * Reads the JSON in data-config of .InputfieldMapMarkerPlusMap, finds the inputs rendered by PHP
 * (same names as the upstream InputfieldMapMarker) and keeps inputs and map in sync:
 * - marker drag or map click → lat/lng inputs, reverse geocode into the address when geocoding is on
 * - address change / Enter → geocode through the module endpoint → lat/lng, marker
 * - lat/lng/zoom inputs → map, map zoom → zoom input
 */

import type { MapProvider, MarkerHandle } from '../core/MapProvider';
import type { ClientConfig, LatLng } from '../core/types';
import { loadProvider } from '../providers/index';
import { geocode, type GeocodeResponse } from '../core/geocode-client';
import { el, fmtCoord, parseJson, setInputValue, toNumber } from '../core/dom';
import { createToolbar } from '../ui/toolbar';
import { injectBaseStyles } from '../ui/styles';

export interface EditorConfig extends ClientConfig {
  name: string;
  field: string;
  marker: { address: string; lat: number | null; lng: number | null; zoom: number; status: number };
  center: LatLng;
  zoom: number;
  defaultZoom: number;
  geocoder: string;
  endpoint: string;
  csrf: { name: string; value: string } | null;
  i18n: Record<string, string>;
}

type Inputs = {
  address: HTMLInputElement | null;
  jsAddress: HTMLInputElement | null;
  raw: HTMLInputElement | null;
  status: HTMLInputElement | null;
  lat: HTMLInputElement | null;
  lng: HTMLInputElement | null;
  zoom: HTMLInputElement | null;
};

export class MarkerEditor {
  private cfg: EditorConfig;
  private provider: MapProvider | null = null;
  private marker: MarkerHandle | null = null;
  private inputs: Inputs;
  private statusLine: HTMLElement | null;
  private abort: AbortController | null = null;
  private resizeObserver: ResizeObserver | null = null;
  private cleanups: Array<() => void> = [];
  private destroyed = false;

  constructor(private container: HTMLElement) {
    this.cfg = parseJson<EditorConfig>(container.getAttribute('data-config'), {} as EditorConfig);
    const scope = container.closest('.Inputfield') ?? container.parentElement ?? document;
    const n = this.cfg.name;
    const q = (name: string) => scope.querySelector<HTMLInputElement>(`[name="${CSS.escape(name)}"]`);
    this.inputs = {
      address: q(n),
      jsAddress: q(`_${n}_js_geocode_address`),
      raw: q(`_${n}_raw`),
      status: q(`_${n}_status`),
      lat: q(`_${n}_lat`),
      lng: q(`_${n}_lng`),
      zoom: q(`_${n}_zoom`),
    };
    this.statusLine = scope.querySelector('.InputfieldMapMarkerPlusStatus');
  }

  async init(): Promise<void> {
    injectBaseStyles();
    const c = this.cfg;
    const pos = this.inputPosition();
    const zoom = toNumber(this.inputs.zoom?.value) || c.zoom || c.defaultZoom || 2;
    this.container.classList.add('mmp-root');

    try {
      this.provider = await loadProvider(c.provider);
      if (this.destroyed) return;
      await this.provider.init({
        container: this.container,
        center: pos ?? c.center,
        zoom: pos ? zoom : c.marker.zoom || c.defaultZoom || zoom,
        style: c.style,
        mode: c.mode,
        lang: c.lang,
        keys: c.keys ?? {},
        sources: c.sources,
        overlaySources: c.overlaySources,
        overlays: c.overlays,
        terrain: c.terrain,
        scrollZoom: c.scrollZoom ?? true,
        interactive: true,
      });
    } catch (e) {
      this.showError(e);
      return;
    }
    if (this.destroyed) {
      this.provider.destroy();
      return;
    }

    const p = this.provider;
    if (pos) this.placeMarker(pos);
    else this.setStatus(c.i18n.clickToPlace ?? '');

    createToolbar(this.container, p, {
      allowModeToggle: c.allowModeToggle,
      allowStyleSwitch: c.allowStyleSwitch,
      styles: c.styles,
      labels: { mode2d: c.i18n.mode2d, mode3d: c.i18n.mode3d, style: c.i18n.style },
    });

    this.cleanups.push(
      p.on('click', (ll) => {
        if (!ll) return;
        this.placeMarker(ll);
        this.writePosition(ll);
        this.reverseGeocode(ll);
      }),
      p.on('zoomend', () => {
        if (this.inputs.zoom) setInputValue(this.inputs.zoom, String(p.getZoom()));
      }),
    );

    this.listen(this.inputs.address, 'change', () => this.forwardGeocode());
    this.listen(this.inputs.address, 'keydown', (e) => {
      if ((e as KeyboardEvent).key === 'Enter') {
        e.preventDefault(); // don't submit the page form
        this.forwardGeocode();
      }
    });
    const onCoords = () => {
      const ll = this.inputPosition();
      if (!ll) return;
      this.placeMarker(ll);
      p.setCenter(ll);
    };
    this.listen(this.inputs.lat, 'change', onCoords);
    this.listen(this.inputs.lng, 'change', onCoords);
    this.listen(this.inputs.zoom, 'change', () => {
      const z = toNumber(this.inputs.zoom?.value);
      if (z !== null && z >= 0 && z !== p.getZoom()) p.setZoom(z);
    });
    this.listen(this.inputs.status, 'change', () => {
      const on = !!this.inputs.status?.checked;
      this.setStatus(on ? c.i18n.on : c.i18n.off);
      if (on && this.inputs.address?.value.trim() && !this.inputPosition()) this.forwardGeocode(true);
    });

    // maps in collapsed fields, closed tabs or modals are 0×0 at first: redraw when they become visible
    if ('ResizeObserver' in window) {
      let last = '';
      this.resizeObserver = new ResizeObserver((entries) => {
        const r = entries[0]?.contentRect;
        if (!r) return;
        const key = `${Math.round(r.width)}x${Math.round(r.height)}`;
        if (key === last) return;
        const wasHidden = last.startsWith('0x') || last === '';
        last = key;
        p.resize();
        const m = this.marker?.getPosition();
        if (wasHidden && m) p.setCenter(m, false);
      });
      this.resizeObserver.observe(this.container);
    }
  }

  private listen(t: HTMLElement | null, ev: string, fn: (e: Event) => void): void {
    if (!t) return;
    t.addEventListener(ev, fn);
    this.cleanups.push(() => t.removeEventListener(ev, fn));
  }

  private geocodeOn(): boolean {
    return this.cfg.geocoder !== 'none' && !!this.inputs.status?.checked;
  }

  private inputPosition(): LatLng | null {
    const lat = toNumber(this.inputs.lat?.value);
    const lng = toNumber(this.inputs.lng?.value);
    if (lat === null || lng === null || Math.abs(lat) > 90 || Math.abs(lng) > 180) return null;
    return { lat, lng };
  }

  private placeMarker(ll: LatLng): void {
    if (!this.provider) return;
    if (this.marker) {
      this.marker.setPosition(ll);
      return;
    }
    this.marker = this.provider.addMarker({ lat: ll.lat, lng: ll.lng, draggable: true });
    this.marker.on('dragend', () => {
      const p = this.marker!.getPosition();
      this.writePosition(p);
      this.reverseGeocode(p);
    });
  }

  private writePosition(ll: LatLng): void {
    setInputValue(this.inputs.lat, fmtCoord(ll.lat));
    setInputValue(this.inputs.lng, fmtCoord(ll.lng));
    if (this.inputs.zoom && this.provider) setInputValue(this.inputs.zoom, String(this.provider.getZoom()));
  }

  private async forwardGeocode(force = false): Promise<void> {
    const address = this.inputs.address?.value.trim() ?? '';
    if (!address || (!force && !this.geocodeOn())) return;
    if (this.inputs.jsAddress?.value === address && this.inputPosition()) return; // unchanged
    const res = await this.request({ q: address });
    if (!res) return;
    if (res.ok && res.lat !== null && res.lng !== null) {
      const ll = { lat: res.lat, lng: res.lng };
      this.placeMarker(ll);
      this.provider?.setCenter(ll);
      if (this.provider && this.provider.getZoom() < 12) this.provider.setZoom(res.accuracy === 'rooftop' ? 16 : 13);
      this.writePosition(ll);
      this.storeResult(address, res);
    }
  }

  private async reverseGeocode(ll: LatLng): Promise<void> {
    if (!this.geocodeOn()) return;
    const res = await this.request({ lat: ll.lat, lng: ll.lng });
    if (res?.ok && res.formatted) {
      // store first: setting the address fires "change", which must not geocode it again
      this.storeResult(res.formatted, res);
      setInputValue(this.inputs.address, res.formatted);
    }
  }

  /** Tell processInput() that this address was geocoded here, so the server keeps the coordinates. */
  private storeResult(address: string, res: GeocodeResponse): void {
    if (this.inputs.jsAddress) this.inputs.jsAddress.value = address;
    if (this.inputs.raw) {
      this.inputs.raw.value = JSON.stringify({ provider: res.provider, formatted: res.formatted, accuracy: res.accuracy });
    }
    if (this.inputs.status && res.status > 0) this.inputs.status.value = String(res.status);
  }

  private async request(q: { q?: string; lat?: number; lng?: number }): Promise<GeocodeResponse | null> {
    this.abort?.abort();
    this.abort = new AbortController();
    const i18n = this.cfg.i18n;
    this.setStatus(i18n.geocoding ?? '…', true);
    try {
      const res = await geocode({ endpoint: this.cfg.endpoint, field: this.cfg.field, csrf: this.cfg.csrf, signal: this.abort.signal, ...q });
      if (res.ok) {
        const s = [res.statusString, res.provider ? `(${res.provider})` : ''].filter(Boolean).join(' ');
        this.setStatus(res.formatted && q.q !== undefined && res.formatted !== q.q ? `${s}: ${res.formatted}` : s);
      } else {
        this.setStatus(`${res.status === -2 ? i18n.notFound : i18n.error}${res.error ? ` (${res.error})` : ''}`);
      }
      return res;
    } catch (e) {
      if ((e as Error).name === 'AbortError') return null;
      this.setStatus(`${i18n.error}: ${(e as Error).message}`);
      return null;
    } finally {
      this.container.removeAttribute('aria-busy');
    }
  }

  private setStatus(text: string, busy = false): void {
    if (busy) this.container.setAttribute('aria-busy', 'true');
    if (this.statusLine) this.statusLine.textContent = text;
  }

  private showError(e: unknown): void {
    console.error('[MapMarkerPlus]', e);
    this.container.replaceChildren(el('div', { class: 'mmp-error' }, `${this.cfg.i18n?.loadError ?? 'Map error'}: ${(e as Error)?.message ?? e}`));
  }

  destroy(): void {
    this.destroyed = true;
    this.abort?.abort();
    this.resizeObserver?.disconnect();
    for (const c of this.cleanups) c();
    this.cleanups = [];
    this.provider?.destroy();
    this.provider = null;
  }
}
