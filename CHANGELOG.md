# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.0.0]

### Added

- Android now computes real routes through OSRM when a provider is configured
  (`DIRECTIONS_ANDROID_PROVIDER=osrm`, `DIRECTIONS_OSRM_URL=...`). The `table`
  service resolves the whole batch in one request, with a per-destination
  `/route` fallback for servers that disable it.
- `Directions.IsSupported` bridge function, exposed as `Directions::isSupported()`
  and `Directions::support()` in PHP and `isSupported()` / `support()` in JS.
- Publishable `config/directions.php` (`--tag=directions-config`) covering the
  destination cap, the per-route timeout and the Android routing provider.
- `DistancesReceived::rows()`, `routed()` and `failed()` so listeners stop
  hand-decoding the JSON payload.
- `error` code on every unrouted destination: `unsupported`, `timeout`,
  `over_limit`, `no_route`, `failed`.
- `setProvider()` in the JS library, for handing the Android routing config over
  from the server.

### Fixed

- **Android never dispatched `DistancesReceived`.** A caller awaiting the event
  waited forever, and the README described a behaviour the code did not have.
  The event is now always dispatched on both platforms, with one row per
  destination.
- **iOS could deadlock and never dispatch the event.** The semaphore guarding
  the sequential route calculation had no timeout, so a route that never called
  back stalled the batch permanently. Each destination now has its own budget.
- **iOS dispatched a stale event class** (`Nativephp\Directions\...`) when the
  caller did not name one, left over from the vendor rename.
- **The JS library read the wrong part of the response.** `/_native/api/call`
  answers `{ status, data }`; the library read the top level, so every field was
  `undefined`. It now unwraps the envelope, sends `X-CSRF-TOKEN` and throws on a
  native error.
- Destinations beyond the batch cap are reported as `over_limit` instead of
  being dropped silently.

### Changed

- **Breaking.** `Directions::distances()` returns the correlation id (`string`)
  instead of `true`, and generates a UUID when none is given. A successful call
  is still truthy.
- **Breaking.** An unknown `transport` now throws `InvalidArgumentException`
  instead of silently falling back to `automobile`.
- Android declares `android.permission.INTERNET`, required for server-backed
  routing.
- Malformed destinations are dropped in PHP rather than in each native runtime.
- Source comments and documentation translated to English.

## [2.0.1]

- Vendor renamed to `guppylab`.

## [1.0.0]

- First release: `Directions.Distances` with MapKit on iOS.
