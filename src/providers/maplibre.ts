/**
 * MapLibre adapter, built on map-engine's MapBase (2D/3D camera, terrain, raster source registry).
 * Markers are DOM markers; clustering uses a clustered GeoJSON source that drives DOM markers,
 * so no glyphs or sprites are needed.
 */

import maplibregl from 'maplibre-gl';
import { MapBase } from '@sla/map-engine/core/map-base';
import { registerMapSources, registerMapOverlays } from '@sla/map-engine/data/map-sources';
import { Emitter, type MapEvent, type MapProvider, type MarkerEvent, type MarkerHandle, type MarkerOptions, type ProviderInit } from '../core/MapProvider';
import type { ClusterOptions, LatLng, MapMode } from '../core/types';
import { assetUrl, loadStyle } from '../core/assets';
import { el, iconElement } from '../core/dom';

let clusterSeq = 0;

class MLMarker implements MarkerHandle {
  private marker: maplibregl.Marker | null = null;
  private popup: maplibregl.Popup | null = null;
  private img: HTMLImageElement | null = null;
  private events = new Emitter<MarkerEvent, MouseEvent>();
  private pos: LatLng;

  constructor(private map: maplibregl.Map, private o: MarkerOptions) {
    this.pos = { lat: o.lat, lng: o.lng };
  }

  get attached(): boolean {
    return this.marker !== null;
  }

  attach(): void {
    if (this.marker) return;
    let element: HTMLElement | undefined;
    if (this.o.icon) {
      element = iconElement(this.o.icon);
      this.img = element as HTMLImageElement;
    }
    const m = new maplibregl.Marker({ element, draggable: !!this.o.draggable, anchor: this.o.icon ? 'bottom' : 'center' })
      .setLngLat([this.pos.lng, this.pos.lat])
      .addTo(this.map);
    const node = m.getElement();
    if (this.o.title) node.title = this.o.title;
    node.style.cursor = 'pointer';
    node.addEventListener('click', (e) => {
      e.stopPropagation();
      this.events.emit('click', e);
    });
    node.addEventListener('mouseenter', (e) => this.events.emit('mouseenter', e));
    node.addEventListener('mouseleave', (e) => this.events.emit('mouseleave', e));
    m.on('dragend', () => {
      const ll = m.getLngLat();
      this.pos = { lat: ll.lat, lng: ll.lng };
      this.events.emit('dragend');
    });
    this.marker = m;
  }

  detach(): void {
    this.popup?.remove();
    this.marker?.remove();
    this.marker = null;
    this.img = null;
  }

  getPosition(): LatLng {
    return { ...this.pos };
  }

  setPosition(p: LatLng): void {
    this.pos = { ...p };
    this.marker?.setLngLat([p.lng, p.lat]);
    this.popup?.setLngLat([p.lng, p.lat]);
  }

  setIcon(url?: string): void {
    const src = url || this.o.icon;
    if (this.img && src) this.img.src = src;
  }

  openPopup(): void {
    if (!this.o.popupHtml) return;
    if (!this.popup) {
      this.popup = new maplibregl.Popup({ offset: this.o.icon ? 12 : 25, maxWidth: '280px' }).setHTML(this.o.popupHtml);
    }
    this.popup.setLngLat([this.pos.lng, this.pos.lat]).addTo(this.map);
  }

  closePopup(): void {
    this.popup?.remove();
  }

  remove(): void {
    this.detach();
    this.events.clear();
  }

  on(ev: MarkerEvent, cb: (e?: MouseEvent) => void): () => void {
    return this.events.on(ev, cb);
  }
}

export class MapLibreProvider implements MapProvider {
  readonly id = 'maplibre' as const;
  private base = new MapBase();
  private map!: maplibregl.Map;
  private events = new Emitter<MapEvent, LatLng>();
  private markers: MLMarker[] = [];
  private cleanups: Array<() => void> = [];
  private has3d = false;
  private mode: MapMode = '2d';

  async init(o: ProviderInit): Promise<void> {
    await loadStyle(assetUrl('mapmarkerplus.css'));
    if (o.sources?.length) registerMapSources(o.sources, { defaultId: o.style });
    if (o.overlaySources?.length) registerMapOverlays(o.overlaySources);
    const terrain = o.terrain && o.terrain.type === 'raster-dem' && o.terrain.tiles?.length ? o.terrain : { type: 'none' as const };
    this.has3d = terrain.type === 'raster-dem';

    this.map = await this.base.init(o.container, { terrain }, {
      center: [o.center.lng, o.center.lat],
      zoom: o.zoom,
      interactive: o.interactive,
      scrollZoom: o.scrollZoom !== false,
      cooperativeGestures: o.scrollZoom === 'cooperative',
      attributionControl: { compact: true },
      maxPitch: 70,
    });

    this.base.setMapSource(o.style);
    for (const id of o.overlays ?? []) this.base.setOverlay(id, true);
    if (o.mode === '3d') this.setMode('3d');

    const m = this.map;
    const onClick = (e: maplibregl.MapMouseEvent) => this.events.emit('click', { lat: e.lngLat.lat, lng: e.lngLat.lng });
    const onZoom = () => this.events.emit('zoomend');
    const onMove = () => this.events.emit('moveend');
    m.on('click', onClick);
    m.on('zoomend', onZoom);
    m.on('moveend', onMove);
  }

