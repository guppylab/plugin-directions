<?php

namespace Guppylab\Directions;

use Guppylab\Directions\Events\DistancesReceived;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Directions
{
    public const AUTOMOBILE = 'automobile';

    public const WALKING = 'walking';

    /**
     * Real-route distance and travel time from an origin to several destinations.
     *
     * Asynchronous: the result arrives as a {@see DistancesReceived} event —
     * listen with #[OnNative(DistancesReceived::class)]. The event always fires,
     * on both platforms, even when routing is unavailable: destinations that
     * could not be resolved come back with ok: false and an error code, so the
     * caller can fall back to a straight-line estimate without waiting forever.
     *
     * @param  list<array{id:int|string,lat:float,lng:float}>  $destinations
     * @param  string  $transport  self::AUTOMOBILE|self::WALKING
     * @return string|false the correlation id echoed back on the event, or false
     *                      when running outside the native runtime
     */
    public function distances(
        float $originLat,
        float $originLng,
        array $destinations,
        ?string $id = null,
        string $transport = self::AUTOMOBILE,
    ): string|false {
        if (! function_exists('nativephp_call')) {
            return false;
        }

        $id ??= (string) Str::uuid();

        nativephp_call('Directions.Distances', json_encode([
            'id' => $id,
            'origin' => ['lat' => $originLat, 'lng' => $originLng],
            'destinations' => $this->normalizeDestinations($destinations),
            'transport' => $this->normalizeTransport($transport),
            'event' => DistancesReceived::class,
            'timeout' => (int) config('directions.timeout', 15),
            'max_destinations' => (int) config('directions.max_destinations', 25),
            'provider' => $this->providerConfig(),
        ]));

        return $id;
    }

    /**
     * Whether the device can actually compute routes right now.
     *
     * iOS is always true (MapKit is on-device). Android is true only when a
     * routing provider is configured — see config/directions.php.
     *
     * @return array{supported:bool,provider:string}
     */
    public function support(): array
    {
        $unsupported = ['supported' => false, 'provider' => 'none'];

        if (! function_exists('nativephp_call')) {
            return $unsupported;
        }

        $result = nativephp_call('Directions.IsSupported', json_encode([
            'provider' => $this->providerConfig(),
        ]));

        if (! $result) {
            return $unsupported;
        }

        $decoded = json_decode($result, true);

        return [
            'supported' => (bool) ($decoded['supported'] ?? false),
            'provider' => (string) ($decoded['provider'] ?? 'none'),
        ];
    }

    /**
     * Shorthand for support()['supported'].
     */
    public function isSupported(): bool
    {
        return $this->support()['supported'];
    }

    /**
     * Routing configuration handed to the native side. iOS ignores it (MapKit
     * needs nothing); Android uses it to decide whether it can route at all.
     *
     * @return array{type:string,url:?string,profiles:array<string,string>}
     */
    public function providerConfig(): array
    {
        $type = (string) config('directions.android.provider', 'none');
        $url = config('directions.android.osrm.url');

        return [
            'type' => $url ? $type : 'none',
            'url' => $url ? rtrim((string) $url, '/') : null,
            'profiles' => (array) config('directions.android.osrm.profiles', [
                self::AUTOMOBILE => 'driving',
                self::WALKING => 'foot',
            ]),
        ];
    }

    /**
     * Drop malformed rows early instead of letting the native side decide, and
     * coerce ids to strings so the event payload is shaped predictably.
     *
     * @param  list<array{id:int|string,lat:float,lng:float}>  $destinations
     * @return list<array{id:string,lat:float,lng:float}>
     */
    protected function normalizeDestinations(array $destinations): array
    {
        $normalized = [];

        foreach ($destinations as $destination) {
            if (! isset($destination['id'], $destination['lat'], $destination['lng'])) {
                continue;
            }

            if (! is_numeric($destination['lat']) || ! is_numeric($destination['lng'])) {
                continue;
            }

            $normalized[] = [
                'id' => (string) $destination['id'],
                'lat' => (float) $destination['lat'],
                'lng' => (float) $destination['lng'],
            ];
        }

        return $normalized;
    }

    protected function normalizeTransport(string $transport): string
    {
        $transport = strtolower($transport);

        if (! in_array($transport, [self::AUTOMOBILE, self::WALKING], true)) {
            throw new InvalidArgumentException(
                "Unsupported transport [{$transport}]. Use Directions::AUTOMOBILE or Directions::WALKING."
            );
        }

        return $transport;
    }
}
