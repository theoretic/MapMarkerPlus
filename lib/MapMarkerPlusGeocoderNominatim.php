<?php namespace ProcessWire;

/**
 * Nominatim (OpenStreetMap)
 *
 * https://nominatim.org/release-docs/latest/api/Overview/
 * The public instance allows max 1 request per second and requires an identifying
 * User-Agent: https://operations.osmfoundation.org/policies/nominatim/
 * Set nominatimEmail in the module config, or point nominatimUrl to your own instance.
 *
 */
class MapMarkerPlusGeocoderNominatim extends MapMarkerPlusGeocoder {

	protected $minInterval = 1.1;

	public function getName() {
		return 'nominatim';
	}

	public function getTitle() {
		return 'Nominatim (OpenStreetMap)';
	}

	protected function baseUrl() {
		return rtrim((string) $this->conf('nominatimUrl', 'https://nominatim.openstreetmap.org'), '/');
	}

	public function forwardUrl($address, $lang = '') {
		return $this->baseUrl() . '/search?' . $this->query(array(
			'q' => $address,
			'format' => 'jsonv2',
			'limit' => 1,
			'accept-language' => $lang,
			'email' => $this->conf('nominatimEmail'),
		));
	}

	public function reverseUrl($lat, $lng, $lang = '') {
		return $this->baseUrl() . '/reverse?' . $this->query(array(
			'lat' => $lat,
			'lon' => $lng,
			'format' => 'jsonv2',
			'accept-language' => $lang,
			'email' => $this->conf('nominatimEmail'),
		));
	}

	public function parseResponse(array $json, $reverse = false) {
		if(isset($json['error'])) {
			$error = is_array($json['error']) ? (isset($json['error']['message']) ? $json['error']['message'] : 'Error') : (string) $json['error'];
			// "Unable to geocode" is what reverse returns for the open sea
			return $this->fail(stripos($error, 'unable to geocode') !== false ? -2 : -1, $error);
		}
		$item = $reverse ? $json : (isset($json[0]) ? $json[0] : null);
		if(!is_array($item) || !isset($item['lat'], $item['lon'])) return $this->fail(-2, 'ZERO_RESULTS');
		$addresstype = isset($item['addresstype']) ? $item['addresstype'] : (isset($item['type']) ? $item['type'] : '');
		if(in_array($addresstype, array('house', 'building', 'amenity', 'shop', 'office', 'tourism'))) {
			$accuracy = 'rooftop';
		} else if(in_array($addresstype, array('road', 'street', 'highway'))) {
			$accuracy = 'center';
		} else {
			$accuracy = 'approximate';
		}
		return $this->success($item['lat'], $item['lon'], isset($item['display_name']) ? $item['display_name'] : '', $accuracy, $item);
	}
}
