/**
 * Yandex Maps adapter (JavaScript API v3, `ymaps3`).
 *
 * Markers are DOM elements (own pin, or the given icon), popups are DOM too.
 * Clustering uses the @yandex/ymaps3-clusterer package loaded through ymaps3.import().
 * 3D = camera tilt on the vector map.
 */

import { Emitter, type MapEvent, type MapProvider, type MarkerEvent, type MarkerHandle, type MarkerOptions, type ProviderInit } from '../core/MapProvider';
import type { ClusterOptions, LatLng, MapMode } from '../core/types';
import { loadScript } from '../core/assets';
import { el, iconElement, pinElement } from '../core/dom';

/* The ymaps3 API is used untyped: its type package declares globals that clash with other typings. */
/* eslint-disable @typescript-eslint/no-explicit-any */
type Y = any;

const CLUSTERER_PACKAGE = '@yandex/ymaps3-clusterer@0.0.1';
const DEG = Math.PI / 180;

function ymaps(): Y {
  return (window as unknown as { ymaps3?: Y }).ymaps3;
}

function locale(lang: string): string {
  const map: Record<string, string> = { ru: 'ru_RU', en: 'en_US', tr: 'tr_TR', uk: 'uk_UA', be: 'ru_RU', kk: 'ru_RU', uz: 'uz_UZ' };
  return map[lang] ?? 'en_US';
}

class YMarker implements MarkerHandle {
  readonly element: HTMLElement;
  entity: Y | null = null;
  private events = new Emitter<MarkerEvent, MouseEvent>();
  private img: HTMLImageElement | null = null;
  private popup: HTMLElement | null = null;
  private pos: LatLng;

  constructor(private map: Y, private o: MarkerOptions) {
    this.pos = { lat: o.lat, lng: o.lng };
    const wrap = el('div', { class: 'mmp-ymarker' });
    wrap.style.cssText = 'position:absolute;transform:translate(-50%,-100%);cursor:pointer';
    if (o.icon) {
      this.img = iconElement(o.icon) as HTMLImageElement;
      wrap.appendChild(this.img);
    } else {
      wrap.appendChild(pinElement());
    }
    if (o.title) wrap.title = o.title;
    wrap.addEventListener('click', (e) => {
      if (this.popup && this.popup.contains(e.target as Node)) return;
      e.stopPropagation();
      this.events.emit('click', e);
    });
    wrap.addEventListener('mouseenter', (e) => this.events.emit('mouseenter', e));
    wrap.addEventListener('mouseleave', (e) => this.events.emit('mouseleave', e));
    this.element = wrap;
  }

  /** Create the ymaps3 entity (a new one each time: the clusterer recreates markers) */
  createEntity(): Y {
    const { YMapMarker } = ymaps();
    this.entity = new YMapMarker(
      {
        coordinates: [this.pos.lng, this.pos.lat],
        draggable: !!this.o.draggable,
        onDragEnd: (coords: [number, number]) => {
          this.pos = { lng: coords[0], lat: coords[1] };
          this.events.emit('dragend');
        },
      },
      this.element,
    );
    return this.entity;
  }

  getPosition(): LatLng {
    return { ...this.pos };
  }

  setPosition(p: LatLng): void {
    this.pos = { ...p };
    this.entity?.update({ coordinates: [p.lng, p.lat] });
  }

  setIcon(url?: string): void {
    const src = url || this.o.icon;
    if (this.img && src) this.img.src = src;
  }

  openPopup(): void {
    if (!this.o.popupHtml || this.popup) return;
    const p = el('div', { class: 'mmp-popup' });
    const close = el('button', { type: 'button', class: 'mmp-popup-close', 'aria-label': 'Close' }, '×');
    close.addEventListener('click', (e) => {
      e.stopPropagation();
      this.closePopup();
    });
    const body = el('div');
    body.innerHTML = this.o.popupHtml;
    p.append(close, body);
    for (const ev of ['pointerdown', 'mousedown', 'dblclick', 'wheel']) p.addEventListener(ev, (e) => e.stopPropagation());
    this.element.appendChild(p);
    this.popup = p;
  }

