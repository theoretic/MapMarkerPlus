/** Shared types of the MapMarkerPlus map scripts. */

export type ProviderId = 'maplibre' | 'google' | 'yandex';
export type MapMode = '2d' | '3d';

export interface LatLng {
  lat: number;
  lng: number;
}

/** Raster tile source in the map-engine `MapSource` format, keys already substituted by PHP. */
export interface MapSourceDef {
  id: string;
  name: string;
  tiles: string[];
  maxzoom: number;
  attribution: string;
  scheme?: 'xyz' | 'tms';
}

export interface TerrainDef {
  type: 'raster-dem' | 'none';
  tiles?: string[];
  encoding?: 'terrarium' | 'mapbox';
  exaggeration?: number;
  maxzoom?: number;
}

export interface ProviderKeys {
  google?: string;
  googleMapId?: string;
  yandex?: string;
}

export interface ClusterOptions {
  radius?: number;
  maxZoom?: number;
}

/** Map configuration produced by FieldtypeMapMarkerPlus::getClientConfig() (+ per-use extras). */
export interface ClientConfig {
  provider: ProviderId;
  style: string;
  styles: Record<string, string>;
  mode: MapMode;
  overlays: string[];
  allowModeToggle: boolean;
  allowStyleSwitch: boolean;
  lang: string;
  /** true = wheel zooms, 'cooperative' = Ctrl + wheel, false = no wheel zoom */
  scrollZoom?: boolean | 'cooperative';
  keys: ProviderKeys;
  defaultLat: number;
  defaultLng: number;
  defaultZoom: number;
  sources?: MapSourceDef[];
  overlaySources?: MapSourceDef[];
  terrain?: TerrainDef;
  center?: LatLng;
  zoom?: number;
}

/** Marker as sent by MarkupMapMarkerPlus or written by hand in <map-marker-plus> JSON. */
export interface MarkerData {
  lat: number;
  lng: number;
  title?: string;
  url?: string;
  icon?: string;
  iconHover?: string;
  popup?: string;
  id?: string | number;
  [key: string]: unknown;
}
