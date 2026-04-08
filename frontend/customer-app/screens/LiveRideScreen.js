import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
  Alert,
  Image,
  Linking,
  Modal,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from 'react-native';
import { MaterialCommunityIcons } from '@expo/vector-icons';
import MapView, { Marker, Polyline, PROVIDER_GOOGLE } from '../MapViewCompat';
import * as Location from 'expo-location';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { apiGet, apiPost } from '../apiClient';
import { API_BASE } from '../config';

const ACTIVE_RIDE_KEY = 'customer_active_ride_v1';

const CANCEL_REASONS = [
  'Driver is taking too long',
  'Change of plans',
  'Booked by mistake',
  'Found another ride',
  'Other',
];

const toRad = (v) => (v * Math.PI) / 180;
const distanceMeters = (a, b) => {
  const R = 6371000;
  const dLat = toRad(b.latitude - a.latitude);
  const dLng = toRad(b.longitude - a.longitude);
  const lat1 = toRad(a.latitude);
  const lat2 = toRad(b.latitude);
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(h));
};
const normalizeVehicleType = (raw) => {
  const text = String(raw || '').toLowerCase();
  if (text.includes('bike') || text.includes('motor') || text.includes('scooter')) return 'bike';
  if (text.includes('car') || text.includes('sedan') || text.includes('hatch') || text.includes('suv')) return 'car';
  return 'unknown';
};

const isRideNotFoundError = (error) => String(error?.message || '').toLowerCase().includes('ride not found');

