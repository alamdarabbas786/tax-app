package com.quickgo.customer.ride

import android.animation.ObjectAnimator
import android.content.Intent
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.view.animation.AccelerateDecelerateInterpolator
import androidx.appcompat.app.AppCompatActivity
import com.google.android.gms.maps.CameraUpdateFactory
import com.google.android.gms.maps.GoogleMap
import com.google.android.gms.maps.OnMapReadyCallback
import com.google.android.gms.maps.SupportMapFragment
import com.google.android.gms.maps.model.LatLng
import com.google.android.gms.maps.model.MarkerOptions
import com.quickgo.customer.R
import okhttp3.Call
import okhttp3.Callback
import okhttp3.Response
import org.json.JSONObject
import java.io.IOException

class RideSearchingActivity : AppCompatActivity(), OnMapReadyCallback {

    private val uiHandler = Handler(Looper.getMainLooper())
    private var pollRunnable: Runnable? = null

    private var rideId: String = ""
    private var pickupLat = 0.0
    private var pickupLng = 0.0
    private var googleMap: GoogleMap? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_ride_searching)

        rideId = intent.getStringExtra(EXTRA_RIDE_ID).orEmpty()
        pickupLat = intent.getDoubleExtra(EXTRA_PICKUP_LAT, 0.0)
        pickupLng = intent.getDoubleExtra(EXTRA_PICKUP_LNG, 0.0)

        (supportFragmentManager.findFragmentById(R.id.searchMap) as SupportMapFragment)
            .getMapAsync(this)

        startPulseAnimation()
        startPollingRideStatus()
    }

    override fun onMapReady(map: GoogleMap) {
        googleMap = map
        val pickup = LatLng(pickupLat, pickupLng)
        map.addMarker(MarkerOptions().position(pickup).title("Pickup"))
        map.moveCamera(CameraUpdateFactory.newLatLngZoom(pickup, 16f))
        map.uiSettings.isMapToolbarEnabled = false
    }

    private fun startPulseAnimation() {
        val outer = findViewById<android.view.View>(R.id.pulseOuter)
        val inner = findViewById<android.view.View>(R.id.pulseInner)

        listOf(outer to 1400L, inner to 900L).forEach { (view, duration) ->
            val animator = ObjectAnimator.ofFloat(view, "scaleX", 1f, 1.25f, 1f)
            animator.duration = duration
            animator.repeatCount = ObjectAnimator.INFINITE
            animator.interpolator = AccelerateDecelerateInterpolator()
            animator.start()

            val alpha = ObjectAnimator.ofFloat(view, "alpha", 0.55f, 0.2f, 0.55f)
            alpha.duration = duration
            alpha.repeatCount = ObjectAnimator.INFINITE
            alpha.start()
        }
    }

    private fun startPollingRideStatus() {
        pollRunnable = object : Runnable {
            override fun run() {
                ApiClient.getRideStatus(rideId, object : Callback {
                    override fun onFailure(call: Call, e: IOException) {
                        uiHandler.postDelayed(this@Runnable, POLL_INTERVAL_MS)
                    }

                    override fun onResponse(call: Call, response: Response) {
                        response.use {
                            val body = it.body?.string().orEmpty()
                            val status = parseStatus(body)
                            if (status == "accepted") {
                                val i = Intent(this@RideSearchingActivity, LiveRideActivity::class.java)
                                i.putExtra(EXTRA_RIDE_ID, rideId)
                                startActivity(i)
                                finish()
                            } else if (status == "cancelled" || status == "no_driver_found") {
                                finish()
                            } else {
                                uiHandler.postDelayed(this@Runnable, POLL_INTERVAL_MS)
                            }
                        }
                    }
                })
            }
        }
        uiHandler.post(pollRunnable!!)
    }

    private fun parseStatus(body: String): String {
        return try {
            JSONObject(body).optString("status", "searching")
        } catch (_: Exception) {
            "searching"
        }
    }

    override fun onDestroy() {
        pollRunnable?.let { uiHandler.removeCallbacks(it) }
        super.onDestroy()
    }

    companion object {
        const val EXTRA_RIDE_ID = "ride_id"
        const val EXTRA_PICKUP_LAT = "pickup_lat"
        const val EXTRA_PICKUP_LNG = "pickup_lng"
        private const val POLL_INTERVAL_MS = 4000L
    }
}
