package com.quickgo.driver.navigation

import android.Manifest
import android.animation.TypeEvaluator
import android.animation.ValueAnimator
import android.content.pm.PackageManager
import android.location.Location
import android.os.Bundle
import android.os.Looper
import android.text.Html
import android.view.View
import android.widget.Button
import android.widget.SeekBar
import android.widget.TextView
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import com.quickgo.driver.R
import com.google.android.gms.location.FusedLocationProviderClient
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.maps.CameraUpdateFactory
import com.google.android.gms.maps.GoogleMap
import com.google.android.gms.maps.OnMapReadyCallback
import com.google.android.gms.maps.SupportMapFragment
import com.google.android.gms.maps.model.BitmapDescriptorFactory
import com.google.android.gms.maps.model.JointType
import com.google.android.gms.maps.model.LatLng
import com.google.android.gms.maps.model.Marker
import com.google.android.gms.maps.model.MarkerOptions
import com.google.android.gms.maps.model.Polyline
import com.google.android.gms.maps.model.PolylineOptions
import com.google.maps.android.PolyUtil
import org.json.JSONArray
import org.json.JSONObject
import java.io.BufferedReader
import java.io.OutputStreamWriter
import java.net.HttpURLConnection
import java.net.URL
import java.util.concurrent.Executors

class LiveNavigationActivity : AppCompatActivity(), OnMapReadyCallback {
    private lateinit var fusedLocationClient: FusedLocationProviderClient
    private val ioExecutor = Executors.newSingleThreadExecutor()

    private lateinit var tvNextTurn: TextView
    private lateinit var tvDistance: TextView
    private lateinit var tvEta: TextView
    private lateinit var tvSwipeLabel: TextView
    private lateinit var seekArrived: SeekBar
    private lateinit var btnCancelRide: Button
    private lateinit var btnOtpVerified: Button

    private var googleMap: GoogleMap? = null
    private var driverMarker: Marker? = null
    private var destinationMarker: Marker? = null
    private var routePolyline: Polyline? = null
    private var routePoints: List<LatLng> = emptyList()

    private var rideId: Long = 0L
    private var driverId: Long = 0L
    private var cityId: Int = 0
    private var pickup = LatLng(0.0, 0.0)
    private var drop = LatLng(0.0, 0.0)
    private var currentDestination = LatLng(0.0, 0.0)

    private var backendBaseUrl = ""
    private var apiSharedKey = ""
    private var mapsApiKey = ""

    private var lastRouteRefreshAt = 0L
    private var lastKnownLocation: LatLng? = null
    private var completionSent = false

    private enum class NavState { ACCEPTED, ARRIVED, RIDE_STARTED, COMPLETED, CANCELED }
    private var navState = NavState.ACCEPTED

    private val locationCallback = object : LocationCallback() {
        override fun onLocationResult(result: LocationResult) {
            val loc = result.lastLocation ?: return
            onDriverLocation(LatLng(loc.latitude, loc.longitude))
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_live_navigation)

        tvNextTurn = findViewById(R.id.tv_next_turn)
        tvDistance = findViewById(R.id.tv_distance)
        tvEta = findViewById(R.id.tv_eta)
        tvSwipeLabel = findViewById(R.id.tv_swipe_label)
        seekArrived = findViewById(R.id.seek_arrived)
        btnCancelRide = findViewById(R.id.btn_cancel_ride)
        btnOtpVerified = findViewById(R.id.btn_otp_verified)

        rideId = intent.getLongExtra("ride_id", 0L)
        driverId = intent.getLongExtra("driver_id", 0L)
        cityId = intent.getIntExtra("city_id", 0)
        pickup = LatLng(
            intent.getDoubleExtra("pickup_lat", 0.0),
            intent.getDoubleExtra("pickup_lng", 0.0)
        )
        drop = LatLng(
            intent.getDoubleExtra("drop_lat", 0.0),
            intent.getDoubleExtra("drop_lng", 0.0)
        )
        currentDestination = pickup
        backendBaseUrl = intent.getStringExtra("backend_base_url") ?: ""
        apiSharedKey = intent.getStringExtra("api_shared_key") ?: ""
        mapsApiKey = applicationContext.packageManager
            .getApplicationInfo(packageName, PackageManager.GET_META_DATA)
            .metaData?.getString("com.google.android.geo.API_KEY") ?: ""

        btnCancelRide.setOnClickListener { cancelRide() }
        btnOtpVerified.setOnClickListener { onOtpVerified() }
        setupArrivedSwipe()

        fusedLocationClient = LocationServices.getFusedLocationProviderClient(this)
        val mapFragment = supportFragmentManager.findFragmentById(R.id.map_container) as SupportMapFragment
        mapFragment.getMapAsync(this)
    }

    override fun onMapReady(map: GoogleMap) {
        googleMap = map
        map.uiSettings.isTiltGesturesEnabled = false
        map.uiSettings.isMapToolbarEnabled = false
        map.uiSettings.isMyLocationButtonEnabled = false

        destinationMarker = map.addMarker(
            MarkerOptions()
                .position(currentDestination)
                .title("Pickup")
        )
        startLocationUpdates()
    }

