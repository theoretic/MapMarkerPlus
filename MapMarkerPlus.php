<?php namespace ProcessWire;

/**
 * Value of a MapMarkerPlus field: an address with latitude, longitude and zoom
 *
 * Fork of MapMarker by Ryan Cramer. Licensed under MPL 2.0
 *
 * @property string $address
 * @property string $lat Latitude as numeric string, or blank when unknown
 * @property string $lng Longitude as numeric string, or blank when unknown
 * @property int $status Geocode status, see $geocodeStatuses
 * @property int $zoom Zoom level 0..29 (0 = use field default)
 * @property array $raw Last geocode result: provider, formatted, accuracy, time, result
 * @property bool $skipGeocode Runtime flag: don't geocode on save
 * @property-read string $statusString
 * @property-read string $formatted Formatted address from the last geocode
 * @property-read string $geocoder Name of the geocoder that produced the coordinates
 * @property-read array $latLng array(lat, lng) as floats, or empty array
 *
 */
class MapMarkerPlus extends WireData {

	const statusNoGeocode = -100;

	/**
	 * Status codes, compatible with FieldtypeMapMarker
	 *
	 * @var array
	 *
	 */
	protected $geocodeStatuses = array(
		0 => 'N/A',
		1 => 'OK',
		2 => 'OK_ROOFTOP',
		3 => 'OK_RANGE_INTERPOLATED',
		4 => 'OK_GEOMETRIC_CENTER',
		5 => 'OK_APPROXIMATE',

		-1 => 'UNKNOWN',
		-2 => 'ZERO_RESULTS',
		-3 => 'OVER_QUERY_LIMIT',
		-4 => 'REQUEST_DENIED',
		-5 => 'INVALID_REQUEST',

		-100 => 'Geocode OFF',
	);

	/**
	 * Address that was last geocoded, to avoid geocoding the same address twice
	 *
	 * @var string|null
	 *
	 */
	protected $geocodedAddress = null;

	/**
	 * Field this value belongs to (determines the geocoder)
	 *
	 * @var Field|null
	 *
	 */
	protected $field = null;

	public function __construct() {
		parent::__construct();
		$this->set('lat', '');
		$this->set('lng', '');
		$this->set('address', '');
		$this->set('status', 0);
		$this->set('zoom', 0);
		$this->set('raw', array());
		$this->set('skipGeocode', false);
	}

	/**
	 * @param string $key
	 * @param mixed $value
	 * @return $this
	 *
	 */
	public function set($key, $value) {
		if($key === 'lat' || $key === 'lng') {
			$value = self::sanitizeCoordinate($value, $key === 'lat' ? 90 : 180);
		} else if($key === 'address') {
			$value = $this->wire()->sanitizer->text((string) $value, array('maxLength' => 255));
		} else if($key === 'status') {
			$value = (int) $value;
			if(!isset($this->geocodeStatuses[$value])) $value = -1;
		} else if($key === 'zoom') {
			$value = max(0, min(29, (int) $value));
		} else if($key === 'raw') {
			if(is_string($value)) $value = $value === '' ? array() : json_decode($value, true);
			if(!is_array($value)) $value = array();
		} else if($key === 'skipGeocode') {
			$value = (bool) $value;
		} else if(in_array($key, array('statusString', 'formatted', 'geocoder', 'latLng'))) {
			return $this; // read-only, computed
		}
		return parent::set($key, $value);
	}

	/**
	 * @param string $key
	 * @return mixed
	 *
	 */
	public function get($key) {
		switch($key) {
			case 'statusString':
				$status = (int) parent::get('status');
				return str_replace('_', ' ', isset($this->geocodeStatuses[$status]) ? $this->geocodeStatuses[$status] : 'UNKNOWN');
			case 'formatted':
				$raw = parent::get('raw');
				return is_array($raw) && isset($raw['formatted']) ? (string) $raw['formatted'] : '';
			case 'geocoder':
				$raw = parent::get('raw');
				return is_array($raw) && isset($raw['provider']) ? (string) $raw['provider'] : '';
			case 'latLng':
				return $this->hasCoordinates() ? array((float) $this->lat, (float) $this->lng) : array();
			case 'field':
				return $this->field;
		}
		return parent::get($key);
	}

