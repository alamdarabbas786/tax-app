import React, { useEffect, useState } from 'react';
import { NavigationContainer } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { View, ActivityIndicator, TouchableOpacity } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { MaterialCommunityIcons } from '@expo/vector-icons';
import LoginScreen from './screens/LoginScreen';
import CreateAccountScreen from './screens/CreateAccountScreen';
import DriverHomeScreen from './screens/DriverHomeScreen';
import RideTimelineScreen from './screens/RideTimelineScreen';
import VerificationPendingScreen from './screens/VerificationPendingScreen';
import LiveRideScreen from './screens/LiveRideScreen';
import NavigationScreen from './screens/NavigationScreen';
import RideCompletedScreen from './screens/RideCompletedScreen';
import EarningsHistoryScreen from './screens/EarningsHistoryScreen';
import SupportScreen from './screens/SupportScreen';
import { API_BASE } from './config';

const Stack = createNativeStackNavigator();
const SESSION_KEY = 'driver_session_v1';
const LAST_SCREEN_KEY = 'driver_last_screen_v1';
const RESUMABLE_SCREENS = new Set(['DriverHome', 'LiveRide', 'RideTimeline', 'Support', 'EarningsHistory']);
const toBool = (value) => value === true || value === 1 || value === '1';
const getDeepActiveRoute = (state) => {
  if (!state || !state.routes || typeof state.index !== 'number') return null;
  let route = state.routes[state.index] || null;
  while (route?.state && route.state.routes && typeof route.state.index === 'number') {
    route = route.state.routes[route.state.index] || route;
  }
  return route;
};

function SessionGate() {
  const [ready, setReady] = useState(false);
  const [initialRoute, setInitialRoute] = useState('Login');
  const [initialRouteParams, setInitialRouteParams] = useState({});
  const [session, setSession] = useState(null);

  useEffect(() => {
    const loadSession = async () => {
      try {
        const raw = await AsyncStorage.getItem(SESSION_KEY);
        if (raw) {
          const data = JSON.parse(raw);
          setSession(data);
          if (data?.token && data?.driver) {
            const verifiedDefaultRoute = toBool(data?.driver?.is_verified) ? 'DriverHome' : 'VerificationPending';
            const lastRaw = await AsyncStorage.getItem(LAST_SCREEN_KEY);
            const last = lastRaw ? JSON.parse(lastRaw) : null;
            const lastName = String(last?.name || '');
            const lastParams = (last && typeof last.params === 'object' && last.params) ? last.params : {};
            if (lastName === 'Navigation') {
              try {
                await AsyncStorage.removeItem(LAST_SCREEN_KEY);
              } catch (_) {
                // ignore
              }
            }
            const canResumeLast = RESUMABLE_SCREENS.has(lastName)
              && (lastName !== 'LiveRide' || !!lastParams?.ride?.id);
            if (canResumeLast) {
              setInitialRoute(lastName);
              setInitialRouteParams(lastParams);
            } else {
              setInitialRoute(verifiedDefaultRoute);
            }
          }
        }
      } catch (e) {
        // ignore
      } finally {
        setReady(true);
      }
    };
    loadSession();
  }, []);

  if (!ready) {
    return (
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center' }}>
        <ActivityIndicator size="large" />
      </View>
    );
  }

  return (
    <NavigationContainer
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
          // ignore
        }
      }}
    >
      <Stack.Navigator
        initialRouteName={initialRoute}
        screenOptions={{ headerBackVisible: true }}
      >
        <Stack.Screen name="Login" options={{ title: 'Driver Login' }}>
          {(props) => <LoginScreen {...props} apiBase={API_BASE} sessionKey={SESSION_KEY} />}
        </Stack.Screen>
        <Stack.Screen name="CreateAccount" options={{ title: 'Create Account' }}>
          {(props) => <CreateAccountScreen {...props} apiBase={API_BASE} sessionKey={SESSION_KEY} />}
        </Stack.Screen>
        <Stack.Screen name="VerificationPending" options={{ title: 'Verification Pending' }}>
          {(props) => <VerificationPendingScreen {...props} apiBase={API_BASE} sessionKey={SESSION_KEY} session={session} />}
        </Stack.Screen>
        <Stack.Screen
          name="DriverHome"
          options={({ navigation }) => ({
            title: 'Driver Home',
            headerLeft: () => (
              <TouchableOpacity
                onPress={() => navigation.navigate('Login')}
                style={{ paddingHorizontal: 8, paddingVertical: 4 }}
              >
                <MaterialCommunityIcons name="arrow-left" size={22} color="#111827" />
              </TouchableOpacity>
            ),
          })}
          initialParams={initialRoute === 'DriverHome' ? initialRouteParams : undefined}
        >
          {(props) => <DriverHomeScreen {...props} apiBase={API_BASE} sessionKey={SESSION_KEY} session={session} />}
        </Stack.Screen>
        <Stack.Screen name="LiveRide" options={{ title: 'Live Ride' }} initialParams={initialRoute === 'LiveRide' ? initialRouteParams : undefined}>
          {(props) => <LiveRideScreen {...props} apiBase={API_BASE} />}
        </Stack.Screen>
        <Stack.Screen name="Navigation" options={{ title: 'Navigation' }} initialParams={initialRoute === 'Navigation' ? initialRouteParams : undefined}>
          {(props) => <NavigationScreen {...props} apiBase={API_BASE} />}
        </Stack.Screen>
        <Stack.Screen name="RideCompleted" options={{ title: 'Ride Completed' }}>
          {(props) => <RideCompletedScreen {...props} apiBase={API_BASE} />}
        </Stack.Screen>
        <Stack.Screen name="EarningsHistory" options={{ title: 'Earnings' }} initialParams={initialRoute === 'EarningsHistory' ? initialRouteParams : undefined}>
          {(props) => <EarningsHistoryScreen {...props} apiBase={API_BASE} />}
        </Stack.Screen>
        <Stack.Screen name="RideTimeline" options={{ title: 'Ride Timeline' }} initialParams={initialRoute === 'RideTimeline' ? initialRouteParams : undefined}>
          {(props) => <RideTimelineScreen {...props} />}
        </Stack.Screen>
        <Stack.Screen name="Support" options={{ title: 'Support' }} initialParams={initialRoute === 'Support' ? initialRouteParams : undefined}>
          {(props) => <SupportScreen {...props} />}
        </Stack.Screen>
      </Stack.Navigator>
    </NavigationContainer>
  );
}

export default function App() {
  return <SessionGate />;
}