    private fun startLocationUpdates() {
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) != PackageManager.PERMISSION_GRANTED) {
            ActivityCompat.requestPermissions(this, arrayOf(Manifest.permission.ACCESS_FINE_LOCATION), 1001)
            return
        }

        googleMap?.isMyLocationEnabled = true
        val request = LocationRequest.Builder(4000L)
            .setMinUpdateIntervalMillis(3000L)
            .setPriority(LocationRequest.PRIORITY_HIGH_ACCURACY)
            .build()
        fusedLocationClient.requestLocationUpdates(request, locationCallback, Looper.getMainLooper())
    }

    private fun onDriverLocation(point: LatLng) {
        lastKnownLocation = point
        animateDriverMarker(point)
        sendDriverLocationToBackend(point)

        val firstFix = routePoints.isEmpty()
        val deviated = isDriverOffRoute(point)
        val now = System.currentTimeMillis()
        val periodicRefresh = now - lastRouteRefreshAt > 25000L
        if (firstFix || deviated || periodicRefresh) {
            requestDirections(point, currentDestination)
            lastRouteRefreshAt = now
        }

        if (navState == NavState.ACCEPTED) {
            val distToPickup = distanceMeters(point, pickup)
            if (distToPickup <= 50.0) {
                enableArrivedSwipe(distToPickup <= 30.0)
            }
        } else if (navState == NavState.RIDE_STARTED) {
            val distToDrop = distanceMeters(point, drop)
            if (!completionSent && distToDrop <= 40.0) {
                completionSent = true
                completeRide()
            }
        }
    }

    private fun requestDirections(origin: LatLng, destination: LatLng) {
        if (mapsApiKey.isBlank()) return

        ioExecutor.execute {
            try {
                val url = "https://maps.googleapis.com/maps/api/directions/json" +
                    "?origin=${origin.latitude},${origin.longitude}" +
                    "&destination=${destination.latitude},${destination.longitude}" +
                    "&mode=driving&key=$mapsApiKey"
                val conn = URL(url).openConnection() as HttpURLConnection
                conn.requestMethod = "GET"
                conn.connectTimeout = 8000
                conn.readTimeout = 8000
                val body = conn.inputStream.bufferedReader().use(BufferedReader::readText)
                conn.disconnect()

                val parsed = parseDirections(body) ?: return@execute
                runOnUiThread {
                    applyRoute(parsed.points)
                    tvDistance.text = "Distance: ${parsed.distanceText}"
                    tvEta.text = "ETA: ${parsed.durationText}"
                    tvNextTurn.text = parsed.nextTurn
                }
            } catch (_: Throwable) {
            }
        }
    }

    private fun parseDirections(json: String): RouteData? {
        val root = JSONObject(json)
        val routes = root.optJSONArray("routes") ?: return null
        if (routes.length() == 0) return null
        val route = routes.getJSONObject(0)
        val overview = route.getJSONObject("overview_polyline").optString("points", "")
        val legs = route.optJSONArray("legs") ?: JSONArray()
        if (legs.length() == 0) return null

        val leg = legs.getJSONObject(0)
        val distanceText = leg.getJSONObject("distance").optString("text", "--")
        val durationText = leg.getJSONObject("duration").optString("text", "--")
        val steps = leg.optJSONArray("steps") ?: JSONArray()
        val nextTurnHtml = if (steps.length() > 0) steps.getJSONObject(0).optString("html_instructions", "Proceed") else "Proceed"
        val nextTurn = Html.fromHtml(nextTurnHtml, Html.FROM_HTML_MODE_LEGACY).toString().trim()
        return RouteData(
            points = PolylineDecoder.decode(overview),
            distanceText = distanceText,
            durationText = durationText,
            nextTurn = if (nextTurn.isBlank()) "Proceed" else nextTurn
        )
    }

    private fun applyRoute(points: List<LatLng>) {
        if (points.isEmpty()) return
        routePoints = points
        routePolyline?.remove()
        routePolyline = googleMap?.addPolyline(
            PolylineOptions()
                .addAll(points)
                .width(14f)
                .color(0xFF0A84FFFF.toInt())
                .jointType(JointType.ROUND)
        )
        val current = lastKnownLocation ?: points.first()
        googleMap?.animateCamera(CameraUpdateFactory.newLatLngZoom(current, 16f))
    }

    private fun animateDriverMarker(target: LatLng) {
        val map = googleMap ?: return
        if (driverMarker == null) {
            driverMarker = map.addMarker(
                MarkerOptions()
                    .position(target)
                    .title("You")
                    .icon(BitmapDescriptorFactory.defaultMarker(BitmapDescriptorFactory.HUE_AZURE))
            )
            map.animateCamera(CameraUpdateFactory.newLatLngZoom(target, 16f))
            return
        }

        val start = driverMarker!!.position
        val animator = ValueAnimator.ofObject(
            TypeEvaluator<LatLng> { fraction, startValue, endValue ->
                LatLng(
                    startValue.latitude + (endValue.latitude - startValue.latitude) * fraction,
                    startValue.longitude + (endValue.longitude - startValue.longitude) * fraction
                )
            },
            start,
            target
        )
        animator.duration = 900L
        animator.addUpdateListener { va ->
            val pos = va.animatedValue as LatLng
            driverMarker?.position = pos
        }
        animator.start()
    }

    private fun isDriverOffRoute(point: LatLng): Boolean {
        if (routePoints.size < 2) return true
        return !PolyUtil.isLocationOnPath(point, routePoints, true, 70.0)
    }

    private fun setupArrivedSwipe() {
        seekArrived.setOnSeekBarChangeListener(object : SeekBar.OnSeekBarChangeListener {
            override fun onProgressChanged(seekBar: SeekBar?, progress: Int, fromUser: Boolean) = Unit

            override fun onStartTrackingTouch(seekBar: SeekBar?) = Unit

            override fun onStopTrackingTouch(seekBar: SeekBar?) {
                val p = seekBar?.progress ?: 0
                if (seekArrived.isEnabled && p >= 95 && navState == NavState.ACCEPTED) {
                    markArrived()
                }
                seekArrived.progress = 0
            }
        })
    }

    private fun enableArrivedSwipe(within30m: Boolean) {
        seekArrived.isEnabled = true
        tvSwipeLabel.text = if (within30m) {
            "Swipe to Arrived"
        } else {
            "Near pickup. Move closer and swipe to Arrived"
        }
    }

    private fun markArrived() {
        navState = NavState.ARRIVED
        tvSwipeLabel.text = "Arrived. Verify OTP to start trip"
        seekArrived.isEnabled = false
        btnOtpVerified.visibility = View.VISIBLE
        postRideStatus("/api/arrived_ride.php", JSONObject().apply {
            put("ride_id", rideId)
            put("driver_id", driverId)
        })
    }

    private fun onOtpVerified() {
        navState = NavState.RIDE_STARTED
        currentDestination = drop
        destinationMarker?.position = drop
        destinationMarker?.title = "Drop"
        tvNextTurn.text = "Proceed to drop location"
        btnOtpVerified.visibility = View.GONE
        postRideStatus("/api/start_ride.php", JSONObject().apply {
            put("ride_id", rideId)
            put("driver_id", driverId)
        })
        lastKnownLocation?.let { requestDirections(it, drop) }
    }

    private fun completeRide() {
        navState = NavState.COMPLETED
        tvSwipeLabel.text = "Ride completed"
        postRideStatus("/api/complete_ride.php", JSONObject().apply {
            put("ride_id", rideId)
            put("driver_id", driverId)
            put("final_fare", intent.getDoubleExtra("final_fare", 0.0))
        })
    }

    private fun cancelRide() {
        navState = NavState.CANCELED
        postRideStatus("/api/cancel_ride.php", JSONObject().apply {
            put("ride_id", rideId)
            put("driver_id", driverId)
        })
        finish()
    }

    private fun sendDriverLocationToBackend(point: LatLng) {
        if (backendBaseUrl.isBlank()) return
        postRideStatus("/api/update_driver_location.php", JSONObject().apply {
            put("driver_id", driverId)
            put("latitude", point.latitude)
            put("longitude", point.longitude)
            put("online_status", 1)
            put("availability", if (navState == NavState.ACCEPTED) 1 else 0)
        })
    }

    private fun postRideStatus(path: String, payload: JSONObject) {
        if (backendBaseUrl.isBlank()) return
        ioExecutor.execute {
            try {
                val url = URL(backendBaseUrl.trimEnd('/') + path)
                val conn = url.openConnection() as HttpURLConnection
                conn.requestMethod = "POST"
                conn.setRequestProperty("Content-Type", "application/json")
                if (apiSharedKey.isNotBlank()) {
                    conn.setRequestProperty("X-API-KEY", apiSharedKey)
                }
                conn.doOutput = true
                OutputStreamWriter(conn.outputStream).use { it.write(payload.toString()) }
                if (conn.responseCode in 200..299) {
                    conn.inputStream.close()
                } else {
                    conn.errorStream?.close()
                }
                conn.disconnect()
            } catch (_: Throwable) {
            }
        }
    }

    private fun distanceMeters(a: LatLng, b: LatLng): Double {
        val out = FloatArray(1)
        Location.distanceBetween(a.latitude, a.longitude, b.latitude, b.longitude, out)
        return out[0].toDouble()
    }

    override fun onDestroy() {
        super.onDestroy()
        fusedLocationClient.removeLocationUpdates(locationCallback)
        ioExecutor.shutdownNow()
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode == 1001 && grantResults.isNotEmpty() && grantResults[0] == PackageManager.PERMISSION_GRANTED) {
            startLocationUpdates()
        }
    }

    data class RouteData(
        val points: List<LatLng>,
        val distanceText: String,
        val durationText: String,
        val nextTurn: String
    )
}
