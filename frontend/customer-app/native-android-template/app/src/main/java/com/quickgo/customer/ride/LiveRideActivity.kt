package com.quickgo.customer.ride

import android.animation.ValueAnimator
import android.content.Intent
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.util.Log
import android.view.animation.LinearInterpolator
import android.widget.ImageView
import android.widget.TextView
import androidx.appcompat.app.AppCompatActivity
import com.bumptech.glide.Glide
import com.google.android.gms.location.*
import com.google.android.gms.maps.CameraUpdateFactory
import com.google.android.gms.maps.GoogleMap
import com.google.android.gms.maps.OnMapReadyCallback
import com.google.android.gms.maps.SupportMapFragment
import com.google.android.gms.maps.model.*
import com.google.android.material.button.MaterialButton
import com.quickgo.customer.R
import okhttp3.Call
import okhttp3.Callback
import okhttp3.Response
import org.json.JSONObject
import java.io.IOException

class LiveRideActivity : AppCompatActivity(), OnMapReadyCallback {

    private lateinit var tvEta: TextView
    private lateinit var tvDistance: TextView
    private lateinit var tvRideStatus: TextView
    private lateinit var tvDriverName: TextView
    private lateinit var tvDriverMeta: TextView
    private lateinit var tvPickup: TextView
    private lateinit var tvDrop: TextView
    private lateinit var tvFare: TextView
    private lateinit var tvOtp: TextView
    private lateinit var btnCall: MaterialButton
    private lateinit var btnCancelRide: MaterialButton
    private lateinit var imgDriver: ImageView

    private var googleMap: GoogleMap? = null
    private var driverMarker: Marker? = null
    private var pickupMarker: Marker? = null
    private var routePolyline: Polyline? = null

    private lateinit var fusedLocation: FusedLocationProviderClient
    private lateinit var locationRequest: LocationRequest
    private lateinit var locationCallback: LocationCallback

    private val uiHandler = Handler(Looper.getMainLooper())
    private var pollRunnable: Runnable? = null

    private var rideId: String = ""
    private var rideStatus = "accepted"

