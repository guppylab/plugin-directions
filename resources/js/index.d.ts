// Type definitions for the Directions plugin JS library.

export interface Destination {
    id: string | number;
    lat: number;
    lng: number;
}

/** Why a destination could not be routed. */
export type DistanceError =
    | 'unsupported'
    | 'timeout'
    | 'over_limit'
    | 'no_route'
    | 'failed';

export interface DistanceResult {
    id: string;
    meters?: number;
    seconds?: number;
    ok: boolean;
    error?: DistanceError;
}

export interface RoutingProvider {
    /** 'osrm' to route on Android, 'none' to report every destination as unsupported. */
    type: 'none' | 'osrm';
    /** Base URL of the routing server. Required when type is 'osrm'. */
    url?: string | null;
    /** Transport mode to provider profile, e.g. { automobile: 'driving', walking: 'foot' }. */
    profiles?: Record<string, string>;
}

export interface DistancesOptions {
    /** Correlation id echoed back on the DistancesReceived event. Generated when omitted. */
    id?: string;
    /** Travel mode. Defaults to "automobile". */
    transport?: 'automobile' | 'walking';
    /** Seconds allowed per route before it is reported as a timeout. Defaults to 15. */
    timeout?: number;
    /** Batch cap; extra destinations come back as over_limit. Defaults to 25. */
    maxDestinations?: number;
    /** Overrides the provider set with setProvider(). */
    provider?: RoutingProvider;
}

export interface SupportResult {
    supported: boolean;
    provider: string;
}

/** Set the routing provider used by subsequent calls (Android only). */
export function setProvider(provider: RoutingProvider): void;

/**
 * Trigger a real-route distance/ETA calculation. The result arrives via
 * `onDistances`, always, on both platforms.
 */
export function distances(
    originLat: number,
    originLng: number,
    destinations: Destination[],
    options?: DistancesOptions,
): Promise<{ id: string }>;

/** Whether this device can compute real routes. */
export function support(options?: { provider?: RoutingProvider }): Promise<SupportResult>;

/** Shorthand for `(await support()).supported`. */
export function isSupported(options?: { provider?: RoutingProvider }): Promise<boolean>;

/** Subscribe to route results. Returns an unsubscribe function. */
export function onDistances(
    callback: (results: DistanceResult[], id: string | null) => void,
): () => void;

declare const _default: {
    distances: typeof distances;
    onDistances: typeof onDistances;
    support: typeof support;
    isSupported: typeof isSupported;
    setProvider: typeof setProvider;
};

export default _default;
