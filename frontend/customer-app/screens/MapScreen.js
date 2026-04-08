import React, { useEffect, useRef, useState } from 'react';
import { View, Text, StyleSheet, TextInput, TouchableOpacity, Platform, Alert } from 'react-native';
import MapView, { Marker, PROVIDER_GOOGLE } from '../MapViewCompat';
import * as Location from 'expo-location';

const DEFAULT_REGION = {
  latitude: 28.6139,
  longitude: 77.2090,
  latitudeDelta: 0.08,
  longitudeDelta: 0.08
};

function formatAddress(item) {
  if (!item) return '';
  const parts = [item.name, item.street, item.city, item.region].filter(Boolean);
  return parts.join(', ');
}

export default function MapScreen() {
  const mapRef = useRef(null);
  const [pickupText, setPickupText] = useState('');
  const [dropText, setDropText] = useState('');
  const [userLocation, setUserLocation] = useState(null);

  useEffect(() => {
    (async () => {
      const { status } = await Location.requestForegroundPermissionsAsync();
      if (status !== 'granted') {
        return;
      }
      const current = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High });
      if (current?.coords) {
        setUserLocation({
          latitude: current.coords.latitude,
          longitude: current.coords.longitude
        });
      }
    })();
  }, []);

  const handleMyLocation = async () => {
    const { status } = await Location.requestForegroundPermissionsAsync();
    if (status !== 'granted') {
      Alert.alert('Location required', 'Please allow location access to use this feature.');
      return;
    }
    const current = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High });
    if (!current?.coords) return;

    const region = {
      latitude: current.coords.latitude,
      longitude: current.coords.longitude,
      latitudeDelta: 0.01,
      longitudeDelta: 0.01
    };
    mapRef.current?.animateToRegion(region, 700);

    setUserLocation({
      latitude: current.coords.latitude,
      longitude: current.coords.longitude
    });

    const addresses = await Location.reverseGeocodeAsync({
      latitude: current.coords.latitude,
      longitude: current.coords.longitude
    });
    const addressText = formatAddress(addresses?.[0]);
    if (addressText) {
      setPickupText(addressText);
    } else {
      setPickupText(`${current.coords.latitude.toFixed(5)}, ${current.coords.longitude.toFixed(5)}`);
    }
  };

  return (
    <View style={styles.container}>
      <MapView
        ref={mapRef}
        provider={PROVIDER_GOOGLE}
        style={styles.map}
        initialRegion={DEFAULT_REGION}
        showsUserLocation
        showsMyLocationButton={Platform.OS === 'android'}
      >
        {userLocation ? (
          <Marker coordinate={userLocation} title="You" />
        ) : null}
      </MapView>

      <View style={styles.inputsCard}>
        <Text style={styles.header}>Where are you going?</Text>
        <View style={styles.inputRow}>
          <View style={[styles.dot, styles.dotPickup]} />
          <TextInput
            style={styles.input}
            placeholder="Pickup location"
            placeholderTextColor="#9CA3AF"
            value={pickupText}
            onChangeText={setPickupText}
          />
        </View>
        <View style={styles.inputDivider} />
        <View style={styles.inputRow}>
          <View style={[styles.dot, styles.dotDrop]} />
          <TextInput
            style={styles.input}
            placeholder="Drop location"
            placeholderTextColor="#9CA3AF"
            value={dropText}
            onChangeText={setDropText}
          />
        </View>
      </View>

      <TouchableOpacity style={styles.myLocationBtn} onPress={handleMyLocation} activeOpacity={0.9}>
        <Text style={styles.myLocationText}>MY</Text>
      </TouchableOpacity>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#FFFFFF'
  },
  map: {
    ...StyleSheet.absoluteFillObject
  },
  inputsCard: {
    position: 'absolute',
    top: 16,
    left: 16,
    right: 16,
    backgroundColor: '#FFFFFF',
    borderRadius: 16,
    padding: 16,
    shadowColor: '#000',
    shadowOpacity: 0.08,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: 6 },
    elevation: 6
  },
  header: {
    fontSize: 18,
    fontWeight: '800',
    color: '#111827',
    marginBottom: 12
  },
  inputRow: {
    flexDirection: 'row',
    alignItems: 'center'
  },
  input: {
    flex: 1,
    height: 44,
    fontSize: 15,
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
    bottom: 24,
    width: 54,
    height: 54,
    borderRadius: 27,
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
  }
});

