import React, { useEffect, useState, useRef, useLayoutEffect } from 'react';
import { View, Text, TouchableOpacity, ActivityIndicator, Alert, Animated, Easing, ScrollView, Switch, Modal, Platform } from 'react-native';
import MapView, { Marker, PROVIDER_GOOGLE } from 'react-native-maps';
import * as Location from 'expo-location';
import * as Notifications from 'expo-notifications';
import * as Speech from 'expo-speech';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { styles } from '../styles';
import { apiGet, apiPost } from '../apiClient';
import { registerForPushTokenAsync, getRideActionIds, getRideChannelId } from '../notifications';

const REQUEST_TIMEOUT = 60;
const REQUEST_ALERT_REPEAT_MS = 8000;
const toBool = (value) => value === true || value === 1 || value === '1';

export default function DriverHomeScreen({ route, navigation, apiBase, sessionKey, session }) {
  const driver = route.params?.driver || session?.driver;
  const [token, setToken] = useState(route.params?.token || session?.token || '');
  const [lat, setLat] = useState(driver?.current_lat ? Number(driver.current_lat) : 28.6139);
  const [lng, setLng] = useState(driver?.current_lng ? Number(driver.current_lng) : 77.2090);
  const [altitude, setAltitude] = useState(Number(driver?.current_altitude || 0));
  const [loading, setLoading] = useState(false);
  const [requests, setRequests] = useState([]);
  const [online, setOnline] = useState(false);
  const [stats, setStats] = useState({
    rating: 0,
    total_rides: 0,
    earnings_today: 0,
    is_verified: toBool(driver?.is_verified)
  });
  const [verificationChecked, setVerificationChecked] = useState(Boolean(driver));
  const [countdown, setCountdown] = useState(REQUEST_TIMEOUT);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [requestError, setRequestError] = useState('');
  const [mapError, setMapError] = useState('');
  const watchRef = useRef(null);
  const lastSentRef = useRef(0);
  const slideY = useRef(new Animated.Value(-260)).current;
  const onlineToggleInFlightRef = useRef(false);
  const autoOnlineAppliedRef = useRef(false);
  const notificationIdsRef = useRef({});
  const requestExpiryTimersRef = useRef({});
  const seenRequestsRef = useRef({});
  const alertLoopTimerRef = useRef(null);
  const tokenRef = useRef(token);
  const pendingFcmKeyRef = useRef((sessionKey || 'driver_session_v1') + '_pending_fcm');
  const actionIds = getRideActionIds();
  const mapProvider = Platform.OS === 'android' ? PROVIDER_GOOGLE : undefined;

  useEffect(() => {
    tokenRef.current = token;
  }, [token]);

  const syncPushToken = async (pushToken, authToken) => {
    if (!pushToken || !authToken) return false;
    try {
      await apiPost(apiBase, '/api/driver/push-token', authToken, { fcm_token: pushToken });
      return true;
    } catch (e) {
      return false;
    }
  };

  const normalizeRideFromPayload = (data) => {
    const rawExpiry = Number(data?.expires_in_sec || REQUEST_TIMEOUT);
    const expiresInSec = Number.isFinite(rawExpiry)
      ? Math.min(REQUEST_TIMEOUT, Math.max(1, Math.round(rawExpiry)))
      : REQUEST_TIMEOUT;
    const rideId = String(data?.ride_id || '');
    if (!rideId) return null;
    return {
      id: rideId,
      pickup_address: String(data?.pickup || ''),
      drop_address: String(data?.dropoff || ''),
      distance_km: Number(data?.distance_km || 0),
      duration_min: Number(data?.duration_min || 0),
      pickup_lat: Number(data?.pickup_lat || 0),
      pickup_lng: Number(data?.pickup_lng || 0),
      drop_lat: Number(data?.drop_lat || 0),
      drop_lng: Number(data?.drop_lng || 0),
      driver_earning: Number(data?.driver_profit || data?.driver_earning || 0),
      fare_total: Number(data?.fare_total || data?.fare || 0),
      expires_in_sec: expiresInSec,
      accept_endpoint: String(data?.accept_endpoint || ''),
      reject_endpoint: String(data?.reject_endpoint || '')
    };
  };

  const upsertRequest = (request) => {
    if (!request?.id) return;
    setRequests((prev) => {
      const idx = prev.findIndex((r) => r.id === request.id);
      if (idx === -1) return [request, ...prev];
      const next = [...prev];
      next[idx] = { ...next[idx], ...request };
      return next;
    });
  };

  const normalizeRideFromApi = (request) => {
    const rawExpiry = Number(request?.expires_in_sec || REQUEST_TIMEOUT);
    const expiresInSec = Number.isFinite(rawExpiry)
      ? Math.min(REQUEST_TIMEOUT, Math.max(1, Math.round(rawExpiry)))
      : REQUEST_TIMEOUT;
    const rideId = String(request?.id || '');
    if (!rideId) return null;
    return {
      id: rideId,
      pickup_address: String(request?.pickup_address || ''),
      drop_address: String(request?.drop_address || ''),
      distance_km: Number(request?.distance_km || 0),
      duration_min: Number(request?.duration_min || 0),
      pickup_lat: Number(request?.pickup_lat || 0),
      pickup_lng: Number(request?.pickup_lng || 0),
      drop_lat: Number(request?.drop_lat || 0),
      drop_lng: Number(request?.drop_lng || 0),
      driver_earning: Number(request?.driver_earning || 0),
      fare_total: Number(request?.fare_total || request?.fare || 0),
      expires_in_sec: expiresInSec,
      accept_endpoint: String(request?.accept_endpoint || `/api/driver/rides/${rideId}/accept`),
      reject_endpoint: String(request?.reject_endpoint || `/api/driver/rides/${rideId}/reject`)
    };
  };

  const scheduleLocalRideNotification = async (request) => {
    if (!request?.id) return;
    const rideId = String(request.id);
    // Hard dedupe: one notification per ride request until it is removed/expired.
    if (seenRequestsRef.current[rideId]) return;
    if (notificationIdsRef.current[rideId]) return;
    seenRequestsRef.current[rideId] = Date.now();
    try {
      const notificationId = await Notifications.scheduleNotificationAsync({
        content: {
          title: 'New Ride Request',
          body: `${request.pickup_address || 'Pickup'} -> ${request.drop_address || 'Drop'} | Profit: Rs ${Number(request.driver_earning || 0).toFixed(2)}`,
          sound: 'ride_request.wav',
          channelId: getRideChannelId(),
          priority: Notifications.AndroidNotificationPriority.MAX,
          sticky: true,
          autoDismiss: false,
          data: { ...request, local_actionable: '1' },
          categoryIdentifier: actionIds.categoryId
        },
        trigger: null
      });
      notificationIdsRef.current[rideId] = notificationId;
      const ttlMs = Math.max(1, Number(request.expires_in_sec || REQUEST_TIMEOUT)) * 1000;
      const oldTimer = requestExpiryTimersRef.current[rideId];
      if (oldTimer) {
        clearTimeout(oldTimer);
      }
      const timer = setTimeout(async () => {
        await dismissRideNotification(rideId);
        removeRequest(rideId);
      }, ttlMs);
      requestExpiryTimersRef.current[rideId] = timer;
    } catch (e) {
      // ignore
    }
  };

  const speakRideAlert = () => {
    try {
      // Audible fallback even when notification sound is suppressed by device/app state.
      Speech.stop();
      Speech.speak('New ride request', { rate: 1.0, pitch: 1.0, language: 'en-IN' });
    } catch (e) {
      // ignore speech failures
    }
  };

  const stopRideAlertLoop = () => {
    if (alertLoopTimerRef.current) {
      clearInterval(alertLoopTimerRef.current);
      alertLoopTimerRef.current = null;
    }
    try {
      Speech.stop();
    } catch (e) {
      // ignore
    }
  };

  const startRideAlertLoop = () => {
    stopRideAlertLoop();
    speakRideAlert();
    alertLoopTimerRef.current = setInterval(() => {
      speakRideAlert();
    }, REQUEST_ALERT_REPEAT_MS);
  };

  const removeRequest = (rideId) => {
    const key = String(rideId || '');
    const timer = requestExpiryTimersRef.current[key];
    if (timer) {
      clearTimeout(timer);
      delete requestExpiryTimersRef.current[key];
    }
    delete seenRequestsRef.current[key];
    setRequests((prev) => prev.filter((r) => r.id !== rideId));
  };

  const dismissRideNotification = async (rideId) => {
    const nid = notificationIdsRef.current[String(rideId || '')];
    if (!nid) return;
    try {
      await Notifications.dismissNotificationAsync(nid);
    } catch (e) {
      // ignore
    } finally {
      delete notificationIdsRef.current[String(rideId || '')];
    }
  };

  const getAuthToken = async () => {
    if (tokenRef.current) return tokenRef.current;
    try {
      const raw = await AsyncStorage.getItem(sessionKey || 'driver_session_v1');
      if (!raw) return '';
      const data = JSON.parse(raw);
      return String(data?.token || '');
    } catch (e) {
      return '';
    }
  };

  useEffect(() => {
    const loadToken = async () => {
      if (token) return;
      try {
        const raw = await AsyncStorage.getItem(sessionKey || 'driver_session_v1');
        if (!raw) return;
        const data = JSON.parse(raw);
        if (data?.token) setToken(data.token);
      } catch (e) {
        // ignore
      }
    };
    loadToken();
  }, [token, sessionKey]);

  const loadStats = async () => {
    if (!token) return null;
    try {
      const res = await apiGet(apiBase, '/api/driver/stats', token);
      if (res?.status === 'ok') {
        const totalRides = Number(res.total_rides || 0);
        const normalizedRating = totalRides > 0 ? Number(res.rating || 0) : 0;
        const verified = toBool(res?.is_verified);
        setStats({
          rating: normalizedRating,
          total_rides: totalRides,
          earnings_today: Number(res.earnings_today || 0),
          is_verified: verified
        });
        setVerificationChecked(true);
        return verified;
      }
      return null;
    } catch (e) {
      return null;
    }
  };

  useEffect(() => {
    loadStats();
  }, [token]);

  useEffect(() => {
    if (autoOnlineAppliedRef.current) return;
    if (!token) return;
    if (!verificationChecked) return;
    if (!stats.is_verified) return;
    autoOnlineAppliedRef.current = true;
    updateOnline(true);
  }, [token, verificationChecked, stats.is_verified]);

  useEffect(() => {
    if (!stats.is_verified && online) {
      setOnline(false);
      setRequestError('');
    }
  }, [stats.is_verified, online]);

  const refreshVerification = async () => {
    if (!token) return null;
    try {
      const res = await apiGet(apiBase, '/api/driver/me', token);
      const verified = toBool(res?.driver?.is_verified);
      setStats((prev) => ({ ...prev, is_verified: verified }));
      setVerificationChecked(true);
      return verified;
    } catch (e) {
      return null;
    }
  };

  useEffect(() => {
    const setupPush = async () => {
      const { token: pushToken, type, error } = await registerForPushTokenAsync();
      if (pushToken && type === 'fcm') {
        const authToken = tokenRef.current;
        const ok = await syncPushToken(pushToken, authToken);
        if (!ok) {
          setRequestError('Push token sync failed. Will retry automatically.');
          try {
            await AsyncStorage.setItem(pendingFcmKeyRef.current, String(pushToken));
          } catch (e) {
            // ignore
          }
        }
      } else if (error) {
        setRequestError(String(error));
      }
    };
    setupPush();
    const pushTokenSub =
      typeof Notifications.addPushTokenListener === 'function'
        ? Notifications.addPushTokenListener(async (event) => {
            const refreshed = String(event?.data || '');
            if (!refreshed) return;
            const authToken = tokenRef.current;
            const ok = await syncPushToken(refreshed, authToken);
            if (!ok) {
              try {
                await AsyncStorage.setItem(pendingFcmKeyRef.current, refreshed);
              } catch (e) {
                // ignore
              }
            }
          })
        : null;

    const receivedSub = Notifications.addNotificationReceivedListener(async (notification) => {
      const data = notification.request.content.data || {};
      const eventType = String(data?.type || data?.event_type || '').toLowerCase();
      const cancelledRideId = String(data?.ride_id || '');
      if (eventType === 'ride_cancelled' && cancelledRideId) {
        await dismissRideNotification(cancelledRideId);
        removeRequest(cancelledRideId);
        return;
      }
      const request = normalizeRideFromPayload(data);
      if (!request) return;

      upsertRequest(request);
      speakRideAlert();
    });

    const responseSub = Notifications.addNotificationResponseReceivedListener(async (response) => {
      const data = response?.notification?.request?.content?.data || {};
      const eventType = String(data?.type || data?.event_type || '').toLowerCase();
      const cancelledRideId = String(data?.ride_id || '');
      if (eventType === 'ride_cancelled' && cancelledRideId) {
        await dismissRideNotification(cancelledRideId);
        removeRequest(cancelledRideId);
        return;
      }
      const request = normalizeRideFromPayload(data);
      if (!request) return;

      const tokenValue = await getAuthToken();
      if (!tokenValue) return;

      const actionId = response.actionIdentifier;
      try {
        if (actionId === actionIds.accept) {
          const acceptPath = request.accept_endpoint || `/api/driver/rides/${request.id}/accept`;
          const json = await apiPost(apiBase, acceptPath, tokenValue, {});
          if (json?.status === 'ok') {
            await dismissRideNotification(request.id);
            removeRequest(request.id);
            navigation.navigate('LiveRide', {
              ride: { ...request, status: 'driver_assigned' },
              token: tokenValue,
              driver
            });
          }
        } else if (actionId === actionIds.reject) {
          const rejectPath = request.reject_endpoint || `/api/driver/rides/${request.id}/reject`;
          await apiPost(apiBase, rejectPath, tokenValue, {});
          await dismissRideNotification(request.id);
          removeRequest(request.id);
        }
      } catch (e) {
        // ignore
      }
    });

    return () => {
      receivedSub.remove();
      responseSub.remove();
      if (pushTokenSub?.remove) pushTokenSub.remove();
      Object.values(requestExpiryTimersRef.current).forEach((t) => clearTimeout(t));
      requestExpiryTimersRef.current = {};
    };
  }, [apiBase, token, navigation, sessionKey]);

  useEffect(() => {
    const flushPendingFcm = async () => {
      if (!token) return;
      try {
        const saved = await AsyncStorage.getItem(pendingFcmKeyRef.current);
        if (!saved) return;
        const ok = await syncPushToken(saved, token);
        if (ok) {
          await AsyncStorage.removeItem(pendingFcmKeyRef.current);
        }
      } catch (e) {
        // ignore
      }
    };
    flushPendingFcm();
  }, [token]);

  useEffect(() => {
    let mounted = true;
    const initLocation = async () => {
      const { status } = await Location.requestForegroundPermissionsAsync();
      if (status !== 'granted') {
        Alert.alert('Permission required', 'Location permission is needed to go online.');
        return;
      }

      const sub = await Location.watchPositionAsync(
        {
          accuracy: Location.Accuracy.High,
          timeInterval: 5000,
          distanceInterval: 10
        },
        (loc) => {
          if (!mounted) return;
          const { latitude, longitude, altitude: alt } = loc.coords;
          setLat(latitude);
          setLng(longitude);
          setAltitude(Number(alt || 0));

          const now = Date.now();
          if (online && now - lastSentRef.current > 5000) {
            lastSentRef.current = now;
            sendLocation(true, latitude, longitude, Number(alt || 0)).then((res) => {
              if (res?.ok) {
                fetchRequests();
              }
            });
          }
        }
      );
      watchRef.current = sub;
    };

    initLocation();
    return () => {
      mounted = false;
      if (watchRef.current) watchRef.current.remove();
    };
  }, [online]);

  const sendLocation = async (nextOnline, latVal, lngVal, altitudeVal = 0) => {
    try {
      await apiPost(apiBase, '/api/driver/location', token, {
        lat: Number(latVal),
        lng: Number(lngVal),
        altitude: Number(altitudeVal || 0),
        is_available: nextOnline
      });
      return { ok: true };
    } catch (e) {
      return { ok: false, error: e };
    }
  };

  const updateOnline = async (nextOnline) => {
    if (onlineToggleInFlightRef.current || loading) {
      return;
    }

    if (!token) {
      Alert.alert('Login required', 'Please login again.');
      navigation.replace('Login');
      return;
    }
    if (nextOnline && (!verificationChecked || !stats.is_verified)) {
      const fresh = await refreshVerification();
      if (fresh !== true) {
        Alert.alert('Pending', 'Account not verified yet. Online hone ke liye account verification required hai.');
        setOnline(false);
        setRequestError('');
        return;
      }
    }
    onlineToggleInFlightRef.current = true;
    setLoading(true);
    try {
      const result = await sendLocation(nextOnline, lat, lng, altitude);
      if (!result.ok) throw result.error || new Error('Failed');
      setOnline(nextOnline);
      setRequestError('');
      if (nextOnline) fetchRequests(true);
    } catch (e) {
      Alert.alert('Error', String(e.message || e));
      setOnline(false);
    } finally {
      setLoading(false);
      onlineToggleInFlightRef.current = false;
    }
  };

  const fetchRequests = async () => {
    if (!online) return;
    try {
      const json = await apiGet(apiBase, '/api/driver/requests', token);
      if (json?.status === 'ok') {
        const nextRequests = (json.requests || [])
          .map(normalizeRideFromApi)
          .filter(Boolean);
        const activeIds = new Set(nextRequests.map((r) => String(r.id)));
        Object.keys(seenRequestsRef.current).forEach((id) => {
          if (!activeIds.has(id)) {
            delete seenRequestsRef.current[id];
          }
        });
        setRequests(nextRequests);
        setRequestError('');
        const newlyArrived = nextRequests.filter((r) => !seenRequestsRef.current[String(r.id)]);
        if (newlyArrived.length > 0) {
          for (const req of newlyArrived) {
            // Polling fallback: ensure audible alert even if remote push is delayed/missed.
            await scheduleLocalRideNotification(req);
          }
          speakRideAlert();
        }
      }
    } catch (e) {
      setRequestError(String(e?.message || 'Unable to load ride requests'));
    }
  };

  useEffect(() => {
    let timer;
    if (online) {
      fetchRequests();
      timer = setInterval(fetchRequests, 8000);
    }
    return () => timer && clearInterval(timer);
  }, [online, token, apiBase]);

  const activeRequest = requests[0] || null;

  useEffect(() => {
    if (activeRequest?.id) {
      startRideAlertLoop();
    } else {
      stopRideAlertLoop();
    }
    return () => stopRideAlertLoop();
  }, [activeRequest?.id]);

  const liveBarOffset = 24;

  useEffect(() => {
    if (!activeRequest) {
      Animated.timing(slideY, {
        toValue: -260,
        duration: 200,
        useNativeDriver: true,
        easing: Easing.out(Easing.quad)
      }).start();
      return;
    }

    Animated.timing(slideY, {
      toValue: 0,
      duration: 250,
      useNativeDriver: true,
      easing: Easing.out(Easing.cubic)
    }).start();

    setCountdown(Math.max(1, Number(activeRequest.expires_in_sec || REQUEST_TIMEOUT)));
    const t = setInterval(() => {
      setCountdown((prev) => {
        if (prev <= 1) {
          clearInterval(t);
          handleReject(activeRequest);
          return 0;
        }
        return prev - 1;
      });
    }, 1000);
    return () => clearInterval(t);
  }, [activeRequest?.id]);

  const handleAccept = async (ride) => {
    if (!ride?.id) return;
    stopRideAlertLoop();
    setLoading(true);
    try {
      const acceptPath = ride.accept_endpoint || `/api/driver/rides/${ride.id}/accept`;
      const json = await apiPost(apiBase, acceptPath, token, {});
      if (json?.status !== 'ok') throw new Error(json?.message || 'Failed');
      await dismissRideNotification(ride.id);
      removeRequest(ride.id);
      navigation.navigate('LiveRide', { ride: { ...ride, status: 'driver_assigned' }, token, driver });
    } catch (e) {
      Alert.alert('Error', String(e.message || e));
    } finally {
      setLoading(false);
    }
  };

  const handleReject = async (ride) => {
    if (!ride?.id) return;
    stopRideAlertLoop();
    try {
      const rejectPath = ride.reject_endpoint || `/api/driver/rides/${ride.id}/reject`;
      await apiPost(apiBase, rejectPath, token, {});
    } catch (e) {
      // ignore
    } finally {
      await dismissRideNotification(ride.id);
      removeRequest(ride.id);
    }
  };

  const statusLabel = online ? 'Online' : 'Offline';
  const verificationLabel = stats.is_verified ? 'Verified' : 'Pending';
  const menuItems = [
    { key: 'profile', label: 'Profile' },
    { key: 'rides', label: 'My Rides' },
    { key: 'earning', label: 'My Earning' },
    { key: 'rating', label: 'My Rating' },
    { key: 'logout', label: 'Logout' }
  ];

  const handleTopMenuPress = (key) => {
    setDrawerOpen(false);
    if (key === 'profile') {
      Alert.alert(
        'My Profile',
        `Name: ${driver?.name || 'N/A'}\nPhone: ${driver?.phone || 'N/A'}\nVehicle: ${driver?.vehicle_type || 'N/A'}`
      );
      return;
    }
    if (key === 'rides') {
      navigation.navigate('RideTimeline', { status: online ? 'driver_assigned' : 'searching' });
      return;
    }
    if (key === 'earning') {
      navigation.navigate('EarningsHistory', { token });
      return;
    }
    if (key === 'rating') {
      Alert.alert('My Rating', `Rating: ${stats.rating}\nTotal rides: ${stats.total_rides}`);
      return;
    }
    if (key === 'logout') {
      Alert.alert('Logout', 'Do you want to logout?', [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Logout',
          style: 'destructive',
          onPress: async () => {
            try {
              await AsyncStorage.removeItem(sessionKey || 'driver_session_v1');
            } catch (e) {
              // ignore
            }
            navigation.replace('Login');
          }
        }
      ]);
    }
  };

  useLayoutEffect(() => {
    navigation.setOptions({
      headerRight: () => (
        <TouchableOpacity style={styles.headerMenuBtn} onPress={() => setDrawerOpen((v) => !v)}>
          <Text style={styles.headerMenuBtnText}>Menu</Text>
        </TouchableOpacity>
      )
    });
  }, [navigation]);

  return (
    <View style={styles.screen}>
      <MapView
        style={styles.map}
        provider={mapProvider}
        region={{
          latitude: lat,
          longitude: lng,
          latitudeDelta: 0.05,
          longitudeDelta: 0.05
        }}
        onMapReady={() => setMapError('')}
        onMapLoaded={() => setMapError('')}
        onError={(e) => setMapError(String(e?.nativeEvent?.error || 'Map failed to load'))}
        showsUserLocation
      >
        <Marker coordinate={{ latitude: lat, longitude: lng }} title="You" />
      </MapView>

      <TouchableOpacity style={[styles.floatingBtn, { top: 96 }]} onPress={() => navigation.navigate('Support')}>
        <Text style={styles.floatingBtnText}>SOS</Text>
      </TouchableOpacity>
      <TouchableOpacity style={[styles.floatingBtn, { top: 152 }]} onPress={() => navigation.navigate('EarningsHistory', { token })}>
        <Text style={styles.floatingBtnText}>E</Text>
      </TouchableOpacity>

      <View style={styles.mapOverlay}>
        <ScrollView
          style={styles.dashboardScroll}
          contentContainerStyle={[styles.dashboardScrollContent, { paddingBottom: liveBarOffset + 20 }]}
          showsVerticalScrollIndicator
          alwaysBounceVertical
        >
          <View style={styles.card}>
            <View style={styles.toggleTopRow}>
              <View>
                <Text style={styles.cardTitle}>Captain Dashboard</Text>
                <Text style={styles.statusText}>Status: {statusLabel}</Text>
              </View>
              <Switch
                value={online}
                onValueChange={updateOnline}
                disabled={loading || !stats.is_verified}
                trackColor={{ false: '#d1d5db', true: '#22c55e' }}
                thumbColor={online ? '#ffffff' : '#f3f4f6'}
              />
            </View>
            <View style={styles.headerRow}>
              <Text style={styles.cardLine}>Mode: {online ? 'Online' : 'Offline'}</Text>
              <View style={styles.badge}>
                <Text style={styles.badgeText}>{verificationLabel}</Text>
              </View>
            </View>
            <Text style={styles.cardLine}>Today Earnings: Rs {stats.earnings_today}</Text>
            <Text style={styles.cardLine}>Total Rides: {stats.total_rides}</Text>
            <Text style={styles.cardLine}>Rating: {stats.rating}</Text>
            <Text style={styles.cardLine}>Current Altitude: {Number(altitude || 0).toFixed(1)} m</Text>
            {mapError ? <Text style={styles.error}>Map Error: {mapError}</Text> : null}
            {requestError ? <Text style={styles.error}>Request Error: {requestError}</Text> : null}
          </View>
        </ScrollView>
      </View>

      {activeRequest && (
        <Animated.View
          style={[
            styles.sheet,
            styles.liveRideSheet,
            { transform: [{ translateY: slideY }] }
          ]}
        >
          <Text style={styles.sheetTitle}>New Ride Request</Text>
          <Text style={styles.statusLine}>Pickup: {activeRequest.pickup_address}</Text>
          <Text style={styles.statusLine}>Drop: {activeRequest.drop_address}</Text>
          <Text style={styles.statusLine}>Distance: {activeRequest.distance_km} km</Text>
          <Text style={styles.statusLine}>ETA: {activeRequest.duration_min} min</Text>
          <Text style={styles.statusLine}>Driver Earning: Rs {activeRequest.driver_earning}</Text>
          <Text style={styles.statusLine}>Time Left: {countdown}s</Text>

          <View style={styles.rowInline}>
            <TouchableOpacity style={styles.acceptBtn} onPress={() => handleAccept(activeRequest)} disabled={loading}>
              {loading ? <ActivityIndicator /> : <Text style={styles.acceptText}>Accept</Text>}
            </TouchableOpacity>
            <TouchableOpacity style={styles.rejectBtn} onPress={() => handleReject(activeRequest)} disabled={loading}>
              <Text style={styles.rejectText}>Reject</Text>
            </TouchableOpacity>
          </View>
        </Animated.View>
      )}

      <Modal visible={drawerOpen} transparent animationType="fade" onRequestClose={() => setDrawerOpen(false)}>
        <View style={styles.drawerOverlay}>
          <TouchableOpacity style={styles.drawerBackdrop} onPress={() => setDrawerOpen(false)} />
          <View style={styles.drawerPanel}>
            <Text style={styles.drawerTitle}>Navigation</Text>
            {menuItems.map((item) => (
              <TouchableOpacity key={item.key} style={styles.drawerItem} onPress={() => handleTopMenuPress(item.key)}>
                <Text style={styles.drawerItemText}>{item.label}</Text>
              </TouchableOpacity>
            ))}
          </View>
        </View>
      </Modal>
    </View>
  );
}
