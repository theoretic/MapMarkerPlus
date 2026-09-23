<?php namespace ProcessWire;

/**
 * ProcessWire MapMarkerPlus Fieldtype
 *
 * Holds an address with latitude, longitude and zoom. Geocodes with a selectable provider
 * (Photon, Nominatim, Google, Yandex, MapTiler, or your own via hook) and shows maps with
 * a selectable renderer (MapLibre via atis.pro map-engine, Google Maps, Yandex Maps) in 2D or 3D.
 *
 * Fork of FieldtypeMapMarker, Copyright (C) 2023 by Ryan Cramer.
 * Licensed under MPL 2.0
 *
 * https://processwire.com
 *
 * Module config:
 * @property string $googleMapsKey Browser key for the Google Maps JavaScript API
 * @property string $googleGeocodingKey Server key for the Google Geocoding API (falls back to googleMapsKey)
 * @property string $googleMapId Map ID, needed for vector maps (tilt, 3D) and advanced markers
 * @property string $yandexMapsKey Key for Yandex Maps JavaScript API v3
 * @property string $yandexGeocoderKey Key for the Yandex HTTP Geocoder (falls back to yandexMapsKey)
 * @property string $maptilerKey
 * @property string $cartoKey
 * @property string $openaipKey
 * @property string $nominatimUrl
 * @property string $nominatimEmail
 * @property string $photonUrl
 * @property string $defaultMapProvider maplibre|google|yandex
 * @property string $defaultGeocoder photon|nominatim|google|yandex|maptiler|none
 * @property string $defaultLat
 * @property string $defaultLng
 * @property int $defaultZoom
 * @property string $geocodeLang
 * @property int $cacheTtl
 * @property string $terrainTiles
 * @property string $terrainEncoding terrarium|mapbox
 * @property float $terrainExaggeration
 *
 * @method array getGeocoders() Geocoder name => class name, hook after to add providers
 * @method string getEndpointPath()
 *
 */

require_once(__DIR__ . '/MapMarkerPlus.php');
require_once(__DIR__ . '/lib/MapMarkerPlusGeocodeResult.php');
require_once(__DIR__ . '/lib/MapMarkerPlusGeocoder.php');
require_once(__DIR__ . '/lib/MapMarkerPlusGeocoderGoogle.php');
require_once(__DIR__ . '/lib/MapMarkerPlusGeocoderYandex.php');
require_once(__DIR__ . '/lib/MapMarkerPlusGeocoderNominatim.php');
require_once(__DIR__ . '/lib/MapMarkerPlusGeocoderPhoton.php');
require_once(__DIR__ . '/lib/MapMarkerPlusGeocoderMapTiler.php');
require_once(__DIR__ . '/lib/MapMarkerPlusMigration.php');

class FieldtypeMapMarkerPlus extends Fieldtype implements ConfigurableModule {

	const version = '1.0.0';

	/**
	 * Database schema version: 0/1 = FieldtypeMapMarker, 2 = FieldtypeLeafletMapMarker, 3 = this
	 *
	 */
	const schemaVersion = 3;

	const providers = array('maplibre', 'google', 'yandex');

	const defaultStyles = array('maplibre' => 'osm', 'google' => 'roadmap', 'yandex' => 'scheme');

	public static function getModuleInfo() {
		return array(
			'title' => 'MapMarkerPlus',
			'version' => self::version,
			'summary' => 'Address with latitude/longitude. Selectable geocoder (Photon, Nominatim, Google, Yandex, MapTiler) and map (MapLibre, Google, Yandex), 2D/3D.',
			'installs' => array('InputfieldMapMarkerPlus', 'MarkupMapMarkerPlus'),
			'requires' => 'ProcessWire>=3.0.200, PHP>=7.4',
			'autoload' => true,
			'icon' => 'map-marker',
		);
	}

	/**
	 * Field settings stashed between Fields::changeTypeReady and Fields::changedType
	 *
	 * @var array
	 *
	 */
	protected $migrationStash = array();

	/**
	 * Decoded src/providers/styles.json
	 *
	 * @var array|null
	 *
	 */
	protected static $styles = null;

	public function __construct() {
		parent::__construct();
		foreach($this->getModuleConfigDefaults() as $key => $value) $this->set($key, $value);
	}

	/**
	 * Default module configuration
	 *
	 * @return array
	 *
	 */
	public function getModuleConfigDefaults() {
		return array(
			'googleMapsKey' => '',
			'googleGeocodingKey' => '',
			'googleMapId' => '',
			'yandexMapsKey' => '',
			'yandexGeocoderKey' => '',
			'maptilerKey' => '',
			'cartoKey' => '',
			'openaipKey' => '',
			'nominatimUrl' => 'https://nominatim.openstreetmap.org',
			'nominatimEmail' => '',
			'photonUrl' => 'https://photon.komoot.io',
			'defaultMapProvider' => 'maplibre',
			'defaultGeocoder' => 'photon',
			'defaultLat' => '20',
			'defaultLng' => '0',
			'defaultZoom' => 2,
			'geocodeLang' => '',
			'cacheTtl' => 2592000,
			'terrainTiles' => 'https://s3.amazonaws.com/elevation-tiles-prod/terrarium/{z}/{x}/{y}.png',
			'terrainEncoding' => 'terrarium',
			'terrainExaggeration' => 1.0,
		);
	}

	/**
	 * Current module configuration as array
	 *
	 * @return array
	 *
	 */
	public function getModuleConfig() {
		$a = array();
		foreach(array_keys($this->getModuleConfigDefaults()) as $key) $a[$key] = $this->get($key);
		return $a;
	}

