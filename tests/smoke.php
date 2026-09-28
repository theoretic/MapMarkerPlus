<?php namespace ProcessWire;

/**
 * Smoke test: runs the module classes against a bootstrapped ProcessWire install.
 * Read-only for the database (no page or field is saved), makes no network requests.
 *
 * Usage: php tests/smoke.php /path/to/pw/webroot
 * The target install must NOT have MapMarkerPlus in site/modules (class name clash).
 * An install with a FieldtypeLeafletMapMarker or FieldtypeMapMarker field (davinci, ofsla)
 * also tests the "convert field type" hook against a real field.
 */

$root = rtrim(str_replace('\\', '/', $argv[1] ?? (getenv('PW_ROOT') ?: '')), '/');
if(!is_file("$root/index.php")) exit("Usage: php tests/smoke.php /path/to/pw/webroot\n");
foreach(glob("$root/site/modules/*/FieldtypeMapMarkerPlus.module*") ?: array() as $f) exit("Target install already has the module ($f), pick another one\n");

chdir($root);
include "$root/index.php";
$wire = wire();
$dir = dirname(__DIR__);
require_once "$dir/FieldtypeMapMarkerPlus.module.php";
require_once "$dir/InputfieldMapMarkerPlus.module.php";
require_once "$dir/MarkupMapMarkerPlus.module.php";
require_once __DIR__ . '/GeocoderFake.php';

$fails = 0;
function check($label, $ok) {
	global $fails;
	if(!$ok) $fails++;
	echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
}
function input(array $a) {
	return new WireInputData($a);
}
function fixture($name) {
	return json_decode(file_get_contents(__DIR__ . "/fixtures/geocode/$name.json"), true);
}

/** @var FieldtypeMapMarkerPlus $ft */
$ft = $wire->wire(new FieldtypeMapMarkerPlus());
$ft->set('cacheTtl', 0);
$ft->addHookAfter('getGeocoders', function(HookEvent $e) {
	$e->return = array_merge($e->return, array('fake' => 'MapMarkerPlusGeocoderFake'));
});
$field = new Field();
$field->type = $ft;
$field->name = 'mmp_test';
$field->set('geocoder', 'fake');
$page = new NullPage();

echo "== value\n";
$m = $ft->getBlankValue($page, $field);
$m->lat = '12,5';
$m->lng = 'abc';
check('lat comma → dot', $m->lat === '12.5');
check('non-numeric lng → blank', $m->lng === '');
$m->lat = 91;
check('lat out of range → blank', $m->lat === '');
$m->zoom = 99;
check('zoom clamped to 29', $m->zoom === 29);
$m->status = 42;
check('unknown status → -1', $m->status === -1 && $m->statusString === 'UNKNOWN');
$m->status = MapMarkerPlus::statusNoGeocode;
check('status -100 string', $m->statusString === 'Geocode OFF');
check('blank marker is empty', $ft->isEmptyValue($field, $ft->getBlankValue($page, $field)));
$m2 = $ft->getBlankValue($page, $field);
$m2->lat = 0;
$m2->lng = 10;
check('lat 0 on meridian is a coordinate', $m2->hasCoordinates() && !$ft->isEmptyValue($field, $m2));

echo "== wakeup / sleep\n";
$w = $ft->wakeupValue($page, $field, array('data' => 'Moscow', 'lat' => '55.7558260', 'lng' => '37.6173000', 'status' => 1, 'zoom' => 12, 'raw' => ''));
check('DECIMAL trailing zeros trimmed', $w->lat === '55.755826' && $w->lng === '37.6173');
check('wakeup not changed', !$w->isChanged());
$w0 = $ft->wakeupValue($page, $field, array('data' => '', 'lat' => '0.0000000', 'lng' => '0.0000000', 'status' => 0, 'zoom' => 0));
check('0,0 → no coordinates, legacy row without raw', !$w0->hasCoordinates() && $w0->raw === array());
$wEq = $ft->wakeupValue($page, $field, array('data' => '', 'lat' => '0.0000000', 'lng' => '-78.5', 'status' => 0, 'zoom' => 0));
check('equator kept', $wEq->lat === '0' && $wEq->lng === '-78.5');
$wLeaf = $ft->wakeupValue($page, $field, array('data' => 'x', 'lat' => '1', 'lng' => '2', 'status' => 1, 'zoom' => 0, 'raw' => '[{"lat":"1"}]'));
check('foreign raw (Leaflet) ignored', $wLeaf->raw === array());

