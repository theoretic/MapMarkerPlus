# Converting FieldtypeMapMarker and FieldtypeLeafletMapMarker fields

MapMarkerPlus uses ProcessWire's own field type change. The field keeps its name, ID, templates and
page values, so templates reading `$page->map->lat` / `->lng` / `->address` / `->zoom` keep working.

## Before

- Back up the database.
- Install MapMarkerPlus. If FieldtypeMapMarker is installed, its Google key is copied into the
  MapMarkerPlus settings (Google becomes the default map provider and geocoder).
- Add the keys you need in the MapMarkerPlus module settings.

## Convert

1. Setup > Fields > (your map field) > Basics > **Type**: choose *MapMarkerPlus*, save.
2. On the confirmation screen tick **keep settings** (recommended, both work) and confirm.

What happens:

- ProcessWire creates the new table, copies `data` (address), `lat`, `lng`, `status`, `zoom` and, for the
  Leaflet fork, `raw`, then replaces the old table.
- Coordinates become `DECIMAL(10,7)` (were `FLOAT(10,6)`). They are rounded to 6 decimals once, so they
  print exactly as before.
- Settings are mapped:

| Old setting | New setting |
|---|---|
| `defaultLat`, `defaultLng`, `defaultZoom`, `height` | same names (Input tab) |
| `defaultAddr` = "Castaway Cay" (upstream placeholder) | removed |
| MapMarker `defaultType` HYBRID / SATELLITE / ROADMAP | Google `hybrid` / `satellite` / `roadmap` when a Google key is set, else MapLibre `s2cloudless` / `osm` |
| Leaflet `defaultProvider` `OpenStreetMap.*` | MapLibre `osm` |
| `OpenTopoMap` | `opentopomap` |
| `Esri.WorldImagery` / `Esri.WorldTopoMap` (other `Esri.*`) | `esri-imagery` / `esri-topo` |
| `CartoDB.*` | `carto-voyager` with a Carto key, else `osm` |
| anything else (Thunderforest, HERE, Stamen, MapBox, ...) | `osm` |
| geocoder | module default (Photon unless changed) |

A message after saving lists what was mapped.

The Leaflet fork stored whole Nominatim responses in `raw`. They are kept in the table but ignored;
the next geocode replaces them.

## After

- Replace `MarkupGoogleMap` / `MarkupLeafletMap` calls with `MarkupMapMarkerPlus`; the options are compatible
  (`type` becomes a style). Remove the old `<script src="maps.googleapis.com...">` / Leaflet includes.
- Uninstall FieldtypeMapMarker / FieldtypeLeafletMapMarker once no field uses them.
- There is no way back through the Type select. Restore the backup to undo.

## Tables of earlier MapMarkerPlus versions

On module upgrade `___upgrade()` checks every MapMarkerPlus table and changes `FLOAT` coordinates to
`DECIMAL(10,7)` and adds missing columns. `FieldtypeMapMarkerPlus::updateDatabaseSchema($field)` does the
same for one field.
