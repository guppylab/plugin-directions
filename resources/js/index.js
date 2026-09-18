/**
 * Directions plugin for NativePHP Mobile.
 *
 * Wraps the `Directions.*` bridge functions so the plugin can be used from
 * Inertia (Vue/React) the same way the PHP facade is used from Livewire.
 *
 * @example
 * import { distances, onDistances, isSupported } from 'guppylab-plugin-directions';
 */

const baseUrl = '/_native/api/call';

/**
 * Internal bridge call. Unwraps the `{ status, data }` envelope returned by the
 * core's native-call endpoint and throws on a native error.
 *
 * @private
 * @param {string} method
 * @param {Record<string, unknown>} params
 * @returns {Promise<any>}
 */
async function bridgeCall(method, params = {}) {
    const response = await fetch(baseUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify({ method, params }),
    });

    const result = await response.json();

    if (result.status === 'error') {
        throw new Error(result.message || 'Native call failed');
    }

    const nativeResponse = result.data;

    if (nativeResponse && nativeResponse.data !== undefined) {
        return nativeResponse.data;
    }

    return nativeResponse;
}

/**
 * Routing provider handed to the native side.
 *
 * iOS ignores it — MapKit is on-device. Android needs it, and JavaScript cannot
 * read `config/directions.php`, so share it from the server (an Inertia prop, a
 * meta tag, whatever the app already uses) and pass it here, or call
 * `setProvider()` once at boot. Without it Android reports every destination as
 * `unsupported` instead of routing.
 */
let defaultProvider = { type: 'none', url: null, profiles: {} };

/**
 * Set the routing provider used by subsequent calls.
 *
 * @param {{ type: 'none' | 'osrm', url?: string | null, profiles?: Record<string, string> }} provider
 */
export function setProvider(provider) {
    defaultProvider = {
        type: provider?.type ?? 'none',
        url: provider?.url ?? null,
        profiles: provider?.profiles ?? {},
    };
}

/**
 * Trigger a real-route distance/ETA calculation from one origin to many
 * destinations. The result is delivered via the `DistancesReceived` event —
 * listen with `onDistances()`.
 *
 * The event always fires, on both platforms. Destinations that could not be
 * routed come back with `ok: false` and an `error` code, so the caller can fall
 * back to a straight-line estimate on a signal rather than on a timeout.
 *
 * @param {number} originLat
 * @param {number} originLng
 * @param {Array<{ id: string | number, lat: number, lng: number }>} destinations
 * @param {{ id?: string, transport?: 'automobile' | 'walking', timeout?: number, maxDestinations?: number, provider?: object }} [options]
 * @returns {Promise<{ id: string }>} the correlation id echoed back on the event
 */
export async function distances(originLat, originLng, destinations, options = {}) {
    const id = options.id ?? crypto.randomUUID();

    await bridgeCall('Directions.Distances', {
        id,
        origin: { lat: originLat, lng: originLng },
        destinations: destinations.map((destination) => ({
            id: String(destination.id),
            lat: Number(destination.lat),
            lng: Number(destination.lng),
        })),
        transport: options.transport ?? 'automobile',
        timeout: options.timeout ?? 15,
        max_destinations: options.maxDestinations ?? 25,
        event: 'Guppylab\\Directions\\Events\\DistancesReceived',
        provider: options.provider ?? defaultProvider,
    });

    return { id };
}

/**
 * Whether this device can compute real routes: always true on iOS, true on
 * Android only when a routing provider is configured.
 *
 * @param {{ provider?: object }} [options]
 * @returns {Promise<{ supported: boolean, provider: string }>}
 */
export async function support(options = {}) {
    const response = await bridgeCall('Directions.IsSupported', {
        provider: options.provider ?? defaultProvider,
    });

    return {
        supported: response?.supported ?? false,
        provider: response?.provider ?? 'none',
    };
}

/**
 * Shorthand for `(await support()).supported`.
 *
 * @param {{ provider?: object }} [options]
 * @returns {Promise<boolean>}
 */
export async function isSupported(options = {}) {
    return (await support(options)).supported;
}

/**
 * Subscribe to route results. The callback receives the parsed rows (already
 * JSON-decoded) and the optional correlation id.
 *
 * @param {(results: Array<{ id: string, meters?: number, seconds?: number, ok: boolean, error?: string }>, id: string | null) => void} callback
 * @returns {() => void} unsubscribe
 */
export function onDistances(callback) {
    const handler = (event) => {
        const name = String(event?.detail?.event ?? '').replace(/^\\+/, '');

        if (!name.endsWith('Directions\\Events\\DistancesReceived')) {
            return;
        }

        const payload = event.detail.payload ?? {};
        let rows = [];

        try {
            rows = JSON.parse(payload.results ?? '[]');
        } catch (_) {
            rows = [];
        }

        callback(rows, payload.id ?? null);
    };

    document.addEventListener('native-event', handler);

    return () => document.removeEventListener('native-event', handler);
}

export default { distances, onDistances, support, isSupported, setProvider };
