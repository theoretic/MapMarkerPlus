/**
 * InputfieldMapMarkerPlus loader
 *
 * Loaded as a classic script by ProcessWire (Inputfield::renderReady, or the <script> added to
 * ajax-rendered inputfields). Imports the ES module bundle assets/dist/admin.js, which initializes
 * all maps now and later (repeaters, ajax fields, modals).
 *
 * Fork of InputfieldMapMarker by Ryan Cramer. Licensed under MPL 2.0
 */
(function() {
	if(window.MapMarkerPlusAdminLoading) return
	window.MapMarkerPlusAdminLoading = true

	var current = document.currentScript
	var fallback = current && current.src ? current.src.replace(/InputfieldMapMarkerPlus\.js(\?.*)?$/, 'assets/dist/admin.js$1') : ''

	function bundleUrl() {
		var map = document.querySelector('.InputfieldMapMarkerPlusMap[data-config]')
		if(map) {
			try {
				var config = JSON.parse(map.getAttribute('data-config'))
				if(config.bundle) return config.bundle
			} catch(e) {}
		}
		return fallback
	}

	function load() {
		var url = bundleUrl()
		if(!url) return
		import(url).then(function(admin) {
			window.MapMarkerPlusAdmin = admin
			admin.start()
		}).catch(function(e) {
			window.MapMarkerPlusAdminLoading = false
			console.error('[MapMarkerPlus] could not load ' + url, e)
		})
	}

	if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load)
	else load()
})()
