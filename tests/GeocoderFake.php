<?php namespace ProcessWire;

/**
 * Offline geocoder for tests: answers from the fixture files, counts requests
 *
 * "Berlin" => photon-ok, any address containing "zero" => photon-empty, reverse => nominatim-reverse-ok
 *
 */
class MapMarkerPlusGeocoderFake extends MapMarkerPlusGeocoder {

	public static $calls = 0;

	public function getName() {
		return 'fake';
	}

	public function getTitle() {
		return 'Fake (tests)';
	}

	public function forwardUrl($address, $lang = '') {
		return 'fake://forward/' . rawurlencode($address);
	}

	public function reverseUrl($lat, $lng, $lang = '') {
		return "fake://reverse/$lat,$lng";
	}

	protected function request($url, $reverse) {
		self::$calls++;
		if($reverse) {
			$photon = new MapMarkerPlusGeocoderNominatim();
			$json = json_decode(file_get_contents(__DIR__ . '/fixtures/geocode/nominatim-reverse-ok.json'), true);
			$r = $photon->parseResponse($json, true);
		} else {
			$photon = new MapMarkerPlusGeocoderPhoton();
			$file = strpos($url, 'zero') !== false ? 'photon-empty' : 'photon-ok';
			$json = json_decode(file_get_contents(__DIR__ . "/fixtures/geocode/$file.json"), true);
			$r = $photon->parseResponse($json, false);
		}
		return $r;
	}

	public function parseResponse(array $json, $reverse = false) {
		return MapMarkerPlusGeocodeResult::fail('fake', -1, 'not used');
	}
}
