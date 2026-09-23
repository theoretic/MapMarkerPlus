<?php namespace ProcessWire;

/**
 * Google Geocoding API
 *
 * https://developers.google.com/maps/documentation/geocoding
 * Uses the server key (googleGeocodingKey), falls back to googleMapsKey.
 *
 */
class MapMarkerPlusGeocoderGoogle extends MapMarkerPlusGeocoder {

	public function getName() {
		return 'google';
	}

	public function getTitle() {
		return 'Google Geocoding API';
	}

	public function isConfigured() {
		return $this->key() !== '';
	}

	protected function key() {
		return (string) $this->conf('googleGeocodingKey', $this->conf('googleMapsKey'));
	}

	public function forwardUrl($address, $lang = '') {
		return 'https://maps.googleapis.com/maps/api/geocode/json?' .
			$this->query(array('address' => $address, 'language' => $lang, 'key' => $this->key()));
	}

	public function reverseUrl($lat, $lng, $lang = '') {
		return 'https://maps.googleapis.com/maps/api/geocode/json?' .
			$this->query(array('latlng' => "$lat,$lng", 'language' => $lang, 'key' => $this->key()));
	}

	public function parseResponse(array $json, $reverse = false) {
		$status = isset($json['status']) ? (string) $json['status'] : '';
		if($status !== 'OK' || empty($json['results'][0]['geometry']['location'])) {
			$codes = array(
				'ZERO_RESULTS' => -2,
				'OVER_QUERY_LIMIT' => -3,
				'OVER_DAILY_LIMIT' => -3,
				'REQUEST_DENIED' => -4,
				'INVALID_REQUEST' => -5,
			);
			$error = trim($status . ' ' . (isset($json['error_message']) ? $json['error_message'] : ''));
			return $this->fail(isset($codes[$status]) ? $codes[$status] : ($status === 'OK' ? -2 : -1), $error);
		}
		$result = $json['results'][0];
		$location = $result['geometry']['location'];
		$types = array(
			'ROOFTOP' => 'rooftop',
			'RANGE_INTERPOLATED' => 'range',
			'GEOMETRIC_CENTER' => 'center',
			'APPROXIMATE' => 'approximate',
		);
		$type = isset($result['geometry']['location_type']) ? $result['geometry']['location_type'] : '';
		$formatted = isset($result['formatted_address']) ? $result['formatted_address'] : '';
		return $this->success($location['lat'], $location['lng'], $formatted, isset($types[$type]) ? $types[$type] : 'approximate', $result);
	}
}
