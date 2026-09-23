import type { MapProvider } from '../core/MapProvider';
import type { ProviderId } from '../core/types';

/**
 * Load a provider adapter. Each adapter is its own chunk: a Google map never downloads MapLibre.
 */
export async function loadProvider(id: ProviderId | string): Promise<MapProvider> {
  switch (id) {
    case 'google': {
      const m = await import('./google');
      return new m.GoogleProvider();
    }
    case 'yandex': {
      const m = await import('./yandex');
      return new m.YandexProvider();
    }
    default: {
      const m = await import('./maplibre');
      return new m.MapLibreProvider();
    }
  }
}
