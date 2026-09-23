<?php namespace ProcessWire;

/**
 * ProcessWire MapMarkerPlus Markup
 *
 * Renders maps for MapMarkerPlus fields as a <map-marker-plus> web component
 * (assets/dist/frontend.js). Works with MapLibre, Google Maps and Yandex Maps.
 *
 * Fork of MarkupGoogleMap, Copyright (C) 2023 by Ryan Cramer.
 * Licensed under MPL 2.0
 *
 * USAGE
 * =====
 *
 *    $map = $modules->get('MarkupMapMarkerPlus');
 *    echo $map->render($page, 'map');   // one page
 *    echo $map->render($pages->find("template=shop, map!=''"), 'map', array('height' => '500px', 'cluster' => true));
 *
 * The module script tag is output with the first map. Use 'script' => false to output it yourself
 * with renderScript(), i.e. in the document head.
 *
 * OPTIONS (defaults come from the field settings)
 * =======
 *
 * width, height              CSS sizes, integers are pixels (default: 100%, field height or module default height)
 * zoom                       Zoom level (default: field default zoom)
 * lat, lng                   Map center (default: field default location)
 * provider                   maplibre|google|yandex (default: field setting)
 * style                      Style id of the provider, see styles.json (default: field setting)
 * type                       Alias of style for MarkupGoogleMap compatibility: ROADMAP, SATELLITE, HYBRID, TERRAIN
 * mode                       2d|3d (default: field setting)
 * overlays                   MapLibre overlay ids, i.e. array('skyways')
 * id, class                  Attributes of the element (default: mmpmap1.., MarkupMapMarkerPlus)
 * attrs                      More attributes, array('data-foo' => 'bar')
 * useStyles                  Output width/height as inline style (default: true)
 * useMarkerSettings          Single marker map uses the marker's position and zoom (default: true)
 * markerLinkField            Page field for the marker link, blank for none (default: url)
 * markerTitleField           Page field for the marker title, blank for none (default: title)
 * fitToMarkers               Fit the map to multiple markers (default: true)
 * cluster                    Cluster markers: true or array('radius' => 50, 'maxZoom' => 14) (default: false)
 * popup                      Open a popup with the title on click instead of following the link (default: false)
 * popupField                 Page field with popup HTML (trusted markup, not escaped)
 * popupCallback              function(Page $page, MapMarkerPlus $marker): string, popup HTML
 * markerCallback             function(Page $page, MapMarkerPlus $marker): array merged into the marker data
 * useHoverBox                Tooltip that follows the cursor over markers (default: false)
 * hoverBoxMarkup             Markup of the hover box, data-top/data-left are offsets
 * icon, iconHover            URL of marker icons
 * allowModeToggle            Show a 2D/3D switch (default: field setting)
 * allowStyleSwitch           Show a style switch (default: field setting)
 * scrollZoom                 true = wheel zooms, 'cooperative' = Ctrl + wheel, false = no wheel zoom
 *                            (default: module setting "Ctrl + scroll to zoom")
 * lazy                       Load the map when it scrolls into view (default: true)
 * init                       Name of a global JS function, or JS code, run with the element when the map is ready
 * script                     Output the module script tag with the first map (default: true)
 *
 * @method array getMarkerData(Page $page, Field $field, MapMarkerPlus $marker, array $options)
 *
 */
class MarkupMapMarkerPlus extends WireData implements Module {

	public static function getModuleInfo() {
		return array(
			'title' => 'MapMarkerPlus Markup',
			'version' => FieldtypeMapMarkerPlus::version,
			'summary' => 'Renders maps (MapLibre, Google, Yandex) for MapMarkerPlus fields',
			'requires' => 'FieldtypeMapMarkerPlus',
			'icon' => 'map',
		);
	}

	/**
	 * Number of maps rendered in this request
	 *
	 * @var int
	 *
	 */
	protected $n = 0;

	/**
	 * Was the script tag output?
	 *
	 * @var bool
	 *
	 */
	protected $scriptRendered = false;

	/**
	 * @return FieldtypeMapMarkerPlus
	 *
	 */
	public function fieldtype() {
		/** @var FieldtypeMapMarkerPlus $fieldtype */
		$fieldtype = $this->wire()->modules->get('FieldtypeMapMarkerPlus');
		return $fieldtype;
	}

	/**
	 * @param string $fieldName
	 * @return Field
	 * @throws WireException
	 *
	 */
	protected function getMapField($fieldName) {
		$field = $fieldName instanceof Field ? $fieldName : $this->wire()->fields->get($fieldName);
		if(!$field) throw new WireException("Unknown field: $fieldName");
		if(!$field->type instanceof FieldtypeMapMarkerPlus) throw new WireException("Field $fieldName is not a MapMarkerPlus field");
		return $field;
	}

	/**
	 * Default options for a field
	 *
	 * @param string|Field $fieldName
	 * @return array
	 * @throws WireException
	 *
	 */
	public function getOptions($fieldName) {
		return $this->getOptionsForField($this->getMapField($fieldName));
	}

