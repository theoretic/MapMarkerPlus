<?php namespace ProcessWire;

/**
 * Yandex HTTP Geocoder
 *
 * https://yandex.com/maps-api/docs/geocoder-api/
 * Uses yandexGeocoderKey, falls back to yandexMapsKey.
 *
 */
class MapMarkerPlusGeocoderYandex extends MapMarkerPlusGeocoder {

	public function getName() {
		return 'yandex';
	}

	public function getTitle() {
		return 'Yandex Geocoder';
	}

	public function isConfigured() {
		return $this->key() !== '';
	}

	protected function key() {
		return (string) $this->conf('yandexGeocoderKey', $this->conf('yandexMapsKey'));
	}

	public function forwardUrl($address, $lang = '') {
		return 'https://geocode-maps.yandex.ru/1.x/?' . $this->query(array(
			'apikey' => $this->key(),
			'geocode' => $address,
			'format' => 'json',
			'results' => 1,
			'lang' => $this->locale($lang),
		));
	}

	public function reverseUrl($lat, $lng, $lang = '') {
		// Yandex expects "longitude,latitude"
		return 'https://geocode-maps.yandex.ru/1.x/?' . $this->query(array(
			'apikey' => $this->key(),
			'geocode' => "$lng,$lat",
			'format' => 'json',
			'results' => 1,
			'lang' => $this->locale($lang),
		));
	}

	public function parseResponse(array $json, $reverse = false) {
		if(isset($json['statusCode']) && (int) $json['statusCode'] >= 400) {
			$error = trim((isset($json['error']) ? $json['error'] : '') . ' ' . (isset($json['message']) ? $json['message'] : ''));
			return $this->fail($this->statusFromHttpCode((int) $json['statusCode']), $error);
		}
		$members = isset($json['response']['GeoObjectCollection']['featureMember']) ? $json['response']['GeoObjectCollection']['featureMember'] : null;
		if(!is_array($members)) return $this->fail(-1, 'Unexpected response');
		if(empty($members[0]['GeoObject']['Point']['pos'])) return $this->fail(-2, 'ZERO_RESULTS');
		$geo = $members[0]['GeoObject'];
		$pos = preg_split('/\s+/', trim($geo['Point']['pos']));
		if(count($pos) !== 2) return $this->fail(-1, 'Unexpected position format');
		$meta = isset($geo['metaDataProperty']['GeocoderMetaData']) ? $geo['metaDataProperty']['GeocoderMetaData'] : array();
		$precisions = array(
			'exact' => 'rooftop',
			'number' => 'range',
			'near' => 'range',
			'range' => 'range',
			'street' => 'center',
		);
		$precision = isset($meta['precision']) ? $meta['precision'] : 'other';
		$formatted = isset($meta['text']) ? $meta['text'] : trim((isset($geo['name']) ? $geo['name'] : '') . ', ' . (isset($geo['description']) ? $geo['description'] : ''), ', ');
		return $this->success($pos[1], $pos[0], $formatted, isset($precisions[$precision]) ? $precisions[$precision] : 'approximate', $geo);
	}
}