$w->address = 'Berlin';
$sleep = $ft->sleepValue($page, $field, $w);
check('changed address geocoded on sleep', MapMarkerPlusGeocoderFake::$calls === 1 && (float) $sleep['lat'] === 52.5170365);
check('raw stored as JSON with provider', json_decode($sleep['raw'], true)['provider'] === 'photon');
check('formatted from raw', $w->formatted === 'Berlin, Germany' && $w->geocoder === 'photon');
$ft->sleepValue($page, $field, $w);
check('same address not geocoded twice', MapMarkerPlusGeocoderFake::$calls === 1);

$off = $ft->wakeupValue($page, $field, array('data' => 'a', 'lat' => '1', 'lng' => '2', 'status' => -100, 'zoom' => 0));
$off->address = 'Somewhere else';
$ft->sleepValue($page, $field, $off);
check('status -100: no geocode', MapMarkerPlusGeocoderFake::$calls === 1 && $off->lat === '1');

$fieldNone = clone $field;
$fieldNone->set('geocoder', 'none');
$none = $ft->wakeupValue($page, $fieldNone, array('data' => 'a', 'lat' => '1', 'lng' => '2', 'status' => 1, 'zoom' => 0));
$none->address = 'Other';
$ft->sleepValue($page, $fieldNone, $none);
check('geocoder none: no geocode', MapMarkerPlusGeocoderFake::$calls === 1);

$fail = $ft->wakeupValue($page, $field, array('data' => 'a', 'lat' => '1', 'lng' => '2', 'status' => 1, 'zoom' => 0));
$fail->address = 'nowhere-zero';
$fail->geocode(false);
check('failed geocode keeps coordinates, sets status', $fail->lat === '1' && $fail->status === -2);

$empty = $ft->sleepValue($page, $field, $ft->getBlankValue($page, $field));
check('empty value sleeps as 0,0 and blank raw', $empty['lat'] === 0 && $empty['raw'] === '');

$arr = $ft->sanitizeValue($page, $field, array('lat' => '1.5', 'lng' => '2.5', 'address' => 'API'));
check('sanitizeValue accepts array', $arr instanceof MapMarkerPlus && $arr->lat === '1.5' && $arr->address === 'API');

echo "== schema\n";
$schema = $ft->getDatabaseSchema($field);
check('DECIMAL coordinates', $schema['lat'] === 'DECIMAL(10,7) NOT NULL DEFAULT 0');
check('raw MEDIUMTEXT without DEFAULT', $schema['raw'] === 'MEDIUMTEXT NOT NULL');
$tmp = clone $field;
$tmp->name = 'geopoint_PWTMP';
$tmp->id = 999999;
check('_PWTMP clone never touched', $ft->updateDatabaseSchema($tmp) === array());

