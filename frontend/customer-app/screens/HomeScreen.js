import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { View, Text, StyleSheet, TouchableOpacity, Alert, Platform, ScrollView, ActivityIndicator } from 'react-native';
import MapView, { Marker, PROVIDER_GOOGLE } from '../MapViewCompat';
import AsyncStorage from '@react-native-async-storage/async-storage';
import * as Location from 'expo-location';
import { useFocusEffect } from '@react-navigation/native';
import LocationInput from '../components/LocationInput';
import { apiPost } from '../apiClient';
import { API_BASE } from '../config';

const CUSTOMER_SESSION_KEY = 'customer_session_v1';
const ACTIVE_RIDE_KEY = 'customer_active_ride_v1';
const DEFAULT_REGION = {
  latitude: 28.6139,
  longitude: 77.2090,
  latitudeDelta: 0.08,
  longitudeDelta: 0.08
};

function regionForPoints(pickup, drop) {
  if (!pickup?.lat || !drop?.lat) return DEFAULT_REGION;
  const lat1 = Number(pickup.lat);
  const lng1 = Number(pickup.lng);
  const lat2 = Number(drop.lat);
  const lng2 = Number(drop.lng);
  const midLat = (lat1 + lat2) / 2;
  const midLng = (lng1 + lng2) / 2;
  const latDelta = Math.abs(lat1 - lat2) * 1.8 || DEFAULT_REGION.latitudeDelta;
  const lngDelta = Math.abs(lng1 - lng2) * 1.8 || DEFAULT_REGION.longitudeDelta;
  return {
    latitude: midLat,
    longitude: midLng,
    latitudeDelta: Math.max(latDelta, 0.02),
    longitudeDelta: Math.max(lngDelta, 0.02)
  };
}

