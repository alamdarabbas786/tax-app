package com.quickgo.customer.ride

import okhttp3.Callback
import okhttp3.OkHttpClient
import okhttp3.Request

object DirectionsClient {
    private val client = OkHttpClient.Builder().build()

    // Android-restricted Directions key.
    private const val DIRECTIONS_KEY = "AIzaSyB_WRLSV9g3v5XBF6eUZBy_Ig9X0w_4-_g"

    fun getDirections(originLat: Double, originLng: Double, destLat: Double, destLng: Double, callback: Callback) {
        val url = "https://maps.googleapis.com/maps/api/directions/json" +
            "?origin=$originLat,$originLng" +
            "&destination=$destLat,$destLng" +
            "&mode=driving" +
            "&key=$DIRECTIONS_KEY"

        val request = Request.Builder().url(url).get().build()
        client.newCall(request).enqueue(callback)
    }
}
