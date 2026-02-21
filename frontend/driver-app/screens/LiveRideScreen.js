import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
  View,
  Text,
  TouchableOpacity,
  ActivityIndicator,
  Alert,
  TextInput,
  Animated,
  PanResponder,
  Vibration,
  Platform,
  Linking
} from 'react-native';
import { MaterialCommunityIcons } from '@expo/vector-icons';
import { SafeAreaView } from 'react-native-safe-area-context';
import MapView, { Marker, Polyline, PROVIDER_GOOGLE } from 'react-native-maps';
import * as Location from 'expo-location';
import * as Speech from 'expo-speech';
import * as Notifications from 'expo-notifications';
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

const clamp = (v, min, max) => Math.max(min, Math.min(max, v));

const SWIPE_TRACK_WIDTH = 280;
const SWIPE_KNOB_SIZE = 52;
const SWIPE_MAX = SWIPE_TRACK_WIDTH - SWIPE_KNOB_SIZE - 8;
const SWIPE_TRIGGER = SWIPE_MAX * 0.7;
const ARRIVAL_THRESHOLD_METERS = 200;
const REROUTE_DEVIATION_METERS = 55;
const COMPLETE_RIDE_RADIUS_METERS = 50;

const turnArrowFromInstruction = (instruction) => {
  const text = String(instruction || '').toLowerCase();
  if (text.includes('left')) return '⬅';
  if (text.includes('right')) return '➡';
  if (text.includes('u-turn') || text.includes('uturn')) return '↩';
  if (text.includes('roundabout')) return '⟳';
  return '⬆';
};

const distancePointToSegmentMeters = (p, a, b) => {
  const x = p.longitude;
  const y = p.latitude;
  const x1 = a.longitude;
  const y1 = a.latitude;
  const x2 = b.longitude;
  const y2 = b.latitude;
  const A = x - x1;
  const B = y - y1;
  const C = x2 - x1;
  const D = y2 - y1;
  const dot = A * C + B * D;
  const lenSq = C * C + D * D;
  let param = -1;
  if (lenSq !== 0) param = dot / lenSq;
  let xx;
  let yy;
  if (param < 0) {
    xx = x1;
    yy = y1;
  } else if (param > 1) {
    xx = x2;
    yy = y2;
  } else {
    xx = x1 + param * C;
    yy = y1 + param * D;
  }
  return distanceKm(p, { latitude: yy, longitude: xx }) * 1000;
};

const minDistanceToPolylineMeters = (point, polyline) => {
  if (!Array.isArray(polyline) || polyline.length < 2) return Number.MAX_SAFE_INTEGER;
  let min = Number.MAX_SAFE_INTEGER;
  for (let i = 0; i < polyline.length - 1; i += 1) {
    const d = distancePointToSegmentMeters(point, polyline[i], polyline[i + 1]);
    if (d < min) min = d;
  }
  return min;
};

const isTwoWheelerVehicle = (vehicleType) => {
  const v = String(vehicleType || '').toLowerCase();
  return v.includes('bike') || v.includes('moto') || v.includes('scooter') || v.includes('two');
};

const normalizeRideStatus = (value) => {
  const s = String(value || '').toLowerCase().trim();
  if (!s) return 'driver_assigned';
  if (['cancelled', 'canceled', 'customer_cancelled', 'ride_cancelled', 'cancel'].includes(s)) {
    return 'cancelled';
  }
  if (['accepted', 'assigned', 'driver_assigned', 'driver_arriving', 'requested', 'searching'].includes(s)) {
    return 'driver_assigned';
  }
  if (['arrived', 'driver_arrived', 'waiting'].includes(s)) {
    return 'driver_arrived';
  }
  if (['ride_started', 'in_progress', 'enroute'].includes(s)) {
    return 'ride_started';
  }
  if (['ride_completed', 'completed', 'ride_closed'].includes(s)) {
    return 'ride_completed';
  }
  return s;
};

const statusStage = (status) => {
  const s = normalizeRideStatus(status);
  if (s === 'driver_assigned') return 1;
  if (s === 'driver_arrived') return 2;
  if (s === 'ride_started') return 3;
  if (s === 'ride_completed') return 4;
  if (s === 'cancelled') return 99;
  return 0;
};

