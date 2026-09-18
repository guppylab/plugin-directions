## guppylab/plugin-directions

Real-route distance and travel time (ETA) from one origin to multiple
destinations.

- **iOS**: MapKit `MKDirections` — on-device, no API key, no cost.
- **Android**: OSRM over HTTP, and only when the app configures a server
  (`DIRECTIONS_ANDROID_PROVIDER=osrm`, `DIRECTIONS_OSRM_URL=...`). Unconfigured,
  every destination comes back with `error: "unsupported"`.

The call is asynchronous — `distances()` returns immediately and the result
arrives as a native event. The event always fires, on both platforms, with one
row per destination, so a caller never waits on a result that will not come.

### PHP Usage (Livewire/Blade)

@verbatim
<code-snippet name="Requesting distances" lang="php">
use Guppylab\Directions\Facades\Directions;

$id = Directions::distances(
    originLat: -22.2171,
    originLng: -49.9501,
    destinations: [
        ['id' => 'store-1', 'lat' => -22.2200, 'lng' => -49.9600],
        ['id' => 'store-2', 'lat' => -22.2100, 'lng' => -49.9400],
    ],
    id: 'nearby',                     // optional; a UUID is generated when omitted
    transport: Directions::AUTOMOBILE, // or Directions::WALKING
);
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Receiving the results" lang="php">
use Guppylab\Directions\Events\DistancesReceived;
use Native\Mobile\Attributes\OnNative;

// The #[OnNative] attribute must live on the component class, not on a trait.
#[OnNative(DistancesReceived::class)]
public function onDistances(DistancesReceived $event): void
{
    if ($event->failed()) {
        $this->etas = $this->haversineFallback();

        return;
    }

    // ['store-1' => ['id' => 'store-1', 'meters' => 1234, 'seconds' => 180, 'ok' => true]]
    $this->etas = $event->routed();
}
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Checking support up front" lang="php">
use Guppylab\Directions\Facades\Directions;

// false on Android without a configured routing provider — hide the ETA column
// instead of rendering empty cells after a round trip.
$canRoute = Directions::isSupported();
</code-snippet>
@endverbatim

### JavaScript Usage (Inertia — Vue or React)

@verbatim
<code-snippet name="Requesting and receiving in JS" lang="js">
import { distances, onDistances, setProvider } from 'guppylab-plugin-directions';

// JS cannot read config/directions.php. Share Directions::providerConfig() from
// the server (Inertia prop, meta tag) and hand it over once at boot.
setProvider(page.props.directionsProvider);

const unsubscribe = onDistances((rows, id) => {
    // rows: [{ id: 'store-1', meters: 1234, seconds: 180, ok: true }, ...]
});

await distances(-22.2171, -49.9501, [
    { id: 'store-1', lat: -22.2200, lng: -49.9600 },
], { transport: 'automobile' });
</code-snippet>
@endverbatim

### Rules

- Always handle `ok: false` rows with a haversine fallback. Use
  `$event->failed()` to detect the case where nothing routed at all.
- `meters` and `seconds` are absent when `ok` is `false`; an `error` code is
  present instead: `unsupported`, `timeout`, `over_limit`, `no_route`, `failed`.
- Never point `DIRECTIONS_OSRM_URL` at `router.project-osrm.org` — the OSRM
  project forbids production use of the demo server. Run your own instance.
- No permissions are required on iOS: MapKit routes between the coordinates
  passed in, not from the device's own position. Android needs `INTERNET`, which
  the plugin declares.
- Keep batches at or below `directions.max_destinations` (default 25).
  Destinations beyond it come back with `error: "over_limit"`.
- Pass an `id` when more than one request can be in flight, and match it in the
  event handler before applying the results.
