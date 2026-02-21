import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
  Alert,
  FlatList,
  Platform,
  StyleSheet,
  Text,
  TextInput,
  TouchableOpacity,
  View,
  ActivityIndicator
} from 'react-native';
import MapView, { Marker, PROVIDER_GOOGLE } from 'react-native-maps';
import * as Location from 'expo-location';
import { apiPost } from '../apiClient';

const API_BASE = 'http://192.168.1.44:3000';
const DEFAULT_REGION = {
  latitude: 28.6139,
  longitude: 77.2090,
  latitudeDelta: 0.08,
  longitudeDelta: 0.08
};

function normalizePoint(point) {
  if (!point || typeof point !== 'object') return null;
  const latRaw = point.lat ?? point.latitude;
  const lngRaw = point.lng ?? point.longitude;
  const lat = Number(latRaw);
  const lng = Number(lngRaw);
  const address =
    point.address ||
    point.formatted_address ||
    point.name ||
    '';
  return {
    ...point,
    lat: Number.isFinite(lat) ? lat : null,
    lng: Number.isFinite(lng) ? lng : null,
    address: String(address || ''),
    name: String(point.name || '')
  };
}

function regionForPoints(pickup, drop) {
  if (!pickup?.lat || !pickup?.lng || !drop?.lat || !drop?.lng) return DEFAULT_REGION;
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

function formatAddress(item) {
  if (!item) return '';
  const parts = [item.name, item.street, item.city, item.region].filter(Boolean);
  return parts.join(', ');
}

export default function SelectVehicleScreen({ navigation, route }) {
  const mapRef = useRef(null);
  const { pickup, drop, distance_km, duration_minutes, session } = route?.params || {};
  const initialDropRef = useRef(normalizePoint(drop));
  const [pickupPoint, setPickupPoint] = useState(normalizePoint(pickup));
  const [dropPoint, setDropPoint] = useState(initialDropRef.current);
  const [pickupText, setPickupText] = useState(normalizePoint(pickup)?.address || '');
  const [dropText, setDropText] = useState(normalizePoint(drop)?.address || '');
  const [userLocation, setUserLocation] = useState(null);
  const [vehicleOptions, setVehicleOptions] = useState([]);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    const nextPickup = normalizePoint(pickup);
    const nextDrop = normalizePoint(drop);
    setPickupPoint(nextPickup);
    // Keep drop exactly as selected on Home. Do not override with fallback/current location.
    if (nextDrop?.lat && nextDrop?.lng) {
      initialDropRef.current = nextDrop;
      setDropPoint(nextDrop);
    } else if (initialDropRef.current?.lat && initialDropRef.current?.lng) {
      setDropPoint(initialDropRef.current);
    } else {
      setDropPoint(nextDrop);
    }
    setPickupText(nextPickup?.address || '');
    setDropText(
      (nextDrop?.address && nextDrop?.lat && nextDrop?.lng)
        ? nextDrop.address
        : (initialDropRef.current?.address || '')
    );
  }, [pickup, drop]);

  const region = useMemo(() => regionForPoints(pickupPoint, dropPoint), [pickupPoint, dropPoint]);
  const pickupDisplayText = pickupPoint?.address || pickupPoint?.name || pickupText || '';
  const dropDisplayText = dropPoint?.address || dropPoint?.name || dropText || '';

  useEffect(() => {
    let mounted = true;
    const loadPricing = async () => {
      setLoading(true);
      try {
        const distanceKm = Number(distance_km || 5);
        const durationMin = Number(duration_minutes || 12);
        const res = await apiPost(API_BASE, '/api/fare/estimate', null, {
          distance_km: distanceKm,
          duration_minutes: durationMin
        });
        const sorted = (res?.vehicle_options || [])
          .map((v) => ({
            key: v.vehicle_type,
            label: v.vehicle_type
              .replace('_', ' ')
              .replace(/\b\w/g, (c) => c.toUpperCase()),
            fare: v.fare
          }))
          .sort((a, b) => Number(a.fare || 0) - Number(b.fare || 0));
        if (mounted) setVehicleOptions(sorted);
      } catch (e) {
        if (mounted) setVehicleOptions([]);
      } finally {
        if (mounted) setLoading(false);
      }
    };
    loadPricing();
    return () => { mounted = false; };
  }, [distance_km, duration_minutes]);

  const requestLocation = async () => {
    const { status } = await Location.requestForegroundPermissionsAsync();
    if (status !== 'granted') {
      Alert.alert('Location required', 'Please allow location access to use this feature.');
      return null;
    }
    const current = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High });
    return current?.coords || null;
  };

  const handleMyLocation = async () => {
    const coords = await requestLocation();
    if (!coords) return;

    const newRegion = {
      latitude: coords.latitude,
      longitude: coords.longitude,
      latitudeDelta: 0.01,
      longitudeDelta: 0.01
    };
    mapRef.current?.animateToRegion(newRegion, 700);
    setUserLocation({ latitude: coords.latitude, longitude: coords.longitude });

    const addresses = await Location.reverseGeocodeAsync({
      latitude: coords.latitude,
      longitude: coords.longitude
    });
    const addressText = formatAddress(addresses?.[0]);
    const nextPickup = {
      ...(pickupPoint || {}),
      name: addressText || 'Current location',
      address: addressText || `${coords.latitude.toFixed(5)}, ${coords.longitude.toFixed(5)}`,
      lat: coords.latitude,
      lng: coords.longitude
    };
    setPickupPoint(normalizePoint(nextPickup));
    if (addressText) {
      setPickupText(addressText);
    } else {
      setPickupText(`${coords.latitude.toFixed(5)}, ${coords.longitude.toFixed(5)}`);
    }
  };

  const onSelectVehicle = (vehicle) => {
    if (!pickupPoint?.lat || !pickupPoint?.lng || !dropPoint?.lat || !dropPoint?.lng) {
      Alert.alert('Missing location', 'Please select pickup and drop locations again.');
      return;
    }
    const normalizedPickup = normalizePoint(pickupPoint);
    const normalizedDrop = normalizePoint(dropPoint);
    navigation.navigate('PaymentMethodAndOffers', {
      vehicle,
      fare: Number(vehicle.fare || 0),
      pickupText: pickupDisplayText,
      dropText: dropDisplayText,
      pickup: normalizedPickup,
      drop: normalizedDrop,
      distance_km,
      duration_minutes,
      session,
      apiBase: API_BASE
    });
  };

  const renderVehicle = ({ item }) => (
    <TouchableOpacity style={styles.vehicleRow} activeOpacity={0.9} onPress={() => onSelectVehicle(item)}>
      <View style={styles.vehicleIcon}>
        <Text style={styles.vehicleIconText}>{item.label.charAt(0)}</Text>
      </View>
      <View style={styles.vehicleInfo}>
        <Text style={styles.vehicleName}>{item.label}</Text>
        <Text style={styles.vehicleFare}>Rs {item.fare ?? '--'}</Text>
      </View>
      <TouchableOpacity style={styles.selectBtn} activeOpacity={0.9} onPress={() => onSelectVehicle(item)}>
        <Text style={styles.selectBtnText}>Select</Text>
      </TouchableOpacity>
    </TouchableOpacity>
  );

  return (
    <View style={styles.screen}>
      <View style={styles.mapWrap}>
        <MapView
          ref={mapRef}
          style={styles.map}
          provider={PROVIDER_GOOGLE}
          initialRegion={DEFAULT_REGION}
          region={region}
          showsUserLocation
          showsMyLocationButton={Platform.OS === 'android'}
        >
          {Number.isFinite(Number(pickupPoint?.lat)) && Number.isFinite(Number(pickupPoint?.lng)) ? (
            <Marker coordinate={{ latitude: Number(pickupPoint.lat), longitude: Number(pickupPoint.lng) }} title="Pickup" />
          ) : null}
          {Number.isFinite(Number(dropPoint?.lat)) && Number.isFinite(Number(dropPoint?.lng)) ? (
            <Marker coordinate={{ latitude: Number(dropPoint.lat), longitude: Number(dropPoint.lng) }} title="Drop" />
          ) : null}
          {userLocation ? (
            <Marker coordinate={userLocation} title="You" />
          ) : null}
        </MapView>

        <View style={styles.inputCard}>
          <View style={styles.inputRow}>
            <View style={[styles.dot, styles.dotPickup]} />
            <TextInput
              style={styles.input}
              placeholder="Pickup location"
              placeholderTextColor="#9CA3AF"
              value={pickupDisplayText}
              editable={false}
            />
          </View>
          <View style={styles.inputDivider} />
          <View style={styles.inputRow}>
            <View style={[styles.dot, styles.dotDrop]} />
            <TextInput
              style={styles.input}
              placeholder="Drop location"
              placeholderTextColor="#9CA3AF"
              value={dropDisplayText}
              editable={false}
            />
          </View>
        </View>

        <TouchableOpacity style={styles.myLocationBtn} onPress={handleMyLocation} activeOpacity={0.9}>
          <Text style={styles.myLocationText}>MY</Text>
        </TouchableOpacity>
      </View>

      <View style={styles.listWrap}>
        <Text style={styles.sectionTitle}>Select a vehicle</Text>
        {loading ? (
          <View style={styles.loadingRow}>
            <ActivityIndicator color="#111827" />
            <Text style={styles.loadingText}>Calculating fares...</Text>
          </View>
        ) : null}
        <FlatList
          data={vehicleOptions}
          keyExtractor={(item) => item.key}
          renderItem={renderVehicle}
          contentContainerStyle={styles.listContent}
          showsVerticalScrollIndicator={false}
        />
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
    flex: 1.1
  },
  map: {
    flex: 1
  },
  inputCard: {
    position: 'absolute',
    top: 14,
    left: 14,
    right: 14,
    backgroundColor: '#FFFFFF',
    borderRadius: 16,
    padding: 12,
    shadowColor: '#000',
    shadowOpacity: 0.08,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: 6 },
    elevation: 6
  },
  inputRow: {
    flexDirection: 'row',
    alignItems: 'center'
  },
  input: {
    flex: 1,
    height: 44,
    fontSize: 14,
    color: '#111827'
  },
  dot: {
    width: 10,
    height: 10,
    borderRadius: 5,
    marginRight: 10
  },
  dotPickup: {
    backgroundColor: '#16A34A'
  },
  dotDrop: {
    backgroundColor: '#EF4444'
  },
  inputDivider: {
    height: 1,
    backgroundColor: '#E5E7EB',
    marginVertical: 8
  },
  myLocationBtn: {
    position: 'absolute',
    right: 16,
    bottom: 18,
    width: 52,
    height: 52,
    borderRadius: 26,
    backgroundColor: '#FFFFFF',
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: '#000',
    shadowOpacity: 0.15,
    shadowRadius: 10,
    shadowOffset: { width: 0, height: 6 },
    elevation: 8
  },
  myLocationText: {
    fontSize: 12,
    fontWeight: '800',
    color: '#111827'
  },
  listWrap: {
    flex: 1,
    paddingHorizontal: 16,
    paddingTop: 12
  },
  sectionTitle: {
    fontSize: 16,
    fontWeight: '800',
    color: '#111827',
    marginBottom: 8
  },
  listContent: {
    paddingBottom: 16
  },
  loadingRow: {
    flexDirection: 'row',
    alignItems: 'center',
    marginBottom: 8
  },
  loadingText: {
    marginLeft: 8,
    fontSize: 12,
    color: '#6B7280'
  },
  vehicleRow: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#FFFFFF',
    borderRadius: 14,
    padding: 12,
    borderWidth: 1,
    borderColor: '#F0F1F3',
    marginBottom: 10
  },
  vehicleIcon: {
    width: 40,
    height: 40,
    borderRadius: 12,
    backgroundColor: '#FFF4BF',
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: 12
  },
  vehicleIconText: {
    fontSize: 16,
    fontWeight: '800',
    color: '#111827'
  },
  vehicleInfo: {
    flex: 1
  },
  vehicleName: {
    fontSize: 15,
    fontWeight: '800',
    color: '#111827'
  },
  vehicleFare: {
    fontSize: 12,
    color: '#6B7280',
    marginTop: 4
  },
  selectBtn: {
    paddingHorizontal: 14,
    paddingVertical: 8,
    borderRadius: 12,
    backgroundColor: '#FFD100'
  },
  selectBtnText: {
    fontSize: 12,
    fontWeight: '800',
    color: '#111827'
  }
});







