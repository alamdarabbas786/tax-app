import React, { useEffect, useRef, useState } from 'react';
import {
  Alert,
  BackHandler,
  StatusBar,
  StyleSheet,
  Text,
  TextInput,
  TouchableOpacity,
  View,
  ScrollView,
  KeyboardAvoidingView,
  Platform,
  ActivityIndicator
} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { CommonActions, NavigationContainer, createNavigationContainerRef } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { apiGet, apiPost } from './apiClient';
import HomeScreen from './screens/HomeScreen';
import LocationSearchScreen from './screens/LocationSearchScreen';
import SelectVehicleScreen from './screens/SelectVehicleScreen';
import PaymentMethodAndOffersScreen from './screens/PaymentMethodAndOffersScreen';
import PaymentCheckoutScreen from './screens/PaymentCheckoutScreen';
import RideSearchingScreen from './screens/RideSearchingScreen';
import LiveRideScreen from './screens/LiveRideScreen';
import RideCompletedScreen from './screens/RideCompletedScreen';
import { API_BASE } from './config';

const Stack = createNativeStackNavigator();
const CUSTOMER_SESSION_KEY = 'customer_session_v1';
const ACTIVE_RIDE_KEY = 'customer_active_ride_v1';
const LAST_SCREEN_KEY = 'customer_last_screen_v1';
const RESUMABLE_SCREENS = new Set(['Home', 'SelectVehicle', 'PaymentMethodAndOffers', 'PaymentCheckout', 'RideSearching', 'LiveRide']);
const navigationRef = createNavigationContainerRef();

const getDeepActiveRoute = (state) => {
  if (!state || !state.routes || typeof state.index !== 'number') return null;
  let route = state.routes[state.index] || null;
  while (route?.state && route.state.routes && typeof route.state.index === 'number') {
    route = route.state.routes[route.state.index] || route;
  }
  return route;
};

function BootScreen({ navigation }) {
  useEffect(() => {
    let mounted = true;
    const boot = async () => {
      try {
        const raw = await AsyncStorage.getItem(CUSTOMER_SESSION_KEY);
        const session = raw ? JSON.parse(raw) : null;

        if (!session?.token) {
          if (!mounted) return;
          navigation.reset({ index: 0, routes: [{ name: 'Login' }] });
          return;
        }

        const activeRaw = await AsyncStorage.getItem(ACTIVE_RIDE_KEY);
        const active = activeRaw ? JSON.parse(activeRaw) : null;
        const rideId = active?.rideId || active?.ride_id || null;
        const apiBase = active?.apiBase || active?.api_base || API_BASE;
        const screen = String(active?.screen || '').trim();
        const pickup = active?.pickup || null;
        const drop = active?.drop || null;

        if (rideId && (screen === 'LiveRide' || screen === 'RideSearching' || screen === 'PaymentCheckout')) {
          if (!mounted) return;
          navigation.reset({
            index: 0,
            routes: [
              {
                name: screen,
                params: {
                  rideId,
                  token: session.token,
                  apiBase,
                  session,
                  pickup,
                  drop,
                },
              },
            ],
          });
          return;
        }

        const lastRaw = await AsyncStorage.getItem(LAST_SCREEN_KEY);
        const last = lastRaw ? JSON.parse(lastRaw) : null;
        const lastName = String(last?.name || '');
        const lastParams = (last && typeof last.params === 'object' && last.params) ? last.params : {};
        if (RESUMABLE_SCREENS.has(lastName)) {
          const routeParams = {
            ...(lastParams || {}),
            session,
          };
          if ((lastName === 'LiveRide' || lastName === 'RideSearching' || lastName === 'PaymentCheckout') && !routeParams.rideId) {
            routeParams.rideId = rideId || null;
          }
          if ((lastName === 'LiveRide' || lastName === 'RideSearching' || lastName === 'PaymentCheckout') && !routeParams.rideId) {
            navigation.reset({
              index: 0,
              routes: [{ name: 'Home', params: { session } }],
            });
            return;
          }
          if (!mounted) return;
          navigation.reset({
            index: 0,
            routes: [{ name: lastName, params: routeParams }],
          });
          return;
        }

        if (!mounted) return;
        navigation.reset({
          index: 0,
          routes: [{ name: 'Home', params: { session } }],
        });
      } catch (_) {
        if (!mounted) return;
        navigation.reset({ index: 0, routes: [{ name: 'Login' }] });
      }
    };
    boot();
    return () => {
      mounted = false;
    };
  }, [navigation]);

  return (
    <View style={[styles.screen, { alignItems: 'center', justifyContent: 'center' }]}>
      <ActivityIndicator color="#111827" />
      <Text style={{ marginTop: 10, color: '#6B7280', fontWeight: '700' }}>Loading...</Text>
    </View>
  );
}