export default function HomeScreen({ navigation, route }) {
  const mapRef = useRef(null);
  const gpsWatchRef = useRef(null);
  const pickupRef = useRef(null);
  const dropRef = useRef(null);
  const mapSelectionTargetRef = useRef('pickup');
  const [mapInitialRegion, setMapInitialRegion] = useState(null);
  const [mapKey, setMapKey] = useState('map_default');
  const [session, setSession] = useState(route?.params?.session || null);
  const [pickup, setPickup] = useState(null);
  const [drop, setDrop] = useState(null);
  const [loading, setLoading] = useState(false);
  const [autoPickupTried, setAutoPickupTried] = useState(false);
  const [mapSelectionTarget, setMapSelectionTarget] = useState('pickup');

  const quickLocationFromCoords = useCallback((lat, lng, fallbackName) => ({
    id: `coord_${lat}_${lng}`,
    place_id: null,
    name: fallbackName,
    area: '',
    address: `${Number(lat).toFixed(6)}, ${Number(lng).toFixed(6)}`,
    lat: Number(lat),
    lng: Number(lng)
  }), []);

  useEffect(() => {
    pickupRef.current = pickup;
    dropRef.current = drop;
  }, [pickup, drop]);

  useEffect(() => {
    mapSelectionTargetRef.current = mapSelectionTarget;
  }, [mapSelectionTarget]);

  const buildPickupFromCoords = useCallback(async (lat, lng, fallbackName = 'Selected Location') => {
    let titleText = fallbackName;
    let areaText = '';
    let addressText = `${Number(lat).toFixed(6)}, ${Number(lng).toFixed(6)}`;
    try {
      const rev = await Location.reverseGeocodeAsync({ latitude: Number(lat), longitude: Number(lng) });
      const p = rev?.[0];
      const titleParts = [p?.name, p?.street].filter(Boolean);
      const addrParts = [p?.name, p?.street, p?.district, p?.subregion, p?.city, p?.region, p?.postalCode].filter(Boolean);
      titleText = titleParts.join(', ') || fallbackName;
      areaText = [p?.subregion, p?.city, p?.region].filter(Boolean).join(', ');
      if (addrParts.length) addressText = addrParts.join(', ');
    } catch (_) {
      // keep fallback label
    }

    return {
      id: `coord_${lat}_${lng}`,
      place_id: null,
      name: titleText,
      area: areaText,
      address: addressText,
      lat: Number(lat),
      lng: Number(lng)
    };
  }, []);

  const getCurrentLocationPickup = useCallback(async () => {
    const { status } = await Location.requestForegroundPermissionsAsync();
    if (status !== 'granted') {
      Alert.alert('Location permission required', 'Please allow location permission to auto-fill pickup.');
      return null;
    }

    if (Platform.OS === 'android') {
      try {
        await Location.enableNetworkProviderAsync();
      } catch (_) {
        // User can still continue with GPS provider.
      }
    }

    let pos = null;
    try {
      pos = await Location.getCurrentPositionAsync({
        accuracy: Location.Accuracy.Highest
      });
    } catch (_) {
      // fallback below
    }

    if (!pos) {
      pos = await Location.getLastKnownPositionAsync({
        maxAge: 120000,
        requiredAccuracy: 200
      });
    }

    const lat = Number(pos?.coords?.latitude || 0);
    const lng = Number(pos?.coords?.longitude || 0);
    if (!lat || !lng) {
      Alert.alert('Location unavailable', 'Turn on GPS/High accuracy and try again.');
      return null;
    }

    return buildPickupFromCoords(lat, lng, 'Current Location');
  }, [buildPickupFromCoords]);

  const applyMapSelection = useCallback(
    async (latitude, longitude, forcedName = null) => {
      const shouldSelectDrop =
        mapSelectionTargetRef.current === 'drop'
        || (pickupRef.current?.lat && !dropRef.current?.lat);
      const targetLabel = shouldSelectDrop
        ? (forcedName || 'Selected drop on map')
        : (forcedName || 'Selected pickup on map');
      const fastLocation = quickLocationFromCoords(latitude, longitude, targetLabel);
      if (shouldSelectDrop) {
        setDrop(fastLocation);
        setMapSelectionTarget('drop');
      } else {
        setPickup(fastLocation);
        setMapSelectionTarget('pickup');
      }

      mapRef.current?.animateToRegion(
        {
          latitude,
          longitude,
          latitudeDelta: 0.01,
          longitudeDelta: 0.01
        },
        450
      );

      const exactLocation = await buildPickupFromCoords(latitude, longitude, targetLabel);
      if (shouldSelectDrop) {
        setDrop(exactLocation);
      } else {
        setPickup(exactLocation);
      }
    },
    [buildPickupFromCoords, quickLocationFromCoords]
  );

  const onMapPress = useCallback(
    (event) => {
      const latitude = Number(event?.nativeEvent?.coordinate?.latitude);
      const longitude = Number(event?.nativeEvent?.coordinate?.longitude);
      if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return;
      applyMapSelection(latitude, longitude);
    },
    [applyMapSelection]
  );

  const onMapPoiClick = useCallback(
    (event) => {
      const latitude = Number(event?.nativeEvent?.coordinate?.latitude);
      const longitude = Number(event?.nativeEvent?.coordinate?.longitude);
      const poiName = String(event?.nativeEvent?.name || '').trim();
      if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return;
      applyMapSelection(latitude, longitude, poiName || null);
    },
    [applyMapSelection]
  );

  useEffect(() => {
    let mounted = true;
    const bootstrapMapRegion = async () => {
      try {
        const { status } = await Location.requestForegroundPermissionsAsync();
        if (status !== 'granted') return;
        if (Platform.OS === 'android') {
          try {
            await Location.enableNetworkProviderAsync();
          } catch (_) {
            // continue with available provider
          }
        }
        const pos = (await Location.getCurrentPositionAsync({
          accuracy: Location.Accuracy.Highest
        })) || (await Location.getLastKnownPositionAsync({
          maxAge: 120000,
          requiredAccuracy: 200
        }));
        const lat = Number(pos?.coords?.latitude || 0);
        const lng = Number(pos?.coords?.longitude || 0);
        if (!mounted || !lat || !lng) return;
        const nextRegion = {
          latitude: lat,
          longitude: lng,
          latitudeDelta: 0.01,
          longitudeDelta: 0.01
        };
        setMapInitialRegion(nextRegion);
        // Force map to re-initialize with real current location region.
        setMapKey(`map_${lat}_${lng}`);
        mapRef.current?.animateToRegion(nextRegion, 650);

        gpsWatchRef.current = await Location.watchPositionAsync(
          {
            accuracy: Location.Accuracy.Balanced,
            timeInterval: 5000,
            distanceInterval: 10
          },
          (loc) => {
            if (!mounted) return;
            const nextLat = Number(loc?.coords?.latitude || 0);
            const nextLng = Number(loc?.coords?.longitude || 0);
            if (!nextLat || !nextLng) return;
            const liveRegion = {
              latitude: nextLat,
              longitude: nextLng,
              latitudeDelta: 0.01,
              longitudeDelta: 0.01
            };
            setMapInitialRegion(liveRegion);
            if (!pickupRef.current?.lat && !dropRef.current?.lat) {
              mapRef.current?.animateToRegion(liveRegion, 450);
            }
          }
        );
      } catch (_) {
        // keep default fallback region
      }
    };
    bootstrapMapRegion();
    return () => {
      mounted = false;
      if (gpsWatchRef.current) {
        gpsWatchRef.current.remove();
        gpsWatchRef.current = null;
      }
    };
  }, []);

  useEffect(() => {
    if (route?.params?.session?.token) {
      setSession(route.params.session);
    }
  }, [route?.params?.session]);

  useEffect(() => {
    const loadSession = async () => {
      if (session?.token) return;
      try {
        const raw = await AsyncStorage.getItem('customer_session_v1');
        if (!raw) return;
        const parsed = JSON.parse(raw);
        if (parsed?.token) setSession(parsed);
      } catch (e) {
        // ignore
      }
    };
    loadSession();
  }, [session?.token]);

  useEffect(() => {
    const params = route?.params;
    if (!params?.selection) return;
    const { type, location } = params.selection;
    if (type === 'pickup') {
      setPickup(location);
    } else if (type === 'drop') {
      setDrop(location);
    }
    navigation.setParams({ selection: null, selectionId: null, fromSearch: null });
  }, [route?.params?.selectionId, route?.params?.selection, navigation]);

  useEffect(() => {
    let mounted = true;
    const setCurrentLocationAsPickup = async () => {
      if (pickup || autoPickupTried) return;
      setAutoPickupTried(true);
      try {
        const pickupFromGps = await getCurrentLocationPickup();
        if (!mounted) return;
        if (!pickupFromGps) return;
        setPickup(pickupFromGps);
        mapRef.current?.animateToRegion(
          {
            latitude: Number(pickupFromGps.lat),
            longitude: Number(pickupFromGps.lng),
            latitudeDelta: 0.01,
            longitudeDelta: 0.01
          },
          650
        );
      } catch (_) {
        // ignore GPS bootstrap errors
      }
    };
    setCurrentLocationAsPickup();
    return () => {
      mounted = false;
    };
  }, [pickup, autoPickupTried, getCurrentLocationPickup]);

  useFocusEffect(
    useCallback(() => {
      if (!pickup && autoPickupTried) {
        getCurrentLocationPickup().then((pickupFromGps) => {
          if (pickupFromGps) {
            setPickup(pickupFromGps);
            mapRef.current?.animateToRegion(
              {
                latitude: Number(pickupFromGps.lat),
                longitude: Number(pickupFromGps.lng),
                latitudeDelta: 0.01,
                longitudeDelta: 0.01
              },
              650
            );
          }
        });
      }
    }, [pickup, autoPickupTried, getCurrentLocationPickup])
  );

  const pickupLabel = useMemo(() => pickup?.name || pickup?.address, [pickup]);
  const dropLabel = useMemo(() => drop?.name || drop?.address, [drop]);
  const region = useMemo(() => regionForPoints(pickup, drop), [pickup, drop]);

  useEffect(() => {
    if (!mapRef.current) return;
    // Only sync map when we actually have a pickup/drop; otherwise keep the initial view until GPS loads.
    if (!pickup?.lat && !drop?.lat) return;
    mapRef.current.animateToRegion(region, 650);
  }, [region.latitude, region.longitude, region.latitudeDelta, region.longitudeDelta, pickup?.lat, drop?.lat]);

  const onLogout = async () => {
    try {
      await AsyncStorage.multiRemove([CUSTOMER_SESSION_KEY, ACTIVE_RIDE_KEY]);
    } catch (_) {}
    setSession(null);
    navigation.reset({ index: 0, routes: [{ name: 'Login' }] });
  };

  const onMyLocation = async () => {
    const pickupFromGps = await getCurrentLocationPickup();
    if (!pickupFromGps) return;
    setPickup(pickupFromGps);
    mapRef.current?.animateToRegion(
      {
        latitude: Number(pickupFromGps.lat),
        longitude: Number(pickupFromGps.lng),
        latitudeDelta: 0.01,
        longitudeDelta: 0.01
      },
      650
    );
  };

  const onFindCab = async () => {
    if (!session?.token) {
      Alert.alert('Login required', 'Please login again to continue.');
      navigation.replace('Login');
      return;
    }
    if (!pickup || !drop) {
      Alert.alert('Missing details', 'Please select pickup and drop locations.');
      return;
    }
    setLoading(true);
    try {
      const res = await apiPost(API_BASE, '/api/maps/route', null, {
        pickup: {
          lat: pickup.lat,
          lng: pickup.lng,
          place_id: pickup.place_id,
          address: pickup.address
        },
        dropoff: {
          lat: drop.lat,
          lng: drop.lng,
          place_id: drop.place_id,
          address: drop.address
        }
      });
      const distanceKm = Number(res?.distance_km || 0);
      const durationMin = Number(res?.duration_minutes || 0);
      if (!distanceKm || !durationMin) {
        Alert.alert('Error', 'Unable to calculate distance. Try again.');
        return;
      }
      navigation.navigate('SelectVehicle', {
        pickup,
        drop,
        distance_km: distanceKm,
        duration_minutes: durationMin,
        session
      });
    } catch (e) {
      Alert.alert('Error', String(e.message || e));
    } finally {
      setLoading(false);
    }
  };

  return (
    <View style={styles.screen}>
      <View style={styles.mapWrap}>
        {mapInitialRegion ? (
          <MapView
            key={mapKey}
            ref={mapRef}
            style={styles.map}
            provider={PROVIDER_GOOGLE}
            initialRegion={mapInitialRegion}
            showsUserLocation
            showsMyLocationButton={Platform.OS === 'android'}
            onPress={onMapPress}
            onLongPress={onMapPress}
            onPoiClick={onMapPoiClick}
          >
            {pickup?.lat && pickup?.lng ? (
              <Marker coordinate={{ latitude: Number(pickup.lat), longitude: Number(pickup.lng) }} title="Pickup" />
            ) : null}
            {drop?.lat && drop?.lng ? (
              <Marker coordinate={{ latitude: Number(drop.lat), longitude: Number(drop.lng) }} title="Drop" />
            ) : null}
          </MapView>
        ) : (
          <View style={styles.mapLoading}>
            <ActivityIndicator color="#111827" />
            <Text style={styles.mapLoadingText}>Fetching current location...</Text>
          </View>
        )}

        <TouchableOpacity style={styles.myLocFab} onPress={onMyLocation} activeOpacity={0.9}>
          <Text style={styles.myLocFabText}>MY</Text>
        </TouchableOpacity>
      </View>
      <View style={styles.sheet}>
        <ScrollView
          style={styles.sheetScroll}
          contentContainerStyle={styles.sheetScrollContent}
          showsVerticalScrollIndicator
          keyboardShouldPersistTaps="handled"
        >
        <View style={styles.headerRow}>
          <Text style={styles.header}>Where are you going?</Text>
          <TouchableOpacity style={styles.logoutBtn} onPress={onLogout} activeOpacity={0.9}>
            <Text style={styles.logoutText}>Logout</Text>
          </TouchableOpacity>
        </View>
        <LocationInput
          label="Pickup Location"
          value={pickupLabel}
          placeholder="Choose pickup"
          onPress={() => {
            setMapSelectionTarget('pickup');
            navigation.navigate('LocationSearch', { type: 'pickup', placeholder: 'Enter pickup location' });
          }}
          onClear={() => setPickup(null)}
        />
        <View style={styles.mapPickerRow}>
          <TouchableOpacity
            style={[styles.mapPickerBtn, mapSelectionTarget === 'pickup' && styles.mapPickerBtnActive]}
            onPress={() => setMapSelectionTarget('pickup')}
            activeOpacity={0.9}
          >
            <Text style={[styles.mapPickerText, mapSelectionTarget === 'pickup' && styles.mapPickerTextActive]}>
              Tap map for Pickup
            </Text>
          </TouchableOpacity>
        </View>
        <View style={styles.gap} />
        <LocationInput
          label="Drop Location"
          value={dropLabel}
          placeholder="Choose drop"
          onPress={() => {
            setMapSelectionTarget('drop');
            navigation.navigate('LocationSearch', { type: 'drop', placeholder: 'Enter drop location' });
          }}
          onClear={() => setDrop(null)}
        />
        <View style={styles.mapPickerRow}>
          <TouchableOpacity
            style={[styles.mapPickerBtn, mapSelectionTarget === 'drop' && styles.mapPickerBtnActive]}
            onPress={() => setMapSelectionTarget('drop')}
            activeOpacity={0.9}
          >
            <Text style={[styles.mapPickerText, mapSelectionTarget === 'drop' && styles.mapPickerTextActive]}>
              Tap map for Drop
            </Text>
          </TouchableOpacity>
        </View>
      
        <TouchableOpacity style={styles.primaryBtn} activeOpacity={0.9} onPress={onFindCab} disabled={loading}>
          <Text style={styles.primaryBtnText}>{loading ? 'Calculating...' : 'Find Cab'}</Text>
        </TouchableOpacity>
        </ScrollView>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  screen: {
    flex: 1,
    backgroundColor: '#FFFFFF'
  },
  mapWrap: {
    flex: 1.2
  },
  map: {
    flex: 1
  },
  mapLoading: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#f3f4f6'
  },
  mapLoadingText: {
    marginTop: 8,
    fontSize: 12,
    color: '#4b5563',
    fontWeight: '600'
  },
  myLocFab: {
    position: 'absolute',
    right: 16,
    bottom: 18,
    width: 52,
    height: 52,
    borderRadius: 26,
    backgroundColor: '#FFFFFF',
    alignItems: 'center',
    justifyContent: 'center',
    zIndex: 50,
    shadowColor: '#000',
    shadowOpacity: 0.15,
    shadowRadius: 10,
    shadowOffset: { width: 0, height: 6 },
    elevation: 8
  },
  myLocFabText: {
    fontSize: 12,
    fontWeight: '900',
    color: '#111827'
  },
  sheet: {
    flex: 1,
    backgroundColor: '#FFFFFF',
    paddingHorizontal: 20,
    paddingTop: 18,
    borderTopLeftRadius: 24,
    borderTopRightRadius: 24,
    shadowColor: '#000',
    shadowOpacity: 0.06,
    shadowRadius: 10,
    shadowOffset: { width: 0, height: -2 },
    elevation: 6
  },
  sheetScroll: {
    flex: 1
  },
  sheetScrollContent: {
    paddingBottom: 24
  },
  header: {
    fontSize: 22,
    fontWeight: '800',
    color: '#111827',
    marginBottom: 16
  },
  headerRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between'
  },
  logoutBtn: {
    backgroundColor: '#F3F4F6',
    borderRadius: 12,
    paddingHorizontal: 12,
    paddingVertical: 8
  },
  logoutText: {
    color: '#111827',
    fontWeight: '800',
    fontSize: 12
  },
  gap: {
    height: 14
  },
  mapPickerRow: {
    marginTop: 8,
    alignItems: 'flex-start'
  },
  mapPickerBtn: {
    borderRadius: 10,
    borderWidth: 1,
    borderColor: '#E5E7EB',
    paddingHorizontal: 10,
    paddingVertical: 6,
    backgroundColor: '#FFFFFF'
  },
  mapPickerBtnActive: {
    borderColor: '#111827',
    backgroundColor: '#F3F4F6'
  },
  mapPickerText: {
    fontSize: 11,
    fontWeight: '700',
    color: '#6B7280'
  },
  mapPickerTextActive: {
    color: '#111827'
  },
  helper: {
    marginTop: 16,
    fontSize: 12,
    color: '#6B7280'
  },
  primaryBtn: {
    marginTop: 8,
    backgroundColor: '#FFD100',
    borderRadius: 16,
    height: 58,
    alignItems: 'center',
    justifyContent: 'center'
  },
  primaryBtnText: {
    color: '#111827',
    fontWeight: '800',
    fontSize: 15,
    letterSpacing: 0.1
  }
});