  setCenter(p: LatLng, animate = true): void {
    if (animate) this.map.easeTo({ center: [p.lng, p.lat], duration: 300 });
    else this.map.setCenter([p.lng, p.lat]);
  }

  getCenter(): LatLng {
    const c = this.map.getCenter();
    return { lat: c.lat, lng: c.lng };
  }

  setZoom(z: number): void {
    this.map.setZoom(z);
  }

  getZoom(): number {
    return Math.round(this.map.getZoom());
  }

  addMarker(o: MarkerOptions): MarkerHandle {
    const m = new MLMarker(this.map, o);
    m.attach();
    this.markers.push(m);
    return m;
  }

  addClusteredMarkers(list: MarkerOptions[], o: ClusterOptions): MarkerHandle[] {
    const map = this.map;
    const handles = list.map((mo) => new MLMarker(map, { ...mo, draggable: false }));
    this.markers.push(...handles);
    const sourceId = `mmp-cluster-${++clusterSeq}`;
    map.addSource(sourceId, {
      type: 'geojson',
      data: {
        type: 'FeatureCollection',
        features: list.map((mo, i) => ({
          type: 'Feature',
          properties: { i },
          geometry: { type: 'Point', coordinates: [mo.lng, mo.lat] },
        })),
      },
      cluster: true,
      clusterRadius: o.radius ?? 50,
      clusterMaxZoom: o.maxZoom ?? 14,
    });
    // invisible layer: makes the source load its tiles so querySourceFeatures() has data
    map.addLayer({ id: `${sourceId}-probe`, type: 'circle', source: sourceId, paint: { 'circle-radius': 0, 'circle-opacity': 0 } });

    const clusters = new Map<number, maplibregl.Marker>();
    const update = () => {
      if (!map.getSource(sourceId) || !map.isSourceLoaded(sourceId)) return;
      const visiblePoints = new Set<number>();
      const visibleClusters = new Set<number>();
      for (const f of map.querySourceFeatures(sourceId)) {
        const p = f.properties as Record<string, unknown>;
        const coords = (f.geometry as GeoJSON.Point).coordinates as [number, number];
        if (p.cluster) {
          const id = Number(p.cluster_id);
          if (visibleClusters.has(id)) continue;
          visibleClusters.add(id);
          if (!clusters.has(id)) {
            const count = Number(p.point_count);
            const size = Math.min(60, 30 + Math.log2(count) * 5);
            const node = el('div', { class: 'mmp-cluster', role: 'button', 'aria-label': String(count) }, String(p.point_count_abbreviated ?? count));
            node.style.width = node.style.height = `${size}px`;
            node.addEventListener('click', async (e) => {
              e.stopPropagation();
              const src = map.getSource(sourceId) as maplibregl.GeoJSONSource;
              const zoom = await src.getClusterExpansionZoom(id);
              map.easeTo({ center: coords, zoom: Math.min(zoom, 20) });
            });
            clusters.set(id, new maplibregl.Marker({ element: node }).setLngLat(coords).addTo(map));
          }
        } else {
          visiblePoints.add(Number(p.i));
        }
      }
      for (const [id, marker] of clusters) {
        if (!visibleClusters.has(id)) {
          marker.remove();
          clusters.delete(id);
        }
      }
      handles.forEach((h, i) => {
        if (visiblePoints.has(i)) h.attach();
        else if (h.attached) h.detach();
      });
    };
    map.on('moveend', update);
    map.on('sourcedata', (e) => {
      if (e.sourceId === sourceId && e.isSourceLoaded) update();
    });
    update();
    this.cleanups.push(() => {
      for (const m of clusters.values()) m.remove();
      clusters.clear();
    });
    return handles;
  }

  fitBounds(pts: LatLng[], o: { padding?: number; maxZoom?: number; minZoom?: number } = {}): void {
    if (!pts.length) return;
    this.base.fitToPoints(pts.map((p) => [p.lng, p.lat] as [number, number]), { padding: o.padding ?? 50, maxZoom: o.maxZoom ?? 16 });
    if (o.minZoom !== undefined) {
      this.map.once('moveend', () => {
        if (this.map.getZoom() < o.minZoom!) this.map.setZoom(o.minZoom!);
      });
    }
  }

  supports3d(): boolean {
    return this.has3d;
  }

  setMode(m: MapMode): void {
    this.mode = m;
    const is3d = m === '3d';
    if (this.has3d) this.base.setTerrain(is3d);
    this.base.setMode(is3d);
  }

  getMode(): MapMode {
    return this.mode;
  }

  setStyle(id: string): void {
    this.base.setMapSource(id);
  }

  getStyle(): string {
    return this.base.getMapSource();
  }

  on(ev: MapEvent, cb: (p?: LatLng) => void): () => void {
    return this.events.on(ev, cb);
  }

  resize(): void {
    this.map?.resize();
  }

  destroy(): void {
    for (const c of this.cleanups) c();
    for (const m of this.markers) m.remove();
    this.markers = [];
    this.events.clear();
    this.base.dispose();
  }

  getNative(): maplibregl.Map {
    return this.map;
  }
}
