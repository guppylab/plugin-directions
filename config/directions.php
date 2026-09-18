<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Maximum destinations per call
    |--------------------------------------------------------------------------
    |
    | Routing engines are rate limited. MapKit in particular throttles hard when
    | a burst of MKDirections requests is issued, and the plugin resolves them
    | sequentially, so a large batch can take minutes. Anything beyond this cap
    | is dropped and reported back with ok: false and error: "over_limit".
    |
    */

    'max_destinations' => env('DIRECTIONS_MAX_DESTINATIONS', 25),

    /*
    |--------------------------------------------------------------------------
    | Per-route timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Upper bound for a single route calculation. When it elapses the row is
    | reported with ok: false and error: "timeout" instead of stalling the whole
    | batch — the event is always dispatched.
    |
    */

    'timeout' => env('DIRECTIONS_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | iOS
    |--------------------------------------------------------------------------
    |
    | MapKit MKDirections: on-device, no API key, no cost. Nothing to configure.
    |
    */

    'ios' => [
        'provider' => 'mapkit',
    ],

    /*
    |--------------------------------------------------------------------------
    | Android
    |--------------------------------------------------------------------------
    |
    | Android has no free on-device routing engine, so routing is opt-in and
    | needs a server. Supported providers:
    |
    |   none  Routing is unavailable. Every destination comes back with
    |         ok: false and error: "unsupported" so the app can fall back to a
    |         straight-line (haversine) estimate deterministically.
    |
    |   osrm  Open Source Routing Machine. Point `url` at your own instance.
    |         The public demo server (router.project-osrm.org) is explicitly not
    |         allowed for production use by the OSRM project, so there is no
    |         default here on purpose — set your own.
    |
    */

    'android' => [

        'provider' => env('DIRECTIONS_ANDROID_PROVIDER', 'none'),

        'osrm' => [

            'url' => env('DIRECTIONS_OSRM_URL'),

            // OSRM profile names are defined by whoever built the routing graph.
            // These are the defaults of a stock osrm-backend deployment.
            'profiles' => [
                'automobile' => env('DIRECTIONS_OSRM_PROFILE_AUTOMOBILE', 'driving'),
                'walking' => env('DIRECTIONS_OSRM_PROFILE_WALKING', 'foot'),
            ],
        ],
    ],
];