echo "== geocoder parsers\n";
$cfg = array('googleMapsKey' => 'k', 'yandexMapsKey' => 'k', 'maptilerKey' => 'k');
$parse = function($class, $fixture, $reverse = false) use($wire, $cfg) {
	$class = __NAMESPACE__ . "\\$class";
	$g = $wire->wire(new $class($cfg));
	return $g->parseResponse(fixture($fixture), $reverse);
};
$r = $parse('MapMarkerPlusGeocoderGoogle', 'google-ok');
check('google ok approximate', $r->ok() && $r->status === 5 && abs($r->lat - 26.0836) < 1e-9);
$r = $parse('MapMarkerPlusGeocoderGoogle', 'google-rooftop');
check('google rooftop = 2', $r->status === 2 && $r->accuracy === 'rooftop');
check('google denied = -4', $parse('MapMarkerPlusGeocoderGoogle', 'google-denied')->status === -4);
check('google zero = -2', $parse('MapMarkerPlusGeocoderGoogle', 'google-zero')->status === -2);
$r = $parse('MapMarkerPlusGeocoderYandex', 'yandex-ok');
check('yandex pos is "lng lat"', $r->ok() && abs($r->lat - 55.757218) < 1e-9 && abs($r->lng - 37.611347) < 1e-9 && $r->status === 2);
check('yandex empty = -2', $parse('MapMarkerPlusGeocoderYandex', 'yandex-empty')->status === -2);
check('yandex 403 = -4', $parse('MapMarkerPlusGeocoderYandex', 'yandex-forbidden')->status === -4);
$r = $parse('MapMarkerPlusGeocoderNominatim', 'nominatim-ok');
check('nominatim building = rooftop', $r->ok() && $r->status === 2);
check('nominatim empty = -2', $parse('MapMarkerPlusGeocoderNominatim', 'nominatim-empty')->status === -2);
$r = $parse('MapMarkerPlusGeocoderNominatim', 'nominatim-reverse-ok', true);
check('nominatim reverse', $r->ok() && strpos($r->formatted, 'Чегет') === 0);
check('nominatim reverse sea = -2', $parse('MapMarkerPlusGeocoderNominatim', 'nominatim-reverse-sea', true)->status === -2);
$r = $parse('MapMarkerPlusGeocoderPhoton', 'photon-ok');
check('photon city approximate', $r->ok() && $r->status === 5 && $r->formatted === 'Berlin, Germany');
$r = $parse('MapMarkerPlusGeocoderPhoton', 'photon-house');
check('photon house rooftop + formatted', $r->status === 2 && $r->formatted === 'Тверская улица 7, 125009 Москва, Россия');
check('photon empty = -2', $parse('MapMarkerPlusGeocoderPhoton', 'photon-empty')->status === -2);
$r = $parse('MapMarkerPlusGeocoderMapTiler', 'maptiler-ok');
check('maptiler poi', $r->ok() && $r->status === 2 && abs($r->lng - 2.2944) < 1e-9);
check('maptiler empty = -2', $parse('MapMarkerPlusGeocoderMapTiler', 'maptiler-empty')->status === -2);

$yandex = $wire->wire(new MapMarkerPlusGeocoderYandex(array('yandexMapsKey' => 'K')));
check('yandex reverse url is lng,lat', strpos($yandex->reverseUrl(55.1, 37.2, 'ru'), 'geocode=37.2%2C55.1') !== false && strpos($yandex->reverseUrl(55.1, 37.2, 'ru'), 'lang=ru_RU') !== false);
$photon = $wire->wire(new MapMarkerPlusGeocoderPhoton(array()));
check('photon drops unsupported lang', strpos($photon->forwardUrl('x', 'ru'), 'lang=') === false && strpos($photon->forwardUrl('x', 'de'), 'lang=de') !== false);
$google = $wire->wire(new MapMarkerPlusGeocoderGoogle(array()));
check('google without key not configured', !$google->isConfigured() && $google->geocode('x')->status === -4);

