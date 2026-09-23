/**
 * Google Maps adapter (Maps JavaScript API via @googlemaps/js-api-loader).
 *
 * With a Map ID (module setting googleMapId) the map is a vector map: AdvancedMarkerElement markers and
 * tilt/heading for 3D. Without it, legacy markers and 2D only.
 */

import { importLibrary, setOptions } from '@googlemaps/js-api-loader';
import { MarkerClusterer, SuperClusterAlgorithm } from '@googlemaps/markerclusterer';
import { Emitter, type MapEvent, type MapProvider, type MarkerEvent, type MarkerHandle, type MarkerOptions, type ProviderInit } from '../core/MapProvider';
import type { ClusterOptions, LatLng, MapMode } from '../core/types';
import { iconElement } from '../core/dom';

let optionsSet = false;

type NativeMarker = google.maps.marker.AdvancedMarkerElement | google.maps.Marker;

class GMarker implements MarkerHandle {
  readonly native: NativeMarker;
  private events = new Emitter<MarkerEvent, MouseEvent>();
  private info: google.maps.InfoWindow | null = null;
  private img: HTMLImageElement | null = null;
  private listeners: google.maps.MapsEventListener[] = [];

  constructor(private map: google.maps.Map, private o: MarkerOptions, advanced: boolean, attach: boolean) {
    const position = { lat: o.lat, lng: o.lng };
    if (advanced) {
      let content: HTMLElement | undefined;
      if (o.icon) {
        content = iconElement(o.icon);
        this.img = content as HTMLImageElement;
      }
      const m = new google.maps.marker.AdvancedMarkerElement({
        map: attach ? map : null,
        position,
        title: o.title ?? '',
        gmpDraggable: !!o.draggable,
        content,
      });
      this.listeners.push(m.addListener('click', () => this.events.emit('click')));
      this.listeners.push(m.addListener('dragend', () => this.events.emit('dragend')));
      const node = m as unknown as HTMLElement;
      node.addEventListener('mouseenter', (e) => this.events.emit('mouseenter', e as MouseEvent));
      node.addEventListener('mouseleave', (e) => this.events.emit('mouseleave', e as MouseEvent));
      this.native = m;
    } else {
      const m = new google.maps.Marker({
        map: attach ? map : null,
        position,
        title: o.title ?? '',
        draggable: !!o.draggable,
        icon: o.icon || undefined,
      });
      this.listeners.push(m.addListener('click', (e: google.maps.MapMouseEvent) => this.events.emit('click', e.domEvent as MouseEvent)));
      this.listeners.push(m.addListener('dragend', () => this.events.emit('dragend')));
      this.listeners.push(m.addListener('mouseover', (e: google.maps.MapMouseEvent) => this.events.emit('mouseenter', e.domEvent as MouseEvent)));
      this.listeners.push(m.addListener('mouseout', (e: google.maps.MapMouseEvent) => this.events.emit('mouseleave', e.domEvent as MouseEvent)));
      this.native = m;
    }
  }

  getPosition(): LatLng {
    const m = this.native;
    if (m instanceof google.maps.Marker) {
      const p = m.getPosition();
      return p ? { lat: p.lat(), lng: p.lng() } : { lat: this.o.lat, lng: this.o.lng };
    }
    const p = m.position;
    if (!p) return { lat: this.o.lat, lng: this.o.lng };
    if (p instanceof google.maps.LatLng) return { lat: p.lat(), lng: p.lng() };
    return { lat: Number(p.lat), lng: Number(p.lng) };
  }

  setPosition(p: LatLng): void {
    const m = this.native;
    if (m instanceof google.maps.Marker) m.setPosition(p);
    else m.position = p;
  }

  setIcon(url?: string): void {
    const src = url || this.o.icon;
    if (!src) return;
    const m = this.native;
    if (m instanceof google.maps.Marker) m.setIcon(src);
    else if (this.img) this.img.src = src;
  }

  openPopup(): void {
    if (!this.o.popupHtml) return;
    if (!this.info) this.info = new google.maps.InfoWindow({ content: this.o.popupHtml, maxWidth: 280 });
    this.info.open({ anchor: this.native, map: this.map });
  }

  closePopup(): void {
    this.info?.close();
  }

  remove(): void {
    this.info?.close();
    for (const l of this.listeners) l.remove();
    const m = this.native;
    if (m instanceof google.maps.Marker) m.setMap(null);
    else m.map = null;
    this.events.clear();
  }

  on(ev: MarkerEvent, cb: (e?: MouseEvent) => void): () => void {
    return this.events.on(ev, cb);
  }
}

export class GoogleProvider implements MapProvider {
  readonly id = 'google' as const;
  private map!: google.maps.Map;
  private advanced = false;
  private events = new Emitter<MapEvent, LatLng>();
  private markers: GMarker[] = [];
  private clusterers: MarkerClusterer[] = [];
  private listeners: google.maps.MapsEventListener[] = [];
  private mode: MapMode = '2d';
  private container!: HTMLElement;

