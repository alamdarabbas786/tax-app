import React, { useEffect, useMemo, useRef, useState } from 'react';
import { View, Text, TouchableOpacity, Alert, Platform } from 'react-native';
import MapView, { Marker, Polyline, PROVIDER_GOOGLE } from '../MapViewCompat';
import * as Location from 'expo-location';
import Constants from 'expo-constants';
import { styles } from '../styles';
import { apiGet, apiPost } from '../apiClient';

const toRad = (v) => (v * Math.PI) / 180;
const distanceKm = (a, b) => {
  const R = 6371;
  const dLat = toRad(b.latitude - a.latitude);
  const dLng = toRad(b.longitude - a.longitude);
  const lat1 = toRad(a.latitude);
  const lat2 = toRad(b.latitude);
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(h));
};

const decodePolyline = (t) => {
  if (!t || typeof t !== 'string') return [];
  let index = 0;
  let lat = 0;
  let lng = 0;
  const coordinates = [];
  while (index < t.length) {
    let b;
    let shift = 0;
    let result = 0;
    do {
      b = t.charCodeAt(index++) - 63;
      result |= (b & 0x1f) << shift;
      shift += 5;
    } while (b >= 0x20);
    const dlat = (result & 1) ? ~(result >> 1) : (result >> 1);
    lat += dlat;

    shift = 0;
    result = 0;
    do {
      b = t.charCodeAt(index++) - 63;
      result |= (b & 0x1f) << shift;
      shift += 5;
    } while (b >= 0x20);
    const dlng = (result & 1) ? ~(result >> 1) : (result >> 1);
    lng += dlng;

    coordinates.push({ latitude: lat / 1e5, longitude: lng / 1e5 });
  }
  return coordinates;
};