echo "== providers / styles / client config\n";
check('registry parsed', count(FieldtypeMapMarkerPlus::getStylesRegistry()['maplibre']) >= 9);
$styles = $ft->getMapStyles('maplibre');
check('key-gated styles hidden without key', isset($styles['osm'], $styles['esri-imagery']) && !isset($styles['maptiler-streets']) && !isset($styles['carto-voyager']));
$ft->set('maptilerKey', 'MT KEY');
$sources = $ft->getMapSources('maplibre');
$mt = array_values(array_filter($sources, function($s) { return $s['id'] === 'maptiler-streets'; }));
check('maptiler source with url-encoded key', count($mt) === 1 && strpos($mt[0]['tiles'][0], 'key=MT%20KEY') !== false);
$ft->set('maptilerKey', '');
$fieldG = clone $field;
$fieldG->set('mapProvider', 'google');
$fieldG->set('styleGoogle', 'hybrid');
$ft->set('googleMapsKey', 'GKEY');
$c = $ft->getClientConfig($fieldG);
check('google client config', $c['provider'] === 'google' && $c['style'] === 'hybrid' && $c['keys']['google'] === 'GKEY' && !isset($c['sources']));
$ft->set('googleMapsKey', '');
$c = $ft->getClientConfig($field, array('style' => 'bogus', 'overlays' => array('skyways', 'openaip')));
check('maplibre default: osm, overlays filtered by key', $c['provider'] === 'maplibre' && $c['style'] === 'osm' && $c['overlays'] === array('skyways') && $c['terrain']['type'] === 'raster-dem');
check('no secret keys in maplibre config', !isset($c['keys']['google']) && strpos(json_encode($c), 'googleGeocodingKey') === false);

echo "== inputfield\n";
$in = $wire->wire(new InputfieldMapMarkerPlus());
$in->hasField = $field;
$in->attr('name', 'mmp_test');
$in->attr('id', 'Inputfield_mmp_test');
$val = $ft->wakeupValue($page, $field, array('data' => "O'Hare <b>", 'lat' => '41.97', 'lng' => '-87.9', 'status' => 1, 'zoom' => 9));
$in->attr('value', $val);
$out = $in->render();
check('address tags stripped, quote escaped', strpos($out, "value='O&#039;Hare'") !== false);
check('data-config has no raw quote/tag', preg_match("/data-config='([^']*)'/", $out, $mm) === 1 && strpos($mm[1], '<') === false);
$cfg = json_decode(html_entity_decode($mm[1], ENT_QUOTES), true);
check('data-config decodes', is_array($cfg) && $cfg['marker']['lat'] === 41.97 && $cfg['field'] === 'mmp_test' && $cfg['geocoder'] === 'fake');
check('same input names as upstream', strpos($out, "name='_mmp_test_lat'") !== false && strpos($out, "name='_mmp_test_js_geocode_address'") !== false && strpos($out, "name='_mmp_test_status'") !== false);

MapMarkerPlusGeocoderFake::$calls = 0;
$post = array('mmp_test' => 'Berlin', '_mmp_test_lat' => '1', '_mmp_test_lng' => '2', '_mmp_test_zoom' => '14', '_mmp_test_status' => '1', '_mmp_test_js_geocode_address' => '');
$in->processInput(new WireInputData($post));
check('processInput geocodes changed address', $val->address === 'Berlin' && MapMarkerPlusGeocoderFake::$calls === 1 && $val->lat === '52.5170365' && $val->zoom === 14);

$val2 = $ft->wakeupValue($page, $field, array('data' => 'Old', 'lat' => '1', 'lng' => '2', 'status' => 1, 'zoom' => 0));
$in->attr('value', $val2);
$raw = json_encode(array('provider' => 'photon', 'formatted' => 'Formatted <x>', 'accuracy' => 'rooftop', 'result' => array('evil' => 1)));
$post = array('mmp_test' => 'Dragged here', '_mmp_test_lat' => '10.5', '_mmp_test_lng' => '20,25', '_mmp_test_zoom' => '', '_mmp_test_status' => '2', '_mmp_test_js_geocode_address' => 'Dragged here', '_mmp_test_raw' => $raw);
$in->processInput(new WireInputData($post));
check('JS-geocoded address not re-geocoded', MapMarkerPlusGeocoderFake::$calls === 1 && $val2->lat === '10.5' && $val2->lng === '20.25' && $val2->skipGeocode);
check('posted raw sanitized', $val2->raw['provider'] === 'photon' && $val2->raw['result'] === array() && strpos($val2->formatted, '<') === false);
$post = array('mmp_test' => 'Dragged here', '_mmp_test_lat' => '', '_mmp_test_lng' => '5', '_mmp_test_zoom' => '3');
$in->processInput(new WireInputData($post));
check('unchecked toggle = -100, half coordinate cleared', $val2->status === MapMarkerPlus::statusNoGeocode && !$val2->hasCoordinates());

