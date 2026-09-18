package com.guppylab.plugins.directions

import android.util.Log
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONArray
import org.json.JSONObject
import java.io.BufferedReader
import java.net.HttpURLConnection
import java.net.URL
import java.util.concurrent.Executors

/**
 * Android side of the Directions plugin.
 *
 * Android has no free on-device routing engine equivalent to MapKit's
 * MKDirections, so routing here is opt-in and server backed: point the plugin at
 * an OSRM instance (config/directions.php) and it resolves real routes; leave it
 * unconfigured and every destination comes back with ok: false / "unsupported".
 *
 * Either way the DistancesReceived event is always dispatched, exactly once,
 * with one row per destination — the contract is identical to iOS, so a caller
 * can fall back to a straight-line estimate on a signal instead of on a timeout
 * that never arrives.
 */
object DirectionsFunctions {

    private const val TAG = "Directions"
    private const val DEFAULT_EVENT = "Guppylab\\Directions\\Events\\DistancesReceived"

    /** Routing is network bound; keep it off the main thread. */
    private val executor = Executors.newSingleThreadExecutor()

    // MARK: - Directions.Distances

    class Distances(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val requestId = parameters["id"] as? String
            val event = parameters["event"] as? String ?: DEFAULT_EVENT
            val transport = (parameters["transport"] as? String ?: "automobile").lowercase()
            val timeoutMs = ((parameters["timeout"] as? Number)?.toInt() ?: 15) * 1000
            val maxDestinations = (parameters["max_destinations"] as? Number)?.toInt() ?: 25

            // The bridge hands parameters over as raw org.json values, so nested
            // objects arrive as JSONObject and nested arrays as JSONArray.
            val origin = parameters["origin"] as? JSONObject
            val originLat = origin?.optDouble("lat", Double.NaN) ?: Double.NaN
            val originLng = origin?.optDouble("lng", Double.NaN) ?: Double.NaN

            if (originLat.isNaN() || originLng.isNaN()) {
                return BridgeResponse.error("invalid_origin", "origin { lat, lng } is required")
            }

            val all = parseDestinations(parameters["destinations"])
            val destinations = all.take(maxDestinations)
            val overflow = all.drop(maxDestinations)

            val provider = parameters["provider"] as? JSONObject
            val providerType = (provider?.optString("type") ?: "none").lowercase()
            val baseUrl = provider?.optString("url")?.takeIf { it.isNotEmpty() }?.trimEnd('/')
            val profile = resolveProfile(provider?.optJSONObject("profiles"), transport)

            executor.execute {
                val rows = when {
                    destinations.isEmpty() -> emptyList()
                    providerType == "osrm" && !baseUrl.isNullOrEmpty() ->
                        routeWithOsrm(baseUrl, profile, originLat, originLng, destinations, timeoutMs)
                    else -> destinations.map { unroutable(it.id, "unsupported") }
                }

                val results = JSONArray()
                rows.forEach { results.put(it) }
                overflow.forEach { results.put(unroutable(it.id, "over_limit")) }

                dispatch(event, requestId, results.toString())
            }

            return BridgeResponse.success(mapOf("success" to true))
        }

