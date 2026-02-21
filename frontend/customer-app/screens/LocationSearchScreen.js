import React, { useEffect, useState } from 'react';
import {
  Alert,
  KeyboardAvoidingView,
  Platform,
  StyleSheet,
  Text,
  TouchableOpacity,
  View
} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import * as Location from 'expo-location';
import { GooglePlacesAutocomplete } from 'react-native-google-places-autocomplete';

const GOOGLE_PLACES_API_KEY = 'AIzaSyB_WRLSV9g3v5XBF6eUZBy_Ig9X0w_4-_g';
const RECENT_KEY = 'recent_locations_v1';
const BIAS_RADIUS_METERS = 200000;

export default function LocationSearchScreen({ navigation, route }) {
  const type = route?.params?.type || 'pickup';
  const placeholder = route?.params?.placeholder || 'Enter location';
  const [recent, setRecent] = useState([]);
  const [locationBias, setLocationBias] = useState(null);
  const [query, setQuery] = useState('');
  const [searchMessage, setSearchMessage] = useState('');

  useEffect(() => {
    AsyncStorage.getItem(RECENT_KEY)
      .then((raw) => (raw ? JSON.parse(raw) : []))
      .then((items) => setRecent(Array.isArray(items) ? items : []))
      .catch(() => setRecent([]));
  }, []);

  useEffect(() => {
    let mounted = true;
    (async () => {
      const { status } = await Location.requestForegroundPermissionsAsync();
      if (status !== 'granted') return;
      const loc = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced });
      if (!mounted) return;
      setLocationBias({ lat: loc.coords.latitude, lng: loc.coords.longitude });
    })();
    return () => { mounted = false; };
  }, []);

  const saveRecent = async (item) => {
    const next = [item, ...recent.filter((r) => r.id !== item.id)].slice(0, 5);
    setRecent(next);
    try {
      await AsyncStorage.setItem(RECENT_KEY, JSON.stringify(next));
    } catch (e) {
      // ignore
    }
  };

  const onSelect = async (data, details) => {
    if (!details?.geometry?.location) {
      Alert.alert('Error', 'Unable to fetch place details.');
      return;
    }
    const location = {
      id: data.place_id,
      place_id: data.place_id,
      name: details.name || data.structured_formatting?.main_text || data.description,
      area: data.structured_formatting?.secondary_text || '',
      address: details.formatted_address,
      lat: details.geometry.location.lat,
      lng: details.geometry.location.lng
    };
    await saveRecent(location);
    navigation.navigate({
      name: 'Home',
      params: { selection: { type, location }, fromSearch: true, selectionId: Date.now() },
      merge: true
    });
  };

  const recentRows = recent.map((item) => (
    <TouchableOpacity
      key={item.id}
      style={styles.item}
      onPress={() =>
        navigation.navigate({
          name: 'Home',
          params: { selection: { type, location: item }, fromSearch: true, selectionId: Date.now() },
          merge: true
        })
      }
      activeOpacity={0.9}
    >
      <View style={styles.itemIcon}>
        <Text style={styles.itemIconText}>@</Text>
      </View>
      <View style={styles.itemText}>
        <Text style={styles.itemTitle}>{item.name}</Text>
        <Text style={styles.itemSub}>{item.area || item.address}</Text>
      </View>
    </TouchableOpacity>
  ));

  const showRecent = query.trim().length === 0;

  return (
    <KeyboardAvoidingView
      style={styles.screen}
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
    >
      <View style={styles.header}>
        <TouchableOpacity onPress={() => navigation.goBack()} style={styles.backBtn} activeOpacity={0.9}>
          <Text style={styles.backText}>{'<'} Back</Text>
        </TouchableOpacity>
        <Text style={styles.headerTitle}>{type === 'pickup' ? 'Pickup' : 'Drop'} Location</Text>
      </View>

      <GooglePlacesAutocomplete
        placeholder={placeholder}
        fetchDetails
        debounce={180}
        minLength={2}
        timeout={12000}
        enablePoweredByContainer={false}
        onFail={(error) =>
          setSearchMessage(error?.error_message || error?.message || 'Search temporarily unavailable. Try again.')
        }
        onTimeout={() => setSearchMessage('Search timeout. Check network and retry.')}
        onPress={onSelect}
        listViewDisplayed={query.trim().length > 0}
        keepResultsAfterBlur={false}
        keyboardShouldPersistTaps="handled"
        textInputProps={{
          value: query,
          onChangeText: (text) => {
            setQuery(text);
            setSearchMessage('');
          },
          autoFocus: true,
          returnKeyType: 'search',
          autoCapitalize: 'none',
          autoCorrect: false
        }}
        nearbyPlacesAPI="GooglePlacesSearch"
        GooglePlacesDetailsQuery={{
          fields: 'geometry,name,formatted_address'
        }}
        query={{
          key: GOOGLE_PLACES_API_KEY,
          language: 'en',
          components: 'country:in',
          ...(locationBias ? { location: `${locationBias.lat},${locationBias.lng}`, radius: BIAS_RADIUS_METERS } : {})
        }}
        styles={{
          container: styles.autoContainer,
          textInputContainer: styles.searchBox,
          textInput: styles.searchInput,
          listView: styles.listView,
          row: styles.row,
          description: styles.rowDescription,
          separator: styles.rowSeparator
        }}
        renderLeftButton={() => (
          <View style={styles.searchIconWrap}>
            <Text style={styles.searchIcon}>@</Text>
          </View>
        )}
        renderEmpty={() => (
          !showRecent ? <Text style={styles.statusText}>{searchMessage || 'No places found'}</Text> : null
        )}
      />

      {showRecent && recent.length > 0 ? (
        <View style={styles.recentWrap}>
          <Text style={styles.sectionTitle}>Recent locations</Text>
          {recentRows}
        </View>
      ) : null}
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  screen: {
    flex: 1,
    backgroundColor: '#FFFFFF',
    paddingHorizontal: 18,
    paddingTop: 16
  },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    marginBottom: 12
  },
  backBtn: {
    paddingVertical: 6,
    paddingRight: 10
  },
  backText: {
    fontSize: 13,
    fontWeight: '700',
    color: '#111827'
  },
  headerTitle: {
    fontSize: 18,
    fontWeight: '800',
    color: '#111827'
  },
  autoContainer: {
    flex: 0
  },
  searchBox: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#FFFFFF',
    borderRadius: 16,
    borderWidth: 1,
    borderColor: '#EEF0F3',
    paddingHorizontal: 12,
    height: 52,
    shadowColor: '#000',
    shadowOpacity: 0.04,
    shadowRadius: 6,
    shadowOffset: { width: 0, height: 3 },
    elevation: 2
  },
  searchIconWrap: {
    paddingRight: 6,
    justifyContent: 'center'
  },
  searchIcon: {
    color: '#111827',
    fontWeight: '800'
  },
  searchInput: {
    flex: 1,
    fontSize: 15,
    color: '#111827',
    fontWeight: '600'
  },
  listView: {
    backgroundColor: '#FFFFFF',
    marginTop: 8,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: '#EEF0F3',
    maxHeight: 300
  },
  row: {
    paddingVertical: 12,
    paddingHorizontal: 12
  },
  rowDescription: {
    fontSize: 14,
    color: '#111827',
    fontWeight: '600'
  },
  rowSeparator: {
    height: 1,
    backgroundColor: '#F1F2F4'
  },
  statusText: {
    marginTop: 12,
    fontSize: 12,
    color: '#6B7280'
  },
  sectionTitle: {
    marginTop: 16,
    fontSize: 12,
    color: '#111827',
    fontWeight: '800'
  },
  recentWrap: {
    marginTop: 10
  },
  item: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 12,
    borderBottomWidth: 1,
    borderBottomColor: '#F1F2F4'
  },
  itemIcon: {
    width: 32,
    height: 32,
    borderRadius: 12,
    backgroundColor: '#FFF4BF',
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: 10
  },
  itemIconText: {
    color: '#111827',
    fontWeight: '800'
  },
  itemText: {
    flex: 1
  },
  itemTitle: {
    fontSize: 15,
    fontWeight: '700',
    color: '#111827'
  },
  itemSub: {
    fontSize: 12,
    color: '#6B7280',
    marginTop: 2
  }
});
