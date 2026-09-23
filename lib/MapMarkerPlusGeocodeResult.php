<?php namespace ProcessWire;

/**
 * Normalized result of a forward or reverse geocode, whatever provider produced it
 *
 * Part of MapMarkerPlus, a fork of FieldtypeMapMarker by Ryan Cramer.
 * Licensed under MPL 2.0
 *
 * @property float|null $lat
 * @property float|null $lng
 * @property string $formatted Formatted address returned by the provider
 * @property int $status One of the MapMarkerPlus status codes (legacy MapMarker numbering)
 * @property string $accuracy rooftop|range|center|approximate or blank
 * @property string $provider Geocoder name, i.e. "photon"
 * @property array $raw Provider response (first result only)
 * @property string $error Error message when not ok
 *
 */
class MapMarkerPlusGeocodeResult extends WireData {

	/**
	 * Accuracy strings mapped to the legacy status codes of FieldtypeMapMarker
	 *
	 */
	const accuracyStatuses = array(
		'rooftop' => 2,
		'range' => 3,
		'center' => 4,
		'approximate' => 5,
	);

	public function __construct() {
		parent::__construct();
		$this->setArray(array(
			'lat' => null,
			'lng' => null,
			'formatted' => '',
			'status' => 0,
			'accuracy' => '',
			'provider' => '',
			'raw' => array(),
			'error' => '',
		));
	}

	/**
	 * Build a successful result
	 *
	 * @param string $provider
	 * @param float $lat
	 * @param float $lng
	 * @param string $formatted
	 * @param string $accuracy rooftop|range|center|approximate
	 * @param array $raw
	 * @return self
	 *
	 */
	public static function success($provider, $lat, $lng, $formatted, $accuracy, array $raw = array()) {
		$r = new self();
		if(!isset(self::accuracyStatuses[$accuracy])) $accuracy = 'approximate';
		$r->setArray(array(
			'lat' => (float) $lat,
			'lng' => (float) $lng,
			'formatted' => (string) $formatted,
			'status' => self::accuracyStatuses[$accuracy],
			'accuracy' => $accuracy,
			'provider' => (string) $provider,
			'raw' => $raw,
		));
		return $r;
	}

	/**
	 * Build a failed result
	 *
	 * @param string $provider
	 * @param int $status Negative status code, see MapMarkerPlus
	 * @param string $error
	 * @return self
	 *
	 */
	public static function fail($provider, $status, $error = '') {
		$r = new self();
		$r->setArray(array(
			'status' => $status < 0 ? (int) $status : -1,
			'provider' => (string) $provider,
			'error' => (string) $error,
		));
		return $r;
	}

	/**
	 * Did the geocode produce coordinates?
	 *
	 * @return bool
	 *
	 */
	public function ok() {
		return $this->status > 0 && $this->lat !== null && $this->lng !== null;
	}

	/**
	 * Array for JSON output and for storing in the marker's "raw" column
	 *
	 * @param bool $withRaw Include the provider response?
	 * @return array
	 *
	 */
	public function toArray($withRaw = false) {
		$a = array(
			'ok' => $this->ok(),
			'lat' => $this->lat,
			'lng' => $this->lng,
			'formatted' => $this->formatted,
			'status' => $this->status,
			'accuracy' => $this->accuracy,
			'provider' => $this->provider,
		);
		if($this->error !== '') $a['error'] = $this->error;
		if($withRaw) $a['raw'] = $this->raw;
		return $a;
	}
}