echo "== admin provider / experimental\n";
check('MapLibre + fake geocoder: no experimental note', strpos($out, 'InputfieldMapMarkerPlusNote') === false);
$fieldGx = clone $field;
$fieldGx->set('mapProvider', 'google');
$fieldGx->set('geocoder', 'yandex');
check('admin map defaults to MapLibre for a Google field', $ft->getAdminMapProvider($fieldGx) === 'maplibre' && $ft->getMapProvider($fieldGx) === 'google');
$inG = $ft->getInputfield($page, $fieldGx);
check('inputfield client config uses the admin provider', $inG->clientConfig['provider'] === 'maplibre' && isset($inG->clientConfig['sources']));
$inG->hasField = $fieldGx;
$inG->attr('name', 'mmp_g');
$inG->attr('value', $ft->getBlankValue($page, $fieldGx));
$outG = $inG->render();
check('experimental note next to the inputs', strpos($outG, 'InputfieldMapMarkerPlusNote') !== false && strpos($outG, 'Google Maps map on the frontend') !== false && strpos($outG, 'Yandex geocoder') !== false);
$ft->set('adminMapProvider', 'field');
check('admin provider "field" follows the field', $ft->getAdminMapProvider($fieldGx) === 'google' && $ft->getInputfield($page, $fieldGx)->clientConfig['provider'] === 'google');
$ft->set('adminMapProvider', 'maplibre');
$labels = $ft->getProviderLabels();
check('provider labels mark Google/Yandex experimental', strpos($labels['google'], 'experimental') !== false && strpos($labels['yandex'], 'experimental') !== false && strpos($labels['maplibre'], 'experimental') === false);
check('geocoder labels mark Google/Yandex experimental', strpos($ft->getGeocoderLabel('yandex'), 'experimental') !== false && strpos($ft->getGeocoderLabel('photon'), 'experimental') === false);

echo "== endpoint\n";
$users = $wire->users;
$users->setCurrentUser($users->getGuestUser());
$res = $ft->geocodeRequest(input(array('field' => '', 'q' => 'Berlin')), true);
check('guest gets 403', $res['ok'] === false && $res['error'] === 'Not allowed');
$su = $users->get('roles=superuser, sort=id');
$users->setCurrentUser($su);
$res = $ft->geocodeRequest(input(array('q' => '')), true);
check('missing q → error', $res['ok'] === false);
$res = $ft->geocodeRequest(input(array('q' => 'Berlin')), false);
check('CSRF required', $res['ok'] === false && strpos($res['error'], 'CSRF') !== false);
$ft->set('defaultGeocoder', 'fake');
$res = $ft->geocodeRequest(input(array('q' => 'Berlin')), true);
check('forward ok via default geocoder', $res['ok'] === true && $res['formatted'] === 'Berlin, Germany' && $res['statusString'] === 'OK APPROXIMATE' && !isset($res['raw']));
$res = $ft->geocodeRequest(input(array('lat' => '43.2567', 'lng' => '42,4895')), true);
check('reverse ok', $res['ok'] === true && strpos($res['formatted'], 'Чегет') === 0);
$res = $ft->geocodeRequest(input(array('field' => 'title', 'q' => 'x')), true);
check('non-MapMarkerPlus field rejected', $res['ok'] === false);
$users->setCurrentUser($users->getGuestUser());

