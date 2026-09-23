# Map providers, styles and geocoders

## Map providers

| Provider | Library | 3D | Key |
|---|---|---|---|
| `maplibre` | MapLibre GL through map-engine `MapBase` (bundled, ~280 KB gzip, loaded only when used) | raster DEM terrain + 55° pitch | none for the default styles |
| `google` | Maps JavaScript API via `@googlemaps/js-api-loader` | tilt 45° / heading 20° on vector maps: needs **Map ID** | `googleMapsKey` (+ `googleMapId`) |
| `yandex` | JavaScript API v3 (`ymaps3`) | camera tilt 45° | `yandexMapsKey` |

Without a Map ID Google maps use legacy markers and the 2D/3D switch is hidden.

## MapLibre styles and overlays

All MapLibre styles are raster tile sources in the map-engine `MapSource` format (256 px tiles).
PHP substitutes keys and hands the resolved sources to the script, which registers them with
`registerMapSources()` / `registerMapOverlays()`.

| Id | Source | Max zoom | Key | License / caveat |
|---|---|---|---|---|
| `osm` | tile.openstreetmap.org (map-engine) | 19 | – | [OSM tile policy](https://operations.osmfoundation.org/policies/tiles/): no heavy use |
| `opentopomap` | OpenTopoMap (map-engine default) | 17 | – | CC-BY-SA |
| `s2cloudless` | EOX Sentinel-2 cloudless 2020 (map-engine) | 15 | – | CC-BY-NC-SA: **non-commercial** |
| `esri-imagery` | Esri World Imagery | 19 | – | may answer 403 when hotlinked from some hosts |
| `esri-topo` | Esri World Topo Map | 19 | – | same |
| `carto-voyager` | Carto Voyager | 20 | `cartoKey` | removed from map-engine: watermarked without a key |
| `maptiler-streets`, `maptiler-satellite`, `maptiler-topo` | MapTiler raster | 20–22 | `maptilerKey` | |

| Overlay | Source | Key | License |
|---|---|---|---|
| `skyways`, `thermals` | thermal.kk7.ch (map-engine, TMS, `src=` hostname) | – | CC-BY-NC-SA |
| `openaip` | openAIP airspace | `openaipKey` | CC-BY-NC-SA |

Terrain: AWS Terrarium DEM by default (`terrainTiles`), or e.g. MapTiler `terrain-rgb` with encoding `mapbox`.

To add a style, add it to `src/providers/styles.json` (a `key` entry names the module setting that holds the
key, `{key}` in the tile URL is replaced) and rebuild.

## Geocoders

| Name | Service | Key | Notes |
|---|---|---|---|
| `photon` (default) | photon.komoot.io or `photonUrl` | – | Languages: en, de, fr, it (others fall back to local names) |
| `nominatim` | nominatim.openstreetmap.org or `nominatimUrl` | – | 1 request/second (throttled with a lock file), set `nominatimEmail` |
| `google` | Geocoding API | `googleGeocodingKey` or `googleMapsKey` | |
| `yandex` | HTTP Geocoder 1.x | `yandexGeocoderKey` or `yandexMapsKey` | Commercial use needs a paid plan |
| `maptiler` | MapTiler Geocoding | `maptilerKey` | |

Results are normalized to the legacy status codes of FieldtypeMapMarker, so `status > 0` still means "found":

| Status | Meaning |
|---|---|
| 2 / 3 / 4 / 5 | OK rooftop / range / center (street) / approximate |
| -2 | nothing found |
| -3 | rate limit (HTTP 429) |
| -4 | denied (HTTP 401/403, missing key) |
| -5 | invalid request |
| -1 | other error |
| -100 | geocoding switched off for this value |

A failed geocode keeps the previous coordinates (upstream reset them to 0).
