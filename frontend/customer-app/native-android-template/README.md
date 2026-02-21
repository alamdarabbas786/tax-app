# Customer Native Android Template (Rapido-Style)

This folder contains a drop-in native Android template for:
- `RideSearchingActivity` (animated searching + server polling)
- `LiveRideActivity` (map + driver card + OTP + cancel flow)
- `CancelRideBottomSheet` (reason picker + cancel callback)
- `ApiClient` and `DirectionsClient` (backend + Directions API calls)
- PHP sample endpoints under `backend-php-samples/`

Package used: `com.quickgo.customer` (from `app.json`).

## 1) Dependencies to add in app `build.gradle`

```gradle
implementation "com.google.android.gms:play-services-maps:19.0.0"
implementation "com.google.android.gms:play-services-location:21.3.0"
implementation "androidx.constraintlayout:constraintlayout:2.1.4"
implementation "com.google.android.material:material:1.12.0"
implementation "com.github.bumptech.glide:glide:4.16.0"
```

## 2) Manifest additions

```xml
<uses-permission android:name="android.permission.ACCESS_FINE_LOCATION"/>
<uses-permission android:name="android.permission.ACCESS_COARSE_LOCATION"/>
<uses-permission android:name="android.permission.ACCESS_BACKGROUND_LOCATION"/>
<uses-permission android:name="android.permission.INTERNET"/>
```

Activities:
- `.ride.RideSearchingActivity`
- `.ride.LiveRideActivity`

## 3) Backend endpoint contract (sample files included)

- `backend-php-samples/ride_status.php`
- `backend-php-samples/live_ride.php`
- `backend-php-samples/cancel_ride.php`
- `backend-php-samples/db.php`

## 4) Notes

- Polling interval is 4 seconds (adjustable).
- Driver marker updates smoothly.
- Camera auto-fits driver and active target (pickup or drop).
- OTP display is bold and centered.
- Cancel flow uses a bottom sheet with reasons.
- Directions API is used to draw route polyline and ETA/distance.