	public function init() {
		parent::init();
		$this->addHookAfter('Fieldtype::getCompatibleFieldtypes', $this, 'hookCompatibleFieldtypes');
		$this->addHookAfter('Fields::changeTypeReady', $this, 'hookChangeTypeReady');
		$this->addHookAfter('Fields::changedType', $this, 'hookChangedType');
		$this->addHook($this->getEndpointPath(), $this, 'hookGeocodeEndpoint');
	}

	/**
	 * URL path of the geocode endpoint used by the inputfield
	 *
	 * @return string
	 *
	 */
	public function ___getEndpointPath() {
		return '/mapmarkerplus/geocode/';
	}

	/*********************************************************************************
	 * Fieldtype
	 *
	 */

	/**
	 * @param Page $page
	 * @param Field $field
	 * @return InputfieldMapMarkerPlus
	 *
	 */
	public function getInputfield(Page $page, Field $field) {
		/** @var InputfieldMapMarkerPlus $inputfield */
		$inputfield = $this->wire()->modules->get('InputfieldMapMarkerPlus');
		$inputfield->set('clientConfig', $this->getClientConfig($field));
		return $inputfield;
	}

	/**
	 * Only other fieldtypes can convert to this one (see hookCompatibleFieldtypes)
	 *
	 * @param Field $field
	 * @return null
	 *
	 */
	public function ___getCompatibleFieldtypes(Field $field) {
		return null;
	}

	/**
	 * @param Page $page
	 * @param Field $field
	 * @param MapMarkerPlus|array|null $value
	 * @return MapMarkerPlus
	 *
	 */
	public function sanitizeValue(Page $page, Field $field, $value) {
		if(is_array($value)) {
			$marker = $this->getBlankValue($page, $field);
			foreach(array('address', 'lat', 'lng', 'zoom', 'status') as $key) {
				if(array_key_exists($key, $value)) $marker->set($key, $value[$key]);
			}
			$value = $marker;
		}
		if(!$value instanceof MapMarkerPlus) $value = $this->getBlankValue($page, $field);
		if(!$value->getField()) $value->setField($field);
		if($value->isChanged()) $page->trackChange($field->name);
		return $value;
	}

	/**
	 * @param Page $page
	 * @param Field $field
	 * @return MapMarkerPlus
	 *
	 */
	public function getBlankValue(Page $page, Field $field) {
		$value = new MapMarkerPlus();
		$this->wire($value);
		$value->setField($field);
		$value->setTrackChanges(true);
		return $value;
	}

	/**
	 * @param Field $field
	 * @param mixed $value
	 * @return bool
	 *
	 */
	public function isEmptyValue(Field $field, $value) {
		if($value instanceof MapMarkerPlus) return $value->isEmpty();
		return empty($value);
	}

	/**
	 * @param Page $page
	 * @param Field $field
	 * @param array $value Row from the database
	 * @return MapMarkerPlus
	 *
	 */
	public function ___wakeupValue(Page $page, Field $field, $value) {
		$marker = $this->getBlankValue($page, $field);
		$marker->setTrackChanges(false);
		if(!is_array($value)) $value = array();

		$lat = isset($value['lat']) ? self::trimDecimal($value['lat']) : '';
		$lng = isset($value['lng']) ? self::trimDecimal($value['lng']) : '';
		// 0,0 is how "no coordinates" is stored (NOT NULL columns)
		if((float) $lat === 0.0 && (float) $lng === 0.0) $lat = $lng = '';

		$marker->set('address', isset($value['data']) ? $value['data'] : '');
		$marker->set('lat', $lat);
		$marker->set('lng', $lng);
		$marker->set('status', isset($value['status']) ? $value['status'] : 0);
		$marker->set('zoom', isset($value['zoom']) ? $value['zoom'] : 0);
		$raw = isset($value['raw']) ? $value['raw'] : '';
		$raw = is_string($raw) && $raw !== '' ? json_decode($raw, true) : $raw;
		// only keep raw data in our own format ("v" key); FieldtypeLeafletMapMarker stored provider responses
		$marker->set('raw', is_array($raw) && isset($raw['v']) ? $raw : array());

		$marker->resetTrackChanges(true);
		return $marker;
	}

	/**
	 * @param Page $page
	 * @param Field $field
	 * @param MapMarkerPlus $value
	 * @return array
	 * @throws WireException
	 *
	 */
	public function ___sleepValue(Page $page, Field $field, $value) {
		if(!$value instanceof MapMarkerPlus) throw new WireException('Expecting an instance of MapMarkerPlus');
		$marker = $value;

		if($marker->isChanged('address') && $marker->address !== '' && $marker->status != MapMarkerPlus::statusNoGeocode) {
			if($this->getGeocoderName($field) !== 'none') $marker->geocode();
		}

		$raw = $marker->raw;
		return array(
			'data' => $marker->address,
			'lat' => $marker->hasCoordinates() ? $marker->lat : 0,
			'lng' => $marker->hasCoordinates() ? $marker->lng : 0,
			'status' => $marker->status,
			'zoom' => $marker->zoom,
			'raw' => is_array($raw) && count($raw) ? json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
		);
	}

	/**
	 * Markup for Lister columns and similar
	 *
	 * @param Page $page
	 * @param Field $field
	 * @param MapMarkerPlus|null $value
	 * @param string $property
	 * @return string
	 *
	 */
	public function ___markupValue(Page $page, Field $field, $value = null, $property = '') {
		if($value === null) $value = $page->get($field->name);
		if(!$value instanceof MapMarkerPlus) return '';
		$sanitizer = $this->wire()->sanitizer;
		if($property !== '') {
			$v = $value->get($property);
			return is_scalar($v) ? $sanitizer->entities((string) $v) : '';
		}
		return $sanitizer->entities((string) $value);
	}