  closePopup(): void {
    this.popup?.remove();
    this.popup = null;
  }

  remove(): void {
    this.closePopup();
    if (this.entity) {
      try {
        this.map.removeChild(this.entity);
      } catch {
        /* part of a clusterer */
      }
    }
    this.entity = null;
    this.events.clear();
  }

  on(ev: MarkerEvent, cb: (e?: MouseEvent) => void): () => void {
    return this.events.on(ev, cb);
  }
}

export class YandexProvider implements MapProvider {
  readonly id = 'yandex' as const;
  private map: Y;
  private events = new Emitter<MapEvent, LatLng>();
  private layers: Y[] = [];
  private markers: YMarker[] = [];
  private extras: Y[] = [];
  private style = 'scheme';
  private mode: MapMode = '2d';
  private lastZoom = -1;

  async init(o: ProviderInit): Promise<void> {
    if (!o.keys.yandex) throw new Error('Yandex Maps API key missing (MapMarkerPlus module settings)');
    const url = `https://api-maps.yandex.ru/v3/?apikey=${encodeURIComponent(o.keys.yandex)}&lang=${locale(o.lang)}`;
    await loadScript(url, () => !!ymaps());
    const y = ymaps();
    await y.ready;
    const { YMap, YMapDefaultFeaturesLayer, YMapListener } = y;

    const behaviors = o.interactive
      ? ['drag', 'pinchZoom', 'dblClick', 'mouseRotate', 'mouseTilt', ...(o.scrollZoom === true ? ['scrollZoom'] : [])]
      : [];
    this.map = new YMap(o.container, {
      location: { center: [o.center.lng, o.center.lat], zoom: o.zoom },
      behaviors,
    });
    this.setStyle(o.style || 'scheme');
    this.map.addChild(new YMapDefaultFeaturesLayer({}));
    this.map.addChild(
      new YMapListener({
        layer: 'any',
        onClick: (_obj: unknown, event: { coordinates: [number, number] }) => {
          if (event?.coordinates) this.events.emit('click', { lng: event.coordinates[0], lat: event.coordinates[1] });
        },
        onActionEnd: () => this.afterMove(),
      }),
    );
    this.lastZoom = this.getZoom();
    if (o.interactive) await this.addZoomControl();
    if (o.mode === '3d') this.setMode('3d');
  }

  private afterMove(): void {
    const z = this.getZoom();
    if (z !== this.lastZoom) {
      this.lastZoom = z;
      this.events.emit('zoomend');
    }
    this.events.emit('moveend');
  }

  private async addZoomControl(): Promise<void> {
    const y = ymaps();
    try {
      const { YMapZoomControl } = await y.import('@yandex/ymaps3-default-ui-theme');
      const controls = new y.YMapControls({ position: 'right' });
      controls.addChild(new YMapZoomControl({}));
      this.map.addChild(controls);
      this.extras.push(controls);
    } catch {
      /* zoom buttons are optional */
    }
  }

  setCenter(p: LatLng, animate = true): void {
    this.map.setLocation({ center: [p.lng, p.lat], duration: animate ? 300 : 0 });
  }

  getCenter(): LatLng {
    const c = this.map.center;
    return { lng: c[0], lat: c[1] };
  }

  setZoom(z: number): void {
    this.map.setLocation({ zoom: z, duration: 200 });
    this.lastZoom = Math.round(z);
  }

  getZoom(): number {
    return Math.round(this.map.zoom);
  }

  addMarker(o: MarkerOptions): MarkerHandle {
    const m = new YMarker(this.map, o);
    this.map.addChild(m.createEntity());
    this.markers.push(m);
    return m;
  }