	/**
	 * Default options for a field
	 *
	 * @param Field $field
	 * @return array
	 *
	 */
	public function getOptionsForField(Field $field) {
		/** @var FieldtypeMapMarkerPlus $fieldtype */
		$fieldtype = $field->type;
		$lat = MapMarkerPlus::sanitizeCoordinate($field->get('defaultLat'), 90);
		$lng = MapMarkerPlus::sanitizeCoordinate($field->get('defaultLng'), 180);
		$hasDefault = $lat !== '' && $lng !== '';
		$zoom = (int) $field->get('defaultZoom');
		if($zoom < 1) $zoom = $hasDefault ? 12 : (int) $fieldtype->defaultZoom;
		return array(
			'width' => '100%',
			'height' => $fieldtype->getMapHeight($field),
			'zoom' => $zoom,
			'lat' => $hasDefault ? (float) $lat : (float) $fieldtype->defaultLat,
			'lng' => $hasDefault ? (float) $lng : (float) $fieldtype->defaultLng,
			'provider' => $fieldtype->getMapProvider($field),
			'style' => '',
			'type' => '',
			'mode' => $field->get('mapMode') === '3d' ? '3d' : '2d',
			'overlays' => (array) $field->get('mapOverlays'),
			'id' => '',
			'class' => 'MarkupMapMarkerPlus',
			'attrs' => array(),
			'useStyles' => true,
			'useMarkerSettings' => true,
			'markerLinkField' => 'url',
			'markerTitleField' => 'title',
			'fitToMarkers' => true,
			'cluster' => false,
			'popup' => false,
			'popupField' => '',
			'popupCallback' => null,
			'markerCallback' => null,
			'useHoverBox' => false,
			'hoverBoxMarkup' => "<div data-top='-10' data-left='15' style='background: #000; color: #fff; padding: 0.25em 0.5em; border-radius: 3px;'></div>",
			'icon' => '',
			'iconHover' => '',
			'allowModeToggle' => (bool) $field->get('allowModeToggle'),
			'allowStyleSwitch' => (bool) $field->get('allowStyleSwitch'),
			'scrollZoom' => null,
			'lazy' => true,
			'init' => '',
			'script' => true,
		);
	}

	/**
	 * Render a map
	 *
	 * @param Page|PageArray|Page[] $items Page(s) having the map field
	 * @param string|Field $fieldName Name of the MapMarkerPlus field
	 * @param array $options See the class description
	 * @return string
	 * @throws WireException
	 *
	 */
	public function render($items, $fieldName, array $options = array()) {
		$field = $this->getMapField($fieldName);
		$fieldName = $field->name;
		$options = array_merge($this->getOptionsForField($field), $options);
		$this->n++;

		if($items instanceof Page) $items = array($items);
		if(!is_iterable($items)) throw new WireException('render() expects a Page or PageArray');

		// MarkupGoogleMap compatibility: "type" => style
		if($options['style'] === '' && $options['type'] !== '') $options['style'] = $this->styleFromType($options['type'], $options['provider']);

		$markers = array();
		$first = null;
		foreach($items as $page) {
			if(!$page instanceof Page) continue;
			$marker = $page->get($fieldName);
			if(!$marker instanceof MapMarkerPlus || !$marker->hasCoordinates()) continue;
			if(!$first) $first = $marker;
			$markers[] = $this->getMarkerData($page, $field, $marker, $options);
		}

		$lat = (float) $options['lat'];
		$lng = (float) $options['lng'];
		$zoom = (int) $options['zoom'];
		if($first && $options['useMarkerSettings'] && (count($markers) === 1 || (!$lat && !$lng))) {
			$lat = (float) $first->lat;
			$lng = (float) $first->lng;
			if($first->zoom > 0) $zoom = (int) $first->zoom;
		}

		$client = $field->type->getClientConfig($field, array(
			'provider' => $options['provider'],
			'style' => $options['style'],
			'mode' => $options['mode'],
			'overlays' => $options['overlays'],
			'allowModeToggle' => $options['allowModeToggle'],
			'allowStyleSwitch' => $options['allowStyleSwitch'],
		));

		$config = array_merge($client, array(
			'center' => array('lat' => $lat, 'lng' => $lng),
			'zoom' => $zoom,
			'fit' => $options['fitToMarkers'] && count($markers) > 1,
			'cluster' => $options['cluster'],
			'popup' => (bool) $options['popup'],
			'hoverBox' => $options['useHoverBox'] ? (string) $options['hoverBoxMarkup'] : '',
			'icon' => (string) $options['icon'],
			'iconHover' => (string) $options['iconHover'],
			'scrollZoom' => $options['scrollZoom'] === null ? $client['scrollZoom'] : ($options['scrollZoom'] === 'cooperative' ? 'cooperative' : (bool) $options['scrollZoom']),
			'lazy' => (bool) $options['lazy'],
		));

		return $this->renderElement($config, $markers, $options);
	}