	/**
	 * Normalize a latitude or longitude: "12,5" => "12.5", non-numeric or out of range => ""
	 *
	 * @param mixed $value
	 * @param int $max 90 for latitude, 180 for longitude
	 * @return string
	 *
	 */
	public static function sanitizeCoordinate($value, $max) {
		if(is_float($value) || is_int($value)) {
			$value = (string) $value;
		} else {
			$value = str_replace(',', '.', trim((string) $value));
		}
		if(!is_numeric($value) || abs((float) $value) > $max) return '';
		return $value;
	}

	/**
	 * Set the field this value belongs to
	 *
	 * @param Field|null $field
	 * @return $this
	 *
	 */
	public function setField(?Field $field = null) {
		$this->field = $field;
		return $this;
	}

	/**
	 * @return Field|null
	 *
	 */
	public function getField() {
		return $this->field;
	}

	/**
	 * Get all status codes and their names
	 *
	 * @return array
	 *
	 */
	public function getStatuses() {
		return $this->geocodeStatuses;
	}

	/**
	 * Are latitude and longitude both set?
	 *
	 * @return bool
	 *
	 */
	public function hasCoordinates() {
		$lat = parent::get('lat');
		$lng = parent::get('lng');
		return $lat !== '' && $lng !== '' && $lat !== null && $lng !== null;
	}

	/**
	 * Is there nothing in this value?
	 *
	 * @return bool
	 *
	 */
	public function isEmpty() {
		return parent::get('address') === '' && !$this->hasCoordinates();
	}

	/**
	 * Geocode the address with the geocoder configured for the field
	 *
	 * On failure the coordinates are left untouched and the status tells what went wrong.
	 *
	 * @param bool $notices Report the result with $this->message()/$this->error()?
	 * @return int Status code
	 *
	 */
	public function geocode($notices = true) {
		if($this->skipGeocode) return self::statusNoGeocode;
		if($this->geocodedAddress === $this->address) return $this->status;
		$this->geocodedAddress = $this->address;

		$fieldtype = $this->field && $this->field->type instanceof FieldtypeMapMarkerPlus
			? $this->field->type
			: $this->wire()->modules->get('FieldtypeMapMarkerPlus');
		if(!$fieldtype instanceof FieldtypeMapMarkerPlus) return $this->status;

		$result = $fieldtype->geocodeMarker($this, $this->field);

		if($notices) {
			$address = $this->address;
			if($result->ok()) {
				$this->message(sprintf($this->_('Geocode %1$s (%2$s): "%3$s"'), $this->statusString, $result->provider, $address));
			} else if($result->status !== self::statusNoGeocode) {
				$this->error(sprintf($this->_('Error geocoding address "%1$s" with %2$s: %3$s'), $address, $result->provider, $result->error ?: $this->statusString));
			}
		}

		return $this->status;
	}

	/**
	 * Apply a geocode result to this value
	 *
	 * @param MapMarkerPlusGeocodeResult $result
	 * @param bool $setAddress Also replace the address with the formatted one (reverse geocoding)
	 * @return $this
	 *
	 */
	public function applyGeocodeResult(MapMarkerPlusGeocodeResult $result, $setAddress = false) {
		$this->set('status', $result->status);
		if(!$result->ok()) return $this;
		$this->set('lat', $result->lat);
		$this->set('lng', $result->lng);
		if($setAddress && $result->formatted !== '') {
			$this->set('address', $result->formatted);
			$this->geocodedAddress = $this->address;
		}
		$this->set('raw', array(
			'v' => 1,
			'provider' => $result->provider,
			'formatted' => $result->formatted,
			'accuracy' => $result->accuracy,
			'time' => time(),
			'result' => $result->raw,
		));
		return $this;
	}

	/**
	 * Array for JSON (map config, Markup module)
	 *
	 * @return array
	 *
	 */
	public function toArray() {
		return array(
			'address' => $this->address,
			'lat' => $this->hasCoordinates() ? (float) $this->lat : null,
			'lng' => $this->hasCoordinates() ? (float) $this->lng : null,
			'zoom' => $this->zoom,
			'status' => $this->status,
		);
	}

	/**
	 * String value is the address, followed by coordinates when present
	 *
	 * @return string
	 *
	 */
	public function __toString() {
		$s = (string) $this->address;
		if($this->hasCoordinates()) $s = trim("$s ($this->lat, $this->lng)");
		return $s;
	}
}