        private fun dispatch(event: String, requestId: String?, results: String) {
            val payload = JSONObject().apply {
                put("results", results)
                if (requestId != null) put("id", requestId)
            }

            activity.runOnUiThread {
                NativeActionCoordinator.dispatchEvent(activity, event, payload.toString())
            }
        }
    }

    // MARK: - Directions.IsSupported

    /**
     * Whether this device can compute real routes: true only when a routing
     * provider is configured. Lets an app hide an ETA column up front instead of
     * rendering empty cells after a round trip.
     */
    class IsSupported(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val provider = parameters["provider"] as? JSONObject
            val type = (provider?.optString("type") ?: "none").lowercase()
            val url = provider?.optString("url")

            val supported = type == "osrm" && !url.isNullOrEmpty()

            return BridgeResponse.success(
                mapOf(
                    "supported" to supported,
                    "provider" to if (supported) type else "none",
                ),
            )
        }
    }

    // MARK: - OSRM

    data class Destination(val id: String, val lat: Double, val lng: Double)

    /**
     * Resolve every destination against OSRM.
     *
     * The table service answers the whole batch in one request, which is what a
     * "distance to each of these" screen actually wants. Servers are free to
     * disable it (or cap max-table-size below the batch), so a failure falls back
     * to one /route call per destination rather than giving up on the batch.
     */
    private fun routeWithOsrm(
        baseUrl: String,
        profile: String,
        originLat: Double,
        originLng: Double,
        destinations: List<Destination>,
        timeoutMs: Int,
    ): List<JSONObject> {
        tableRoute(baseUrl, profile, originLat, originLng, destinations, timeoutMs)?.let { return it }

        return destinations.map { destination ->
            singleRoute(baseUrl, profile, originLat, originLng, destination, timeoutMs)
        }
    }

    /** One request for the whole batch. Returns null when the server refuses it. */
    private fun tableRoute(
        baseUrl: String,
        profile: String,
        originLat: Double,
        originLng: Double,
        destinations: List<Destination>,
        timeoutMs: Int,
    ): List<JSONObject>? {
        // OSRM takes coordinates as lng,lat — the opposite order of the payload.
        val coordinates = buildString {
            append(coordinate(originLng, originLat))
            destinations.forEach { append(';').append(coordinate(it.lng, it.lat)) }
        }

        val url = "$baseUrl/table/v1/$profile/$coordinates" +
            "?sources=0&annotations=duration,distance"

        val body = get(url, timeoutMs) ?: return null
        val json = runCatching { JSONObject(body) }.getOrNull() ?: return null

        if (json.optString("code") != "Ok") {
            Log.w(TAG, "OSRM table refused the request: ${json.optString("code")}")
            return null
        }

        // sources=0 means a single row, one column per input coordinate; column 0
        // is the origin against itself, so destinations start at index 1.
        val durations = json.optJSONArray("durations")?.optJSONArray(0) ?: return null
        val distances = json.optJSONArray("distances")?.optJSONArray(0) ?: return null

        return destinations.mapIndexed { index, destination ->
            val column = index + 1
            val seconds = durations.optDouble(column, Double.NaN)
            val meters = distances.optDouble(column, Double.NaN)

            if (seconds.isNaN() || meters.isNaN()) {
                unroutable(destination.id, "no_route")
            } else {
                routed(destination.id, meters, seconds)
            }
        }
    }

    /** Fallback: one route request for a single destination. */
    private fun singleRoute(
        baseUrl: String,
        profile: String,
        originLat: Double,
        originLng: Double,
        destination: Destination,
        timeoutMs: Int,
    ): JSONObject {
        val url = "$baseUrl/route/v1/$profile/" +
            "${coordinate(originLng, originLat)};${coordinate(destination.lng, destination.lat)}" +
            "?overview=false&alternatives=false"

        val body = get(url, timeoutMs) ?: return unroutable(destination.id, "failed")
        val json = runCatching { JSONObject(body) }.getOrNull()
            ?: return unroutable(destination.id, "failed")

        if (json.optString("code") != "Ok") {
            return unroutable(destination.id, "no_route")
        }

        val route = json.optJSONArray("routes")?.optJSONObject(0)
            ?: return unroutable(destination.id, "no_route")

        return routed(destination.id, route.optDouble("distance"), route.optDouble("duration"))
    }

    // MARK: - Helpers

    private fun get(url: String, timeoutMs: Int): String? {
        var connection: HttpURLConnection? = null

        return try {
            connection = (URL(url).openConnection() as HttpURLConnection).apply {
                requestMethod = "GET"
                connectTimeout = timeoutMs
                readTimeout = timeoutMs
                setRequestProperty("Accept", "application/json")
            }

            if (connection.responseCode !in 200..299) {
                Log.w(TAG, "Routing request failed with HTTP ${connection.responseCode}")
                return null
            }

            connection.inputStream.bufferedReader().use(BufferedReader::readText)
        } catch (e: Exception) {
            Log.w(TAG, "Routing request failed", e)
            null
        } finally {
            connection?.disconnect()
        }
    }

    /** Malformed rows are skipped rather than failing the whole batch. */
    private fun parseDestinations(raw: Any?): List<Destination> {
        val array = raw as? JSONArray ?: return emptyList()

        return (0 until array.length()).mapNotNull { index ->
            val row = array.optJSONObject(index) ?: return@mapNotNull null
            val id = row.opt("id")?.toString()?.takeIf { it.isNotEmpty() } ?: return@mapNotNull null
            val lat = row.optDouble("lat", Double.NaN)
            val lng = row.optDouble("lng", Double.NaN)

            if (lat.isNaN() || lng.isNaN()) return@mapNotNull null

            Destination(id, lat, lng)
        }
    }

    private fun resolveProfile(profiles: JSONObject?, transport: String): String {
        profiles?.optString(transport)?.takeIf { it.isNotEmpty() }?.let { return it }

        return if (transport == "walking") "foot" else "driving"
    }

    private fun coordinate(lng: Double, lat: Double): String = "$lng,$lat"

    private fun routed(id: String, meters: Double, seconds: Double) = JSONObject().apply {
        put("id", id)
        put("meters", Math.round(meters).toInt())
        put("seconds", Math.round(seconds).toInt())
        put("ok", true)
    }

    private fun unroutable(id: String, error: String) = JSONObject().apply {
        put("id", id)
        put("ok", false)
        put("error", error)
    }
}
