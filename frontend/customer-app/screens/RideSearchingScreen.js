import React, { useEffect, useMemo, useRef, useState } from 'react';
import { View, Text, StyleSheet, ActivityIndicator, TouchableOpacity, Animated, Alert } from 'react-native';
import MapView, { Marker, PROVIDER_GOOGLE } from 'react-native-maps';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { apiGet, apiPost } from '../apiClient';
import { API_BASE } from '../config';

const ACTIVE_RIDE_KEY = 'customer_active_ride_v1';
const isRideNotFoundError = (error) => String(error?.message || '').toLowerCase().includes('ride not found');

export default function RideSearchingScreen({ navigation, route }) {
  const { rideId, token, apiBase, session } = route?.params || {};
  const [authToken, setAuthToken] = useState(token || session?.token || '');
  const [ride, setRide] = useState(null);
  const [statusText, setStatusText] = useState('Finding nearby drivers...');
  const [titleText, setTitleText] = useState('Finding nearby drivers...');
  const pulse = useRef(new Animated.Value(0)).current;
  const mapRef = useRef(null);
  const resolvedBase = apiBase || API_BASE;

  const pickup = useMemo(() => ({
    latitude: Number(ride?.pickup_lat || route?.params?.pickup?.lat || 28.6139),
    longitude: Number(ride?.pickup_lng || route?.params?.pickup?.lng || 77.209),
  }), [ride, route?.params?.pickup?.lat, route?.params?.pickup?.lng]);
  const mapRegion = useMemo(
    () => ({
      ...pickup,
      latitudeDelta: 0.01,
      longitudeDelta: 0.01,
    }),
    [pickup.latitude, pickup.longitude]
  );

  useEffect(() => {
    if (!mapRef.current) return;
    mapRef.current.animateToRegion(mapRegion, 350);
  }, [mapRegion.latitude, mapRegion.longitude, mapRegion.latitudeDelta, mapRegion.longitudeDelta]);

  useEffect(() => {
    const loop = Animated.loop(
      Animated.sequence([
        Animated.timing(pulse, { toValue: 1, duration: 1100, useNativeDriver: true }),
        Animated.timing(pulse, { toValue: 0, duration: 1100, useNativeDriver: true }),
      ])
    );
    loop.start();
    return () => loop.stop();
  }, [pulse]);

  useEffect(() => {
    let mounted = true;
    const loadToken = async () => {
      if (authToken) return;
      try {
        const raw = await AsyncStorage.getItem('customer_session_v1');
        if (!raw) return;
        const parsed = JSON.parse(raw);
        const savedToken = String(parsed?.token || '');
        if (mounted && savedToken) setAuthToken(savedToken);
      } catch (_) {
        // ignore
      }
    };
    loadToken();
    return () => {
      mounted = false;
    };
  }, [authToken]);

  useEffect(() => {
    let timer;
    let mounted = true;
    let movedToLiveRide = false;

    // Ensure current ride is persisted for resume after restart.
    (async () => {
      try {
        await AsyncStorage.setItem(
          ACTIVE_RIDE_KEY,
          JSON.stringify({
            rideId,
            screen: 'RideSearching',
            apiBase: resolvedBase,
            pickup: route?.params?.pickup || null,
            drop: route?.params?.drop || null,
            ts: Date.now(),
          })
        );
      } catch (_) {
        // ignore
      }
    })();

    const fetchRide = async () => {
      if (!rideId) return;
      if (!authToken) {
        setStatusText('Reconnecting session...');
        return;
      }
      try {
        const res = await apiGet(resolvedBase, `/api/rides/${rideId}`, authToken);
        if (!mounted || res?.status !== 'ok' || !res?.ride) return;
        const r = res.ride;
        const rideStatus = String(r.status || '').toLowerCase();
        setRide(r);

        if (rideStatus === 'searching') {
          setTitleText('Finding nearby drivers...');
          setStatusText('Finding nearby drivers...');
          try {
            const active = await apiGet(resolvedBase, '/api/rides/active', authToken);
            const aRide = active?.ride || null;
            const aStatus = String(aRide?.status || '').toLowerCase();
            const canUseActiveRide =
              aRide &&
              aRide.id &&
              aRide.id !== rideId &&
              ['driver_assigned', 'accepted', 'driver_arriving', 'driver_arrived', 'arrived', 'waiting', 'ride_started', 'in_progress'].includes(aStatus);
            if (canUseActiveRide && !movedToLiveRide) {
              movedToLiveRide = true;
              clearInterval(timer);
              navigation.replace('LiveRide', {
                rideId: aRide.id,
                token: authToken,
                apiBase: resolvedBase,
                session,
              });
            }
          } catch (_) {
            // ignore fallback check errors
          }
          return;
        }

        if (rideStatus === 'no_driver_found') {
          setTitleText('No drivers available');
          setStatusText('No drivers found nearby. Please try again.');
          try {
            await AsyncStorage.removeItem(ACTIVE_RIDE_KEY);
          } catch (_) {}
          clearInterval(timer);
          return;
        }

        if (rideStatus === 'cancelled') {
          setTitleText('Ride cancelled');
          const cancelledBy = String(r?.cancelled_by || '').toLowerCase().trim();
          const cancelReason = String(r?.cancel_reason || '').toLowerCase().trim();
          const isDriverCancelled = cancelledBy === 'driver' || cancelReason.includes('driver');
          const isAdminCancelled = cancelledBy === 'admin' || cancelReason.includes('admin');
          const cancelMsg = isAdminCancelled
            ? 'Admin cancelled the ride request.'
            : (isDriverCancelled ? 'Cancelled request by driver.' : 'Ride cancelled.');
          setStatusText(cancelMsg);
          try {
            await AsyncStorage.removeItem(ACTIVE_RIDE_KEY);
          } catch (_) {}
          clearInterval(timer);
          Alert.alert('Ride Update', cancelMsg, [
            {
              text: 'OK',
              onPress: () =>
                navigation.reset({
                  index: 0,
                  routes: [{ name: 'Home', params: { session } }]
                }),
            }
          ]);
          return;
        }

        if (rideStatus === 'awaiting_payment') {
          setTitleText('Payment Pending');
          setStatusText('Please complete payment first. Driver search will start automatically after confirmation.');
          return;
        }

        const shouldGoLive =
          [
            'driver_assigned',
            'accepted',
            'driver_arriving',
            'driver_arrived',
            'arrived',
            'waiting',
            'ride_started',
            'in_progress',
          ].includes(rideStatus) || (!!r.driver_id && !['searching', 'no_driver_found', 'cancelled'].includes(rideStatus));

        if (shouldGoLive && !movedToLiveRide) {
          movedToLiveRide = true;
          setStatusText('Driver assigned');
          clearInterval(timer);
          try {
            await AsyncStorage.setItem(
              ACTIVE_RIDE_KEY,
              JSON.stringify({
                rideId,
                screen: 'LiveRide',
                apiBase: resolvedBase,
                ts: Date.now(),
              })
            );
          } catch (_) {}
          navigation.replace('LiveRide', {
            rideId,
            token: authToken,
            apiBase: resolvedBase,
            session,
          });
          return;
        }

        setStatusText(`Ride status: ${rideStatus}`);
      } catch (e) {
        if (!mounted) return;
        const msg = String(e?.message || '');
        if (isRideNotFoundError(e)) {
          try {
            await AsyncStorage.removeItem(ACTIVE_RIDE_KEY);
          } catch (_) {}
          clearInterval(timer);
          navigation.reset({
            index: 0,
            routes: [{ name: 'Home', params: { session } }]
          });
          return;
        }
        if (msg.toLowerCase().includes('unauthorized')) {
          setStatusText('Session expired. Please login again.');
          clearInterval(timer);
          return;
        }
        setStatusText('Connecting to server...');
      }
    };

    fetchRide();
    timer = setInterval(fetchRide, 4000);
    return () => {
      mounted = false;
      timer && clearInterval(timer);
    };
  }, [rideId, authToken, resolvedBase, navigation, session]);

  const goHome = async () => {
    try {
      if (rideId && authToken) {
        await apiPost(resolvedBase, `/api/rides/${rideId}/cancel`, authToken, {
          reason: 'Cancelled by customer while searching',
        });
      }
    } catch (_) {
      // Ignore cancel call failures; user still goes home.
    }
    try {
      await AsyncStorage.removeItem(ACTIVE_RIDE_KEY);
    } catch (_) {}
    navigation.reset({
      index: 0,
      routes: [{ name: 'Home', params: { session } }]
    });
  };

  return (
    <View style={styles.screen}>
      <MapView
        ref={mapRef}
        provider={PROVIDER_GOOGLE}
        style={StyleSheet.absoluteFill}
        region={mapRegion}
      >
        <Marker coordinate={pickup} title="Pickup" />
      </MapView>

      <View style={styles.pulseWrap} pointerEvents="none">
        <Animated.View
          style={[
            styles.pulseOuter,
            {
              transform: [{ scale: pulse.interpolate({ inputRange: [0, 1], outputRange: [1, 1.45] }) }],
              opacity: pulse.interpolate({ inputRange: [0, 1], outputRange: [0.38, 0.08] }),
            },
          ]}
        />
        <Animated.View
          style={[
            styles.pulseInner,
            {
              transform: [{ scale: pulse.interpolate({ inputRange: [0, 1], outputRange: [1, 1.2] }) }],
              opacity: pulse.interpolate({ inputRange: [0, 1], outputRange: [0.7, 0.28] }),
            },
          ]}
        />
      </View>

      <View style={styles.bottomCard}>
        <ActivityIndicator size="small" color="#111827" />
        <Text style={styles.title}>{titleText}</Text>
        <Text style={styles.subtitle}>{statusText}</Text>
        <View style={styles.progressTrack}>
          <Animated.View
            style={[
              styles.progressFill,
              {
                transform: [
                  {
                    translateX: pulse.interpolate({ inputRange: [0, 1], outputRange: [-140, 220] }),
                  },
                ],
              },
            ]}
          />
        </View>

        <TouchableOpacity style={styles.primaryBtn} onPress={goHome}>
          <Text style={styles.primaryBtnText}>Cancel Search</Text>
        </TouchableOpacity>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  screen: {
    flex: 1,
    backgroundColor: '#0F172A',
  },
  pulseWrap: {
    position: 'absolute',
    top: '34%',
    left: '50%',
    width: 10,
    height: 10,
    marginLeft: -5,
    marginTop: -5,
    alignItems: 'center',
    justifyContent: 'center',
  },
  pulseOuter: {
    position: 'absolute',
    width: 140,
    height: 140,
    borderRadius: 70,
    backgroundColor: '#F59E0B',
  },
  pulseInner: {
    position: 'absolute',
    width: 88,
    height: 88,
    borderRadius: 44,
    backgroundColor: '#F59E0B',
  },
  bottomCard: {
    position: 'absolute',
    left: 16,
    right: 16,
    bottom: 16,
    borderRadius: 20,
    backgroundColor: '#FFFFFF',
    padding: 16,
    alignItems: 'center',
    shadowColor: '#000',
    shadowOpacity: 0.12,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: 6 },
    elevation: 8,
  },
  title: {
    marginTop: 10,
    fontSize: 21,
    fontWeight: '800',
    color: '#111827',
  },
  subtitle: {
    marginTop: 8,
    fontSize: 13,
    color: '#6B7280',
    width: '100%',
    textAlign: 'center',
  },
  progressTrack: {
    marginTop: 12,
    width: '100%',
    height: 8,
    borderRadius: 4,
    backgroundColor: '#EEF2F7',
    overflow: 'hidden',
  },
  progressFill: {
    width: 120,
    height: 8,
    borderRadius: 4,
    backgroundColor: '#F59E0B',
  },
  primaryBtn: {
    marginTop: 16,
    backgroundColor: '#FFD100',
    borderRadius: 14,
    paddingVertical: 13,
    paddingHorizontal: 22,
  },
  primaryBtnText: {
    color: '#111827',
    fontWeight: '800',
  },
});







