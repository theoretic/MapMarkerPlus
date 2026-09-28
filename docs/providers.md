# Map providers, styles and geocoders

## Map providers

| Provider | Library | 3D | Key |
|---|---|---|---|
| `maplibre` | MapLibre GL through map-engine `MapBase` (bundled, ~280 KB gzip, loaded only when used) | raster DEM terrain + 55° pitch | none for the default styles |
| `google` | Maps JavaScript API via `@googlemaps/js-api-loader` | tilt 45° / heading 20° on vector maps: needs **Map ID** | `googleMapsKey` (+ `googleMapId`) |
| `yandex` | JavaScript API v3 (`ymaps3`) | camera tilt 45° | `yandexMapsKey` |

Without a Map ID Google maps use legacy markers and the 2D/3D switch is hidden.

Google and Yandex support is **experimental**: the adapters and geocoders are built against the vendor APIs but not
yet tested with real keys. The page editor shows MapLibre by default (module setting "Map in the page editor"), also
for fields that use Google or Yandex on the frontend.

## Free tiers and terms

Checked in September 2026. Prices and terms change: read the linked pages before relying on them.

### Google Maps Platform

- A Cloud billing account with a payment method is required, even within the free usage.
- Since 1 March 2025 each SKU has its own free monthly cap (the $200 credit is gone):

| SKU | Free per month |
|---|---|
| Dynamic Maps (map loads) | 10,000 |
| Geocoding | 10,000 |
| Static Maps | 10,000 |
| 2D Map Tiles | 100,000 |
| 3D Photorealistic Tiles | 1,000 |

- Geocoding terms: latitude/longitude may be cached for 30 consecutive days at most, then deleted (place IDs may be kept),
  and geocoding results must not be used with a non-Google map. So the Google geocoder does not suit a field that keeps
  coordinates, nor a field shown on MapLibre or Yandex.

Sources: [pricing](https://developers.google.com/maps/billing-and-pricing/pricing),
[pricing overview](https://developers.google.com/maps/billing-and-pricing/overview),
[service specific terms](https://cloud.google.com/maps-platform/terms/maps-service-terms).

### Yandex Maps

- Since 20 April 2025 every product is connected and billed separately, with its own key. Keys created before that date
  cover the JavaScript API and the Geocoder together.
- Free per day:

| Product | Free per day |
|---|---|
| JavaScript API | 500 requests |
| Geocoder API | 1,000 |
| Geosuggest API | 10,000 |
| Static API | 10,000 |
| Tiles API | 30 requests per second |

- Free use only for sites and apps anyone can open without registration or payment: not in the ProcessWire admin.
- Data received through the API must not be stored: the Yandex geocoder does not suit a field that keeps coordinates.
- The Yandex logo, copyrights and the "Open in Maps" button must stay visible. Keys that exceed the limits repeatedly
  are blocked for good. Paid plans start around 195,000 ₽ (JavaScript API) and 226,000 ₽ (Geocoder) a year.
- An April 2025 news post gave 25,000 JavaScript API requests a day; the tariffs page now says 500. It is not stated
  whether one map load is one request.

Sources: [tariffs](https://yandex.ru/maps-api/tariffs),
[billing changes 2025](https://yandex.ru/maps-api/docs/news/new-billing/free-usage.html),
[free use conditions](https://yandex.ru/dev/commercial/doc/ru/).

### OpenStreetMap services

Photon and Nominatim results are OpenStreetMap data (ODbL): they may be stored, with attribution. The public Nominatim
allows 1 request per second and needs an identifying contact (module setting). Tile servers have their own usage
policies, see the table below.

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
