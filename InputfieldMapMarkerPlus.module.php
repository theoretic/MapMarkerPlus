<?php namespace ProcessWire;

/**
 * ProcessWire MapMarkerPlus Inputfield
 *
 * Address, geocode switch, latitude, longitude, zoom and an interactive map.
 * InputfieldMapMarkerPlus.js (a small loader for assets/dist/admin.js) and InputfieldMapMarkerPlus.css
 * are loaded by Inputfield::renderReady() because their names match the class name.
 *
 * Fork of InputfieldMapMarker, Copyright (C) 2023 by Ryan Cramer.
 * Licensed under MPL 2.0
 *
 * @property string $defaultAddr Address shown as placeholder for empty values
 * @property string $defaultLat Map center for empty values (blank = module default)
 * @property string $defaultLng
 * @property int $defaultZoom Zoom for empty values and markers without zoom (0 = module default)
 * @property int $height Map height in pixels (0 = module default)
 * @property array $clientConfig Map configuration from FieldtypeMapMarkerPlus::getClientConfig()
 *
 */
class InputfieldMapMarkerPlus extends Inputfield {

	public static function getModuleInfo() {
		return array(
			'title' => 'MapMarkerPlus',
			'version' => FieldtypeMapMarkerPlus::version,
			'summary' => 'Input for the MapMarkerPlus Fieldtype',
			'requires' => 'FieldtypeMapMarkerPlus',
			'icon' => 'map-marker',
		);
	}

	public function __construct() {
		$this->set('defaultAddr', '');
		$this->set('defaultZoom', 0);
		$this->set('defaultLat', '');
		$this->set('defaultLng', '');
		$this->set('height', 0);
		$this->set('clientConfig', array());
		parent::__construct();
	}

	/**
	 * @return FieldtypeMapMarkerPlus
	 *
	 */
	public function fieldtype() {
		if($this->hasField && $this->hasField->type instanceof FieldtypeMapMarkerPlus) return $this->hasField->type;
		/** @var FieldtypeMapMarkerPlus $fieldtype */
		$fieldtype = $this->wire()->modules->get('FieldtypeMapMarkerPlus');
		return $fieldtype;
	}

	/**
	 * Only accept a MapMarkerPlus as value
	 *
	 * @param string $key
	 * @param mixed $value
	 * @return Inputfield|InputfieldMapMarkerPlus
	 * @throws WireException
	 *
	 */
	public function setAttribute($key, $value) {
		if($key === 'value' && !$value instanceof MapMarkerPlus && !is_null($value)) {
			throw new WireException('This input only accepts a MapMarkerPlus for its value');
		}
		return parent::setAttribute($key, $value);
	}

	/**
	 * @return bool
	 *
	 */
	public function isEmpty() {
		$value = $this->attr('value');
		return !$value instanceof MapMarkerPlus || $value->isEmpty();
	}

	/**
	 * @return MapMarkerPlus
	 *
	 */
	protected function marker() {
		$marker = $this->attr('value');
		if(!$marker instanceof MapMarkerPlus) {
			$marker = new MapMarkerPlus();
			$this->wire($marker);
			$this->attr('value', $marker);
		}
		return $marker;
	}

	/**
	 * Data for the map script
	 *
	 * @return array
	 *
	 */
	public function getMapConfig() {
		$config = $this->wire()->config;
		$session = $this->wire()->session;
		$fieldtype = $this->fieldtype();
		$marker = $this->marker();

		$client = $this->clientConfig;
		if(!is_array($client) || empty($client)) $client = $fieldtype->getClientConfig($this->hasField ?: null);

		$defaultLat = MapMarkerPlus::sanitizeCoordinate($this->defaultLat, 90);
		$defaultLng = MapMarkerPlus::sanitizeCoordinate($this->defaultLng, 180);
		$hasDefault = $defaultLat !== '' && $defaultLng !== '';
		$defaultZoom = (int) $this->defaultZoom;
		if($defaultZoom < 1) $defaultZoom = $hasDefault ? 12 : (int) $client['defaultZoom'];

		$csrf = $session ? $session->CSRF : null;
		$version = FieldtypeMapMarkerPlus::version;
		$url = $config->urls('FieldtypeMapMarkerPlus');

		return array_merge($client, array(
			'name' => $this->attr('name'),
			'field' => $this->hasField ? $this->hasField->name : '',
			'marker' => $marker->toArray(),
			'center' => array(
				'lat' => $hasDefault ? (float) $defaultLat : $client['defaultLat'],
				'lng' => $hasDefault ? (float) $defaultLng : $client['defaultLng'],
			),
			'zoom' => $marker->zoom > 0 ? $marker->zoom : $defaultZoom,
			'defaultZoom' => $defaultZoom,
			'geocoder' => $fieldtype->getGeocoderName($this->hasField ?: null),
			'endpoint' => $config->urls->root . ltrim($fieldtype->getEndpointPath(), '/'),
			'csrf' => $csrf ? array('name' => $csrf->getTokenName(), 'value' => $csrf->getTokenValue()) : null,
			'bundle' => "{$url}assets/dist/admin.js?v=$version",
			'i18n' => array(
				'mode2d' => $this->_('2D'),
				'mode3d' => $this->_('3D'),
				'style' => $this->_('Map style'),
				'geocoding' => $this->_('Geocoding…'),
				'notFound' => $this->_('Address not found'),
				'error' => $this->_('Geocoding failed'),
				'off' => $this->_('Geocode OFF'),
				'on' => $this->_('Geocode ON'),
				'clickToPlace' => $this->_('Click the map to place the marker'),
				'loadError' => $this->_('The map could not be loaded'),
			),
		));
	}

