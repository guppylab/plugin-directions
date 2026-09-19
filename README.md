# Directions — NativePHP Mobile plugin

Real-route **distance** and **travel time (ETA)** from one origin to many
destinations.

| | Engine | Cost | Setup |
|---|---|---|---|
| **iOS** | MapKit `MKDirections` (iOS 15+) | free, on-device | none |
| **Android** | OSRM-shaped HTTP endpoint | your own server | set `DIRECTIONS_OSRM_URL` |

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

### Pointing it at your own backend

`url` does not have to be an `osrm-backend` instance — anything answering the
OSRM `table`/`route` shape will do, and your own API is usually the better
target:

```
device ──► https://api.example.com/routing/table/v1/driving/{coords}
                 │  cache by area, rate limit, hold the provider key
                 └──► OSRM, or a commercial matrix API
```

Three things this buys you. The provider key never ships in the bundle, where
anyone holding the APK can read it. Results can be cached per area, and users
cluster, so a handful of upstream calls serves a whole city. And the upstream
provider can be swapped without a new release.

Authenticate against it with `headers`:

```dotenv
DIRECTIONS_OSRM_URL=https://api.example.com/routing
DIRECTIONS_OSRM_TOKEN=a-scoped-revocable-token
```

```php
// config/directions.php
'headers' => array_filter([
    'Authorization' => env('DIRECTIONS_OSRM_TOKEN')
        ? 'Bearer '.env('DIRECTIONS_OSRM_TOKEN')
        : null,
]),
```

Whatever goes in `headers` is readable by whoever holds the build, so it should
be a credential scoped to that endpoint and revocable on its own — never a
provider key you would not hand out. Android refuses to send these over plain
`http` to a routable host (the request still goes out unauthenticated, so you
get a 401 rather than a silent leak); `http` to a loopback or private address
stays allowed for local development.

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
plugin declares. There is no on-device alternative: Play Services Location only
positions, the Maps SDK only renders, and the `google.navigation:` intent hands
the user to the Maps app without returning anything to you.

## License

MIT
