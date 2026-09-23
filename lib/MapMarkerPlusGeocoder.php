<?php namespace ProcessWire;

/**
 * Base class for geocoding providers
 *
 * A provider implements the request URLs and parseResponse(); HTTP, caching,
 * throttling and error mapping live here. Register more providers by hooking
 * FieldtypeMapMarkerPlus::getGeocoders.
 *
 * Part of MapMarkerPlus, a fork of FieldtypeMapMarker by Ryan Cramer.
 * Licensed under MPL 2.0
 *
 */
abstract class MapMarkerPlusGeocoder extends Wire {

	/**
	 * Module configuration of FieldtypeMapMarkerPlus (keys, URLs, cacheTtl, ...)
	 *
	 * @var array
	 *
	 */
	protected $config = array();

	/**
	 * Minimum seconds between two requests to this provider (0 = no throttle)
	 *
	 * @var float
	 *
	 */
	protected $minInterval = 0.0;

	/**
	 * @param array $config Module configuration of FieldtypeMapMarkerPlus
	 *
	 */
	public function __construct(array $config = array()) {
		parent::__construct();
		$this->config = $config;
	}

	/**
	 * Provider name as used in settings, i.e. "photon"
	 *
	 * @return string
	 *
	 */
	abstract public function getName();

	/**
	 * Human readable provider title
	 *
	 * @return string
	 *
	 */
	abstract public function getTitle();

	/**
	 * Does the provider have everything it needs (API key etc.)?
	 *
	 * @return bool
	 *
	 */
	public function isConfigured() {
		return true;
	}

	/**
	 * Get the request URL for a forward geocode
	 *
	 * @param string $address
	 * @param string $lang Two-letter language code or blank
	 * @return string
	 *
	 */
	abstract public function forwardUrl($address, $lang = '');

	/**
	 * Get the request URL for a reverse geocode
	 *
	 * @param float $lat
	 * @param float $lng
	 * @param string $lang
	 * @return string
	 *
	 */
	abstract public function reverseUrl($lat, $lng, $lang = '');

	/**
	 * Turn a decoded provider response into a result
	 *
	 * Public so that tests can feed recorded responses.
	 *
	 * @param array $json
	 * @param bool $reverse
	 * @return MapMarkerPlusGeocodeResult
	 *
	 */
	abstract public function parseResponse(array $json, $reverse = false);

	/**
	 * Geocode an address to coordinates
	 *
	 * @param string $address
	 * @param string $lang
	 * @return MapMarkerPlusGeocodeResult
	 *
	 */
	public function geocode($address, $lang = '') {
		$address = trim((string) $address);
		if($address === '') return $this->fail(-5, 'Empty address');
		if(!$this->isConfigured()) return $this->fail(-4, $this->notConfiguredMessage());
		$key = $this->getName() . "|$lang|f|" . mb_strtolower($address);
		return $this->cached($key, function() use($address, $lang) {
			return $this->request($this->forwardUrl($address, $lang), false);
		});
	}

	/**
	 * Find the address of given coordinates
	 *
	 * @param float $lat
	 * @param float $lng
	 * @param string $lang
	 * @return MapMarkerPlusGeocodeResult
	 *
	 */
	public function reverse($lat, $lng, $lang = '') {
		$lat = (float) $lat;
		$lng = (float) $lng;
		if(abs($lat) > 90 || abs($lng) > 180) return $this->fail(-5, 'Coordinates out of range');
		if(!$this->isConfigured()) return $this->fail(-4, $this->notConfiguredMessage());
		$key = $this->getName() . "|$lang|r|" . round($lat, 6) . ',' . round($lng, 6);
		return $this->cached($key, function() use($lat, $lng, $lang) {
			return $this->request($this->reverseUrl($lat, $lng, $lang), true);
		});
	}

	/**
	 * @return string
	 *
	 */
	protected function notConfiguredMessage() {
		return sprintf('Geocoder "%s" is not configured (API key missing)', $this->getName());
	}

	/**
	 * Get a config value, or $default when missing or blank
	 *
	 * @param string $key
	 * @param mixed $default
	 * @return mixed
	 *
	 */
	protected function conf($key, $default = '') {
		return isset($this->config[$key]) && $this->config[$key] !== '' ? $this->config[$key] : $default;
	}

	/**
	 * Perform the HTTP request and parse the response
	 *
	 * @param string $url
	 * @param bool $reverse
	 * @return MapMarkerPlusGeocodeResult
	 *
	 */
	protected function request($url, $reverse) {
		$this->throttle();
		$http = $this->http();
		$body = $http->get($url);
		$code = (int) $http->getHttpCode();
		if($body === false || $code >= 400 || $code === 0) {
			return $this->fail($this->statusFromHttpCode($code), trim("HTTP $code " . $http->getError()));
		}
		$json = json_decode((string) $body, true);
		if(!is_array($json)) return $this->fail(-1, 'Invalid JSON response');
		$result = $this->parseResponse($json, $reverse);
		$result->provider = $this->getName();
		return $result;
	}