	/**
	 * @return string
	 *
	 */
	public function ___render() {
		$sanitizer = $this->wire()->sanitizer;
		$config = $this->wire()->config;
		$adminTheme = $this->wire()->adminTheme;

		$name = $sanitizer->entities($this->attr('name'));
		$id = $sanitizer->entities($this->attr('id'));
		$marker = $this->marker();

		$classes = array('input' => '', 'checkbox' => '');
		if($adminTheme && method_exists($adminTheme, 'getClass')) {
			foreach(array_keys($classes) as $key) $classes[$key] = $adminTheme->getClass($key);
		}

		$labels = array(
			'addr' => $this->_('Address'),
			'lat' => $this->_('Latitude'),
			'lng' => $this->_('Longitude'),
			'geo' => $this->_('Geocode'),
			'geoTitle' => $this->_('Geocode ON/OFF: find coordinates for the address and the address for a moved marker'),
			'zoom' => $this->_('Zoom'),
		);
		foreach($labels as $key => $label) $labels[$key] = $sanitizer->entities1($label);

		$address = $sanitizer->entities($marker->address);
		$placeholder = $sanitizer->entities((string) $this->defaultAddr);
		$lat = $sanitizer->entities($marker->lat);
		$lng = $sanitizer->entities($marker->lng);
		$zoom = $marker->zoom > 0 ? (int) $marker->zoom : '';
		$geocodeOff = $marker->status == MapMarkerPlus::statusNoGeocode;
		$checked = $geocodeOff ? '' : " checked='checked'";
		$status = $geocodeOff ? 0 : (int) $marker->status;
		$disabledGeocoder = $this->fieldtype()->getGeocoderName($this->hasField ?: null) === 'none';
		$height = (int) $this->height > 0 ? (int) $this->height : $this->fieldtype()->getDefaultHeight();
		$mapConfig = $this->encodeJson($this->getMapConfig());

		$toggle = $disabledGeocoder ? '' : "
			<div class='InputfieldMapMarkerPlusToggle'>
				<label title='$labels[geoTitle]'>
					<input type='checkbox' class='$classes[checkbox]' name='_{$name}_status' id='_{$id}_toggle' value='$status'$checked />
					<span>$labels[geo]</span>
				</label>
			</div>";

		$out = "
		<div class='InputfieldMapMarkerPlusInputs'>
			<div class='InputfieldMapMarkerPlusAddress'>
				<label for='$id'>$labels[addr]</label>
				<input type='text' id='$id' name='$name' value='$address' placeholder='$placeholder' maxlength='255' autocomplete='off' class='$classes[input]' />
				<input type='hidden' name='_{$name}_js_geocode_address' value='' />
				<input type='hidden' name='_{$name}_raw' value='' />
			</div>$toggle
			<div class='InputfieldMapMarkerPlusLat'>
				<label for='_{$id}_lat'>$labels[lat]</label>
				<input type='text' id='_{$id}_lat' name='_{$name}_lat' value='$lat' inputmode='decimal' class='$classes[input]' />
			</div>
			<div class='InputfieldMapMarkerPlusLng'>
				<label for='_{$id}_lng'>$labels[lng]</label>
				<input type='text' id='_{$id}_lng' name='_{$name}_lng' value='$lng' inputmode='decimal' class='$classes[input]' />
			</div>
			<div class='InputfieldMapMarkerPlusZoom'>
				<label for='_{$id}_zoom'>$labels[zoom]</label>
				<input type='number' min='0' max='29' id='_{$id}_zoom' name='_{$name}_zoom' value='$zoom' class='$classes[input]' />
			</div>
		</div>
		<div class='InputfieldMapMarkerPlusMap' id='_{$id}_map' style='height: {$height}px' data-config='$mapConfig'></div>
		<p class='InputfieldMapMarkerPlusStatus detail' aria-live='polite'>" . $sanitizer->entities($this->statusLine($marker)) . "</p>";

		if($config->ajax) {
			// inputfields rendered by ajax don't get their assets from $config->scripts/styles
			$url = $config->urls('InputfieldMapMarkerPlus');
			$v = FieldtypeMapMarkerPlus::version;
			$out .= "<link rel='stylesheet' href='{$url}InputfieldMapMarkerPlus.css?v=$v' />" .
				"<script src='{$url}InputfieldMapMarkerPlus.js?v=$v'></script>";
		}

		$this->warnMissingKey();

		return $out;
	}