function LoginScreen({ navigation }) {
  const [phone, setPhone] = useState('');
  const [loading, setLoading] = useState(false);

  const phoneValid = /^\d{10}$/.test(phone.trim());

  const onContinue = async () => {
    if (!phoneValid) {
      Alert.alert('Invalid number', 'Enter a valid 10-digit mobile number');
      return;
    }
    setLoading(true);
    try {
      const check = await apiPost(API_BASE, '/api/check-mobile', null, { phone });
      if (!check?.exists) {
        Alert.alert(
          'Mobile number not found',
          'Please create an account.',
          [{ text: 'OK', onPress: () => navigation.navigate('Register', { phone }) }]
        );
        return;
      }
      await apiPost(API_BASE, '/api/send-otp', null, { phone });
      navigation.navigate('Otp', { phone });
    } catch (e) {
      Alert.alert('Error', String(e.message || e));
    } finally {
      setLoading(false);
    }
  };

  return (
    <View style={styles.screen}>
      <StatusBar barStyle="dark-content" />
      <View style={styles.header}>
        <View style={styles.brandDot} />
        <Text style={styles.brand}>QuickGo</Text>
      </View>

      <View style={styles.hero}>
        <Text style={styles.title}>Ride Fast.{"\n"}Reach Safe.</Text>
        <Text style={styles.subtitle}>Sign in to book a ride in minutes</Text>
      </View>

      <View style={styles.card}>
        <Text style={styles.label}>Mobile Number</Text>
        <View style={styles.inputRow}>
          <Text style={styles.countryCode}>+91</Text>
          <TextInput
            style={styles.input}
            placeholder="Enter your phone"
            placeholderTextColor="#9aa3ad"
            keyboardType="phone-pad"
            value={phone}
            onChangeText={setPhone}
            maxLength={10}
          />
        </View>

        <TouchableOpacity style={styles.primaryBtn} activeOpacity={0.9} onPress={onContinue} disabled={loading}>
          <Text style={styles.primaryBtnText}>{loading ? 'Please wait...' : 'Continue'}</Text>
        </TouchableOpacity>

        <TouchableOpacity
          style={styles.linkBtn}
          activeOpacity={0.9}
          onPress={() => navigation.navigate('Register', { phone })}
        >
          <Text style={styles.linkText}>Create an account</Text>
        </TouchableOpacity>

        <Text style={styles.tnc}>By continuing, you agree to our Terms and Privacy Policy</Text>
      </View>

      <View style={styles.footer}>
        <Text style={styles.footerText}>Need help?</Text>
        <Text style={styles.footerLink}>Contact Support</Text>
      </View>
    </View>
  );
}

function OtpScreen({ route, navigation }) {
  const phone = route?.params?.phone || '';

  const [otpDigits, setOtpDigits] = useState(['', '', '', '']);
  const otpRefs = [useRef(null), useRef(null), useRef(null), useRef(null)];

  const setOtpAt = (index, value) => {
    const digit = value.replace(/\D/g, '').slice(-1);
    const next = [...otpDigits];
    next[index] = digit;
    setOtpDigits(next);
    if (digit && index < 3) {
      otpRefs[index + 1].current?.focus();
    }
  };

  const verifyOtp = async () => {
    const otp = otpDigits.join('');
    if (otp.length !== 4) {
      Alert.alert('Error', 'Enter 4-digit OTP');
      return;
    }

    try {
      const res = await apiPost(API_BASE, '/api/auth/verify-otp', null, {
        phone,
        otp,
        role: 'customer',
        full_name: 'Customer'
      });

      if (!res?.token) {
        Alert.alert('Error', res?.message || 'Login failed');
        return;
      }

      navigation.replace('Home', {
        session: {
          token: res.token,
          customer_id: res.subject_id || null,
          phone
        }
      });
      await AsyncStorage.setItem(
        CUSTOMER_SESSION_KEY,
        JSON.stringify({
          token: res.token,
          customer_id: res.subject_id || null,
          phone
        })
      );
    } catch (e) {
      Alert.alert('Error', String(e.message || e));
    }
  };

  return (
    <View style={styles.screen}>
      <View style={styles.hero}>
        <Text style={styles.title}>Enter OTP</Text>
        <Text style={styles.subtitleSmall}>We sent a 4-digit code to +91 {phone || 'XXXXXXXXXX'}</Text>
      </View>

      <View style={styles.card}>
        <View style={styles.otpRow}>
          {otpDigits.map((digit, i) => (
            <TextInput
              key={i}
              ref={otpRefs[i]}
              style={styles.otpBox}
              keyboardType="number-pad"
              maxLength={1}
              value={digit}
              onChangeText={(val) => setOtpAt(i, val)}
            />
          ))}
        </View>

        <TouchableOpacity style={styles.primaryBtn} activeOpacity={0.9} onPress={verifyOtp}>
          <Text style={styles.primaryBtnText}>Verify & Login</Text>
        </TouchableOpacity>
      </View>
    </View>
  );
}

