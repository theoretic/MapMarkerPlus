<?php namespace ProcessWire;

/**
 * Photon (komoot), OpenStreetMap data, no API key
 *
 * https://photon.komoot.io — set photonUrl for a self-hosted instance.
 *
 */
class MapMarkerPlusGeocoderPhoton extends MapMarkerPlusGeocoder {

	/**
	 * Languages the public Photon instance accepts (others give HTTP 400)
	 *
	 */
	const languages = array('en', 'de', 'fr', 'it');

	public function getName() {
		return 'photon';
	}

	public function getTitle() {
		return 'Photon (komoot, OpenStreetMap)';
	}

	protected function baseUrl() {
		return rtrim((string) $this->conf('photonUrl', 'https://photon.komoot.io'), '/');
	}

	protected function lang($lang) {
		$lang = strtolower(substr((string) $lang, 0, 2));
		return in_array($lang, self::languages, true) ? $lang : '';
	}

	public function forwardUrl($address, $lang = '') {
		return $this->baseUrl() . '/api/?' . $this->query(array('q' => $address, 'limit' => 1, 'lang' => $this->lang($lang)));
	}

	public function reverseUrl($lat, $lng, $lang = '') {
		return $this->baseUrl() . '/reverse?' . $this->query(array('lat' => $lat, 'lon' => $lng, 'limit' => 1, 'lang' => $this->lang($lang)));
	}

	public function parseResponse(array $json, $reverse = false) {
		if(isset($json['message']) && !isset($json['features'])) return $this->fail(-5, (string) $json['message']);
		if(!isset($json['features']) || !is_array($json['features'])) return $this->fail(-1, 'Unexpected response');
		$feature = isset($json['features'][0]) ? $json['features'][0] : null;
		if(!$feature || !isset($feature['geometry']['coordinates'][1])) return $this->fail(-2, 'ZERO_RESULTS');
		$p = isset($feature['properties']) ? $feature['properties'] : array();
		$coords = $feature['geometry']['coordinates'];
		$type = isset($p['type']) ? $p['type'] : '';
		$osmKey = isset($p['osm_key']) ? $p['osm_key'] : '';
		if($type === 'house' || $osmKey === 'building' || !empty($p['housenumber'])) {
			$accuracy = 'rooftop';
		} else if($type === 'street' || $osmKey === 'highway') {
			$accuracy = 'center';
		} else {
			$accuracy = 'approximate';
		}
		return $this->success($coords[1], $coords[0], $this->formatAddress($p), $accuracy, $feature);
	}

	/**
	 * Photon has no formatted address, build one from the properties
	 *
	 * @param array $p
	 * @return string
	 *
	 */
	protected function formatAddress(array $p) {
		$get = function($k) use($p) { return isset($p[$k]) ? trim((string) $p[$k]) : ''; };
		$street = trim($get('street') . ' ' . $get('housenumber'));
		$city = trim($get('postcode') . ' ' . ($get('city') !== '' ? $get('city') : $get('district')));
		$parts = array($get('name'), $street, $city, $get('state'), $get('country'));
		$out = array();
		foreach($parts as $part) {
			if($part !== '' && !in_array($part, $out, true)) $out[] = $part;
		}
		return implode(', ', $out);
	}
}