	/**
	 * @param Field $field
	 * @return array
	 *
	 */
	public function getDatabaseSchema(Field $field) {
		$schema = parent::getDatabaseSchema($field);
		$schema['data'] = "VARCHAR(255) NOT NULL DEFAULT ''";	// address
		$schema['lat'] = 'DECIMAL(10,7) NOT NULL DEFAULT 0';	// latitude
		$schema['lng'] = 'DECIMAL(10,7) NOT NULL DEFAULT 0';	// longitude
		$schema['status'] = 'TINYINT NOT NULL DEFAULT 0';		// geocode status
		$schema['zoom'] = 'TINYINT NOT NULL DEFAULT 0';			// zoom level
		$schema['raw'] = 'MEDIUMTEXT NOT NULL';					// last geocode result (JSON)
		$schema['keys']['latlng'] = 'KEY latlng (lat, lng)';
		$schema['keys']['data'] = 'FULLTEXT KEY `data` (`data`)';
		$schema['keys']['zoom'] = 'KEY zoom (zoom)';
		return $schema;
	}

	/**
	 * Bring an existing table up to the current schema (FLOAT coordinates, missing columns)
	 *
	 * Called from ___upgrade() and after a type change, never from getDatabaseSchema(): that one also runs
	 * for the temporary "_PWTMP" clone inside Fields::changeFieldtype(), which shares the ID of the real field.
	 *
	 * @param Field $field
	 * @return array Names of the changes made
	 *
	 */
	public function updateDatabaseSchema(Field $field) {
		$done = array();
		if(!$field->id || substr($field->name, -6) === '_PWTMP') return $done;
		$database = $this->wire()->database;
		$table = $database->escapeTable($field->getTable());
		if(!$database->tableExists($table)) return $done;

		$columns = array();
		$query = $database->prepare("SHOW COLUMNS FROM `$table`");
		$query->execute();
		while($row = $query->fetch(\PDO::FETCH_ASSOC)) $columns[strtolower($row['Field'])] = strtolower($row['Type']);
		$query->closeCursor();

		$schema = $this->getDatabaseSchema($field);
		$alter = array();
		foreach(array('lat', 'lng') as $col) {
			if(isset($columns[$col]) && strpos($columns[$col], 'decimal(10,7)') !== 0) {
				$alter[] = "MODIFY `$col` $schema[$col]";
				$done[] = "$col: $columns[$col] => DECIMAL(10,7)";
			}
		}
		if(!isset($columns['zoom'])) {
			$alter[] = "ADD `zoom` $schema[zoom] AFTER `status`";
			$done[] = 'added zoom';
		}
		if(!isset($columns['raw'])) {
			$alter[] = "ADD `raw` $schema[raw]";
			$done[] = 'added raw';
		}
		if(count($alter)) {
			try {
				$database->exec("ALTER TABLE `$table` " . implode(', ', $alter));
				if(strpos(implode(' ', $alter), 'MODIFY') !== false) $this->roundLegacyCoordinates($field);
				$this->message(sprintf($this->_('Updated table %1$s: %2$s'), $table, implode(', ', $done)));
			} catch(\Exception $e) {
				$this->error($e->getMessage());
				return array();
			}
		}
		if((int) $field->get('schemaVersion') !== self::schemaVersion) {
			$field->set('schemaVersion', self::schemaVersion);
			$field->save();
		}
		return $done;
	}

	/**
	 * Round coordinates that came from FLOAT(10,6) columns to 6 decimals
	 *
	 * FLOAT is single precision: 43.256712 is stored as 43.2567139..., which DECIMAL(10,7) would show.
	 * Rounding restores what the old columns displayed, so templates print the same values as before.
	 *
	 * @param Field $field
	 *
	 */
	protected function roundLegacyCoordinates(Field $field) {
		$database = $this->wire()->database;
		$table = $database->escapeTable($field->getTable());
		$database->exec("UPDATE `$table` SET lat = ROUND(lat, 6), lng = ROUND(lng, 6)");
	}

	/**
	 * Update tables of all fields of this type when the module version changes
	 *
	 * @param int|string $fromVersion
	 * @param int|string $toVersion
	 *
	 */
	public function ___upgrade($fromVersion, $toVersion) {
		foreach($this->wire()->fields->findByType($this) as $field) {
			$this->updateDatabaseSchema($field);
		}
	}

	/**
	 * Copy the Google key of FieldtypeMapMarker, if that one is installed
	 *
	 */
	public function ___install() {
		parent::___install();
		$modules = $this->wire()->modules;
		if(!$modules->isInstalled('FieldtypeMapMarker')) return;
		$old = $modules->getConfig('FieldtypeMapMarker');
		if(empty($old['googleApiKey'])) return;
		$modules->saveConfig($this, array(
			'googleMapsKey' => $old['googleApiKey'],
			'googleGeocodingKey' => $old['googleApiKey'],
			'defaultMapProvider' => 'google',
			'defaultGeocoder' => 'google',
		));
		$this->message($this->_('Copied the Google API key from FieldtypeMapMarker.'));
	}

	/**
	 * Selectors: address (FULLTEXT or SQL operators), lat, lng, zoom, status
	 *
	 * @param PageFinderDatabaseQuerySelect|DatabaseQuerySelect $query
	 * @param string $table
	 * @param string $subfield
	 * @param string $operator
	 * @param string $value
	 * @return DatabaseQuerySelect
	 * @throws PageFinderSyntaxException
	 *
	 */
	public function getMatchQuery($query, $table, $subfield, $operator, $value) {
		if(!$subfield || $subfield === 'address') $subfield = 'data';
		if(!in_array($subfield, array('data', 'lat', 'lng', 'zoom', 'status'), true)) {
			throw new PageFinderSyntaxException("Unknown subfield '$subfield' for $this->className (use address, lat, lng, zoom or status)");
		}
		if($subfield !== 'data' || $this->wire()->database->isOperator($operator)) {
			return parent::getMatchQuery($query, $table, $subfield, $operator, $value);
		}
		$ft = new DatabaseQuerySelectFulltext($query);
		$this->wire($ft);
		$ft->match($table, $subfield, $operator, $value);
		return $query;
	}