function RegisterScreen({ route, navigation }) {
  const phone = route?.params?.phone || '';
  const [phoneNumber, setPhoneNumber] = useState(phone);
  const [fullName, setFullName] = useState('');
  const [email, setEmail] = useState('');
  const [city, setCity] = useState('');
  const [pinCode, setPinCode] = useState('');
  const [gender, setGender] = useState('');
  const [agreed, setAgreed] = useState(false);
  const [loading, setLoading] = useState(false);

  const emailValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim());
  const pinValid = /^\d{6}$/.test(pinCode.trim());
  const phoneValid = /^\d{10}$/.test(phoneNumber.trim());
  const formValid =
    fullName.trim().length > 1 &&
    phoneValid &&
    emailValid &&
    city.trim().length > 1 &&
    pinValid &&
    agreed;

  const onRegister = async () => {
    if (!formValid) return;
    setLoading(true);
    try {
      await apiPost(API_BASE, '/api/customer/register', null, {
        full_name: fullName,
        phone: phoneNumber,
        email,
        city,
        pin_code: pinCode,
        gender
      });

      await apiPost(API_BASE, '/api/auth/request-otp', null, {
        phone: phoneNumber,
        role: 'customer'
      });
      const login = await apiPost(API_BASE, '/api/auth/verify-otp', null, {
        phone: phoneNumber,
        otp: '1234',
        role: 'customer',
        full_name: fullName || 'Customer',
        email
      });
      if (!login?.token) {
        throw new Error('Registration succeeded but login failed');
      }
      const session = {
        token: login.token,
        customer_id: login.subject_id || null,
        phone: phoneNumber
      };
      await AsyncStorage.setItem(CUSTOMER_SESSION_KEY, JSON.stringify(session));
      navigation.replace('Home', { session });
    } catch (e) {
      Alert.alert('Error', String(e.message || e));
    } finally {
      setLoading(false);
    }
  };

  return (
    <KeyboardAvoidingView style={styles.screen} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView contentContainerStyle={styles.regScroll} keyboardShouldPersistTaps="handled">
      <View style={styles.hero}>
        <Text style={styles.title}>Create Account</Text>
        <Text style={styles.subtitleSmall}>Complete your profile to continue</Text>
      </View>

      <View style={[styles.card, styles.regCard]}>
        <Text style={styles.regLabel}>Full Name</Text>
        <TextInput
          style={styles.regInputField}
          placeholder="Your full name"
          placeholderTextColor="#9aa3ad"
          value={fullName}
          onChangeText={setFullName}
        />

        <Text style={styles.regLabel}>Mobile Number</Text>
        <TextInput
          style={[styles.regInputField]}
          value={phoneNumber}
          placeholder="Mobile number"
          placeholderTextColor="#9aa3ad"
          keyboardType="number-pad"
          maxLength={10}
          onChangeText={(v) => setPhoneNumber(v.replace(/\\D/g, "").slice(0, 10))}
        />

        <Text style={styles.regLabel}>Email Address</Text>
        <TextInput
          style={styles.regInputField}
          placeholder="you@example.com"
          placeholderTextColor="#9aa3ad"
          value={email}
          onChangeText={setEmail}
          keyboardType="email-address"
          autoCapitalize="none"
        />

        <Text style={styles.regLabel}>City</Text>
        <TextInput
          style={styles.regInputField}
          placeholder="Your city"
          placeholderTextColor="#9aa3ad"
          value={city}
          onChangeText={setCity}
        />

        <Text style={styles.regLabel}>Pin Code</Text>
        <TextInput
          style={styles.regInputField}
          placeholder="6-digit pin"
          placeholderTextColor="#9aa3ad"
          value={pinCode}
          onChangeText={setPinCode}
          keyboardType="number-pad"
          maxLength={6}
        />

        <Text style={styles.regLabel}>Gender (Optional)</Text>
        <View style={styles.regRadioRow}>
          {['Male', 'Female', 'Other'].map((g) => (
            <TouchableOpacity
              key={g}
              style={[styles.radioBtn, gender === g && styles.radioBtnActive]}
              onPress={() => setGender(g)}
              activeOpacity={0.9}
            >
              <View style={[styles.radioDot, gender === g && styles.radioDotActive]} />
              <Text style={styles.radioText}>{g}</Text>
            </TouchableOpacity>
          ))}
        </View>

        <TouchableOpacity style={styles.regCheckboxRow} activeOpacity={0.9} onPress={() => setAgreed(!agreed)}>
          <View style={[styles.checkboxBox, agreed && styles.checkboxBoxChecked]}>
            {agreed && <View style={styles.checkboxTick} />}
          </View>
          <Text style={styles.checkboxText}>I agree to Terms & Privacy Policy</Text>
        </TouchableOpacity>

        <TouchableOpacity
          style={[styles.regPrimaryBtn, !formValid && styles.primaryBtnDisabled]}
          activeOpacity={0.9}
          disabled={!formValid || loading}
          onPress={onRegister}
        >
          <Text style={styles.primaryBtnText}>{loading ? 'Creating...' : 'Create Account'}</Text>
        </TouchableOpacity>
      </View>
          </ScrollView>
    </KeyboardAvoidingView>

  );
}