export default function NavigationScreen({ route, navigation, apiBase }) {
  const { ride, token, status: initialStatus, driver } = route.params || {};
  const [status, setStatus] = useState(initialStatus || ride?.status || 'driver_assigned');
  const [driverLoc, setDriverLoc] = useState({
    latitude: Number(ride?.pickup_lat || 28.6139),
    longitude: Number(ride?.pickup_lng || 77.2090)
  });
  const [routeLine, setRouteLine] = useState([]);
  const [distanceText, setDistanceText] = useState('--');
  const [etaText, setEtaText] = useState('--');
  const [nextInstruction, setNextInstruction] = useState('--');
  const [mapError, setMapError] = useState('');
  const mapRef = useRef(null);
  const watchRef = useRef(null);

  const pickup = { latitude: Number(ride?.pickup_lat || 28.6139), longitude: Number(ride?.pickup_lng || 77.2090) };
  const dropoff = { latitude: Number(ride?.drop_lat || 28.4595), longitude: Number(ride?.drop_lng || 77.0266) };
  const target = status === 'ride_started' ? dropoff : pickup;
  const mapApiKey =
    Constants?.expoConfig?.android?.config?.googleMaps?.apiKey ||
    Constants?.manifest2?.extra?.expoClient?.android?.config?.googleMaps?.apiKey ||
    '';
  const mapProvider = Platform.OS === 'android' && mapApiKey ? PROVIDER_GOOGLE : undefined;
  const fallbackLine = useMemo(() => [driverLoc, target], [driverLoc.latitude, driverLoc.longitude, target.latitude, target.longitude]);
  const canCancelBeforeStart = ['driver_assigned', 'accepted', 'assigned', 'driver_arriving', 'driver_arrived', 'arrived', 'waiting'].includes(String(status || '').toLowerCase());

  useEffect(() => {
    if (!ride?.id || !token) return undefined;
    let active = true;
    let handled = false;
    const pollRide = async () => {
      try {
        const res = await apiGet(apiBase, `/api/rides/${ride.id}?t=${Date.now()}`, token);
        if (!active || handled || res?.status !== 'ok' || !res?.ride) return;
        const rideStatus = String(res?.ride?.status || '').toLowerCase().trim();
        if (rideStatus !== 'cancelled') return;
        const by = String(res?.ride?.cancelled_by || '').toLowerCase().trim();
        const reason = String(res?.ride?.cancel_reason || '').toLowerCase();
        const isAdminCancelled = by === 'admin' || reason.includes('admin');
        const isCustomerCancelled = by === 'customer' || reason.includes('customer');
        if (!isAdminCancelled && !isCustomerCancelled) return;
        handled = true;
        Alert.alert('Ride Cancelled', isAdminCancelled ? 'Admin cancelled the ride request.' : 'Customer cancelled this ride.', [
          { text: 'OK', onPress: () => navigation.replace('DriverHome', { token, driver }) }
        ]);
      } catch (_) {
        // ignore transient errors; polling retries
      }
    };
    pollRide();
    const timer = setInterval(pollRide, 4000);
    return () => {
      active = false;
      clearInterval(timer);
    };
  }, [apiBase, token, ride?.id, navigation, driver]);

  useEffect(() => {
    let mounted = true;
    const init = async () => {
      const perm = await Location.requestForegroundPermissionsAsync();
      if (perm.status !== 'granted') {
        Alert.alert('Permission required', 'Location permission is required for in-app navigation.');
        return;
      }
      const sub = await Location.watchPositionAsync(
        {
          accuracy: Location.Accuracy.Balanced,
          timeInterval: 4000,
          distanceInterval: 8
        },
        (loc) => {
          if (!mounted) return;
          const next = { latitude: loc.coords.latitude, longitude: loc.coords.longitude };
          setDriverLoc(next);
          mapRef.current?.animateCamera({ center: next, zoom: 16 }, { duration: 600 });
        }
      );
      watchRef.current = sub;
    };
    init();
    return () => {
      mounted = false;
      if (watchRef.current) watchRef.current.remove();
    };
  }, []);

  useEffect(() => {
    let alive = true;
    const loadRoute = async () => {
      try {
        const res = await apiPost(apiBase, '/api/maps/route', token, {
          pickup: { lat: driverLoc.latitude, lng: driverLoc.longitude },
          dropoff: { lat: target.latitude, lng: target.longitude },
          vehicle_type: String(ride?.vehicle_type || '').toLowerCase()
        });
        if (!alive) return;
        setRouteLine(res?.polyline ? decodePolyline(res.polyline) : []);
        setDistanceText(String(res?.distance_text || `${distanceKm(driverLoc, target).toFixed(2)} km`));
        setEtaText(String(res?.duration_text || '--'));
        const step = Array.isArray(res?.steps) && res.steps.length > 0 ? String(res.steps[0]?.instruction || '--') : '--';
        setNextInstruction(step.replace(/<[^>]*>/g, ''));
        setMapError('');
      } catch (_e) {
        if (!alive) return;
        setMapError('Route fetch failed');
      }
    };
    loadRoute();
    const timer = setInterval(loadRoute, 5000);
    return () => {
      alive = false;
      clearInterval(timer);
    };
  }, [apiBase, token, driverLoc.latitude, driverLoc.longitude, target.latitude, target.longitude, ride?.vehicle_type]);

  const cancelRide = async () => {
    if (!ride?.id) return;
    Alert.alert('Cancel Ride', 'Are you sure you want to cancel this request?', [
      { text: 'No', style: 'cancel' },
      {
        text: 'Yes, Cancel',
        style: 'destructive',
        onPress: async () => {
          try {
            const res = await apiPost(apiBase, `/api/driver/rides/${ride.id}/cancel`, token, {
              reason: 'Driver cancelled before ride start'
            });
            if (res?.status !== 'ok') {
              throw new Error(res?.message || 'Failed');
            }
            Alert.alert('Ride Cancelled', res?.message || 'Ride cancelled');
            navigation.replace('DriverHome', { token, driver });
          } catch (e) {
            Alert.alert('Error', String(e?.message || e));
          }
        }
      }
    ]);
  };

  return (
    <View style={styles.screen}>
      <MapView
        ref={mapRef}
        style={styles.map}
        provider={mapProvider}
        initialRegion={{
          latitude: driverLoc.latitude,
          longitude: driverLoc.longitude,
          latitudeDelta: 0.05,
          longitudeDelta: 0.05
        }}
        scrollEnabled
        zoomEnabled
        rotateEnabled
        pitchEnabled
        showsTraffic
        showsCompass
        onError={(e) => setMapError(String(e?.nativeEvent?.error || 'Map failed to load'))}
      >
        <Marker coordinate={driverLoc} title="Driver" />
        <Marker coordinate={pickup} title="Pickup" />
        <Marker coordinate={dropoff} title="Drop" />
        <Polyline coordinates={routeLine.length ? routeLine : fallbackLine} strokeWidth={5} strokeColor="#16A34A" />
      </MapView>

      <View style={[styles.navBanner, styles.liveRideTopCard]}>
        <Text style={styles.liveRideTopTitle}>Navigation</Text>
        <Text style={styles.navBannerLine}>ETA {etaText} | Distance {distanceText}</Text>
        <Text style={styles.navBannerLine}>Next: {nextInstruction}</Text>
        {!!mapError && <Text style={styles.navBannerLine}>{mapError}</Text>}
      </View>

      <View style={[styles.sheet, styles.liveRideBottomCard]}>
        <Text style={styles.sheetTitle}>In-App Navigation</Text>
        <Text style={styles.statusLine}>Pickup: {ride?.pickup_address}</Text>
        <Text style={styles.statusLine}>Drop: {ride?.drop_address}</Text>

        <View style={styles.rowInline}>
          <TouchableOpacity style={[styles.topQuickBtn, styles.topQuickBtnRecenter]} onPress={() => setStatus('driver_assigned')}>
            <Text style={styles.topQuickBtnText}>To Pickup</Text>
          </TouchableOpacity>
          <TouchableOpacity style={[styles.topQuickBtn, styles.topQuickBtnNav]} onPress={() => setStatus('ride_started')}>
            <Text style={styles.topQuickBtnText}>To Drop</Text>
          </TouchableOpacity>
        </View>

        {canCancelBeforeStart ? (
          <TouchableOpacity style={styles.cancelRideBtn} onPress={cancelRide}>
            <Text style={styles.cancelRideBtnText}>Cancel Request</Text>
          </TouchableOpacity>
        ) : null}

        <TouchableOpacity style={styles.ghostBtn} onPress={() => navigation.goBack()}>
          <Text style={styles.ghostBtnText}>Back to Live Ride</Text>
        </TouchableOpacity>
      </View>
    </View>
  );
}

