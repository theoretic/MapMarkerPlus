/**
 * The provider abstraction: one interface, three adapters (MapLibre via map-engine, Google, Yandex).
 * Admin editor and frontend element only talk to this interface.
 */

import type { ClusterOptions, LatLng, MapMode, MapSourceDef, ProviderKeys, TerrainDef, ProviderId } from './types';

export interface ProviderInit {
  container: HTMLElement;
  center: LatLng;
  zoom: number;
  style: string;
  mode: MapMode;
  lang: string;
  keys: ProviderKeys;
  /** MapLibre only */
  sources?: MapSourceDef[];
  overlaySources?: MapSourceDef[];
  overlays?: string[];
  terrain?: TerrainDef;
  /** 'cooperative' = ctrl/two-finger zoom (admin), true = wheel zoom, false = buttons only */
  scrollZoom: boolean | 'cooperative';
  interactive: boolean;
}

export interface MarkerOptions {
  lat: number;
  lng: number;
  draggable?: boolean;
  title?: string;
  icon?: string;
  /** HTML shown in a provider popup on openPopup() */
  popupHtml?: string;
}

export type MarkerEvent = 'click' | 'dragend' | 'mouseenter' | 'mouseleave';

export interface MarkerHandle {
  getPosition(): LatLng;
  setPosition(p: LatLng): void;
  setIcon(url?: string): void;
  openPopup(): void;
  closePopup(): void;
  remove(): void;
  on(ev: MarkerEvent, cb: (e?: MouseEvent) => void): () => void;
}

export type MapEvent = 'click' | 'zoomend' | 'moveend';

export interface MapProvider {
  readonly id: ProviderId;
  init(o: ProviderInit): Promise<void>;
  setCenter(p: LatLng, animate?: boolean): void;
  getCenter(): LatLng;
  setZoom(z: number): void;
  getZoom(): number;
  addMarker(o: MarkerOptions): MarkerHandle;
  /**
   * Show the markers clustered. Returns the handles in the same order (their DOM nodes may come and go
   * as clusters change, but event subscriptions stay valid).
   */
  addClusteredMarkers(list: MarkerOptions[], o: ClusterOptions): MarkerHandle[];
  fitBounds(pts: LatLng[], o?: { padding?: number; maxZoom?: number; minZoom?: number }): void;
  setMode(m: MapMode): void;
  getMode(): MapMode;
  /** Is real 3D available (MapLibre terrain, Google vector map, Yandex tilt)? */
  supports3d(): boolean;
  setStyle(id: string): void;
  getStyle(): string;
  on(ev: MapEvent, cb: (p?: LatLng) => void): () => void;
  resize(): void;
  destroy(): void;
  /** The provider's own map object (maplibregl.Map, google.maps.Map, ymaps3 YMap) */
  getNative(): unknown;
}

/** Tiny event helper for adapters */
export class Emitter<E extends string, A = unknown> {
  private listeners = new Map<E, Set<(a?: A) => void>>();

  on(ev: E, cb: (a?: A) => void): () => void {
    let set = this.listeners.get(ev);
    if (!set) this.listeners.set(ev, (set = new Set()));
    set.add(cb);
    return () => set!.delete(cb);
  }

  emit(ev: E, a?: A): void {
    this.listeners.get(ev)?.forEach((cb) => {
      try {
        cb(a);
      } catch (e) {
        console.error('[MapMarkerPlus]', e);
      }
    });
  }

  clear(): void {
    this.listeners.clear();
  }
}
