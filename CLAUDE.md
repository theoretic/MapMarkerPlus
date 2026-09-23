# MapMarkerPlus

ProcessWire 3 module trio, fork of Ryan Cramer's FieldtypeMapMarker (remote `upstream`):
`FieldtypeMapMarkerPlus` (storage, geocoding, endpoint, conversion hooks, module config),
`InputfieldMapMarkerPlus` (admin input), `MarkupMapMarkerPlus` (frontend render). Value class `MapMarkerPlus`.
Map scripts are TypeScript in `src/`, built with Vite into `assets/dist/` (committed).

## Layout

| Path | Role |
|---|---|
| `FieldtypeMapMarkerPlus.module.php` | Fieldtype, `getClientConfig()`, geocoder registry `___getGeocoders()`, URL hook `/mapmarkerplus/geocode/` (`geocodeRequest()`), schema v3 + `updateDatabaseSchema()`, type-change hooks |
| `InputfieldMapMarkerPlus.module.php` | Same input names as upstream (`name`, `_{n}_lat`, `_{n}_lng`, `_{n}_zoom`, `_{n}_status`, `_{n}_js_geocode_address`) + `_{n}_raw`; map config in `data-config` |
| `InputfieldMapMarkerPlus.js` / `.css` | Auto-loaded by core because the basename = class name. The JS is a classic loader that `import()`s `assets/dist/admin.js` (admin themes only print classic `<script>`) |
| `MarkupMapMarkerPlus.module.php` | Emits `<map-marker-plus>` + JSON child, never inline JS with values |
| `lib/` | Geocoders (base `MapMarkerPlusGeocoder`), result class, `MapMarkerPlusMigration` |
| `src/providers/styles.json` | Style/overlay registry shared by PHP and TS. `key` = module setting, `{key}` substituted by PHP |
| `src/core/MapProvider.ts` | Provider interface; `src/providers/{maplibre,google,yandex}.ts` adapters, each its own chunk |
| `src/admin/`, `src/frontend/` | The two entries |
| `tests/smoke.php` | Offline smoke test against a real install (fixtures in `tests/fixtures/geocode/`, fake geocoder) |

## Commands

```bash
npm install && npm run build      # also writes assets/dist/BUILD (source hash)
npm run check:dist                # stale dist check
node --check InputfieldMapMarkerPlus.js
for f in *.php lib/*.php tests/*.php; do D:/work/sites/modules/PHP-8.5/php -l $f; done
D:/work/sites/modules/PHP-8.5/php tests/smoke.php D:/work/sites/home/livingston.osp/application/webroot
```

Smoke test targets must not have MapMarkerPlus in `site/modules`. davinci has it (junction, see below), so
run the smoke test on livingston (or ofsla, which has a Leaflet `geopoint` field for the type-change hook check).
PW core used locally: `D:/install/processwire/wire` (3.0.273) — read it instead of guessing signatures.
CLI bootstraps of davinci need `$_SERVER['DOCUMENT_ROOT']` set (StaticPages config reads it).

## Conventions

- PHP: `namespace ProcessWire;`, tabs, LF, `array()`, `$this->wire()->x`, `$this->_()`, escape all output.
  `___` only on core-hookable methods or deliberate hooks (`getGeocoders`, `getEndpointPath`, `getMarkerData`).
- TS: 2 spaces, no framework. Adapters only talk through `MapProvider`; keep a Google page free of MapLibre.
- Config reaches JS only as JSON (`data-config`, `<script type="application/json">`), encoded with `JSON_HEX_*`.
- Secret keys (geocoding) stay server-side. Only browser map keys and keys that are part of tile URLs go to the client.
- Version: `FieldtypeMapMarkerPlus::version` (used by all three modules), `package.json`, `CHANGELOG.md`.
- **Rebuild and commit `assets/dist/` with every `src/` change** (`npm run check:dist`).

## Constraints

- Template API of upstream must keep working: `->address`, `->lat`, `->lng`, `->zoom`, `->status`, `->statusString`;
  `lat`/`lng` are strings, `''` when unknown.
- `getDatabaseSchema()` must stay side-effect free: `Fields::changeFieldtype()` calls it for a `_PWTMP` clone that shares
  the real field's ID. Schema changes only in `updateDatabaseSchema()` (upgrade, type change).
- map-engine (`D:/work/projects/map-engine`) is untouched by this module; it is a `file:` dependency (npm 12 blocks git deps).
  Its tag `v0.4.0` exists only locally; remote has up to `v0.3.0`.
- Local davinci: `site/modules/MapMarkerPlus` is a junction to this repo, module installed, test field `mmp_test`,
  template `mmp-test`, unpublished page `/mmp-test/`. Changes here are live there.
- Local `.osp` hosts are not reachable through the tool proxy: use `curl --noproxy '*'`, or a localhost proxy
  that sets `Host:` for the browser pane.

## Ideas, not done

- MapTiler vector styles (MapBase only does raster).
- Google photorealistic 3D (`maps3d`).
- Address autocomplete in the admin (Photon/Google/Yandex suggest).
- Draw a marker preview in Lister (`___markupValue` with a static map image).
