import Foundation
import MapKit

// MARK: - Directions Function Namespace

/// Real-route distance and travel time using MapKit (MKDirections): on-device,
/// no API key, no cost. Namespace: "Directions.*"
enum DirectionsFunctions {

    /// Event class dispatched when the caller does not name one. Kept in sync
    /// with `Guppylab\Directions\Events\DistancesReceived`.
    static let defaultEvent = "Guppylab\\Directions\\Events\\DistancesReceived"

    // MARK: - Directions.Distances

    /// Distance and ETA by route, from one origin to each destination.
    ///
    /// Parameters:
    ///   - origin: { lat: number, lng: number }
    ///   - destinations: [ { id: string, lat: number, lng: number } ]
    ///   - transport: (optional) "automobile" | "walking". Default: automobile
    ///   - timeout: (optional) seconds allowed per route. Default: 15
    ///   - max_destinations: (optional) batch cap. Default: 25
    ///   - id: (optional) correlation string, echoed back on the event
    ///   - event: (optional) event class to dispatch
    ///
    /// Always dispatches exactly one event with { id, results }, where results
    /// is a JSON string of [ { id, meters?, seconds?, ok, error? } ]. Every
    /// destination handed in is present in the result — a caller never has to
    /// guess whether more rows are coming.
    final class Distances: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let reqId = parameters["id"] as? String
            let event = parameters["event"] as? String ?? DirectionsFunctions.defaultEvent
            let transport = (parameters["transport"] as? String ?? "automobile").lowercased()
            let timeout = (parameters["timeout"] as? NSNumber)?.doubleValue ?? 15
            let maxDestinations = (parameters["max_destinations"] as? NSNumber)?.intValue ?? 25

            guard let origin = parameters["origin"] as? [String: Any],
                  let oLat = (origin["lat"] as? NSNumber)?.doubleValue,
                  let oLng = (origin["lng"] as? NSNumber)?.doubleValue else {
                return BridgeResponse.error(code: "invalid_origin", message: "origin { lat, lng } is required")
            }

            // Destinations: [{ id, lat, lng }]. Malformed rows are skipped.
            var dests: [(id: String, lat: Double, lng: Double)] = []
            if let arr = parameters["destinations"] as? [[String: Any]] {
                for d in arr {
                    guard let idAny = d["id"],
                          let lat = (d["lat"] as? NSNumber)?.doubleValue,
                          let lng = (d["lng"] as? NSNumber)?.doubleValue else { continue }
                    let id = (idAny as? String) ?? String(describing: idAny)
                    dests.append((id: id, lat: lat, lng: lng))
                }
            }

            // Anything past the cap is reported rather than silently dropped.
            let overflow = dests.count > maxDestinations ? Array(dests[maxDestinations...]) : []
            if dests.count > maxDestinations {
                dests = Array(dests[..<maxDestinations])
            }

            let transportType: MKDirectionsTransportType = (transport == "walking") ? .walking : .automobile
            let source = MKMapItem(placemark: MKPlacemark(
                coordinate: CLLocationCoordinate2D(latitude: oLat, longitude: oLng)))

            // MKDirections is rate limited, so destinations are resolved one at a
            // time. Each one gets its own budget: a route that never calls back
            // costs `timeout` seconds and is reported as such, it does not stall
            // the batch or swallow the event.
            DispatchQueue.global(qos: .userInitiated).async {
                let lock = NSLock()
                var results: [[String: Any]] = []

                func append(_ row: [String: Any]) {
                    lock.lock(); results.append(row); lock.unlock()
                }

                for dest in dests {
                    let done = DispatchSemaphore(value: 0)
                    let settleLock = NSLock()
                    var settled = false

                    // Exactly one row per destination, whichever finishes first:
                    // the route callback, the retry, or the timeout below.
                    let settle: ([String: Any]) -> Void = { row in
                        settleLock.lock()
                        defer { settleLock.unlock() }
                        guard !settled else { return }
                        settled = true
                        append(row)
                        done.signal()
                    }

                    DirectionsFunctions.calculate(
                        source: source,
                        dest: dest,
                        transportType: transportType,
                        attempt: 0,
                        settle: settle
                    )

                    if done.wait(timeout: .now() + timeout) == .timedOut {
                        settle(["id": dest.id, "ok": false, "error": "timeout"])
                    }
                }

                for dest in overflow {
                    append(["id": dest.id, "ok": false, "error": "over_limit"])
                }

                lock.lock(); let payload = results; lock.unlock()
                let json = (try? JSONSerialization.data(withJSONObject: payload, options: []))
                    .flatMap { String(data: $0, encoding: .utf8) } ?? "[]"

                DispatchQueue.main.async {
                    LaravelBridge.shared.send?(event, ["id": reqId, "results": json])
                }
            }

            return BridgeResponse.success(data: [:])
        }
    }

    // MARK: - Directions.IsSupported

    /// Whether the device can compute routes. Always true on iOS: MKDirections
    /// is on-device and needs no key, no account and no network provider.
    final class IsSupported: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            return BridgeResponse.success(data: ["supported": true, "provider": "mapkit"])
        }
    }

    // MARK: - Route calculation

    /// Calculate one route. MKDirections throttles under load and answers with
    /// an error rather than a route, so a single failure is retried once after a
    /// short breather before the destination is reported as unroutable.
    private static func calculate(
        source: MKMapItem,
        dest: (id: String, lat: Double, lng: Double),
        transportType: MKDirectionsTransportType,
        attempt: Int,
        settle: @escaping ([String: Any]) -> Void
    ) {
        let request = MKDirections.Request()
        request.source = source
        request.destination = MKMapItem(placemark: MKPlacemark(
            coordinate: CLLocationCoordinate2D(latitude: dest.lat, longitude: dest.lng)))
        request.transportType = transportType
        request.requestsAlternateRoutes = false

        MKDirections(request: request).calculate { response, error in
            if let route = response?.routes.first {
                settle([
                    "id": dest.id,
                    "meters": Int(route.distance.rounded()),
                    "seconds": Int(route.expectedTravelTime.rounded()),
                    "ok": true,
                ])
                return
            }

            if attempt < 1 {
                DispatchQueue.global(qos: .userInitiated).asyncAfter(deadline: .now() + 0.9) {
                    calculate(
                        source: source,
                        dest: dest,
                        transportType: transportType,
                        attempt: attempt + 1,
                        settle: settle
                    )
                }
                return
            }

            settle([
                "id": dest.id,
                "ok": false,
                "error": error == nil ? "no_route" : "failed",
            ])
        }
    }
}
