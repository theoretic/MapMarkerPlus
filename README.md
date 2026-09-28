# MapMarkerPlus for ProcessWire

A map field for ProcessWire 3: an address with latitude, longitude and zoom, geocoded with the
service you choose and shown on the map you choose, in 2D or 3D.

Fork of [FieldtypeMapMarker](https://github.com/ryancramerdesign/FieldtypeMapMarker) by Ryan Cramer
(MPL 2.0). The template API (`$page->map->address`, `->lat`, `->lng`, `->zoom`) is unchanged, and
existing FieldtypeMapMarker and FieldtypeLeafletMapMarker fields can be converted in place.

| | |
|---|---|
| Map providers | **MapLibre** (atis.pro [map-engine](https://github.com/theoretic/map-engine), OSM-based raster styles, 3D terrain), **Google Maps** (experimental), **Yandex Maps** (experimental) |
| Geocoders | **Photon** (default, no key), **Nominatim**, **Google** (experimental), **Yandex** (experimental), **MapTiler**, your own via hook |
| 2D / 3D | MapLibre: terrain + pitch · Google: tilt on a vector map (Map ID) · Yandex: camera tilt |
| Frontend | `MarkupMapMarkerPlus::render()` or the `<map-marker-plus>` web component: markers, clustering, popups, hover box |

## Requirements

ProcessWire 3.0.200+, PHP 7.4+. No node on the server: the built scripts are in `assets/dist/`.

## Install

1. Copy the module to `site/modules/MapMarkerPlus/` (the `src/`, `tests/`, `scripts/` and `node_modules/`
   folders are not needed on a site).
2. Modules > Refresh, install **MapMarkerPlus** (Fieldtype). The Inputfield and Markup modules install with it.
3. Enter API keys in the module settings if you use Google, Yandex, MapTiler, Carto or openAIP.
4. Setup > Fields > Add: type **MapMarkerPlus**. Pick map provider, style, mode and geocoder on the *Details* tab,
   default location and height on the *Input* tab.

## Module settings

| Setting | Use |
|---|---|
| Default map provider / geocoder | Used by fields set to "Default" |
| Map in the page editor | MapLibre (default) or the field's provider. Frontend maps always use the field's provider |
| Default latitude / longitude / zoom | Map view for empty values when the field has no default location |
| Default map height | Height in pixels (450) for fields whose *Input* tab height is 0 |
| Ctrl + scroll to zoom | Off: the mouse wheel zooms the map. On: only Ctrl + wheel zooms (Yandex: wheel zoom off) |
| Language | Two-letter code for geocoding results and map labels (blank: the user's language, if its name is a two-letter code) |
| Geocode cache lifetime | Successful results are cached in WireCache (30 days by default) |
| Google: Maps key, Geocoding key, Map ID | Browser key (restrict by referrer), server key (restrict by IP), Map ID for vector maps (3D, advanced markers) |
| Yandex: JavaScript API key, Geocoder key | One key per product (keys made before 20 April 2025 cover both) |
| Photon / Nominatim URL, Nominatim e-mail | Self-hosted instances; the public Nominatim needs an e-mail and allows 1 request/second |
| MapTiler, Carto, openAIP keys | Unlock the MapTiler geocoder and styles, Carto Voyager, the openAIP overlay |
| 3D terrain | Elevation tiles for MapLibre 3D (default: AWS Terrarium), encoding, exaggeration |

Keys for geocoding never reach the browser: the admin geocodes through `/mapmarkerplus/geocode/`
(POST, logged-in users with `page-edit`, CSRF protected).

**Google Maps and Yandex Maps support is experimental**: not yet tested with real keys, and their free terms
are restrictive. Neither free tier allows keeping geocoded coordinates in the database, and free Yandex
keys may not be used behind a login. See [free tiers and terms](docs/providers.md#free-tiers-and-terms).
MapLibre with Photon or Nominatim needs no key and allows storing coordinates.

## Map styles

| Provider | Styles | Notes |
|---|---|---|
| MapLibre | `osm`, `opentopomap`, `s2cloudless` (from map-engine), `esri-imagery`, `esri-topo`, `carto-voyager`\*, `maptiler-streets`\*, `maptiler-satellite`\*, `maptiler-topo`\* | Overlays: `skyways`, `thermals` (kk7), `openaip`\*. Sentinel-2 and kk7 are non-commercial licenses. Esri tiles may be blocked for some hosts |
| Google | `roadmap`, `satellite`, `hybrid`, `terrain` | |
| Yandex | `scheme`, `satellite`, `hybrid` | |

\* only when the key is set. The list lives in `src/providers/styles.json`, shared by PHP and the scripts.
Details and licenses: [docs/providers.md](docs/providers.md).

## Template API

```php
echo $page->map->address;       // as entered
echo $page->map->lat;           // "" when unknown
echo $page->map->lng;
echo $page->map->zoom;          // 0 = field default
echo $page->map->formatted;     // address as returned by the geocoder
echo $page->map->statusString;  // i.e. "OK ROOFTOP", "ZERO RESULTS", "Geocode OFF"

$page->map->hasCoordinates();
$page->of(false);
$page->map->address = 'Terskol';
$page->save('map');             // geocodes the changed address
$page->map = ['lat' => 43.35, 'lng' => 42.44];  // arrays are accepted too

$pages->find("map.lat>43, map.lat<44");     // selectors: address (fulltext), lat, lng, zoom, status
$pages->find("map*=Nalchik");
```

## Rendering maps

```php
$map = $modules->get('MarkupMapMarkerPlus');
echo $map->render($page, 'map');
echo $map->render($pages->find("template=hut, map.lat!=0"), 'map', [
	'height' => 500,
	'cluster' => true,
	'popup' => true,               // title (+ link) in a popup instead of following the link
	'provider' => 'maplibre',      // override the field settings
	'style' => 'opentopomap',
	'mode' => '3d',
	'allowModeToggle' => true,
]);
```

All [MarkupGoogleMap options](MarkupMapMarkerPlus.module.php) still work (`type => 'SATELLITE'` maps to a style),
plus `provider`, `style`, `mode`, `overlays`, `cluster`, `popup`, `popupField`, `popupCallback`, `markerCallback`,
`scrollZoom`, `lazy`, `attrs`. Hook `MarkupMapMarkerPlus::getMarkerData` to change marker data (icons per page, etc.).

The first map outputs `<script type="module" src=".../assets/dist/frontend.js">`; pass `'script' => false`
and echo `$map->renderScript()` yourself to place it elsewhere.

### Web component

`render()` outputs a `<map-marker-plus>` element. It also works in static HTML:

```html
<script type="module" src="/site/modules/MapMarkerPlus/assets/dist/frontend.js"></script>

<map-marker-plus provider="maplibre" map-style="opentopomap" mode="3d" allow-mode-toggle
                 cluster popup fit style="height: 400px">
  <script type="application/json">
    {"markers": [
      {"lat": 43.3499, "lng": 42.4453, "title": "Elbrus", "url": "/elbrus/"},
      {"lat": 43.2567, "lng": 42.4895, "title": "Cheget", "popup": "<b>Cheget</b><br>3 lifts"}
    ]}
  </script>
</map-marker-plus>
```

Attributes: `provider`, `map-style`, `mode`, `zoom`, `lat`, `lng`, `lang`, `api-key`, `map-id`, `overlays`, `cluster`
(or JSON `{"radius":50,"maxZoom":14}`), `fit`, `popup`, `lazy`, `scroll-zoom` (`true`, `false` or `ctrl`), `hover-box`, `allow-mode-toggle`,
`allow-style-switch`, `interactive`, `data-init` (global function called with the element).
Events: `mmp:ready`, `mmp:markerclick` (cancelable, `detail` = marker data), `mmp:error`.
Properties: `el.provider` (the map adapter, `el.provider.getNative()` is the MapLibre/Google/Yandex map), `el.addMarker()`, `el.fitToMarkers()`.

## Converting existing fields

Fields of **FieldtypeMapMarker** and **FieldtypeLeafletMapMarker** offer *MapMarkerPlus* in their *Type* select.
Data is copied by ProcessWire, settings are mapped. See [docs/migration.md](docs/migration.md).

## Adding a geocoder

```php
// site/ready.php
require_once __DIR__ . '/classes/MyGeocoder.php'; // class MyGeocoder extends MapMarkerPlusGeocoder (namespace ProcessWire)
$wire->addHookAfter('FieldtypeMapMarkerPlus::getGeocoders', function($e) {
	$e->return = $e->return + ['mine' => 'MyGeocoder'];
});
```

Implement `getName()`, `getTitle()`, `forwardUrl()`, `reverseUrl()` and `parseResponse()`; HTTP, caching,
throttling and error mapping come from the base class (see `lib/MapMarkerPlusGeocoderPhoton.php`).

## Development

```bash
npm install              # map-engine is a file: dependency (../../../map-engine)
npm run build            # typecheck + build assets/dist/ + record the source hash
npm run check:dist       # fails when assets/dist/ is older than the sources
php tests/smoke.php /path/to/pw/webroot
```

Commit `assets/dist/` with every source change. See [CLAUDE.md](CLAUDE.md) for conventions.

## License

MPL 2.0, see [LICENSE](LICENSE). Upstream: Copyright (C) 2023 by Ryan Cramer.