  async init(o: ProviderInit): Promise<void> {
    if (!o.keys.google) throw new Error('Google Maps API key missing (MapMarkerPlus module settings)');
    if (!optionsSet) {
      setOptions({ key: o.keys.google, v: 'weekly', language: o.lang });
      optionsSet = true;
    }
    const { Map } = await importLibrary('maps');
    this.advanced = !!o.keys.googleMapId;
    if (this.advanced) await importLibrary('marker');
    else await importLibrary('marker').catch(() => undefined);

    this.container = o.container;
    this.map = new Map(o.container, {
      center: o.center,
      zoom: o.zoom,
      mapTypeId: o.style || 'roadmap',
      mapId: o.keys.googleMapId || undefined,
      gestureHandling: o.interactive ? (o.scrollZoom === true ? 'greedy' : 'cooperative') : 'none',
      scrollwheel: o.scrollZoom !== false,
      disableDefaultUI: !o.interactive,
      mapTypeControl: false,
      streetViewControl: false,
      clickableIcons: false,
      tilt: 0,
    });
    if (o.mode === '3d') this.setMode('3d');

    this.listeners.push(
      this.map.addListener('click', (e: google.maps.MapMouseEvent) => {
        if (e.latLng) this.events.emit('click', { lat: e.latLng.lat(), lng: e.latLng.lng() });
      }),
      this.map.addListener('zoom_changed', () => this.events.emit('zoomend')),
      this.map.addListener('idle', () => this.events.emit('moveend')),
    );
  }

  setCenter(p: LatLng, animate = true): void {
    if (animate) this.map.panTo(p);
    else this.map.setCenter(p);
  }

  getCenter(): LatLng {
    const c = this.map.getCenter();
    return c ? { lat: c.lat(), lng: c.lng() } : { lat: 0, lng: 0 };
  }

  setZoom(z: number): void {
    this.map.setZoom(z);
  }

  getZoom(): number {
    return Math.round(this.map.getZoom() ?? 0);
  }

  addMarker(o: MarkerOptions): MarkerHandle {
    const m = new GMarker(this.map, o, this.advanced, true);
    this.markers.push(m);
    return m;
  }

  addClusteredMarkers(list: MarkerOptions[], o: ClusterOptions): MarkerHandle[] {
    const handles = list.map((mo) => new GMarker(this.map, { ...mo, draggable: false }, this.advanced, false));
    this.markers.push(...handles);
    const clusterer = new MarkerClusterer({
      map: this.map,
      markers: handles.map((h) => h.native),
      algorithm: new SuperClusterAlgorithm({ radius: o.radius ?? 60, maxZoom: o.maxZoom ?? 14 }),
      onClusterClick: (_e, cluster, map) => {
        if (cluster.bounds) map.fitBounds(cluster.bounds);
      },
    });
    this.clusterers.push(clusterer);
    return handles;
  }

  fitBounds(pts: LatLng[], o: { padding?: number; maxZoom?: number; minZoom?: number } = {}): void {
    if (!pts.length) return;
    const b = new google.maps.LatLngBounds();
    for (const p of pts) b.extend(p);
    this.map.fitBounds(b, o.padding ?? 50);
    const l = google.maps.event.addListenerOnce(this.map, 'idle', () => {
      const z = this.map.getZoom() ?? 0;
      if (o.maxZoom !== undefined && z > o.maxZoom) this.map.setZoom(o.maxZoom);
      if (o.minZoom !== undefined && z < o.minZoom) this.map.setZoom(o.minZoom);
    });
    this.listeners.push(l);
  }

  supports3d(): boolean {
    return this.advanced;
  }

  setMode(m: MapMode): void {
    this.mode = m;
    if (!this.advanced) return;
    const is3d = m === '3d';
    this.map.setTilt(is3d ? 45 : 0);
    this.map.setHeading(is3d ? 20 : 0);
  }

  getMode(): MapMode {
    return this.mode;
  }

  setStyle(id: string): void {
    this.map.setMapTypeId(id);
  }

  getStyle(): string {
    return String(this.map.getMapTypeId() ?? 'roadmap');
  }

  on(ev: MapEvent, cb: (p?: LatLng) => void): () => void {
    return this.events.on(ev, cb);
  }

  resize(): void {
    // Google maps observe their container size themselves
  }

  destroy(): void {
    for (const c of this.clusterers) c.clearMarkers();
    for (const m of this.markers) m.remove();
    for (const l of this.listeners) l.remove();
    this.markers = [];
    this.clusterers = [];
    this.listeners = [];
    this.events.clear();
    if (this.container) this.container.innerHTML = '';
  }

  getNative(): google.maps.Map {
    return this.map;
  }
}