	/**
	 * Field settings (Details tab)
	 *
	 * @param Field $field
	 * @return InputfieldWrapper
	 *
	 */
	public function ___getConfigInputfields(Field $field) {
		$inputfields = parent::___getConfigInputfields($field);
		$modules = $this->wire()->modules;
		$defaultProvider = $this->getDefaultProvider();
		$providerLabels = $this->getProviderLabels();

		/** @var InputfieldFieldset $fs */
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Map');
		$fs->icon = 'map';
		$inputfields->add($fs);

		/** @var InputfieldSelect $f */
		$f = $modules->get('InputfieldSelect');
		$f->attr('name', 'mapProvider');
		$f->label = $this->_('Map provider');
		$f->addOption('default', sprintf($this->_('Default (%s)'), $providerLabels[$defaultProvider]));
		foreach($providerLabels as $name => $label) {
			if(!$this->providerIsConfigured($name)) $label .= ' ' . $this->_('(key missing in module settings)');
			$f->addOption($name, $label);
		}
		$f->attr('value', $field->get('mapProvider') ?: 'default');
		$f->required = true;
		$f->columnWidth = 50;
		$fs->add($f);

		/** @var InputfieldRadios $f */
		$f = $modules->get('InputfieldRadios');
		$f->attr('name', 'mapMode');
		$f->label = $this->_('Map mode');
		$f->addOption('2d', '2D');
		$f->addOption('3d', $this->_('3D (MapLibre: terrain; Google: tilt, needs a Map ID; Yandex: tilt)'));
		$f->attr('value', $field->get('mapMode') === '3d' ? '3d' : '2d');
		$f->columnWidth = 50;
		$fs->add($f);

		foreach(self::providers as $provider) {
			$name = self::styleSettingName($provider);
			/** @var InputfieldSelect $f */
			$f = $modules->get('InputfieldSelect');
			$f->attr('name', $name);
			$f->label = sprintf($this->_('%s style'), $providerLabels[$provider]);
			foreach($this->getMapStyles($provider) as $id => $label) $f->addOption($id, $label);
			$f->attr('value', $this->getMapStyle($field, $provider));
			$f->required = true;
			$f->showIf = "mapProvider=$provider" . ($provider === $defaultProvider ? '|default' : '');
			if($provider === 'maplibre') {
				$f->notes = $this->_('Esri tiles may be blocked for some hosts. Sentinel-2 and kk7 layers are licensed for non-commercial use only. Carto and MapTiler styles appear when their keys are set in the module settings.');
			}
			$f->columnWidth = 50;
			$fs->add($f);
		}

		/** @var InputfieldCheckboxes $f */
		$f = $modules->get('InputfieldCheckboxes');
		$f->attr('name', 'mapOverlays');
		$f->label = $this->_('MapLibre overlays');
		foreach($this->getMapOverlays() as $id => $label) $f->addOption($id, $label);
		$f->attr('value', (array) $field->get('mapOverlays'));
		$f->showIf = 'mapProvider=maplibre' . ($defaultProvider === 'maplibre' ? '|default' : '');
		$f->columnWidth = 50;
		$fs->add($f);

		/** @var InputfieldCheckbox $f */
		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'allowModeToggle');
		$f->label = $this->_('Show 2D/3D switch on the map');
		$f->attr('checked', $field->get('allowModeToggle') ? 'checked' : '');
		$f->columnWidth = 50;
		$fs->add($f);

