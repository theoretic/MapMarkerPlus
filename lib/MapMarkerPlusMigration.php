<?php namespace ProcessWire;

/**
 * Maps field settings of FieldtypeMapMarker and FieldtypeLeafletMapMarker to MapMarkerPlus
 *
 * Used when a field of one of those types is converted with Setup > Fields > (field) > Type.
 * The data columns (data, lat, lng, status, zoom, raw) are copied by the core, see
 * Fields::changeFieldtype(); this class only deals with the settings.
 *
 */
class MapMarkerPlusMigration extends Wire {

	/**
	 * Fieldtypes that can be converted to FieldtypeMapMarkerPlus
	 *
	 */
	const sourceTypes = array('FieldtypeMapMarker', 'FieldtypeLeafletMapMarker');

	/**
	 * Settings copied as they are
	 *
	 */
	const copySettings = array('defaultAddr', 'defaultLat', 'defaultLng', 'defaultZoom', 'height');

	/**
	 * leaflet-providers names (FieldtypeLeafletMapMarker "defaultProvider") => MapLibre style id
	 *
	 * Matched by prefix, first match wins. Unknown names become "osm".
	 *
	 */
	const leafletStyles = array(
		'OpenStreetMap' => 'osm',
		'OpenTopoMap' => 'opentopomap',
		'Esri.WorldImagery' => 'esri-imagery',
		'Esri.WorldTopoMap' => 'esri-topo',
		'Esri' => 'esri-topo',
		'CartoDB' => 'carto-voyager',
	);

	/**
	 * Is the given Fieldtype one we can convert from?
	 *
	 * @param Fieldtype|string $fieldtype
	 * @return bool
	 *
	 */
	public static function isSourceType($fieldtype) {
		$name = $fieldtype instanceof Fieldtype ? $fieldtype->className() : (string) $fieldtype;
		return in_array($name, self::sourceTypes, true);
	}

	/**
	 * Compute the new settings
	 *
	 * @param array $old Settings of the field before the type change ($field->getArray())
	 * @param string $fromType Class name of the previous Fieldtype
	 * @param array $moduleConfig FieldtypeMapMarkerPlus module config
	 * @return array [settings array, notes array]
	 *
	 */
	public function mapSettings(array $old, $fromType, array $moduleConfig) {
		$new = array();
		$notes = array();

		foreach(self::copySettings as $key) {
			if(isset($old[$key]) && $old[$key] !== '') $new[$key] = $old[$key];
		}

		// the upstream default address was a placeholder, not a real choice
		if(isset($new['defaultAddr']) && $new['defaultAddr'] === 'Castaway Cay') unset($new['defaultAddr']);

		$hasKey = function($key) use($moduleConfig) {
			return !empty($moduleConfig[$key]);
		};

		if($fromType === 'FieldtypeLeafletMapMarker') {
			$provider = isset($old['defaultProvider']) ? (string) $old['defaultProvider'] : '';
			$style = 'osm';
			foreach(self::leafletStyles as $prefix => $id) {
				if($provider !== '' && strpos($provider, $prefix) === 0) {
					$style = $id;
					break;
				}
			}
			if($style === 'carto-voyager' && !$hasKey('cartoKey')) $style = 'osm';
			$new['mapProvider'] = 'maplibre';
			$new[FieldtypeMapMarkerPlus::styleSettingName('maplibre')] = $style;
			if($provider !== '') $notes[] = sprintf($this->_('Tile provider "%1$s" mapped to MapLibre style "%2$s".'), $provider, $style);

		} else {
			// FieldtypeMapMarker (Google)
			$type = isset($old['defaultType']) ? strtoupper((string) $old['defaultType']) : 'HYBRID';
			if($hasKey('googleMapsKey')) {
				$styles = array('HYBRID' => 'hybrid', 'SATELLITE' => 'satellite', 'ROADMAP' => 'roadmap', 'TERRAIN' => 'terrain');
				$provider = 'google';
				$style = isset($styles[$type]) ? $styles[$type] : 'hybrid';
			} else {
				$provider = 'maplibre';
				$style = in_array($type, array('HYBRID', 'SATELLITE')) ? 's2cloudless' : 'osm';
				$notes[] = $this->_('No Google Maps key in the MapMarkerPlus module settings, so the field now uses MapLibre.');
			}
			$new['mapProvider'] = $provider;
			$new[FieldtypeMapMarkerPlus::styleSettingName($provider)] = $style;
			$notes[] = sprintf($this->_('Map type "%1$s" mapped to %2$s style "%3$s".'), $type, $provider, $style);
		}

		$new['mapMode'] = '2d';
		$new['geocoder'] = '';
		$new['schemaVersion'] = FieldtypeMapMarkerPlus::schemaVersion;

		return array($new, $notes);
	}

	/**
	 * Apply mapped settings to the field (not saved here, the core saves it right after)
	 *
	 * @param Field $field
	 * @param array $old
	 * @param string $fromType
	 * @param array $moduleConfig
	 * @return array Notes for the user
	 *
	 */
	public function apply(Field $field, array $old, $fromType, array $moduleConfig) {
		list($new, $notes) = $this->mapSettings($old, $fromType, $moduleConfig);
		foreach(array('defaultType', 'defaultProvider') as $key) $field->remove($key);
		foreach($new as $key => $value) $field->set($key, $value);
		return $notes;
	}
}
