# Directions — NativePHP Mobile plugin

Real-route **distance** and **travel time (ETA)** from one origin to many
destinations.

| | Engine | Cost | Setup |
|---|---|---|---|
| **iOS** | MapKit `MKDirections` (iOS 15+) | free, on-device | none |
| **Android** | OSRM over HTTP | your own server | set `DIRECTIONS_OSRM_URL` |

Android has no free on-device routing engine, so routing there is opt-in and
server backed. **Both platforms always answer**: destinations that could not be
routed come back with `ok: false` and an error code, so an app falls back to a
straight-line (haversine) estimate on a signal instead of on a timeout that
never arrives.

## Installation

```bash
composer require guppylab/plugin-directions
php artisan native:plugin:register guppylab/plugin-directions
php artisan native:run   # rebuild the native project
```

Publish the config if you want to route on Android:

```bash
php artisan vendor:publish --tag=directions-config
```

```dotenv
DIRECTIONS_ANDROID_PROVIDER=osrm
DIRECTIONS_OSRM_URL=https://osrm.example.com
```

There is deliberately no default URL. The public demo server
(`router.project-osrm.org`) is not allowed for production use by the OSRM
project, so the plugin will not quietly point your app at it. Run your own
`osrm-backend`, or leave the provider as `none` and use the fallback.

## Usage (PHP / Livewire)

```php
use Guppylab\Directions\Facades\Directions;
use Guppylab\Directions\Events\DistancesReceived;
use Native\Mobile\Attributes\OnNative;

class NearbyStores extends \Livewire\Component
{
    public array $etas = [];

    public function calculate(): void
    {
        Directions::distances(-23.5505, -46.6333, [
            ['id' => 1, 'lat' => -23.5614, 'lng' => -46.6559],
            ['id' => 2, 'lat' => -23.5880, 'lng' => -46.6820],
        ]);
    }

    #[OnNative(DistancesReceived::class)]
    public function onDistances(DistancesReceived $event): void
    {
        if ($event->failed()) {
            $this->etas = $this->haversineFallback();

            return;
        }

        $this->etas = $event->routed();
    }
}
```

`distances()` returns the correlation id it sent (a UUID when you do not pass
one), so concurrent calls can be told apart by `$event->id`.

Check support up front to hide an ETA column instead of rendering empty cells:

```php
Directions::isSupported();          // bool
Directions::support();              // ['supported' => bool, 'provider' => 'mapkit'|'osrm'|'none']
```

## Usage (JavaScript / Inertia — Vue or React)

```js
import { distances, onDistances, setProvider } from 'guppylab-plugin-directions';

// JavaScript cannot read config/directions.php. Share it from the server
// (an Inertia prop, a meta tag) and hand it over once at boot:
setProvider(page.props.directionsProvider); // Directions::providerConfig() in PHP

const unsubscribe = onDistances((rows, id) => {
    rows.filter((row) => row.ok);           // { id, meters, seconds, ok }
    rows.filter((row) => !row.ok);          // { id, ok: false, error }
});

await distances(-23.5505, -46.6333, [
    { id: 1, lat: -23.5614, lng: -46.6559 },
]);
```

The JS library ships TypeScript definitions and works across Livewire v3/v4 and
Inertia (Vue/React).

## Result contract

Every destination you pass appears exactly once in the result.

```json
[
    { "id": "1", "meters": 4312, "seconds": 780, "ok": true },
    { "id": "2", "ok": false, "error": "no_route" }
]
```

| `error` | Meaning |
|---|---|
| `unsupported` | No routing engine available (Android without a provider) |
| `timeout` | The engine did not answer within `directions.timeout` |
| `over_limit` | Dropped because the batch exceeded `directions.max_destinations` |
| `no_route` | The engine answered but found no route |
| `failed` | The engine returned an error |

## Notes on each platform

**iOS.** `MKDirections` is rate limited, so destinations are resolved one at a
time, each with its own timeout and a single retry. A batch of 25 driving
routes takes a few seconds; large batches take proportionally longer, which is
why `max_destinations` defaults to 25.

**Android.** The OSRM `table` service answers the whole batch in one request.
Servers may disable it or cap `max-table-size`, in which case the plugin falls
back to one `/route` request per destination. Requires `INTERNET`, which the
plugin declares.

## License

MIT