export default function App() {
  const cancelNoticeShownRef = useRef(false);

  useEffect(() => {
    let alive = true;
    let timer;

    const rideNotFound = (error) => String(error?.message || '').toLowerCase().includes('ride not found');

    const navigateHome = (session) => {
      if (!navigationRef.isReady()) return;
      navigationRef.dispatch(
        CommonActions.reset({
          index: 0,
          routes: [{ name: 'Home', params: { session } }],
        })
      );
    };

    const monitorRideCancellation = async () => {
      if (!alive || !navigationRef.isReady()) return;
      try {
        const sessionRaw = await AsyncStorage.getItem(CUSTOMER_SESSION_KEY);
        const session = sessionRaw ? JSON.parse(sessionRaw) : null;
        const token = String(session?.token || '');
        if (!token) {
          cancelNoticeShownRef.current = false;
          return;
        }

        const activeRaw = await AsyncStorage.getItem(ACTIVE_RIDE_KEY);
        const active = activeRaw ? JSON.parse(activeRaw) : null;
        const rideId = String(active?.rideId || active?.ride_id || '').trim();
        if (!rideId) {
          cancelNoticeShownRef.current = false;
          return;
        }

        const baseUrl = String(active?.apiBase || active?.api_base || API_BASE);
        const res = await apiGet(baseUrl, `/api/rides/${rideId}`, token);
        const ride = res?.ride || null;
        const status = String(ride?.status || '').toLowerCase().trim();
        if (status !== 'cancelled') {
          return;
        }
        if (cancelNoticeShownRef.current) {
          return;
        }
        cancelNoticeShownRef.current = true;
        await AsyncStorage.removeItem(ACTIVE_RIDE_KEY);
        const cancelledBy = String(ride?.cancelled_by || '').toLowerCase().trim();
        const cancelReason = String(ride?.cancel_reason || '').toLowerCase().trim();
        const isDriverCancelled = cancelledBy === 'driver' || cancelReason.includes('driver');
        const isAdminCancelled = cancelledBy === 'admin' || cancelReason.includes('admin');
        const cancelMsg = isAdminCancelled
          ? 'Admin cancelled the ride request.'
          : (isDriverCancelled ? 'Cancelled request by driver.' : 'Ride cancelled.');
        Alert.alert('Ride Update', cancelMsg, [
          {
            text: 'OK',
            onPress: () => {
              navigateHome(session);
              cancelNoticeShownRef.current = false;
            },
          },
        ]);
      } catch (e) {
        if (!alive) return;
        const activeRaw = await AsyncStorage.getItem(ACTIVE_RIDE_KEY);
        const active = activeRaw ? JSON.parse(activeRaw) : null;
        const rideId = String(active?.rideId || active?.ride_id || '').trim();
        if (!rideId) {
          cancelNoticeShownRef.current = false;
          return;
        }
        if (rideNotFound(e)) {
          if (cancelNoticeShownRef.current) return;
          cancelNoticeShownRef.current = true;
          await AsyncStorage.removeItem(ACTIVE_RIDE_KEY);
          const sessionRaw = await AsyncStorage.getItem(CUSTOMER_SESSION_KEY);
          const session = sessionRaw ? JSON.parse(sessionRaw) : null;
          Alert.alert('Ride Update', 'Ride was cancelled.', [
            {
              text: 'OK',
              onPress: () => {
                navigateHome(session);
                cancelNoticeShownRef.current = false;
              },
            },
          ]);
        }
      }
    };

    monitorRideCancellation();
    timer = setInterval(monitorRideCancellation, 3000);
    return () => {
      alive = false;
      if (timer) clearInterval(timer);
    };
  }, []);

  return (
    <NavigationContainer
      ref={navigationRef}
      onStateChange={async (state) => {
        try {
          const route = getDeepActiveRoute(state);
          if (!route?.name || !RESUMABLE_SCREENS.has(route.name)) return;
          await AsyncStorage.setItem(
            LAST_SCREEN_KEY,
            JSON.stringify({
              name: route.name,
              params: route.params || {},
              ts: Date.now(),
            })
          );
        } catch (_) {
          // ignore route persistence errors
        }
      }}
    >
      <Stack.Navigator>
        <Stack.Screen name="Boot" component={BootScreen} options={{ headerShown: false }} />
        <Stack.Screen name="Login" component={LoginScreen} options={{ headerShown: false }} />
        <Stack.Screen name="Otp" component={OtpScreen} options={{ title: '', headerBackTitleVisible: false }} />
        <Stack.Screen name="Register" component={RegisterScreen} options={{ title: '', headerBackTitleVisible: false }} />
        <Stack.Screen name="Home" component={HomeScreen} options={{ title: '', headerBackVisible: true }} />
        <Stack.Screen name="SelectVehicle" component={SelectVehicleScreen} options={{ title: "", headerBackTitleVisible: false }} />
        <Stack.Screen name="PaymentMethodAndOffers" component={PaymentMethodAndOffersScreen} options={{ title: "", headerBackTitleVisible: false }} />
        <Stack.Screen name="PaymentCheckout" component={PaymentCheckoutScreen} options={{ title: "", headerBackVisible: true }} />
        <Stack.Screen name="RideSearching" component={RideSearchingScreen} options={{ title: "", headerBackVisible: true }} />
        <Stack.Screen name="LiveRide" component={LiveRideScreen} options={{ title: "", headerBackVisible: true }} />
        <Stack.Screen name="RideCompleted" component={RideCompletedScreen} options={{ title: "", headerBackVisible: true }} />

        <Stack.Screen name="LocationSearch" component={LocationSearchScreen} options={{ title: '', headerBackTitleVisible: false }} />
      </Stack.Navigator>
    </NavigationContainer>
  );
}