	/**
	 * Prepared WireHttp instance
	 *
	 * @return WireHttp
	 *
	 */
	protected function http() {
		$http = new WireHttp();
		$this->wire($http);
		$http->setTimeout(8);
		$http->setUserAgent($this->userAgent());
		$http->setHeader('Accept', 'application/json');
		return $http;
	}

	/**
	 * Identifying user agent (required by the Nominatim usage policy)
	 *
	 * @return string
	 *
	 */
	protected function userAgent() {
		$parts = array_filter(array((string) $this->wire()->config->httpHost, (string) $this->conf('nominatimEmail')));
		$ua = 'MapMarkerPlus/' . FieldtypeMapMarkerPlus::version;
		if(count($parts)) $ua .= ' (' . implode('; ', $parts) . ')';
		return "$ua ProcessWire";
	}

	/**
	 * Map an HTTP error code to a legacy status code
	 *
	 * @param int $code
	 * @return int
	 *
	 */
	protected function statusFromHttpCode($code) {
		if($code === 429) return -3; // OVER_QUERY_LIMIT
		if($code === 401 || $code === 403) return -4; // REQUEST_DENIED
		if($code === 400 || $code === 404 || $code === 422) return -5; // INVALID_REQUEST
		return -1; // UNKNOWN
	}

	/**
	 * Build a failed result for this provider
	 *
	 * @param int $status
	 * @param string $error
	 * @return MapMarkerPlusGeocodeResult
	 *
	 */
	protected function fail($status, $error = '') {
		return MapMarkerPlusGeocodeResult::fail($this->getName(), $status, $error);
	}

	/**
	 * Build a successful result for this provider
	 *
	 * @param float $lat
	 * @param float $lng
	 * @param string $formatted
	 * @param string $accuracy
	 * @param array $raw
	 * @return MapMarkerPlusGeocodeResult
	 *
	 */
	protected function success($lat, $lng, $formatted, $accuracy, array $raw = array()) {
		return MapMarkerPlusGeocodeResult::success($this->getName(), $lat, $lng, $formatted, $accuracy, $raw);
	}

	/**
	 * Return a cached successful result or produce (and cache) a new one
	 *
	 * @param string $key
	 * @param callable $fn
	 * @return MapMarkerPlusGeocodeResult
	 *
	 */
	protected function cached($key, callable $fn) {
		$ttl = (int) $this->conf('cacheTtl', 0);
		if($ttl <= 0) return $fn();
		$cache = $this->wire()->cache;
		$name = 'geocode-' . md5($key);
		$data = $cache->getFor('MapMarkerPlus', $name, $ttl);
		if(is_array($data) && !empty($data['ok'])) {
			unset($data['ok']);
			$r = new MapMarkerPlusGeocodeResult();
			$r->setArray($data);
			return $r;
		}
		/** @var MapMarkerPlusGeocodeResult $r */
		$r = $fn();
		if($r->ok()) $cache->saveFor('MapMarkerPlus', $name, $r->toArray(true), $ttl);
		return $r;
	}

	/**
	 * Wait until $minInterval seconds passed since the previous request to this provider
	 *
	 * Uses a lock file so concurrent PHP processes are throttled too.
	 *
	 */
	protected function throttle() {
		if($this->minInterval <= 0) return;
		$dir = $this->wire()->config->paths->cache . 'MapMarkerPlus/';
		if(!is_dir($dir)) $this->wire()->files->mkdir($dir, true);
		$fp = @fopen($dir . 'throttle-' . $this->getName(), 'c+');
		if(!$fp) return;
		if(flock($fp, LOCK_EX)) {
			$last = (float) stream_get_contents($fp);
			$wait = min($last + $this->minInterval - microtime(true), $this->minInterval);
			if($wait > 0) usleep((int) ($wait * 1000000));
			ftruncate($fp, 0);
			rewind($fp);
			fwrite($fp, (string) microtime(true));
			fflush($fp);
			flock($fp, LOCK_UN);
		}
		fclose($fp);
	}

	/**
	 * Query string helper that skips blank values
	 *
	 * @param array $params
	 * @return string
	 *
	 */
	protected function query(array $params) {
		foreach($params as $k => $v) {
			if($v === '' || $v === null) unset($params[$k]);
		}
		return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
	}

	/**
	 * Map a two-letter language code to a provider locale, i.e. "ru" => "ru_RU"
	 *
	 * @param string $lang
	 * @return string
	 *
	 */
	protected function locale($lang) {
		$lang = strtolower(substr((string) $lang, 0, 2));
		$map = array('en' => 'en_US', 'ru' => 'ru_RU', 'uk' => 'uk_UA', 'tr' => 'tr_TR', 'be' => 'be_BY', 'kk' => 'kk_KZ', 'uz' => 'uz_UZ');
		return isset($map[$lang]) ? $map[$lang] : '';
	}
}
