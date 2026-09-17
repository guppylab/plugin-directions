## guppylab/plugin-directions

Real-route distance and travel time (ETA) from one origin to multiple
destinations. iOS uses MapKit `MKDirections` (on-device, no API key, no cost).
Android has no free on-device routing engine: every destination comes back with
`ok: false`, so the app must fall back to a straight-line (haversine) estimate.

The call is asynchronous — `distances()` returns immediately and the result
arrives as a native event.

### PHP Usage (Livewire/Blade)

@verbatim
<code-snippet name="Requesting distances" lang="php">
use Guppylab\Directions\Facades\Directions;

Directions::distances(
    originLat: -22.2171,
    originLng: -49.9501,
    destinations: [
        ['id' => 'store-1', 'lat' => -22.2200, 'lng' => -49.9600],
        ['id' => 'store-2', 'lat' => -22.2100, 'lng' => -49.9400],
    ],
    id: 'nearby',            // optional correlation id, echoed back in the event
    transport: 'automobile', // or 'walking'
);
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Receiving the results" lang="php">
use Guppylab\Directions\Events\DistancesReceived;
use Native\Mobile\Attributes\OnNative;

// The #[OnNative] attribute must live on the component class, not on a trait.
#[OnNative(DistancesReceived::class)]
public function onDistances(string $results, ?string $id = null): void
{
    // $results is a JSON string:
    // [{ "id": "store-1", "meters": 1234, "seconds": 180, "ok": true }, ...]
    $rows = json_decode($results, true);
}
</code-snippet>
@endverbatim

### JavaScript Usage (Inertia — Vue or React)

@verbatim
<code-snippet name="Requesting and receiving in JS" lang="js">
import { distances, onDistances } from 'guppylab-plugin-directions';

const unsubscribe = onDistances((rows, id) => {
    // rows: [{ id: 'store-1', meters: 1234, seconds: 180, ok: true }, ...]
});

await distances(-22.2171, -49.9501, [
    { id: 'store-1', lat: -22.2200, lng: -49.9600 },
], { transport: 'automobile' });
</code-snippet>
@endverbatim

### Rules

- Always handle `ok: false` rows with a haversine fallback — on Android every
  row is `ok: false`, and on iOS a destination can simply be unroutable.
- `meters` and `seconds` are absent when `ok` is `false`.
- No permissions are required: MapKit routes between the coordinates passed in,
  not from the device's own position.
- Pass an `id` when more than one request can be in flight, and match it in the
  event handler before applying the results.