export default function LiveRideScreen({ navigation, route }) {
  const { rideId, token, apiBase, session } = route?.params || {};
  const mapRef = useRef(null);
  const completedHandledRef = useRef(false);

  const [ride, setRide] = useState(null);
  const [cancelOpen, setCancelOpen] = useState(false);
  const [selectedReason, setSelectedReason] = useState(CANCEL_REASONS[0]);
  const [customerLoc, setCustomerLoc] = useState(null);

  const pickup = useMemo(
    () => ({
      latitude: Number(ride?.pickup_lat || 28.6139),
      longitude: Number(ride?.pickup_lng || 77.2090),
    }),
    [ride?.pickup_lat, ride?.pickup_lng]
  );

  const driverPos = useMemo(
    () => ({
      latitude: Number(ride?.driver_lat || pickup.latitude),
      longitude: Number(ride?.driver_lng || pickup.longitude),
    }),
    [ride?.driver_lat, ride?.driver_lng, pickup.latitude, pickup.longitude]
  );
  const liveDistanceMeters = useMemo(() => {
    if (!customerLoc) return null;
    return Math.max(0, Math.round(distanceMeters(customerLoc, driverPos)));
  }, [customerLoc?.latitude, customerLoc?.longitude, driverPos.latitude, driverPos.longitude]);

  useEffect(() => {
    let mounted = true;
    let sub;
    const initCustomerGps = async () => {
      try {
        const perm = await Location.requestForegroundPermissionsAsync();
        if (perm.status !== 'granted') return;
        sub = await Location.watchPositionAsync(
          {
            accuracy: Location.Accuracy.Balanced,
            timeInterval: 4000,
            distanceInterval: 5,
          },
          (loc) => {
            if (!mounted) return;
            setCustomerLoc({
              latitude: Number(loc?.coords?.latitude || 0),
              longitude: Number(loc?.coords?.longitude || 0),
            });
          }
        );
      } catch (_) {
        // ignore gps errors
      }
    };
    initCustomerGps();
    return () => {
      mounted = false;
      if (sub) sub.remove();
    };
  }, []);

  useEffect(() => {
    let mounted = true;
    let timer;

    const fetchRide = async () => {
      if (!rideId || !token) return;
      try {
        const res = await apiGet(apiBase || API_BASE, `/api/rides/${rideId}`, token);
        if (!mounted || res?.status !== 'ok' || !res?.ride) return;

        const r = res.ride;
        const rideStatus = String(r?.status || '').toLowerCase();
        setRide(r);

        if (['completed', 'ride_completed', 'ride_closed'].includes(rideStatus) && !completedHandledRef.current) {
          completedHandledRef.current = true;
          try {
            await AsyncStorage.removeItem(ACTIVE_RIDE_KEY);
          } catch (_) {}
          navigation.replace('RideCompleted', {
            ride: r,
            session,
            token,
            apiBase: apiBase || API_BASE,
          });
          return;
        }

        if (rideStatus === 'cancelled') {
          const cancelledBy = String(r?.cancelled_by || '').toLowerCase().trim();
          const cancelReason = String(r?.cancel_reason || '').toLowerCase().trim();
          const isDriverCancelled = cancelledBy === 'driver' || cancelReason.includes('driver');
          const isAdminCancelled = cancelledBy === 'admin' || cancelReason.includes('admin');
          const cancelMsg = isAdminCancelled
            ? 'Admin cancelled the ride request.'
            : (isDriverCancelled ? 'Cancelled request by driver.' : `Ride ${r.status}`);
          try {
            await AsyncStorage.removeItem(ACTIVE_RIDE_KEY);
          } catch (_) {}
          Alert.alert('Ride Update', cancelMsg, [
            {
              text: 'OK',
              onPress: () =>
                navigation.reset({
                  index: 0,
                  routes: [{ name: 'Home', params: { session } }],
                }),
            },
          ]);
        }
      } catch (_) {
        if (!mounted) return;
        if (isRideNotFoundError(_)) {
          try {
            await AsyncStorage.removeItem(ACTIVE_RIDE_KEY);
          } catch (_) {}
          Alert.alert('Ride Update', 'Ride not found. Returning to home.', [
            {
              text: 'OK',
              onPress: () =>
                navigation.reset({
                  index: 0,
                  routes: [{ name: 'Home', params: { session } }],
                }),
            },
          ]);
          return;
        }
        // polling retry
      }
    };

    fetchRide();
    timer = setInterval(fetchRide, 4000);
    return () => {
      mounted = false;
      timer && clearInterval(timer);
    };
  }, [rideId, token, apiBase, navigation, session]);

  useEffect(() => {
    // Persist LiveRide screen for resume after app restart.
    (async () => {
      try {
        await AsyncStorage.setItem(
          ACTIVE_RIDE_KEY,
          JSON.stringify({
            rideId,
            screen: 'LiveRide',
            apiBase: apiBase || API_BASE,
            ts: Date.now(),
          })
        );
      } catch (_) {}
    })();
  }, [rideId, apiBase]);

  useEffect(() => {
    if (!mapRef.current) return;
    const coords = customerLoc ? [customerLoc, driverPos] : [pickup, driverPos];
    mapRef.current.fitToCoordinates(coords, {
      edgePadding: { top: 100, right: 70, bottom: 300, left: 70 },
      animated: true,
    });
  }, [pickup, driverPos, customerLoc?.latitude, customerLoc?.longitude]);

  const handleCall = async () => {
    const phone = ride?.driver_phone;
    if (!phone) {
      Alert.alert('Info', 'Driver phone not available');
      return;
    }
    const url = `tel:${phone}`;
    const supported = await Linking.canOpenURL(url);
    if (supported) Linking.openURL(url);
  };

  const cancelRide = async () => {
    try {
      await apiPost(apiBase || API_BASE, `/api/rides/${rideId}/cancel`, token, {
        reason: selectedReason,
      });
      try {
        await AsyncStorage.removeItem(ACTIVE_RIDE_KEY);
      } catch (_) {}
      setCancelOpen(false);
      navigation.reset({
        index: 0,
        routes: [{ name: 'Home', params: { session } }],
      });
    } catch (e) {
      Alert.alert('Error', String(e?.message || e));
    }
  };

  const canOpenNavigation = ['ride_started', 'in_progress'].includes(String(ride?.status || '').toLowerCase());
  const canCancelRide = !['driver_arrived', 'arrived', 'waiting', 'ride_started', 'in_progress', 'enroute', 'ride_completed', 'completed', 'ride_closed', 'cancelled'].includes(
    String(ride?.status || '').toLowerCase()
  );
  const captainPhoto =
    ride?.driver_photo_url ||
    ride?.driver_photo ||
    ride?.photo_url ||
    ride?.driver_image ||
    '';
  const captainAvatarLabel = String(ride?.driver_name || 'Driver').trim().split(/\s+/)[0] || 'Driver';
  const vehicleType = normalizeVehicleType(
    ride?.driver_vehicle_type || ride?.vehicle_type || ride?.vehicle || ''
  );
  const vehicleLabel = vehicleType === 'bike' ? 'Bike' : vehicleType === 'car' ? 'Car' : 'Vehicle';
  const vehicleIconName = vehicleType === 'bike' ? 'motorbike' : 'car';
  const driverVehicleNumber =
    String(
      ride?.driver_vehicle_number ||
        ride?.vehicle_number ||
        ride?.driver_vehicle ||
        ''
    ).trim() || '--';

  const openNavigation = async () => {
    const lat = Number(ride?.drop_lat);
    const lng = Number(ride?.drop_lng);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
      Alert.alert('Navigation', 'Drop location not available yet.');
      return;
    }
    const url = `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}&travelmode=driving`;
    const supported = await Linking.canOpenURL(url);
    if (!supported) {
      Alert.alert('Navigation', 'Maps app is not available on this device.');
      return;
    }
    Linking.openURL(url);
  };

  return (
    <View style={styles.screen}>
      <MapView
        ref={mapRef}
        provider={PROVIDER_GOOGLE}
        style={StyleSheet.absoluteFill}
        showsUserLocation
        showsMyLocationButton
        initialRegion={{
          latitude: pickup.latitude,
          longitude: pickup.longitude,
          latitudeDelta: 0.03,
          longitudeDelta: 0.03,
        }}
      >
        <Marker coordinate={pickup} title="Pickup" pinColor="#16A34A" />
        {customerLoc ? <Marker coordinate={customerLoc} title="You" pinColor="#F59E0B" /> : null}
        <Marker coordinate={driverPos} title={`Captain (${vehicleLabel})`} anchor={{ x: 0.5, y: 0.5 }}>
          <View style={styles.vehicleMarker}>
            <MaterialCommunityIcons name={vehicleIconName} size={20} color="#FFFFFF" />
          </View>
        </Marker>
        <Polyline coordinates={customerLoc ? [driverPos, customerLoc] : [driverPos, pickup]} strokeColor="#2563EB" strokeWidth={5} />
      </MapView>

      <View style={styles.topPill}>
        <Text style={styles.topPillText}>ETA {ride?.eta_text || '--'} | {ride?.distance_text || '--'} | Captain {liveDistanceMeters != null ? `${liveDistanceMeters} m` : '--'}</Text>
      </View>

      <View style={styles.card}>
        <Text style={styles.title}>Captain Assigned</Text>
        <Text style={styles.status}>Status: {ride?.status || 'accepted'}</Text>

        <View style={styles.driverRow}>
          {captainPhoto ? (
            <Image source={{ uri: captainPhoto }} style={styles.avatarImage} />
          ) : (
            <View style={styles.avatar}>
              <Text style={styles.avatarText} numberOfLines={1}>
                {captainAvatarLabel}
              </Text>
            </View>
          )}
          <View style={{ flex: 1 }}>
            <Text style={styles.driverName}>{ride?.driver_name || 'Driver'}</Text>
            <Text style={styles.driverMeta}>{(ride?.driver_rating || '4.8')} * | {vehicleLabel}</Text>
            <View style={styles.vehicleBadge}>
              <Text style={styles.vehicleBadgeText}>{vehicleLabel}</Text>
            </View>
            <Text style={styles.vehicleNoText}>Vehicle No: {driverVehicleNumber}</Text>
          </View>
          <TouchableOpacity style={styles.callBtn} onPress={handleCall}>
            <Text style={styles.callText}>Call</Text>
          </TouchableOpacity>
        </View>

        <Text style={styles.line}>Pickup: {ride?.pickup_address || '--'}</Text>
        <Text style={styles.line}>Drop: {ride?.drop_address || '--'}</Text>
        <Text style={styles.lineBold}>Fare: Rs {ride?.fare || '--'}</Text>

        <Text style={styles.otpLabel}>Share this OTP with your driver to start the ride</Text>
        <Text style={styles.otp}>{ride?.otp_code || '1234'}</Text>

        {canOpenNavigation ? (
          <TouchableOpacity style={styles.navBtn} onPress={openNavigation}>
            <Text style={styles.navBtnText}>Open Navigation</Text>
          </TouchableOpacity>
        ) : null}

        {canCancelRide ? (
          <TouchableOpacity style={styles.cancelBtn} onPress={() => setCancelOpen(true)}>
            <Text style={styles.cancelBtnText}>Cancel Ride</Text>
          </TouchableOpacity>
        ) : null}
      </View>

      <Modal visible={cancelOpen} animationType="slide" transparent onRequestClose={() => setCancelOpen(false)}>
        <View style={styles.modalBackdrop}>
          <View style={styles.modalCard}>
            <Text style={styles.modalTitle}>Why do you want to cancel?</Text>
            {CANCEL_REASONS.map((reason) => (
              <TouchableOpacity
                key={reason}
                style={styles.reasonRow}
                onPress={() => setSelectedReason(reason)}
                activeOpacity={0.9}
              >
                <View style={[styles.radio, selectedReason === reason && styles.radioActive]} />
                <Text style={styles.reasonText}>{reason}</Text>
              </TouchableOpacity>
            ))}
            <TouchableOpacity style={styles.confirmCancelBtn} onPress={cancelRide}>
              <Text style={styles.confirmCancelText}>Confirm Cancel</Text>
            </TouchableOpacity>
            <TouchableOpacity style={styles.closeBtn} onPress={() => setCancelOpen(false)}>
              <Text style={styles.closeText}>Close</Text>
            </TouchableOpacity>
          </View>
        </View>
      </Modal>
    </View>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: '#0F172A' },
  topPill: {
    position: 'absolute',
    top: 52,
    alignSelf: 'center',
    backgroundColor: '#111827',
    paddingHorizontal: 14,
    paddingVertical: 8,
    borderRadius: 999,
  },
  topPillText: { color: '#fff', fontWeight: '700', fontSize: 12 },
  card: {
    position: 'absolute',
    left: 16,
    right: 16,
    bottom: 16,
    backgroundColor: '#fff',
    borderRadius: 20,
    padding: 16,
  },
  title: { fontSize: 18, fontWeight: '800', color: '#111827' },
  status: { marginTop: 4, fontSize: 12, color: '#6B7280' },
  driverRow: { marginTop: 12, flexDirection: 'row', alignItems: 'center' },
  avatar: {
    width: 44,
    height: 44,
    borderRadius: 22,
    backgroundColor: '#FDE68A',
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: 10,
  },
  avatarImage: {
    width: 44,
    height: 44,
    borderRadius: 22,
    marginRight: 10,
    backgroundColor: '#E5E7EB',
  },
  avatarText: { fontWeight: '800', color: '#111827' },
  vehicleMarker: {
    width: 38,
    height: 38,
    borderRadius: 19,
    backgroundColor: '#2563EB',
    borderWidth: 2,
    borderColor: '#FFFFFF',
    alignItems: 'center',
    justifyContent: 'center',
  },
  driverName: { fontSize: 14, fontWeight: '800', color: '#111827' },
  driverMeta: { fontSize: 12, color: '#6B7280', marginTop: 2 },
  vehicleBadge: {
    alignSelf: 'flex-start',
    marginTop: 6,
    backgroundColor: '#EEF2FF',
    borderColor: '#C7D2FE',
    borderWidth: 1,
    borderRadius: 999,
    paddingHorizontal: 8,
    paddingVertical: 3,
  },
  vehicleBadgeText: { color: '#1E3A8A', fontSize: 11, fontWeight: '800' },
  vehicleNoText: { marginTop: 4, color: '#374151', fontSize: 12, fontWeight: '700' },
  callBtn: { backgroundColor: '#16A34A', paddingHorizontal: 14, paddingVertical: 8, borderRadius: 10 },
  callText: { color: '#fff', fontWeight: '700' },
  line: { marginTop: 9, fontSize: 13, color: '#374151' },
  lineBold: { marginTop: 9, fontSize: 14, color: '#111827', fontWeight: '800' },
  otpLabel: { marginTop: 12, textAlign: 'center', color: '#6B7280', fontSize: 12 },
  otp: {
    marginTop: 4,
    textAlign: 'center',
    fontSize: 34,
    fontWeight: '900',
    color: '#111827',
    letterSpacing: 6,
  },
  navBtn: {
    marginTop: 12,
    backgroundColor: '#2563EB',
    paddingVertical: 12,
    borderRadius: 12,
    alignItems: 'center',
  },
  navBtnText: { color: '#FFFFFF', fontWeight: '800' },
  cancelBtn: {
    marginTop: 14,
    backgroundColor: '#DC2626',
    paddingVertical: 12,
    borderRadius: 12,
    alignItems: 'center',
  },
  cancelBtnText: { color: '#fff', fontWeight: '800' },
  modalBackdrop: {
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.35)',
    justifyContent: 'flex-end',
  },
  modalCard: {
    backgroundColor: '#fff',
    borderTopLeftRadius: 20,
    borderTopRightRadius: 20,
    padding: 18,
  },
  modalTitle: { fontSize: 18, fontWeight: '800', color: '#111827', marginBottom: 8 },
  reasonRow: { flexDirection: 'row', alignItems: 'center', paddingVertical: 8 },
  radio: {
    width: 16,
    height: 16,
    borderRadius: 8,
    borderWidth: 1.5,
    borderColor: '#111827',
    marginRight: 10,
  },
  radioActive: { backgroundColor: '#111827' },
  reasonText: { color: '#111827', fontSize: 14 },
  confirmCancelBtn: {
    marginTop: 12,
    backgroundColor: '#DC2626',
    borderRadius: 12,
    paddingVertical: 12,
    alignItems: 'center',
  },
  confirmCancelText: { color: '#fff', fontWeight: '800' },
  closeBtn: { marginTop: 8, alignItems: 'center', paddingVertical: 8 },
  closeText: { color: '#6B7280', fontWeight: '700' },
});