const shouldApplyStatusTransition = (currentStatus, nextStatus) => {
  const current = normalizeRideStatus(currentStatus);
  const next = normalizeRideStatus(nextStatus);
  if (!next || current === next) return false;
  if (next === 'cancelled' || next === 'ride_completed') return true;
  return statusStage(next) >= statusStage(current);
};

const getCancelSourceFromRide = (rideData) => {
  const by = String(rideData?.cancelled_by || '').toLowerCase().trim();
  const reason = String(rideData?.cancel_reason || '').toLowerCase();
  if (by === 'admin' || reason.includes('admin')) return 'admin';
  if (by === 'customer' || reason.includes('customer')) return 'customer';
  if (by === 'driver' || reason.includes('driver')) return 'driver';
  return '';
};

const getCancelSourceFromEvent = (eventData) => {
  const by = String(eventData?.cancelled_by || '').toLowerCase().trim();
  const reason = String(eventData?.reason || '').toLowerCase();
  if (by === 'admin' || reason.includes('admin')) return 'admin';
  if (by === 'customer' || reason.includes('customer')) return 'customer';
  if (by === 'driver' || reason.includes('driver')) return 'driver';
  return '';
};

const isRideNotFoundError = (error) => String(error?.message || '').toLowerCase().includes('ride not found');

