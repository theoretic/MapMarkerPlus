<?php namespace ProcessWire;

/**
 * MapTiler Geocoding API
 *
 * https://docs.maptiler.com/cloud/api/geocoding/
 *
 */
class MapMarkerPlusGeocoderMapTiler extends MapMarkerPlusGeocoder {

	public function getName() {
		return 'maptiler';
	}

	public function getTitle() {
		return 'MapTiler Geocoding';
	}

	public function isConfigured() {
		return (string) $this->conf('maptilerKey') !== '';
	}

	protected function lang($lang) {
		return strtolower(substr((string) $lang, 0, 2));
	}

	public function forwardUrl($address, $lang = '') {
		return 'https://api.maptiler.com/geocoding/' . rawurlencode($address) . '.json?' .
			$this->query(array('key' => $this->conf('maptilerKey'), 'limit' => 1, 'language' => $this->lang($lang)));
	}

	public function reverseUrl($lat, $lng, $lang = '') {
		return 'https://api.maptiler.com/geocoding/' . ((float) $lng) . ',' . ((float) $lat) . '.json?' .
			$this->query(array('key' => $this->conf('maptilerKey'), 'limit' => 1, 'language' => $this->lang($lang)));
	}

	public function parseResponse(array $json, $reverse = false) {
		if(isset($json['message']) && !isset($json['features'])) return $this->fail(-1, (string) $json['message']);
		if(!isset($json['features']) || !is_array($json['features'])) return $this->fail(-1, 'Unexpected response');
		$f = isset($json['features'][0]) ? $json['features'][0] : null;
		if(!$f) return $this->fail(-2, 'ZERO_RESULTS');
		if(isset($f['center'][1])) {
			$lng = $f['center'][0];
			$lat = $f['center'][1];
		} else if(isset($f['geometry']['type'], $f['geometry']['coordinates'][1]) && $f['geometry']['type'] === 'Point') {
			$lng = $f['geometry']['coordinates'][0];
			$lat = $f['geometry']['coordinates'][1];
		} else {
			return $this->fail(-1, 'No coordinates in response');
		}
		$types = isset($f['place_type']) ? (array) $f['place_type'] : array();
		if(array_intersect($types, array('address', 'poi'))) {
			$accuracy = 'rooftop';
		} else if(in_array('road', $types, true)) {
			$accuracy = 'center';
		} else {
			$accuracy = 'approximate';
		}
		return $this->success($lat, $lng, isset($f['place_name']) ? $f['place_name'] : '', $accuracy, $f);
	}
}