echo "== markup\n";
/** @var MarkupMapMarkerPlus $markup */
$markup = $wire->wire(new MarkupMapMarkerPlus());
$p = new Page($wire->pages->get(1)->template);
$p->of(false);
$p->title = '</script><img src=x onerror=alert(1)>';
$p->name = 'test';
$mk = $ft->wakeupValue($p, $field, array('data' => 'a', 'lat' => '1', 'lng' => '2', 'status' => 1, 'zoom' => 0));
$opts = $markup->getOptionsForField($field);
$data = $markup->getMarkerData($p, $field, $mk, array_merge($opts, array('markerLinkField' => '', 'popupCallback' => function($page, $marker) { return '<b>' . $marker->lat . '</b>'; })));
check('marker title stripped of tags', $data['title'] === '' || strpos($data['title'], '<') === false);
check('popup callback', $data['popup'] === '<b>1</b>');
$html = $markup->renderElement(array('provider' => 'maplibre'), array(array('lat' => 1, 'lng' => 2, 'title' => '</script><b>x', 'popup' => "<i>'\"</i>")), array('id' => 'm1', 'height' => 400));
check('script tag once', substr_count($markup->renderElement(array(), array(), array('id' => 'm2')) . $html, 'frontend.js') === 1);
check('no raw </script> inside JSON', substr_count($html, '</script>') === 2 && strpos($html, '</script>') !== false);
check('JSON decodes back', preg_match('~<script type="application/json">(.*?)</script>~s', $html, $jm) === 1 && json_decode($jm[1], true)['markers'][0]['popup'] === "<i>'\"</i>");
check('height px style', strpos($html, 'height: 400px') !== false);
$html = $markup->renderElement(array(), array(), array('id' => 'x"y', 'init' => 'myInit', 'attrs' => array('data-a' => '"<')));
check('attributes escaped, init name as data-init', strpos($html, 'id="x&quot;y"') !== false && strpos($html, 'data-init="myInit"') !== false && strpos($html, 'data-a="&quot;&lt;"') !== false);

echo "== migration\n";
$mig = $wire->wire(new MapMarkerPlusMigration());
list($s, $notes) = $mig->mapSettings(array('defaultProvider' => 'Esri.WorldImagery', 'defaultLat' => '43.25', 'defaultLng' => '42.5', 'defaultZoom' => 9, 'height' => 400, 'defaultAddr' => 'Castaway Cay'), 'FieldtypeLeafletMapMarker', array());
check('leaflet provider → maplibre style', $s['mapProvider'] === 'maplibre' && $s['styleMaplibre'] === 'esri-imagery' && $s['defaultLat'] === '43.25' && !isset($s['defaultAddr']));
list($s) = $mig->mapSettings(array('defaultProvider' => 'CartoDB.Positron'), 'FieldtypeLeafletMapMarker', array());
check('carto without key → osm', $s['styleMaplibre'] === 'osm');
list($s) = $mig->mapSettings(array('defaultType' => 'ROADMAP'), 'FieldtypeMapMarker', array('googleMapsKey' => 'k'));
check('mapmarker with key → google roadmap', $s['mapProvider'] === 'google' && $s['styleGoogle'] === 'roadmap');
list($s) = $mig->mapSettings(array('defaultType' => 'HYBRID'), 'FieldtypeMapMarker', array());
check('mapmarker without key → maplibre satellite', $s['mapProvider'] === 'maplibre' && $s['styleMaplibre'] === 's2cloudless');

$ft->init();
$legacy = null;
foreach($wire->fields as $f) {
	if($f->type && MapMarkerPlusMigration::isSourceType($f->type)) { $legacy = $f; break; }
}
if($legacy) {
	$types = $wire->fields->getCompatibleFieldtypes($legacy);
	check("type select of '$legacy->name' ({$legacy->type}) offers MapMarkerPlus", $types->has($ft) && $types->first() === $legacy->type);
} else {
	echo "skip no FieldtypeMapMarker/FieldtypeLeafletMapMarker field in this install\n";
}
$titleField = $wire->fields->get('title');
check('other types unaffected', !$wire->fields->getCompatibleFieldtypes($titleField)->has($ft));

echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