	/**
	 * Status text under the map
	 *
	 * @param MapMarkerPlus $marker
	 * @return string
	 *
	 */
	protected function statusLine(MapMarkerPlus $marker) {
		if($marker->status == 0) return '';
		$s = $marker->statusString;
		if($marker->geocoder !== '') $s .= " ({$marker->geocoder})";
		if($marker->formatted !== '' && $marker->formatted !== $marker->address) $s .= ': ' . $marker->formatted;
		return $s;
	}

	/**
	 * Tell superusers when the map provider has no key
	 *
	 */
	protected function warnMissingKey() {
		$fieldtype = $this->fieldtype();
		$provider = $fieldtype->getMapProvider($this->hasField ?: null);
		if($fieldtype->providerIsConfigured($provider)) return;
		$msg = sprintf($this->_('Please set up the %s API key in the MapMarkerPlus module settings'), $provider === 'google' ? 'Google Maps' : 'Yandex Maps');
		$user = $this->wire()->user;
		if($user && $user->isSuperuser()) {
			$url = $this->wire()->config->urls->admin . 'module/edit?name=FieldtypeMapMarkerPlus';
			$this->warning("<a href='$url'>" . $this->wire()->sanitizer->entities1($msg) . '</a>', Notice::allowMarkup);
		} else {
			$this->warning($msg);
		}
	}

	/**
	 * JSON safe for a single-quoted HTML attribute
	 *
	 * @param array $data
	 * @return string
	 *
	 */
	protected function encodeJson(array $data) {
		$json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		return htmlspecialchars((string) $json, ENT_QUOTES, 'UTF-8');
	}

	/**
	 * @return string
	 *
	 */
	public function ___renderValue() {
		$marker = $this->marker();
		if($marker->isEmpty()) return '';
		$sanitizer = $this->wire()->sanitizer;
		$out = $sanitizer->entities($marker->address);
		if($marker->hasCoordinates()) {
			$coords = $sanitizer->entities("$marker->lat, $marker->lng");
			$out .= ($out !== '' ? '<br />' : '') . "<span class='detail'>$coords</span>";
		}
		return "<p>$out</p>";
	}

	/**
	 * @param WireInputData $input
	 * @return $this
	 *
	 */
	public function ___processInput(WireInputData $input) {
		$name = $this->attr('name');
		$marker = $this->marker();
		if(!isset($input->$name)) return $this;

		$marker->set('address', (string) $input->$name);
		$marker->set('lat', (string) $input["_{$name}_lat"]);
		$marker->set('lng', (string) $input["_{$name}_lng"]);
		if(!$marker->hasCoordinates()) {
			$marker->set('lat', '');
			$marker->set('lng', '');
		}

		$zoom = $input["_{$name}_zoom"];
		$marker->set('zoom', ctype_digit((string) $zoom) ? (int) $zoom : 0);

		$status = $input["_{$name}_status"];
		$geocoderName = $this->fieldtype()->getGeocoderName($this->hasField ?: null);
		if($geocoderName === 'none') {
			$marker->set('status', 0);
		} else if(is_null($status)) {
			$marker->set('status', MapMarkerPlus::statusNoGeocode);
		} else {
			$marker->set('status', (int) $status);
		}

		if($marker->isChanged('address') && $marker->address !== '' && $marker->status != MapMarkerPlus::statusNoGeocode && $geocoderName !== 'none') {
			if((string) $input["_{$name}_js_geocode_address"] === $marker->address) {
				// the JS already geocoded (or reverse geocoded) this address: keep the coordinates the user sees
				$marker->skipGeocode = true;
				$raw = $this->sanitizeRaw((string) $input["_{$name}_raw"]);
				if(count($raw)) $marker->set('raw', $raw);
				$this->message($this->_('Skipping geocode (already done by the map)'), Notice::debug);
			} else {
				$marker->geocode();
			}
		}

		if($marker->isChanged()) $this->trackChange('value');
		return $this;
	}