    private var pickupLat = 0.0
    private var pickupLng = 0.0
    private var dropLat = 0.0
    private var dropLng = 0.0
    private var driverLat = 0.0
    private var driverLng = 0.0
    private var driverPhone = ""

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_live_ride)

        rideId = intent.getStringExtra(RideSearchingActivity.EXTRA_RIDE_ID).orEmpty()

        bindViews()
        setupMap()
        setupLocationUpdates()
        setupActions()
        startLivePolling()
    }

    private fun bindViews() {
        tvEta = findViewById(R.id.tvEta)
        tvDistance = findViewById(R.id.tvDistance)
        tvRideStatus = findViewById(R.id.tvRideStatus)
        tvDriverName = findViewById(R.id.tvDriverName)
        tvDriverMeta = findViewById(R.id.tvDriverMeta)
        tvPickup = findViewById(R.id.tvPickup)
        tvDrop = findViewById(R.id.tvDrop)
        tvFare = findViewById(R.id.tvFare)
        tvOtp = findViewById(R.id.tvOtp)
        btnCall = findViewById(R.id.btnCall)
        btnCancelRide = findViewById(R.id.btnCancelRide)
        imgDriver = findViewById(R.id.imgDriver)
    }

    private fun setupMap() {
        val mapFragment = supportFragmentManager.findFragmentById(R.id.liveMap) as SupportMapFragment
        mapFragment.getMapAsync(this)
    }

    override fun onMapReady(map: GoogleMap) {
        googleMap = map
        map.uiSettings.isZoomControlsEnabled = true
        map.uiSettings.isCompassEnabled = true
        map.uiSettings.isTiltGesturesEnabled = true
        map.uiSettings.isMyLocationButtonEnabled = true
    }

    private fun setupLocationUpdates() {
        fusedLocation = LocationServices.getFusedLocationProviderClient(this)
        locationRequest = LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, 4000L)
            .setMinUpdateIntervalMillis(3000L)
            .setWaitForAccurateLocation(true)
            .build()

        locationCallback = object : LocationCallback() {
            override fun onLocationResult(result: LocationResult) {
                val last = result.lastLocation ?: return
                ApiClient.updateCustomerLocation(rideId, last.latitude, last.longitude, object : Callback {
                    override fun onFailure(call: Call, e: IOException) = Unit
                    override fun onResponse(call: Call, response: Response) {
                        response.close()
                    }
                })
            }
        }
    }

    private fun setupActions() {
        btnCall.setOnClickListener {
            if (driverPhone.isBlank()) return@setOnClickListener
            val dialIntent = Intent(Intent.ACTION_DIAL)
            dialIntent.data = android.net.Uri.parse("tel:$driverPhone")
            startActivity(dialIntent)
        }

        btnCancelRide.setOnClickListener {
            val sheet = CancelRideBottomSheet { reason ->
                ApiClient.cancelRide(rideId, reason, object : Callback {
                    override fun onFailure(call: Call, e: IOException) {
                        Log.e(TAG, "Cancel failed", e)
                    }

                    override fun onResponse(call: Call, response: Response) {
                        response.close()
                        runOnUiThread { finish() }
                    }
                })
            }
            sheet.show(supportFragmentManager, "cancel_ride")
        }
    }

    private fun startLivePolling() {
        pollRunnable = object : Runnable {
            override fun run() {
                ApiClient.getLiveRide(rideId, object : Callback {
                    override fun onFailure(call: Call, e: IOException) {
                        uiHandler.postDelayed(this@Runnable, POLL_MS)
                    }

                    override fun onResponse(call: Call, response: Response) {
                        response.use {
                            val body = it.body?.string().orEmpty()
                            if (it.isSuccessful) {
                                applyLiveRide(body)
                            }
                            uiHandler.postDelayed(this@Runnable, POLL_MS)
                        }
                    }
                })
            }
        }
        uiHandler.post(pollRunnable!!)
    }

    private fun applyLiveRide(payload: String) {
        try {
            val root = JSONObject(payload)
            val ride = root.optJSONObject("ride") ?: return
            val driver = root.optJSONObject("driver") ?: JSONObject()

            rideStatus = ride.optString("status", "accepted")
            pickupLat = ride.optDouble("pickup_lat", pickupLat)
            pickupLng = ride.optDouble("pickup_lng", pickupLng)
            dropLat = ride.optDouble("drop_lat", dropLat)
            dropLng = ride.optDouble("drop_lng", dropLng)
            val otp = ride.optString("otp_code", "----")

            driverLat = driver.optDouble("latitude", driverLat)
            driverLng = driver.optDouble("longitude", driverLng)
            driverPhone = driver.optString("phone", "")

            runOnUiThread {
                tvRideStatus.text = "Status: $rideStatus"
                tvPickup.text = "Pickup: ${ride.optString("pickup_address", "--")}"
                tvDrop.text = "Drop: ${ride.optString("drop_address", "--")}"
                tvFare.text = "Fare: ${ride.optString("fare", "--")}"
                tvDriverName.text = driver.optString("name", "Driver")
                val rating = driver.optString("rating", "4.8")
                val vehicleNo = driver.optString("vehicle_number", "--")
                val vehicleModel = driver.optString("vehicle_model", "Bike")
                tvDriverMeta.text = "$rating ★  |  $vehicleNo  |  $vehicleModel"
                tvOtp.text = otp

                val photo = driver.optString("photo_url", "")
                if (photo.isNotBlank()) {
                    Glide.with(this).load(photo).circleCrop().into(imgDriver)
                }

                updateMapObjects()
                fetchRouteAndEta()
            }

            if (rideStatus == "completed" || rideStatus == "cancelled") {
                runOnUiThread { finish() }
            }
        } catch (e: Exception) {
            Log.e(TAG, "Invalid live ride JSON", e)
        }
    }

    private fun updateMapObjects() {
        val map = googleMap ?: return

        val driverPos = LatLng(driverLat, driverLng)
        val pickupPos = LatLng(pickupLat, pickupLng)

        if (driverMarker == null) {
            driverMarker = map.addMarker(
                MarkerOptions()
                    .position(driverPos)
                    .title("Driver")
                    .icon(BitmapDescriptorFactory.defaultMarker(BitmapDescriptorFactory.HUE_AZURE))
            )
        } else {
            animateMarker(driverMarker!!, driverPos)
        }

        if (pickupMarker == null) {
            pickupMarker = map.addMarker(MarkerOptions().position(pickupPos).title("Pickup"))
        } else {
            pickupMarker?.position = pickupPos
        }

        val target = if (rideStatus == "accepted" || rideStatus == "arrived") {
            pickupPos
        } else {
            LatLng(dropLat, dropLng)
        }

        val bounds = LatLngBounds.builder()
            .include(driverPos)
            .include(target)
            .build()
        map.animateCamera(CameraUpdateFactory.newLatLngBounds(bounds, 150))
    }

    private fun fetchRouteAndEta() {
        val target = if (rideStatus == "accepted" || rideStatus == "arrived") {
            LatLng(pickupLat, pickupLng)
        } else {
            LatLng(dropLat, dropLng)
        }

        DirectionsClient.getDirections(driverLat, driverLng, target.latitude, target.longitude, object : Callback {
            override fun onFailure(call: Call, e: IOException) {
                Log.e(TAG, "Directions failed", e)
            }

            override fun onResponse(call: Call, response: Response) {
                response.use {
                    val body = it.body?.string().orEmpty()
                    if (!it.isSuccessful) return
                    drawRouteAndMeta(body)
                }
            }
        })
    }

    private fun drawRouteAndMeta(payload: String) {
        try {
            val root = JSONObject(payload)
            val routes = root.optJSONArray("routes") ?: return
            if (routes.length() == 0) return

            val firstRoute = routes.getJSONObject(0)
            val encoded = firstRoute.getJSONObject("overview_polyline").optString("points", "")
            val points = decodePolyline(encoded)

            val leg = firstRoute.optJSONArray("legs")?.optJSONObject(0) ?: JSONObject()
            val etaText = leg.optJSONObject("duration")?.optString("text", "--") ?: "--"
            val distanceText = leg.optJSONObject("distance")?.optString("text", "--") ?: "--"

            runOnUiThread {
                tvEta.text = "ETA: $etaText"
                tvDistance.text = "Distance: $distanceText"

                routePolyline?.remove()
                routePolyline = googleMap?.addPolyline(
                    PolylineOptions()
                        .addAll(points)
                        .color(0xFF2563EB.toInt())
                        .width(12f)
                )
            }
        } catch (e: Exception) {
            Log.e(TAG, "Directions parse failed", e)
        }
    }

    private fun decodePolyline(encoded: String): List<LatLng> {
        val poly = ArrayList<LatLng>()
        var index = 0
        var lat = 0
        var lng = 0

        while (index < encoded.length) {
            var b: Int
            var shift = 0
            var result = 0
            do {
                b = encoded[index++].code - 63
                result = result or ((b and 0x1f) shl shift)
                shift += 5
            } while (b >= 0x20)
            val dlat = if (result and 1 != 0) (result shr 1).inv() else result shr 1
            lat += dlat

            shift = 0
            result = 0
            do {
                b = encoded[index++].code - 63
                result = result or ((b and 0x1f) shl shift)
                shift += 5
            } while (b >= 0x20)
            val dlng = if (result and 1 != 0) (result shr 1).inv() else result shr 1
            lng += dlng

            poly.add(LatLng(lat / 1E5, lng / 1E5))
        }
        return poly
    }

    private fun animateMarker(marker: Marker, to: LatLng) {
        val start = marker.position
        val animator = ValueAnimator.ofFloat(0f, 1f)
        animator.duration = 900
        animator.interpolator = LinearInterpolator()
        animator.addUpdateListener { va ->
            val t = va.animatedFraction
            val lat = (to.latitude - start.latitude) * t + start.latitude
            val lng = (to.longitude - start.longitude) * t + start.longitude
            marker.position = LatLng(lat, lng)
        }
        animator.start()
    }

    override fun onStart() {
        super.onStart()
        try {
            fusedLocation.requestLocationUpdates(locationRequest, locationCallback, Looper.getMainLooper())
        } catch (_: SecurityException) {
            // Ask location permissions before opening this screen.
        }
    }

    override fun onStop() {
        fusedLocation.removeLocationUpdates(locationCallback)
        super.onStop()
    }

    override fun onDestroy() {
        pollRunnable?.let { uiHandler.removeCallbacks(it) }
        super.onDestroy()
    }

    companion object {
        private const val TAG = "LiveRideActivity"
        private const val POLL_MS = 4000L
    }
}