  addClusteredMarkers(list: MarkerOptions[], o: ClusterOptions): MarkerHandle[] {
    const handles = list.map((mo) => new YMarker(this.map, { ...mo, draggable: false }));
    this.markers.push(...handles);
    const y = ymaps();
    y.import(CLUSTERER_PACKAGE)
      .then(({ YMapClusterer, clusterByGrid }: Y) => {
        const clusterer = new YMapClusterer({
          method: clusterByGrid({ gridSize: Math.max(32, (o.radius ?? 50) * 1.3) }),
          maxZoom: o.maxZoom ?? 14,
          features: list.map((mo, i) => ({
            type: 'Feature',
            id: String(i),
            geometry: { type: 'Point', coordinates: [mo.lng, mo.lat] },
            properties: { i },
          })),
          marker: (f: { properties: { i: number } }) => handles[f.properties.i].createEntity(),
          cluster: (coordinates: [number, number], features: unknown[]) => {
            const n = features.length;
            const size = Math.min(60, 30 + Math.log2(n) * 5);
            const node = el('div', { class: 'mmp-cluster', role: 'button' }, String(n));
            node.style.cssText += `;position:absolute;transform:translate(-50%,-50%);width:${size}px;height:${size}px`;
            node.addEventListener('click', (e) => {
              e.stopPropagation();
              this.map.setLocation({ center: coordinates, zoom: this.map.zoom + 2, duration: 300 });
            });
            return new y.YMapMarker({ coordinates }, node);
          },
        });
        this.map.addChild(clusterer);
        this.extras.push(clusterer);
      })
      .catch(() => {
        // no clusterer: plain markers
        for (const h of handles) this.map.addChild(h.createEntity());
      });
    return handles;
  }

  fitBounds(pts: LatLng[], o: { padding?: number; maxZoom?: number; minZoom?: number } = {}): void {
    if (!pts.length) return;
    const lngs = pts.map((p) => p.lng);
    const lats = pts.map((p) => p.lat);
    const maxZoom = o.maxZoom ?? 16;
    if (pts.length === 1) {
      this.map.setLocation({ center: [lngs[0], lats[0]], zoom: maxZoom, duration: 0 });
      return;
    }
    this.map.update({ margin: Array(4).fill(o.padding ?? 50) });
    this.map.setLocation({
      bounds: [
        [Math.min(...lngs), Math.max(...lats)],
        [Math.max(...lngs), Math.min(...lats)],
      ],
      duration: 0,
    });
    const z = this.map.zoom;
    if (z > maxZoom) this.map.setLocation({ zoom: maxZoom, duration: 0 });
    if (o.minZoom !== undefined && z < o.minZoom) this.map.setLocation({ zoom: o.minZoom, duration: 0 });
  }

  supports3d(): boolean {
    return true;
  }

  setMode(m: MapMode): void {
    this.mode = m;
    const is3d = m === '3d';
    this.map.setCamera({ tilt: is3d ? 45 * DEG : 0, azimuth: is3d ? 20 * DEG : 0, duration: 300 });
  }

  getMode(): MapMode {
    return this.mode;
  }

  setStyle(id: string): void {
    const y = ymaps();
    for (const l of this.layers) this.map.removeChild(l);
    this.layers = [];
    if (id === 'satellite' || id === 'hybrid') {
      this.layers.push(new y.YMapDefaultSatelliteLayer({}));
    }
    if (id === 'hybrid') {
      // scheme labels and icons over the satellite; ground and buildings stay below it
      this.layers.push(
        new y.YMapDefaultSchemeLayer({
          layers: { ground: { zIndex: 1000 }, buildings: { zIndex: 1001 }, icons: { zIndex: 1700 }, labels: { zIndex: 1800 } },
        }),
      );
    } else if (id !== 'satellite') {
      this.layers.push(new y.YMapDefaultSchemeLayer({}));
      id = 'scheme';
    }
    // layers go first so markers stay on top
    for (const l of this.layers) this.map.addChild(l, 0);
    this.style = id;
  }

  getStyle(): string {
    return this.style;
  }

  on(ev: MapEvent, cb: (p?: LatLng) => void): () => void {
    return this.events.on(ev, cb);
  }

  resize(): void {
    // ymaps3 observes its container size itself
  }

  destroy(): void {
    for (const m of this.markers) m.remove();
    this.markers = [];
    this.events.clear();
    try {
      this.map?.destroy();
    } catch {
      /* already gone */
    }
  }

  getNative(): Y {
    return this.map;
  }
}
