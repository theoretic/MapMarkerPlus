# Changelog

MapMarkerPlus is a fork of [FieldtypeMapMarker](https://github.com/ryancramerdesign/FieldtypeMapMarker)
by Ryan Cramer (MPL 2.0).

## [1.0.0] (2026-09-23)

First release of the fork. New module names (`FieldtypeMapMarkerPlus`, `InputfieldMapMarkerPlus`,
`MarkupMapMarkerPlus`), so it installs next to FieldtypeMapMarker / FieldtypeLeafletMapMarker.
The template API is unchanged.

### Added
- Map providers per field and per render call: MapLibre (atis.pro map-engine), Google Maps, Yandex Maps.
- 2D/3D mode and on-map 2D/3D and style switches. MapLibre 3D uses raster DEM terrain.
- MapLibre styles: OSM, OpenTopoMap, Sentinel-2, Esri imagery/topo, plus Carto and MapTiler with keys;
  overlays kk7 skyways/thermals and openAIP. Registry in `src/providers/styles.json`.
- Geocoders: Photon (default), Nominatim, Google, Yandex, MapTiler; more through `FieldtypeMapMarkerPlus::getGeocoders`.
  Results cached in WireCache, Nominatim throttled to 1 request/second.
- Geocode endpoint `/mapmarkerplus/geocode/` (URL hook, `page-edit`, CSRF): API keys stay on the server.
- `raw` column with the last geocode result: `$value->formatted`, `$value->geocoder`.
- Conversion of FieldtypeMapMarker and FieldtypeLeafletMapMarker fields through Setup > Fields > Type,
  with data copy and settings mapping (`docs/migration.md`).
- `<map-marker-plus>` web component: markers from JSON, fit, clustering, popups, hover box, lazy start, events.
- Markup options `provider`, `style`, `mode`, `overlays`, `cluster`, `popup`, `popupField`, `popupCallback`,
  `markerCallback`, `scrollZoom`, `lazy`, `attrs`; hook `MarkupMapMarkerPlus::getMarkerData`.
- Selectors on `lat`, `lng`, `zoom`, `status` subfields; `___markupValue()` for Lister.
- Array values: `$page->map = ['lat' => .., 'lng' => ..]`.

### Changed
- Coordinates are `DECIMAL(10,7)` (were `FLOAT(10,6)`, single precision). Converted and upgraded tables
  are rounded to 6 decimals once, so existing values print as before.
- `(string) $value` is "address (lat, lng)" (was "address (lat, lng, zoom) [status]").
- Admin: no jQuery, no Google script on every admin page, map scripts load only for the chosen provider.
  Inputs use a grid layout. Clicking the map places the marker.
- No default "Castaway Cay" address: empty values show the module's default view.
- Frontend output is an element with JSON data instead of inline JavaScript.
- Version numbers are semver strings.

### Fixed
- Unescaped titles and URLs in the inline JavaScript of MarkupGoogleMap (XSS).
- Latitude or longitude 0 alone (equator, prime meridian) was treated as "no coordinates".
- A failed geocode reset the coordinates to 0; now they are kept and only the status changes.
- `Inputfield::isEmpty()` looked at the latitude only.
- A change of latitude, longitude or zoom without an address change did not always mark the page as changed.
- Maps in closed tabs, collapsed or ajax-loaded fields and repeaters did not render (ResizeObserver,
  MutationObserver, script tag in ajax responses).
- `renderReady()` signature deprecated on PHP 8.4.

## [3.0.0] upstream (2023-12-28)

Upstream FieldtypeMapMarker as imported (module version `300`), Google Maps only.
