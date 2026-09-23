/**
 * Frontend entry: registers <map-marker-plus>.
 * <script type="module" src="/site/modules/MapMarkerPlus/assets/dist/frontend.js"></script>
 */

import { setAssetBase } from '../core/assets';
import { MapMarkerPlusElement } from './MapMarkerPlusElement';

setAssetBase(import.meta.url);

if (!customElements.get('map-marker-plus')) {
  customElements.define('map-marker-plus', MapMarkerPlusElement);
}

export { MapMarkerPlusElement };
export { loadProvider } from '../providers/index';
export const version = '1.0.0';
