/** Client of the module's geocode endpoint (FieldtypeMapMarkerPlus::geocodeRequest). */

export interface GeocodeResponse {
  ok: boolean;
  lat: number | null;
  lng: number | null;
  formatted: string;
  status: number;
  statusString?: string;
  accuracy: string;
  provider: string;
  error?: string;
}

export interface GeocodeRequest {
  endpoint: string;
  field: string;
  csrf: { name: string; value: string } | null;
  q?: string;
  lat?: number;
  lng?: number;
  signal?: AbortSignal;
}

export async function geocode(r: GeocodeRequest): Promise<GeocodeResponse> {
  const body = new FormData();
  body.set('field', r.field);
  if (r.q !== undefined) body.set('q', r.q);
  if (r.lat !== undefined && r.lng !== undefined) {
    body.set('lat', String(r.lat));
    body.set('lng', String(r.lng));
  }
  const headers: Record<string, string> = { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' };
  if (r.csrf) {
    body.set(r.csrf.name, r.csrf.value);
    headers[`X-${r.csrf.name}`] = r.csrf.value;
  }
  const res = await fetch(r.endpoint, { method: 'POST', body, headers, credentials: 'same-origin', signal: r.signal });
  let data: GeocodeResponse | null = null;
  try {
    data = (await res.json()) as GeocodeResponse;
  } catch {
    data = null;
  }
  if (!data || typeof data !== 'object') {
    return { ok: false, lat: null, lng: null, formatted: '', status: -1, accuracy: '', provider: '', error: `HTTP ${res.status}` };
  }
  return data;
}