export default function LiveRideScreen({ route, navigation, apiBase }) {
  const { ride, token, driver } = route.params || {};
  const [loading, setLoading] = useState(false);
  const [status, setStatus] = useState(normalizeRideStatus(ride?.status));
  const [otp, setOtp] = useState('');
  const [waitingSeconds, setWaitingSeconds] = useState(0);
  const [driverLoc, setDriverLoc] = useState({
    latitude: Number(ride?.pickup_lat || 28.6139),
    longitude: Number(ride?.pickup_lng || 77.209)
  });
  const [driverAccuracyMeters, setDriverAccuracyMeters] = useState(0);
  const [etaMinutes, setEtaMinutes] = useState(0);
  const [routeLine, setRouteLine] = useState([]);
  const [routeMeta, setRouteMeta] = useState({ distanceText: '', durationText: '', nextInstruction: '' });
  const [inAppNavEnabled, setInAppNavEnabled] = useState(true);
  const [voiceEnabled, setVoiceEnabled] = useState(true);
  const [currentRoad, setCurrentRoad] = useState('');
  const [navProgress, setNavProgress] = useState(0);
  const [navError, setNavError] = useState('');
  const [mapReady, setMapReady] = useState(false);
  const [navRefreshTick, setNavRefreshTick] = useState(0);
  const watchRef = useRef(null);
  const mapRef = useRef(null);
  const cancelHandledRef = useRef(false);
  const [followMode, setFollowMode] = useState(true);
  const slideX = useRef(new Animated.Value(0)).current;
  const slideValRef = useRef(0);
  const activeSwipeRef = useRef(false);
  const baselineDistanceKmRef = useRef(null);
  const lastSpokenInstructionRef = useRef('');
  const lastSpokenAtRef = useRef(0);
  const milestoneSpokenRef = useRef({
    near500: false,
    near150: false,
    arrived: false
  });

  const pickup = { latitude: Number(ride?.pickup_lat || 28.6139), longitude: Number(ride?.pickup_lng || 77.209) };
  const dropoff = { latitude: Number(ride?.drop_lat || 28.4595), longitude: Number(ride?.drop_lng || 77.0266) };
  const vehicleType = String(
    driver?.vehicle_type || ride?.driver_vehicle_type || ride?.vehicle_type || ''
  ).toLowerCase();
  const navVehicleMode = isTwoWheelerVehicle(vehicleType) ? 'Bike' : 'Car';
  const mapApiKey =
    Constants?.expoConfig?.android?.config?.googleMaps?.apiKey ||
    Constants?.manifest2?.extra?.expoClient?.android?.config?.googleMaps?.apiKey ||
    '';
  const mapProvider = Platform.OS === 'android' && mapApiKey ? PROVIDER_GOOGLE : undefined;

  const straightLine = useMemo(() => [pickup, dropoff], [pickup.latitude, pickup.longitude, dropoff.latitude, dropoff.longitude]);

  useEffect(() => {
    const id = slideX.addListener(({ value }) => {
      slideValRef.current = value;
    });
    return () => slideX.removeListener(id);
  }, [slideX]);

  useEffect(() => {
    let timer;
    if (status === 'driver_arrived') {
      timer = setInterval(() => setWaitingSeconds((prev) => prev + 1), 1000);
    } else {
      setWaitingSeconds(0);
    }
    return () => timer && clearInterval(timer);
  }, [status]);

  useEffect(() => {
    if (!ride?.id || !token) return undefined;
    let active = true;
    const handleRideCancelled = (cancelSource = '') => {
      if (cancelHandledRef.current) return;
      cancelHandledRef.current = true;
      setStatus('cancelled');
      const msg = cancelSource === 'admin' ? 'Admin cancelled the ride request.' : 'Customer cancelled this ride.';
      Alert.alert('Ride Cancelled', msg, [
        { text: 'OK', onPress: () => navigation.replace('DriverHome', { driver, token }) }
      ]);
    };
    const pollRide = async () => {
      try {
        const res = await apiGet(apiBase, `/api/rides/${ride.id}?t=${Date.now()}`, token);
        if (!active || res?.status !== 'ok' || !res?.ride) return;
        const latest = normalizeRideStatus(res.ride.status);
        if (shouldApplyStatusTransition(status, latest)) {
          setStatus(latest);
        }
        if (latest === 'cancelled') {
          const source = getCancelSourceFromRide(res.ride);
          if (source === 'customer' || source === 'admin') {
            handleRideCancelled(source);
          }
        }
      } catch (_e) {
        if (!active) return;
        if (isRideNotFoundError(_e)) {
          Alert.alert('Ride Update', 'Ride not found. Returning to home.', [
            { text: 'OK', onPress: () => navigation.replace('DriverHome', { driver, token }) }
          ]);
          return;
        }
        // Ignore transient polling errors; do not auto-cancel on network/auth glitches.
      }
    };
    pollRide();
    const t = setInterval(pollRide, 4000);
    return () => {
      active = false;
      clearInterval(t);
    };
  }, [apiBase, token, ride?.id, status, navigation, driver]);

  useEffect(() => {
    if (!ride?.id) return undefined;
    const rideId = String(ride.id);
    const onRideCancelled = (cancelSource = '') => {
      if (cancelHandledRef.current) return;
      cancelHandledRef.current = true;
      setStatus('cancelled');
      const msg = cancelSource === 'admin' ? 'Admin cancelled the ride request.' : 'Customer cancelled this ride.';
      Alert.alert('Ride Cancelled', msg, [
        { text: 'OK', onPress: () => navigation.replace('DriverHome', { driver, token }) }
      ]);
    };

    const receivedSub = Notifications.addNotificationReceivedListener((notification) => {
      const data = notification?.request?.content?.data || {};
      const eventType = String(data?.type || data?.event_type || '').toLowerCase();
      const eventRideId = String(data?.ride_id || '');
      if (eventType === 'ride_cancelled' && eventRideId === rideId) {
        const source = getCancelSourceFromEvent(data);
        if (source === 'customer' || source === 'admin') {
          onRideCancelled(source);
        }
      }
    });

    const responseSub = Notifications.addNotificationResponseReceivedListener((response) => {
      const data = response?.notification?.request?.content?.data || {};
      const eventType = String(data?.type || data?.event_type || '').toLowerCase();
      const eventRideId = String(data?.ride_id || '');
      if (eventType === 'ride_cancelled' && eventRideId === rideId) {
        const source = getCancelSourceFromEvent(data);
        if (source === 'customer' || source === 'admin') {
          onRideCancelled(source);
        }
      }
    });

    return () => {
      receivedSub.remove();
      responseSub.remove();
    };
  }, [ride?.id, navigation, driver, token]);

  useEffect(() => {
    let mounted = true;
    const initLocation = async () => {
      const { status: perm } = await Location.requestForegroundPermissionsAsync();
      if (perm !== 'granted') return;
      // Request background permission for better continuity during long rides.
      await Location.requestBackgroundPermissionsAsync();

      const sub = await Location.watchPositionAsync(
        {
          accuracy: Location.Accuracy.Balanced,
          timeInterval: 4000,
          distanceInterval: 8
        },
        (loc) => {
          if (!mounted) return;
          const { latitude, longitude, accuracy } = loc.coords;
          const next = { latitude, longitude };
          setDriverLoc(next);
          setDriverAccuracyMeters(Number(accuracy || 0));
          if (inAppNavEnabled && followMode && mapRef.current) {
            mapRef.current.animateToRegion(
              {
                latitude,
                longitude,
                latitudeDelta: 0.012,
                longitudeDelta: 0.012
              },
              500
            );
          }
          const target = status === 'ride_started' ? dropoff : pickup;
          const km = distanceKm(next, target);
          const eta = Math.max(1, Math.round((km / 25) * 60));
          setEtaMinutes(eta);
          if (baselineDistanceKmRef.current == null || km > baselineDistanceKmRef.current) {
            baselineDistanceKmRef.current = km;
          }
          const baseline = baselineDistanceKmRef.current || km || 1;
          const progress = Math.max(0, Math.min(1, 1 - km / baseline));
          setNavProgress(progress);
        }
      );
      watchRef.current = sub;
    };

    initLocation();
    return () => {
      mounted = false;
      if (watchRef.current) watchRef.current.remove();
    };
  }, [status, pickup.latitude, pickup.longitude, dropoff.latitude, dropoff.longitude, followMode, inAppNavEnabled]);

  useEffect(() => {
    let active = true;

    const loadRoute = async () => {
      const target = status === 'ride_started' ? dropoff : pickup;
      try {
        if (!inAppNavEnabled) {
          setRouteLine([]);
          setRouteMeta({ distanceText: '', durationText: '', nextInstruction: '' });
          setNavError('');
          return;
        }
        const res = await apiPost(apiBase, '/api/maps/route', token, {
          pickup: { lat: driverLoc.latitude, lng: driverLoc.longitude },
          dropoff: { lat: target.latitude, lng: target.longitude },
          vehicle_type: vehicleType
        });
        if (!active) return;
        const points = res?.polyline ? decodePolyline(res.polyline) : [];
        setRouteLine(points);
        const firstStep = Array.isArray(res?.steps) && res.steps.length > 0 ? String(res.steps[0]?.instruction || '') : '';
        setRouteMeta({
          distanceText: String(res?.distance_text || ''),
          durationText: String(res?.duration_text || ''),
          nextInstruction: firstStep.replace(/<[^>]*>/g, '')
        });
        setNavError('');
        const routeKm = Number(res?.distance_km || 0);
        if (routeKm > 0) {
          baselineDistanceKmRef.current = routeKm;
        }
      } catch (_e) {
        if (!active) return;
        setRouteLine([]);
        setRouteMeta({ distanceText: '', durationText: '', nextInstruction: '' });
        setNavError('Route fetch failed. Check Maps API key/network.');
      }
    };

    loadRoute();
    const interval = setInterval(loadRoute, 5000);
    return () => {
      active = false;
      clearInterval(interval);
    };
  }, [
    apiBase,
    token,
    driverLoc.latitude,
    driverLoc.longitude,
    status,
    vehicleType,
    pickup.latitude,
    pickup.longitude,
    dropoff.latitude,
    dropoff.longitude,
    inAppNavEnabled,
    navRefreshTick
  ]);

  useEffect(() => {
    if (!inAppNavEnabled || !routeLine.length) return;
    const deviationMeters = minDistanceToPolylineMeters(driverLoc, routeLine);
    if (deviationMeters > REROUTE_DEVIATION_METERS) {
      setNavRefreshTick((v) => v + 1);
    }
  }, [driverLoc.latitude, driverLoc.longitude, routeLine, inAppNavEnabled]);

  useEffect(() => {
    let stop = false;
    const updateRoadName = async () => {
      if (!inAppNavEnabled) return;
      try {
        const geo = await Location.reverseGeocodeAsync({
          latitude: driverLoc.latitude,
          longitude: driverLoc.longitude
        });
        if (stop) return;
        if (Array.isArray(geo) && geo.length > 0) {
          const g = geo[0];
          const road = [g.name, g.street].filter(Boolean).join(', ');
          setCurrentRoad(road || '');
        }
      } catch (_e) {
        // ignore reverse-geocode failures
      }
    };
    updateRoadName();
    const t = setInterval(updateRoadName, 15000);
    return () => {
      stop = true;
      clearInterval(t);
    };
  }, [driverLoc.latitude, driverLoc.longitude, inAppNavEnabled]);

  useEffect(() => {
    if (!inAppNavEnabled) return;
    const canUpdate = ['accepted', 'driver_assigned', 'driver_arriving', 'driver_arrived', 'ride_started'].includes(status);
    if (!canUpdate) return;
    const send = async () => {
      try {
        await apiPost(apiBase, '/api/driver/location', token, {
          lat: driverLoc.latitude,
          lng: driverLoc.longitude,
          is_available: false
        });
      } catch (_e) {
        // ignore periodic location sync errors
      }
    };
    send();
    const t = setInterval(send, 5000);
    return () => clearInterval(t);
  }, [apiBase, token, driverLoc.latitude, driverLoc.longitude, status, inAppNavEnabled]);

  const updateStatus = async (action) => {
    if (!ride?.id) return;
    setLoading(true);
    try {
      const body = action === 'start' ? { otp } : action === 'arrived' ? { lat: driverLoc.latitude, lng: driverLoc.longitude } : {};
      const json = await apiPost(apiBase, `/api/driver/rides/${ride.id}/${action}`, token, body);
      if (json.status !== 'ok') throw new Error(json.message || 'Failed');

      if (action === 'arrived') {
        setStatus('driver_arrived');
        Animated.spring(slideX, { toValue: 0, useNativeDriver: false }).start();
      }
      if (action === 'start') setStatus('ride_started');
      if (action === 'complete') {
        navigation.replace('RideCompleted', { ride, token, driver, summary: json });
      }
    } catch (e) {
      Alert.alert('Error', String(e.message || e));
      Animated.spring(slideX, { toValue: 0, useNativeDriver: false }).start();
    } finally {
      setLoading(false);
      activeSwipeRef.current = false;
    }
  };

  const completeRide = async () => {
    if (!ride?.id) return;
    setLoading(true);
    try {
      const json = await apiPost(apiBase, `/api/driver/rides/${ride.id}/complete`, token, {
        distance_km: Number(ride?.distance_km || 0),
        duration_minutes: Number(ride?.duration_min || 0)
      });
      if (json.status !== 'ok') throw new Error(json.message || 'Failed');
      navigation.replace('RideCompleted', { ride, token, driver, summary: json });
    } catch (e) {
      Alert.alert('Error', String(e.message || e));
    } finally {
      setLoading(false);
    }
  };

  const cancelRide = async () => {
    if (!ride?.id) return;
    Alert.alert('Cancel Ride', 'Are you sure you want to cancel this ride?', [
      { text: 'No', style: 'cancel' },
      {
        text: 'Yes, Cancel',
        style: 'destructive',
        onPress: async () => {
          setLoading(true);
          try {
            const reason =
              status === 'driver_assigned'
                ? 'Driver cancelled before arrival'
                : status === 'driver_arrived'
                  ? 'Driver cancelled at pickup'
                  : 'Driver cancelled during ride';
            const json = await apiPost(apiBase, `/api/driver/rides/${ride.id}/cancel`, token, { reason });
            if (json.status !== 'ok') throw new Error(json.message || 'Failed');
            Alert.alert('Ride Cancelled', json.message || 'Ride cancelled');
            navigation.replace('DriverHome', { driver, token });
          } catch (e) {
            Alert.alert('Error', String(e.message || e));
          } finally {
            setLoading(false);
          }
        }
      }
    ]);
  };

  const pickupDistanceMeters = useMemo(
    () => distanceKm(driverLoc, pickup) * 1000,
    [driverLoc.latitude, driverLoc.longitude, pickup.latitude, pickup.longitude]
  );
  const targetDistanceRaw = useMemo(() => {
    const target = status === 'ride_started' ? dropoff : pickup;
    return distanceKm(driverLoc, target);
  }, [driverLoc.latitude, driverLoc.longitude, status, pickup.latitude, pickup.longitude, dropoff.latitude, dropoff.longitude]);

  const targetDistanceMeters = useMemo(() => {
    const rawMeters = targetDistanceRaw * 1000;
    // GPS jitter compensation: if within current accuracy window, treat as arrived/at-point.
    const tolerance = Math.max(60, Math.round(driverAccuracyMeters * 1.5));
    if (rawMeters <= tolerance) return 0;
    return Math.max(0, rawMeters - driverAccuracyMeters);
  }, [targetDistanceRaw, driverAccuracyMeters]);

  const targetDistance = targetDistanceMeters / 1000;

  const isPrePickupState = ['driver_assigned', 'driver_arriving', 'accepted', 'assigned'].includes(status);
  const dynamicArrivalThreshold = Math.max(ARRIVAL_THRESHOLD_METERS, Math.round(driverAccuracyMeters * 1.5));
  const canSwipeArrived = isPrePickupState && pickupDistanceMeters <= dynamicArrivalThreshold;
  const canCompleteRide = status === 'ride_started' && targetDistanceMeters <= COMPLETE_RIDE_RADIUS_METERS;

  useEffect(() => {
    if (!canSwipeArrived || loading || !isPrePickupState) {
      Animated.spring(slideX, { toValue: 0, useNativeDriver: false }).start();
    }
  }, [canSwipeArrived, loading, isPrePickupState, slideX]);

  useEffect(() => {
    if (!mapRef.current || !routeLine.length || !followMode) return;
    try {
      mapRef.current.fitToCoordinates(routeLine, {
        edgePadding: { top: 140, right: 60, bottom: 260, left: 60 },
        animated: true
      });
    } catch (_e) {
      // ignore camera-fit errors on transient map state
    }
  }, [routeLine, followMode]);

  const panResponder = useMemo(
    () =>
      PanResponder.create({
        onMoveShouldSetPanResponder: () => isPrePickupState && !loading,
        onPanResponderMove: (_evt, gestureState) => {
          const next = clamp(gestureState.dx, 0, SWIPE_MAX);
          slideX.setValue(next);
        },
        onPanResponderRelease: async (_evt, gestureState) => {
          if (activeSwipeRef.current) return;
          const end = clamp(gestureState.dx, 0, SWIPE_MAX);
          if (end >= SWIPE_TRIGGER && !loading) {
            if (!canSwipeArrived) {
              Alert.alert(
                'Not at pickup yet',
                `Pickup tak ${Math.max(0, Math.round(pickupDistanceMeters - dynamicArrivalThreshold))} m aur jaana hoga.`
              );
              Animated.spring(slideX, { toValue: 0, useNativeDriver: false }).start();
              return;
            }
            activeSwipeRef.current = true;
            Animated.timing(slideX, { toValue: SWIPE_MAX, duration: 120, useNativeDriver: false }).start(async () => {
              Vibration.vibrate(30);
              await updateStatus('arrived');
            });
            return;
          }
          Animated.spring(slideX, { toValue: 0, useNativeDriver: false }).start();
        }
      }),
    [canSwipeArrived, loading, status, pickupDistanceMeters, dynamicArrivalThreshold]
  );

  const recenterMap = () => {
    mapRef.current?.animateToRegion(
      {
        latitude: driverLoc.latitude,
        longitude: driverLoc.longitude,
        latitudeDelta: 0.015,
        longitudeDelta: 0.015
      },
      300
    );
  };

  const openExternalNavigation = async () => {
    const target = status === 'ride_started' ? dropoff : pickup;
    try {
      const useTwoWheeler = isTwoWheelerVehicle(vehicleType);
      if (Platform.OS === 'android') {
        // google.navigation mode: d=driving, l=two-wheeler, w=walking, b=bicycling
        const navMode = useTwoWheeler ? 'l' : 'd';
        const nativeUrl = `google.navigation:q=${target.latitude},${target.longitude}&mode=${navMode}`;
        const canOpenNative = await Linking.canOpenURL(nativeUrl);
        if (canOpenNative) {
          await Linking.openURL(nativeUrl);
          return;
        }
      }

      const webMode = useTwoWheeler ? 'two-wheeler' : 'driving';
      const webUrl = `https://www.google.com/maps/dir/?api=1&destination=${target.latitude},${target.longitude}&travelmode=${encodeURIComponent(webMode)}`;
      await Linking.openURL(webUrl);
    } catch (_e) {
      Alert.alert('Error', 'Unable to open external navigation');
    }
  };

  const speakGuidance = async (text, minGapMs = 5000) => {
    if (!voiceEnabled || !inAppNavEnabled) return;
    const cleaned = String(text || '').trim();
    if (!cleaned) return;
    const now = Date.now();
    if (now - lastSpokenAtRef.current < minGapMs) return;
    try {
      await Speech.stop();
      Speech.speak(cleaned, {
        language: 'en-IN',
        rate: 0.95,
        pitch: 1.0
      });
      lastSpokenAtRef.current = now;
    } catch (_e) {
      // ignore speech errors
    }
  };

  useEffect(() => {
    if (!inAppNavEnabled || !voiceEnabled) return;
    const next = String(routeMeta.nextInstruction || '').trim();
    if (!next) return;
    if (lastSpokenInstructionRef.current === next) return;
    lastSpokenInstructionRef.current = next;
    speakGuidance(`Next direction: ${next}`, 2500);
  }, [routeMeta.nextInstruction, inAppNavEnabled, voiceEnabled]);

  useEffect(() => {
    if (!inAppNavEnabled || !voiceEnabled) return;
    const meters = Math.round(targetDistance * 1000);
    if (meters <= 500 && !milestoneSpokenRef.current.near500) {
      milestoneSpokenRef.current.near500 = true;
      speakGuidance(`You are ${meters} meters away. Keep going.`, 2500);
    }
    if (meters <= 150 && !milestoneSpokenRef.current.near150) {
      milestoneSpokenRef.current.near150 = true;
      const label = status === 'ride_started' ? 'drop location' : 'pickup location';
      speakGuidance(`Almost there. ${label} is ahead.`, 2500);
    }
    if (meters <= 40 && !milestoneSpokenRef.current.arrived) {
      milestoneSpokenRef.current.arrived = true;
      const label = status === 'ride_started' ? 'drop location' : 'pickup location';
      speakGuidance(`You have reached near the ${label}.`, 2500);
    }
  }, [targetDistance, inAppNavEnabled, voiceEnabled, status]);

  useEffect(() => {
    milestoneSpokenRef.current = {
      near500: false,
      near150: false,
      arrived: false
    };
    lastSpokenInstructionRef.current = '';
  }, [status, ride?.id]);

  return (
    <SafeAreaView style={styles.screen}>
      <MapView
        ref={mapRef}
        style={styles.map}
        provider={mapProvider}
        initialRegion={{
          latitude: pickup.latitude,
          longitude: pickup.longitude,
          latitudeDelta: 0.08,
          longitudeDelta: 0.08
        }}
        scrollEnabled
        zoomEnabled
        rotateEnabled
        pitchEnabled
        showsCompass
        showsTraffic
        showsBuildings
        showsMyLocationButton
        onPanDrag={() => setFollowMode(false)}
        onMapReady={() => {
          setMapReady(true);
          setNavError('');
        }}
        onError={(e) => {
          setNavError(String(e?.nativeEvent?.error || 'Google Map failed to load'));
        }}
      >
        <Marker coordinate={pickup} title="Pickup" />
        <Marker coordinate={dropoff} title="Dropoff" />
        <Marker coordinate={driverLoc} title="You" anchor={{ x: 0.5, y: 0.5 }}>
          <View style={styles.vehicleMarker}>
            <MaterialCommunityIcons
              name={isTwoWheelerVehicle(vehicleType) ? 'motorbike' : 'car'}
              size={20}
              color="#FFFFFF"
            />
          </View>
        </Marker>
        <Polyline coordinates={routeLine.length ? routeLine : straightLine} strokeWidth={4} strokeColor="#FFD100" />
      </MapView>

      <View style={[styles.navBanner, styles.liveRideTopCard]}>
        <Text style={styles.liveRideTopTitle}>Live Ride</Text>
        <Text style={styles.liveRideTopStatus}>{status === 'ride_started' ? 'Ride in progress' : 'Heading to pickup'}</Text>
        <Text style={styles.navBannerLine}>Navigation mode: {navVehicleMode}</Text>
        <Text style={styles.navBannerLine}>
          {inAppNavEnabled
            ? `ETA ${routeMeta.durationText || `${etaMinutes} min`} | ${routeMeta.distanceText || `${targetDistance.toFixed(2)} km`}`
            : 'In-app navigation paused'}
        </Text>
        {!!navError && <Text style={styles.navBannerLine}>{navError}</Text>}
        {!mapReady && <Text style={styles.navBannerLine}>Loading embedded Google Maps navigation...</Text>}
        {!mapApiKey && <Text style={styles.navBannerLine}>Google Maps API key missing for embedded navigation.</Text>}
        {!!routeMeta.nextInstruction && (
          <Text style={styles.navBannerLine}>
            {turnArrowFromInstruction(routeMeta.nextInstruction)} Next: {routeMeta.nextInstruction}
          </Text>
        )}
        {!!currentRoad && <Text style={styles.navBannerLine}>Road: {currentRoad}</Text>}
        <TouchableOpacity
          style={[styles.topQuickBtn, styles.topQuickBtnNav]}
          onPress={openExternalNavigation}
        >
          <Text style={styles.topQuickBtnText}>Open External Navigation</Text>
        </TouchableOpacity>
      </View>

      <View style={[styles.sheet, styles.liveRideBottomCard]}>
        <Text style={styles.sheetTitle}>Live Ride</Text>
        <Text style={styles.statusLine}>Distance to target: {targetDistance.toFixed(2)} km</Text>
        <Text style={styles.statusLine}>Pickup: {ride?.pickup_address}</Text>
        <Text style={styles.statusLine}>Drop: {ride?.drop_address}</Text>
        <View style={styles.navProgressTrack}>
          <View style={[styles.navProgressFill, { width: `${Math.round(navProgress * 100)}%` }]} />
        </View>
        <Text style={styles.statusLine}>Progress: {Math.round(navProgress * 100)}%</Text>

        {isPrePickupState && (
          <View style={styles.swipeCard}>
            <Text style={styles.swipeHint}>
              {canSwipeArrived
                ? 'Swipe to Arrived'
                : `Move closer to pickup (${Math.max(0, Math.round(pickupDistanceMeters - ARRIVAL_THRESHOLD_METERS))} m left)`}
            </Text>
            <View style={styles.swipeTrack}>
              <Text style={styles.swipeTrackText}>Swipe to Arrived</Text>
              <Animated.View
                style={[styles.swipeKnob, { transform: [{ translateX: slideX }] }]}
                {...(isPrePickupState && !loading ? panResponder.panHandlers : {})}
              >
                <Text style={styles.swipeKnobText}>{loading ? '...' : '>>'}</Text>
              </Animated.View>
            </View>
            <TouchableOpacity style={styles.cancelRideBtn} onPress={cancelRide} disabled={loading}>
              {loading ? <ActivityIndicator color="#ffffff" /> : <Text style={styles.cancelRideBtnText}>Cancel Request</Text>}
            </TouchableOpacity>
          </View>
        )}

        {['driver_arrived', 'arrived', 'waiting'].includes(status) && (
          <>
            <Text style={styles.statusLine}>Waiting: {Math.floor(waitingSeconds / 60)}m {waitingSeconds % 60}s</Text>
            <Text style={styles.label}>Enter OTP</Text>
            <TextInput
              style={styles.input}
              placeholder="4-digit OTP"
              value={otp}
              onChangeText={setOtp}
              keyboardType="number-pad"
              maxLength={4}
            />
            <TouchableOpacity style={styles.primaryBtn} onPress={() => updateStatus('start')} disabled={loading || otp.length !== 4}>
              {loading ? <ActivityIndicator /> : <Text style={styles.primaryBtnText}>Start Ride</Text>}
            </TouchableOpacity>
          </>
        )}

        {status === 'ride_started' && (
          <>
          {!canCompleteRide && (
            <Text style={styles.statusLine}>
              Drop tak pahunchne ke baad hi End Ride hoga ({Math.max(0, Math.round(targetDistanceMeters - COMPLETE_RIDE_RADIUS_METERS))} m left)
            </Text>
          )}
          <TouchableOpacity style={styles.primaryBtn} onPress={completeRide} disabled={loading || !canCompleteRide}>
            {loading ? <ActivityIndicator /> : <Text style={styles.primaryBtnText}>End Ride</Text>}
          </TouchableOpacity>
          </>
        )}

        {false && ['driver_assigned', 'driver_arrived', 'accepted', 'assigned', 'driver_arriving'].includes(status) && (
          <TouchableOpacity style={styles.cancelRideBtn} onPress={cancelRide} disabled={loading}>
            {loading ? <ActivityIndicator color="#ffffff" /> : <Text style={styles.cancelRideBtnText}>Cancel Ride</Text>}
          </TouchableOpacity>
        )}

        <TouchableOpacity style={styles.ghostBtn} onPress={() => navigation.navigate('RideTimeline', { ride, status })}>
          <Text style={styles.ghostBtnText}>View Timeline</Text>
        </TouchableOpacity>
      </View>
    </SafeAreaView>
  );
}