		/** @var InputfieldCheckbox $f */
		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'allowStyleSwitch');
		$f->label = $this->_('Show style switch on the map');
		$f->attr('checked', $field->get('allowStyleSwitch') ? 'checked' : '');
		$f->columnWidth = 50;
		$fs->add($f);

		/** @var InputfieldSelect $f */
		$f = $modules->get('InputfieldSelect');
		$f->attr('name', 'geocoder');
		$f->label = $this->_('Geocoder');
		$f->description = $this->_('Service that turns the address into coordinates (and back, when the marker is dragged).');
		$f->addOption('', sprintf($this->_('Default (%s)'), $this->getGeocoderLabel($this->defaultGeocoder)));
		$f->addOption('none', $this->_('None (enter coordinates manually)'));
		foreach(array_keys($this->getGeocoders()) as $name) {
			$geocoder = $this->getGeocoder($name);
			if(!$geocoder) continue;
			$label = $geocoder->getTitle();
			if(!$geocoder->isConfigured()) $label .= ' ' . $this->_('(key missing in module settings)');
			$f->addOption($name, $label);
		}
		$f->attr('value', (string) $field->get('geocoder'));
		$inputfields->add($f);

		return $inputfields;
	}

	/**
	 * Settings that may differ per template (field context)
	 *
	 * @param Field $field
	 * @return array
	 *
	 */
	public function ___getConfigAllowContext(Field $field) {
		$a = parent::___getConfigAllowContext($field);
		foreach(self::providers as $provider) $a[] = self::styleSettingName($provider);
		return array_merge($a, array('mapProvider', 'mapMode', 'mapOverlays', 'allowModeToggle', 'allowStyleSwitch'));
	}

	/*********************************************************************************
	 * Providers and styles
	 *
	 */

	/**
	 * @return array Provider name => label
	 *
	 */
	public function getProviderLabels() {
		return array(
			'maplibre' => $this->_('MapLibre (map-engine, OSM-based tiles)'),
			'google' => $this->_('Google Maps'),
			'yandex' => $this->_('Yandex Maps'),
		);
	}

	/**
	 * Does the map provider have its key?
	 *
	 * @param string $provider
	 * @return bool
	 *
	 */
	public function providerIsConfigured($provider) {
		if($provider === 'google') return $this->googleMapsKey !== '';
		if($provider === 'yandex') return $this->yandexMapsKey !== '';
		return true;
	}

	/**
	 * @return string
	 *
	 */
	public function getDefaultProvider() {
		$p = (string) $this->defaultMapProvider;
		return in_array($p, self::providers, true) ? $p : 'maplibre';
	}

	/**
	 * Map provider used by a field
	 *
	 * @param Field|null $field
	 * @return string maplibre|google|yandex
	 *
	 */
	public function getMapProvider(?Field $field = null) {
		$p = $field ? (string) $field->get('mapProvider') : '';
		return in_array($p, self::providers, true) ? $p : $this->getDefaultProvider();
	}

	/**
	 * Name of the field setting that holds the style for a provider, i.e. "styleMaplibre"
	 *
	 * @param string $provider
	 * @return string
	 *
	 */
	public static function styleSettingName($provider) {
		return 'style' . ucfirst($provider);
	}

	/**
	 * Map style used by a field
	 *
	 * @param Field|null $field
	 * @param string $provider Blank for the field's provider
	 * @return string
	 *
	 */
	public function getMapStyle(?Field $field = null, $provider = '') {
		if($provider === '') $provider = $this->getMapProvider($field);
		$style = $field ? (string) $field->get(self::styleSettingName($provider)) : '';
		$styles = $this->getMapStyles($provider);
		if(isset($styles[$style])) return $style;
		$default = self::defaultStyles[$provider];
		return isset($styles[$default]) ? $default : (string) key($styles);
	}

	/**
	 * Decoded styles.json (shared with the TypeScript sources)
	 *
	 * @return array
	 *
	 */
	public static function getStylesRegistry() {
		if(self::$styles === null) {
			$json = @file_get_contents(__DIR__ . '/src/providers/styles.json');
			$data = $json ? json_decode($json, true) : null;
			self::$styles = is_array($data) ? $data : array('maplibre' => array(), 'overlays' => array(), 'google' => array(), 'yandex' => array());
		}
		return self::$styles;
	}

	/**
	 * Styles usable for a provider (key-gated entries only when their key is set)
	 *
	 * @param string $provider
	 * @return array Style id => label
	 *
	 */
	public function getMapStyles($provider) {
		$registry = self::getStylesRegistry();
		$out = array();
		$list = isset($registry[$provider]) ? $registry[$provider] : array();
		foreach($list as $id => $style) {
			if(!empty($style['key']) && (string) $this->get($style['key']) === '') continue;
			$out[$id] = $style['name'];
		}
		return $out;
	}

	/**
	 * MapLibre overlays usable (key-gated entries only when their key is set)
	 *
	 * @return array Overlay id => label
	 *
	 */
	public function getMapOverlays() {
		return $this->getMapStyles('overlays');
	}

	/**
	 * MapLibre raster sources with keys substituted, in the map-engine MapSource format
	 *
	 * @param string $group "maplibre" or "overlays"
	 * @return array
	 *
	 */
	public function getMapSources($group = 'maplibre') {
		$registry = self::getStylesRegistry();
		$out = array();
		$list = isset($registry[$group]) ? $registry[$group] : array();
		foreach($list as $id => $s) {
			$key = '';
			if(!empty($s['key'])) {
				$key = (string) $this->get($s['key']);
				if($key === '') continue;
			}
			$source = array(
				'id' => $id,
				'name' => $s['name'],
				'tiles' => array_map(function($url) use($key) { return str_replace('{key}', rawurlencode($key), $url); }, $s['tiles']),
				'maxzoom' => (int) $s['maxzoom'],
				'attribution' => $s['attribution'],
			);
			if(!empty($s['scheme'])) $source['scheme'] = $s['scheme'];
			$out[] = $source;
		}
		return $out;
	}

	/**
	 * Configuration for the map scripts (inputfield and frontend)
	 *
	 * Contains only public keys: browser keys of the chosen provider and keys that are part of tile URLs anyway.
	 *
	 * @param Field|null $field
	 * @param array $options Overrides: provider, style, mode, overlays, allowModeToggle, allowStyleSwitch
	 * @return array
	 *
	 */
	public function getClientConfig(?Field $field = null, array $options = array()) {
		$provider = isset($options['provider']) && in_array($options['provider'], self::providers, true)
			? $options['provider'] : $this->getMapProvider($field);
		$styles = $this->getMapStyles($provider);
		$style = isset($options['style']) && isset($styles[$options['style']]) ? $options['style'] : $this->getMapStyle($field, $provider);
		$mode = isset($options['mode']) ? $options['mode'] : ($field ? $field->get('mapMode') : '2d');
		$overlays = isset($options['overlays']) ? (array) $options['overlays'] : ($field ? (array) $field->get('mapOverlays') : array());
		$overlays = array_values(array_intersect($overlays, array_keys($this->getMapOverlays())));

		$config = array(
			'provider' => $provider,
			'style' => $style,
			'styles' => $styles,
			'mode' => $mode === '3d' ? '3d' : '2d',
			'overlays' => $overlays,
			'allowModeToggle' => isset($options['allowModeToggle']) ? (bool) $options['allowModeToggle'] : ($field ? (bool) $field->get('allowModeToggle') : false),
			'allowStyleSwitch' => isset($options['allowStyleSwitch']) ? (bool) $options['allowStyleSwitch'] : ($field ? (bool) $field->get('allowStyleSwitch') : false),
			'lang' => $this->getLang(),
			'keys' => array(),
			'defaultLat' => (float) $this->defaultLat,
			'defaultLng' => (float) $this->defaultLng,
			'defaultZoom' => (int) $this->defaultZoom,
		);

		if($provider === 'google') {
			$config['keys'] = array('google' => $this->googleMapsKey, 'googleMapId' => $this->googleMapId);
		} else if($provider === 'yandex') {
			$config['keys'] = array('yandex' => $this->yandexMapsKey);
		} else {
			$config['sources'] = $this->getMapSources('maplibre');
			$config['overlaySources'] = $this->getMapSources('overlays');
			$tiles = trim((string) $this->terrainTiles);
			$config['terrain'] = $tiles === '' ? array('type' => 'none') : array(
				'type' => 'raster-dem',
				'tiles' => array($tiles),
				'encoding' => $this->terrainEncoding === 'mapbox' ? 'mapbox' : 'terrarium',
				'exaggeration' => (float) $this->terrainExaggeration ?: 1.0,
				'maxzoom' => 15,
			);
		}

		return $config;
	}

	/**
	 * Language code for geocoding and map labels
	 *
	 * @return string Two-letter code
	 *
	 */
	public function getLang() {
		$lang = strtolower(trim((string) $this->geocodeLang));
		if($lang === '') {
			$user = $this->wire()->user;
			$language = $user && $user->language ? $user->language : null;
			if($language && strlen($language->name) === 2) $lang = strtolower($language->name);
		}
		return preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'en';
	}

	/*********************************************************************************
	 * Geocoding
	 *
	 */

	/**
	 * Available geocoders
	 *
	 * Hook after to add a provider: `$event->return['mine'] = 'MyGeocoder';` where MyGeocoder
	 * extends MapMarkerPlusGeocoder (namespace ProcessWire, loaded by you).
	 *
	 * @return array Name => class name (in the ProcessWire namespace)
	 *
	 */
	public function ___getGeocoders() {
		return array(
			'photon' => 'MapMarkerPlusGeocoderPhoton',
			'nominatim' => 'MapMarkerPlusGeocoderNominatim',
			'google' => 'MapMarkerPlusGeocoderGoogle',
			'yandex' => 'MapMarkerPlusGeocoderYandex',
			'maptiler' => 'MapMarkerPlusGeocoderMapTiler',
		);
	}

	/**
	 * @param string $name
	 * @return string
	 *
	 */
	public function getGeocoderLabel($name) {
		if($name === 'none') return $this->_('None');
		$geocoder = $this->getGeocoder($name);
		return $geocoder ? $geocoder->getTitle() : (string) $name;
	}

	/**
	 * Name of the geocoder used by a field
	 *
	 * @param Field|null $field
	 * @return string Geocoder name or "none"
	 *
	 */
	public function getGeocoderName(?Field $field = null) {
		$name = $field ? (string) $field->get('geocoder') : '';
		if($name === '') $name = (string) $this->defaultGeocoder;
		if($name === 'none') return 'none';
		$geocoders = $this->getGeocoders();
		return isset($geocoders[$name]) ? $name : 'photon';
	}

	/**
	 * Get a geocoder instance
	 *
	 * @param string|Field|null $nameOrField Geocoder name, or field to use its geocoder, or null for the default
	 * @return MapMarkerPlusGeocoder|null Null for "none" or unknown
	 *
	 */
	public function getGeocoder($nameOrField = null) {
		$name = is_string($nameOrField) ? $nameOrField : $this->getGeocoderName($nameOrField instanceof Field ? $nameOrField : null);
		$geocoders = $this->getGeocoders();
		if(!isset($geocoders[$name])) return null;
		$class = wireClassName($geocoders[$name], true);
		if(!class_exists($class)) return null;
		$geocoder = new $class($this->getModuleConfig());
		if(!$geocoder instanceof MapMarkerPlusGeocoder) return null;
		$this->wire($geocoder);
		return $geocoder;
	}

	/**
	 * Geocode the address of a marker and apply the result to it
	 *
	 * @param MapMarkerPlus $marker
	 * @param Field|null $field
	 * @return MapMarkerPlusGeocodeResult
	 *
	 */
	public function geocodeMarker(MapMarkerPlus $marker, ?Field $field = null) {
		if(!$field) $field = $marker->getField();
		$geocoder = $this->getGeocoder($field);
		if(!$geocoder) {
			$result = MapMarkerPlusGeocodeResult::fail('none', MapMarkerPlus::statusNoGeocode, 'Geocoding disabled');
		} else {
			$result = $geocoder->geocode($marker->address, $this->getLang());
		}
		$marker->applyGeocodeResult($result);
		return $result;
	}

	/**
	 * Find the address of coordinates
	 *
	 * @param float $lat
	 * @param float $lng
	 * @param Field|null $field
	 * @return MapMarkerPlusGeocodeResult
	 *
	 */
	public function reverseGeocode($lat, $lng, ?Field $field = null) {
		$geocoder = $this->getGeocoder($field);
		if(!$geocoder) return MapMarkerPlusGeocodeResult::fail('none', MapMarkerPlus::statusNoGeocode, 'Geocoding disabled');
		return $geocoder->reverse($lat, $lng, $this->getLang());
	}

	/**
	 * URL hook: geocode endpoint for the inputfield
	 *
	 * POST field=<name>, q=<address> or lat=..&lng=.., CSRF token (X-<tokenName> header or post var).
	 * Returns array (converted to JSON by the core).
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookGeocodeEndpoint(HookEvent $event) {
		$event->return = $this->geocodeRequest();
	}

	/**
	 * Handle a geocode request (separate from the hook for testing)
	 *
	 * @param WireInputData|null $post Defaults to $input->post
	 * @param bool|null $skipCsrf For tests only
	 * @return array
	 *
	 */
	public function geocodeRequest(?WireInputData $post = null, $skipCsrf = false) {
		$input = $this->wire()->input;
		$user = $this->wire()->user;
		$sanitizer = $this->wire()->sanitizer;

		if($post === null) {
			if($input->requestMethod() !== 'POST') return $this->endpointError(405, 'POST required');
			$post = $input->post;
		}
		if(!$user->isLoggedin() || !($user->hasPermission('page-edit') || $user->hasPermission('profile-edit'))) {
			return $this->endpointError(403, 'Not allowed');
		}
		if(!$skipCsrf && !$this->wire()->session->CSRF->hasValidToken()) {
			return $this->endpointError(403, 'Invalid CSRF token, reload the page');
		}

		$field = null;
		$fieldName = $sanitizer->fieldName((string) $post->get('field'));
		if($fieldName !== '') {
			$field = $this->wire()->fields->get($fieldName);
			if(!$field || !$field->type instanceof FieldtypeMapMarkerPlus) return $this->endpointError(400, 'Unknown field');
		}

		$q = $sanitizer->text((string) $post->get('q'), array('maxLength' => 255));
		$lat = MapMarkerPlus::sanitizeCoordinate($post->get('lat'), 90);
		$lng = MapMarkerPlus::sanitizeCoordinate($post->get('lng'), 180);

		if($q !== '') {
			$geocoder = $this->getGeocoder($field);
			$result = $geocoder ? $geocoder->geocode($q, $this->getLang()) : null;
		} else if($lat !== '' && $lng !== '') {
			$result = $this->reverseGeocode($lat, $lng, $field);
		} else {
			return $this->endpointError(400, 'Missing q or lat/lng');
		}
		if(!$result) return $this->endpointError(400, 'Geocoding is disabled for this field');

		$marker = new MapMarkerPlus();
		$this->wire($marker);
		$marker->set('status', $result->status);
		$out = $result->toArray($this->wire()->config->debug);
		$out['statusString'] = $marker->statusString;
		return $out;
	}

	/**
	 * @param int $code
	 * @param string $message
	 * @return array
	 *
	 */
	protected function endpointError($code, $message) {
		if(!headers_sent() && PHP_SAPI !== 'cli') http_response_code($code);
		return array('ok' => false, 'status' => -1, 'error' => $message);
	}

	/*********************************************************************************
	 * Converting fields of FieldtypeMapMarker / FieldtypeLeafletMapMarker
	 *
	 */

	/**
	 * Offer this type in the "Type" select of fields using one of the source types
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookCompatibleFieldtypes(HookEvent $event) {
		if(!MapMarkerPlusMigration::isSourceType($event->object)) return;
		$fieldtypes = $event->return;
		if(!$fieldtypes instanceof Fieldtypes) $fieldtypes = $this->wire(new Fieldtypes());
		$fieldtypes->add($this);
		$event->return = $fieldtypes;
	}

	/**
	 * Remember the settings before the core may wipe them
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookChangeTypeReady(HookEvent $event) {
		/** @var Field $field */
		list($field, $fromType, $toType) = $event->arguments();
		if(!$toType instanceof FieldtypeMapMarkerPlus || !MapMarkerPlusMigration::isSourceType($fromType)) return;
		$this->migrationStash[$field->id] = array($field->getArray(), $fromType->className());
	}

	/**
	 * Map the settings after the data was copied
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookChangedType(HookEvent $event) {
		/** @var Field $field */
		$field = $event->arguments(0);
		if(!isset($this->migrationStash[$field->id])) return;
		list($old, $fromType) = $this->migrationStash[$field->id];
		unset($this->migrationStash[$field->id]);
		/** @var MapMarkerPlusMigration $migration */
		$migration = $this->wire(new MapMarkerPlusMigration());
		$notes = $migration->apply($field, $old, $fromType, $this->getModuleConfig());
		$this->roundLegacyCoordinates($field);
		$this->message(sprintf($this->_('Field "%1$s" converted from %2$s.'), $field->name, $fromType) . ' ' . implode(' ', $notes));
	}

	/*********************************************************************************
	 * Module config
	 *
	 */

	/**
	 * @param InputfieldWrapper $inputfields
	 * @return InputfieldWrapper
	 *
	 */
	public function getModuleConfigInputfields(InputfieldWrapper $inputfields) {
		$modules = $this->wire()->modules;
		$demo = (bool) $this->wire()->config->demo;

		$fieldset = function($label, $icon, $collapsed = false) use($modules, $inputfields) {
			/** @var InputfieldFieldset $fs */
			$fs = $modules->get('InputfieldFieldset');
			$fs->label = $label;
			$fs->icon = $icon;
			if($collapsed) $fs->collapsed = Inputfield::collapsedYes;
			$inputfields->add($fs);
			return $fs;
		};
		$text = function(InputfieldWrapper $fs, $name, $label, $description = '', $columnWidth = 100, $secret = false) use($modules, $demo) {
			/** @var InputfieldText $f */
			$f = $modules->get('InputfieldText');
			$f->attr('name', $name);
			$f->label = $label;
			if($description !== '') $f->description = $description;
			$f->attr('value', $demo && $secret ? '' : (string) $this->get($name));
			$f->columnWidth = $columnWidth;
			$fs->add($f);
			return $f;
		};

		$fs = $fieldset($this->_('Defaults'), 'sliders');

		/** @var InputfieldSelect $f */
		$f = $modules->get('InputfieldSelect');
		$f->attr('name', 'defaultMapProvider');
		$f->label = $this->_('Default map provider');
		foreach($this->getProviderLabels() as $name => $label) $f->addOption($name, $label);
		$f->attr('value', $this->getDefaultProvider());
		$f->required = true;
		$f->columnWidth = 50;
		$fs->add($f);

		/** @var InputfieldSelect $f */
		$f = $modules->get('InputfieldSelect');
		$f->attr('name', 'defaultGeocoder');
		$f->label = $this->_('Default geocoder');
		$f->addOption('none', $this->_('None'));
		foreach(array_keys($this->getGeocoders()) as $name) $f->addOption($name, $this->getGeocoderLabel($name));
		$f->attr('value', (string) $this->defaultGeocoder);
		$f->required = true;
		$f->columnWidth = 50;
		$fs->add($f);

		$text($fs, 'defaultLat', $this->_('Default latitude'), '', 33);
		$text($fs, 'defaultLng', $this->_('Default longitude'), '', 33);
		/** @var InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f->attr('name', 'defaultZoom');
		$f->label = $this->_('Default zoom');
		$f->attr('value', (int) $this->defaultZoom);
		$f->attr('min', 0);
		$f->attr('max', 22);
		$f->columnWidth = 34;
		$fs->add($f);
		$fs->notes = $this->_('Map view for empty values, when the field has no defaults of its own.');

		$f = $text($fs, 'geocodeLang', $this->_('Language'), $this->_('Two-letter code for geocoding results and map labels. Blank = language of the current user when its name is a two-letter code, else "en".'), 50);
		/** @var InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f->attr('name', 'cacheTtl');
		$f->label = $this->_('Geocode cache lifetime (seconds)');
		$f->description = $this->_('Successful results are cached in WireCache. 0 = no cache.');
		$f->attr('value', (int) $this->cacheTtl);
		$f->columnWidth = 50;
		$fs->add($f);

		$fs = $fieldset('Google', 'google', $this->googleMapsKey === '');
		$fs->description = sprintf($this->_('[Get an API key](%s). Restrict the browser key by HTTP referrer and the geocoding key by IP.'), 'https://developers.google.com/maps/documentation/javascript/get-api-key');
		$text($fs, 'googleMapsKey', $this->_('Maps JavaScript API key (browser)'), '', 50, true);
		$text($fs, 'googleGeocodingKey', $this->_('Geocoding API key (server)'), $this->_('Blank = use the browser key.'), 50, true);
		$text($fs, 'googleMapId', $this->_('Map ID'), $this->_('Needed for vector maps: tilt/rotation (3D mode) and advanced markers.'));

		$fs = $fieldset('Yandex', 'map-o', $this->yandexMapsKey === '');
		$fs->description = sprintf($this->_('[Developer dashboard](%s). The JavaScript API and the HTTP Geocoder are separate products with separate keys.'), 'https://developer.tech.yandex.ru/');
		$text($fs, 'yandexMapsKey', $this->_('JavaScript API v3 key'), '', 50, true);
		$text($fs, 'yandexGeocoderKey', $this->_('HTTP Geocoder key'), $this->_('Blank = use the JavaScript API key.'), 50, true);

		$fs = $fieldset($this->_('OpenStreetMap geocoders'), 'globe', true);
		$text($fs, 'photonUrl', $this->_('Photon URL'), '', 50);
		$text($fs, 'nominatimUrl', $this->_('Nominatim URL'), '', 50);
		$text($fs, 'nominatimEmail', $this->_('Contact e-mail for Nominatim'), sprintf($this->_('Required by the [usage policy](%s) of the public instance (max. 1 request per second).'), 'https://operations.osmfoundation.org/policies/nominatim/'));

		$fs = $fieldset($this->_('Other tile and geocoding keys'), 'key', true);
		$text($fs, 'maptilerKey', 'MapTiler', $this->_('Geocoding and MapLibre styles.'), 34, true);
		$text($fs, 'cartoKey', 'Carto', $this->_('Carto Voyager MapLibre style.'), 33, true);
		$text($fs, 'openaipKey', 'openAIP', $this->_('Airspace overlay.'), 33, true);

		$fs = $fieldset($this->_('3D terrain (MapLibre)'), 'area-chart', true);
		$text($fs, 'terrainTiles', $this->_('Elevation tiles URL'), $this->_('Raster DEM tiles with {z}/{x}/{y}. Blank = no terrain.'));
		/** @var InputfieldRadios $f */
		$f = $modules->get('InputfieldRadios');
		$f->attr('name', 'terrainEncoding');
		$f->label = $this->_('Encoding');
		$f->addOption('terrarium', 'Terrarium');
		$f->addOption('mapbox', 'Mapbox / MapTiler terrain-rgb');
		$f->attr('value', $this->terrainEncoding === 'mapbox' ? 'mapbox' : 'terrarium');
		$f->optionColumns = 1;
		$f->columnWidth = 50;
		$fs->add($f);
		/** @var InputfieldFloat $f */
		$f = $modules->get('InputfieldFloat');
		$f->attr('name', 'terrainExaggeration');
		$f->label = $this->_('Exaggeration');
		$f->attr('value', (float) $this->terrainExaggeration);
		$f->columnWidth = 50;
		$fs->add($f);

		return $inputfields;
	}

	/*********************************************************************************
	 * Helpers
	 *
	 */

	/**
	 * "55.1234560" => "55.123456", "10.0000000" => "10"
	 *
	 * @param mixed $value
	 * @return string
	 *
	 */
	public static function trimDecimal($value) {
		$value = (string) $value;
		if(strpos($value, '.') !== false && strpos($value, 'e') === false && strpos($value, 'E') === false) {
			$value = rtrim(rtrim($value, '0'), '.');
		}
		if($value === '-0') $value = '0';
		return $value;
	}
}