	/**
	 * Accept only a known subset of the geocode result posted by the map script
	 *
	 * @param string $json
	 * @return array
	 *
	 */
	protected function sanitizeRaw($json) {
		$data = $json !== '' ? json_decode($json, true) : null;
		if(!is_array($data)) return array();
		$sanitizer = $this->wire()->sanitizer;
		$geocoders = $this->fieldtype()->getGeocoders();
		$provider = isset($data['provider']) ? (string) $data['provider'] : '';
		if(!isset($geocoders[$provider])) return array();
		$accuracy = isset($data['accuracy']) ? (string) $data['accuracy'] : '';
		if(!isset(MapMarkerPlusGeocodeResult::accuracyStatuses[$accuracy])) $accuracy = '';
		return array(
			'v' => 1,
			'provider' => $provider,
			'formatted' => $sanitizer->text(isset($data['formatted']) ? (string) $data['formatted'] : '', array('maxLength' => 1024)),
			'accuracy' => $accuracy,
			'time' => time(),
			'result' => array(),
		);
	}

	/**
	 * Input tab settings
	 *
	 * @return InputfieldWrapper
	 *
	 */
	public function ___getConfigInputfields() {
		$modules = $this->wire()->modules;
		$inputfields = parent::___getConfigInputfields();

		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->attr('name', 'defaultAddr');
		$f->label = $this->_('Default address');
		$f->description = $this->_('Placeholder of the address input. When latitude and longitude below are blank, it is geocoded to become the map center for empty values.');
		$f->attr('value', (string) $this->defaultAddr);
		$inputfields->add($f);

		if($this->defaultAddr !== '' && $this->defaultLat === '' && $this->defaultLng === '' && $this->hasField) {
			$m = new MapMarkerPlus();
			$this->wire($m);
			$m->setField($this->hasField);
			$m->address = $this->defaultAddr;
			if($m->geocode(false) > 0) {
				$this->defaultLat = $m->lat;
				$this->defaultLng = $m->lng;
				$this->message($this->_('Geocoded your default address. Please save once again to keep the default latitude and longitude.'));
			}
		}

		foreach(array('defaultLat' => $this->_('Default latitude'), 'defaultLng' => $this->_('Default longitude')) as $name => $label) {
			/** @var InputfieldText $f */
			$f = $modules->get('InputfieldText');
			$f->attr('name', $name);
			$f->label = $label;
			$f->attr('value', (string) $this->get($name));
			$f->columnWidth = 50;
			$inputfields->add($f);
		}

		/** @var InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f->attr('name', 'height');
		$f->label = $this->_('Map height (in pixels)');
		$f->description = sprintf($this->_('0 = module default (%d)'), $this->fieldtype()->getDefaultHeight());
		$f->attr('value', (int) $this->height);
		$f->attr('type', 'number');
		$f->columnWidth = 50;
		$inputfields->add($f);

		/** @var InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f->attr('name', 'defaultZoom');
		$f->label = $this->_('Default zoom');
		$f->description = $this->_('Between 1 and 22. 0 = 12 when a default location is set, else the module default.');
		$f->attr('value', (int) $this->defaultZoom);
		$f->attr('type', 'number');
		$f->columnWidth = 50;
		$inputfields->add($f);

		/** @var InputfieldMarkup $f */
		$f = $modules->get('InputfieldMarkup');
		$f->label = $this->_('API notes');
		$f->description = $this->_('Values of this field in your template files:');
		$n = $this->wire()->sanitizer->entities($this->hasField ? $this->hasField->name : $this->attr('name'));
		$f->value = '<pre>' .
			"\$page->{$n}->address\n" .
			"\$page->{$n}->lat\n" .
			"\$page->{$n}->lng\n" .
			"\$page->{$n}->zoom\n" .
			"\$page->{$n}->formatted  // address as returned by the geocoder\n" .
			"\$page->{$n}->statusString\n\n" .
			"echo \$modules->get('MarkupMapMarkerPlus')->render(\$page, '{$n}');" .
			'</pre>';
		$f->collapsed = Inputfield::collapsedYes;
		$inputfields->add($f);

		return $inputfields;
	}

	/**
	 * Settings that may differ per template
	 *
	 * @param Field $field
	 * @return array
	 *
	 */
	public function ___getConfigAllowContext($field) {
		return array_merge(parent::___getConfigAllowContext($field), array('defaultAddr', 'defaultLat', 'defaultLng', 'defaultZoom', 'height'));
	}
}