	/**
	 * Data of one marker for the map script
	 *
	 * Hook after to add or change data, i.e. an individual icon.
	 *
	 * @param Page $page
	 * @param Field $field
	 * @param MapMarkerPlus $marker
	 * @param array $options
	 * @return array
	 *
	 */
	public function ___getMarkerData(Page $page, Field $field, MapMarkerPlus $marker, array $options) {
		$data = array(
			'lat' => (float) $marker->lat,
			'lng' => (float) $marker->lng,
			'title' => '',
			'url' => '',
			'id' => $page->id,
		);
		if($options['markerTitleField']) {
			$title = $page->get($options['markerTitleField']);
			$data['title'] = is_scalar($title) || (is_object($title) && method_exists($title, '__toString')) ? trim(strip_tags((string) $title)) : '';
		}
		if($options['markerLinkField']) {
			$url = $page->get($options['markerLinkField']);
			$data['url'] = is_string($url) ? $url : '';
		}
		$popup = '';
		if(is_callable($options['popupCallback'])) {
			$popup = (string) call_user_func($options['popupCallback'], $page, $marker);
		} else if($options['popupField']) {
			$value = $page->get($options['popupField']);
			$popup = is_scalar($value) || (is_object($value) && method_exists($value, '__toString')) ? (string) $value : '';
		}
		if($popup !== '') $data['popup'] = $popup;
		if(is_callable($options['markerCallback'])) {
			$more = call_user_func($options['markerCallback'], $page, $marker);
			if(is_array($more)) $data = array_merge($data, $more);
		}
		return $data;
	}

	/**
	 * Map a MarkupGoogleMap "type" to a style of the provider
	 *
	 * @param string $type
	 * @param string $provider
	 * @return string
	 *
	 */
	protected function styleFromType($type, $provider) {
		$type = strtolower((string) $type);
		if($provider === 'google') return $type;
		if($provider === 'yandex') return $type === 'roadmap' || $type === 'terrain' ? 'scheme' : $type;
		return $type === 'satellite' || $type === 'hybrid' ? 's2cloudless' : ($type === 'terrain' ? 'opentopomap' : 'osm');
	}

	/**
	 * The module script tag (once per request)
	 *
	 * @param bool $force Output even when already output
	 * @return string
	 *
	 */
	public function renderScript($force = false) {
		if($this->scriptRendered && !$force) return '';
		$this->scriptRendered = true;
		$url = $this->wire()->config->urls('FieldtypeMapMarkerPlus') . 'assets/dist/frontend.js?v=' . FieldtypeMapMarkerPlus::version;
		return "<script type='module' src='$url'></script>";
	}

	/**
	 * Render the <map-marker-plus> element
	 *
	 * @param array $config Map configuration
	 * @param array $markers Marker data
	 * @param array $options Render options (id, class, attrs, width, height, useStyles, init, script)
	 * @return string
	 *
	 */
	public function renderElement(array $config, array $markers, array $options = array()) {
		$sanitizer = $this->wire()->sanitizer;
		$id = !empty($options['id']) ? (string) $options['id'] : 'mmpmap' . $this->n;
		$attrs = array(
			'id' => $id,
			'class' => isset($options['class']) ? (string) $options['class'] : 'MarkupMapMarkerPlus',
		);

		$init = isset($options['init']) ? trim((string) $options['init']) : '';
		$initCode = '';
		if($init !== '') {
			if(preg_match('/^[A-Za-z_$][\w$]*(\.[A-Za-z_$][\w$]*)*$/', $init)) {
				$attrs['data-init'] = $init;
			} else {
				$initCode = $init;
			}
		}

		if(!isset($options['useStyles']) || $options['useStyles']) {
			$width = isset($options['width']) ? (string) $options['width'] : '100%';
			$height = isset($options['height']) ? (string) $options['height'] : '450';
			if(ctype_digit($width)) $width .= 'px';
			if(ctype_digit($height)) $height .= 'px';
			$attrs['style'] = "display: block; width: $width; height: $height;";
		}

		if(!empty($options['attrs']) && is_array($options['attrs'])) {
			foreach($options['attrs'] as $k => $v) {
				$k = preg_replace('/[^-\w:]/', '', (string) $k);
				if($k !== '') $attrs[$k] = $v;
			}
		}

		$attrStr = '';
		foreach($attrs as $k => $v) {
			if($v === true) {
				$attrStr .= " $k";
			} else if($v !== false && $v !== null) {
				$attrStr .= " $k=\"" . $sanitizer->entities((string) $v) . '"';
			}
		}

		$json = json_encode(array('options' => $config, 'markers' => array_values($markers)),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		$out = '';
		if(!isset($options['script']) || $options['script']) $out .= $this->renderScript();
		$out .= "<map-marker-plus$attrStr><script type=\"application/json\">$json</script></map-marker-plus>";
		if($initCode !== '') {
			$jsId = json_encode($id, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
			$out .= "<script>document.getElementById($jsId).addEventListener('mmp:ready', function(event) { $initCode }.bind(document.getElementById($jsId)));</script>";
		}
		return $out;
	}
}
