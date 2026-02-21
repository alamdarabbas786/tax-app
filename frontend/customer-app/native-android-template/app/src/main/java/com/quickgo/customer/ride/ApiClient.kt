package com.quickgo.customer.ride

import okhttp3.Callback
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject

object ApiClient {
    private val client = OkHttpClient.Builder().build()

    // Replace with your backend base URL.
    private const val BASE_URL = "https://your-domain.com/api"

    fun getRideStatus(rideId: String, callback: Callback) {
        val request = Request.Builder()
            .url("$BASE_URL/ride_status.php?ride_id=$rideId")
            .get()
            .build()
        client.newCall(request).enqueue(callback)
    }

    fun getLiveRide(rideId: String, callback: Callback) {
        val request = Request.Builder()
            .url("$BASE_URL/live_ride.php?ride_id=$rideId")
            .get()
            .build()
        client.newCall(request).enqueue(callback)
    }

    fun cancelRide(rideId: String, reason: String, callback: Callback) {
        val payload = JSONObject()
            .put("ride_id", rideId)
            .put("reason", reason)
            .toString()

        val request = Request.Builder()
            .url("$BASE_URL/cancel_ride.php")
            .post(payload.toRequestBody("application/json".toMediaType()))
            .build()

        client.newCall(request).enqueue(callback)
    }

    fun updateCustomerLocation(rideId: String, lat: Double, lng: Double, callback: Callback) {
        val payload = JSONObject()
            .put("ride_id", rideId)
            .put("lat", lat)
            .put("lng", lng)
            .toString()

        val request = Request.Builder()
            .url("$BASE_URL/update_customer_location.php")
            .post(payload.toRequestBody("application/json".toMediaType()))
            .build()

        client.newCall(request).enqueue(callback)
    }
}