const styles = StyleSheet.create({
  screen: {
    flex: 1,
    backgroundColor: '#FFFFFF',
    paddingHorizontal: 24,
    paddingTop: 30
  },
  header: {
    flexDirection: 'row',
    alignItems: 'center'
  },
  brandDot: {
    width: 12,
    height: 12,
    borderRadius: 6,
    backgroundColor: '#FFD100',
    marginRight: 8
  },
  brand: {
    fontSize: 17,
    fontWeight: '700',
    color: '#111827'
  },
  hero: {
    marginTop: 24,
    marginBottom: 18
  },
  title: {
    fontSize: 30,
    lineHeight: 36,
    fontWeight: '800',
    letterSpacing: -0.3,
    color: '#111827'
  },
  subtitle: {
    marginTop: 8,
    fontSize: 12,
    lineHeight: 17,
    color: '#6B7280'
  },
  subtitleSmall: {
    fontSize: 12,
    color: '#6B7280',
    marginTop: 6
  },
  card: {
    backgroundColor: '#FFFFFF',
    borderRadius: 20,
    padding: 20,
    paddingTop: 18,
    shadowColor: '#000',
    shadowOpacity: 0.05,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: 6 },
    elevation: 3
  },
  regCard: {
    paddingBottom: 18
  },
  regScroll: {
    paddingBottom: 24
  },
  label: {
    fontSize: 11,
    color: '#6B7280',
    marginBottom: 6,
    marginTop: 10,
    fontWeight: '700',
    letterSpacing: 0.3
  },
  inputRow: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#F3F4F6',
    borderRadius: 16,
    paddingHorizontal: 12,
    height: 58
  },
  countryCode: {
    fontSize: 15,
    fontWeight: '800',
    color: '#111827',
    marginRight: 10
  },
  input: {
    flex: 1,
    fontSize: 15,
    color: '#111827',
    fontWeight: '600'
  },
  inputDisabled: {
    color: '#9AA3AD'
  },
  primaryBtn: {
    marginTop: 16,
    backgroundColor: '#FFD100',
    borderRadius: 16,
    height: 58,
    alignItems: 'center',
    justifyContent: 'center'
  },
  primaryBtnDisabled: {
    opacity: 0.5
  },
  primaryBtnText: {
    color: '#111827',
    fontWeight: '800',
    fontSize: 15,
    letterSpacing: 0.1
  },
  linkBtn: {
    marginTop: 10,
    alignSelf: 'flex-end'
  },
  linkText: {
    fontSize: 12,
    color: '#111827',
    fontWeight: '700'
  },
  otpRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    marginTop: 10,
    marginBottom: 6
  },
  otpBox: {
    width: 52,
    height: 58,
    borderRadius: 12,
    backgroundColor: '#F3F4F6',
    textAlign: 'center',
    fontSize: 17,
    fontWeight: '700',
    color: '#111827'
  },
  regLabel: {
    fontSize: 10.5,
    color: '#6B7280',
    marginBottom: 6,
    marginTop: 14,
    fontWeight: '700',
    letterSpacing: 0.35
  },
  regInputField: {
    backgroundColor: '#F3F4F6',
    borderRadius: 14,
    paddingHorizontal: 14,
    height: 58,
    fontSize: 15,
    color: '#111827',
    fontWeight: '600'
  },
  regRadioRow: {
    flexDirection: 'row',
    gap: 10,
    marginTop: 6
  },
  regCheckboxRow: {
    flexDirection: 'row',
    alignItems: 'center',
    marginTop: 18
  },
  regPrimaryBtn: {
    marginTop: 18,
    backgroundColor: '#FFD100',
    borderRadius: 16,
    height: 58,
    alignItems: 'center',
    justifyContent: 'center'
  },
  radioBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 8,
    paddingHorizontal: 12,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: '#E5E7EB'
  },
  radioBtnActive: {
    borderColor: '#111827'
  },
  radioDot: {
    width: 10,
    height: 10,
    borderRadius: 5,
    borderWidth: 1.5,
    borderColor: '#111827',
    marginRight: 8
  },
  radioDotActive: {
    backgroundColor: '#111827'
  },
  radioText: {
    fontSize: 12,
    fontWeight: '700',
    color: '#111827'
  },
  checkboxBox: {
    width: 18,
    height: 18,
    borderRadius: 4,
    borderWidth: 1.5,
    borderColor: '#111827',
    marginRight: 10,
    alignItems: 'center',
    justifyContent: 'center'
  },
  checkboxBoxChecked: {
    backgroundColor: '#111827'
  },
  checkboxTick: {
    width: 8,
    height: 8,
    backgroundColor: '#FFD100',
    borderRadius: 2
  },
  checkboxText: {
    fontSize: 12,
    color: '#111827',
    fontWeight: '600'
  },
  tnc: {
    marginTop: 14,
    fontSize: 10,
    lineHeight: 14,
    color: '#9AA3AD',
    textAlign: 'center'
  },
  footer: {
    alignItems: 'center',
    marginTop: 16
  },
  footerText: {
    fontSize: 11,
    color: '#6B7280'
  },
  footerLink: {
    marginTop: 4,
    fontSize: 12,
    color: '#111827',
    fontWeight: '700'
  }
});
















