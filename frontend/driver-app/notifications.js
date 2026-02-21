import * as Notifications from 'expo-notifications';
import * as Device from 'expo-device';
import Constants from 'expo-constants';
import { Platform } from 'react-native';

const CHANNEL_ID = 'ride_request';
const RIDE_ACTION_CATEGORY_ID = 'ride_request_actions';
const ACTION_ACCEPT = 'ACCEPT_RIDE';
const ACTION_REJECT = 'REJECT_RIDE';

Notifications.setNotificationHandler({
  handleNotification: async (notification) => {
    return {
      // Always display alerts/sound so drivers don't miss urgent ride requests.
      shouldShowBanner: true,
      shouldShowList: true,
      shouldPlaySound: true,
      shouldSetBadge: false
    };
  }
});

export async function registerForPushTokenAsync() {
  // Expo Go (SDK 53+) does not support remote push notifications.
  if (Constants.appOwnership === 'expo') {
    await configureRideRequestActions();
    return { token: null, type: 'none', error: 'Use development build for remote push (Expo Go not supported)' };
  }

  if (!Device.isDevice) {
    return { token: null, type: 'none', error: 'Physical device required' };
  }

  const { status: existingStatus } = await Notifications.getPermissionsAsync();
  let finalStatus = existingStatus;
  if (existingStatus !== 'granted') {
    const { status } = await Notifications.requestPermissionsAsync();
    finalStatus = status;
  }
  if (finalStatus !== 'granted') {
    return { token: null, type: 'none', error: 'Permission not granted' };
  }

  if (Platform.OS !== 'android') {
    return { token: null, type: 'none', error: 'FCM token is supported on Android only in this app' };
  }

  let token = null;
  try {
    const deviceToken = await Notifications.getDevicePushTokenAsync();
    const raw = deviceToken?.data ?? deviceToken?.token ?? null;
    if (typeof raw === 'string') {
      token = raw.trim();
    } else if (raw && typeof raw === 'object') {
      token = String(raw.token || raw.value || '').trim();
    } else {
      token = null;
    }
  } catch (e) {
    const reason = e?.message ? String(e.message) : 'Failed to get FCM token';
    return { token: null, type: 'none', error: reason };
  }

  if (!token) {
    return { token: null, type: 'none', error: 'FCM token is empty' };
  }

  if (Platform.OS === 'android') {
    await Notifications.setNotificationChannelAsync(CHANNEL_ID, {
      name: 'Ride Requests',
      importance: Notifications.AndroidImportance.MAX,
      vibrationPattern: [0, 250, 250, 250],
      lightColor: '#FFD100',
      lockscreenVisibility: Notifications.AndroidNotificationVisibility.PUBLIC,
      enableVibrate: true,
      sound: 'ride_request.wav'
    });
  }
  await configureRideRequestActions();

  return { token, type: 'fcm', error: null };
}

export function getRideChannelId() {
  return CHANNEL_ID;
}

export function getRideActionIds() {
  return {
    categoryId: RIDE_ACTION_CATEGORY_ID,
    accept: ACTION_ACCEPT,
    reject: ACTION_REJECT
  };
}

export async function configureRideRequestActions() {
  await Notifications.setNotificationCategoryAsync(RIDE_ACTION_CATEGORY_ID, [
    {
      identifier: ACTION_ACCEPT,
      buttonTitle: 'Accept',
      options: { opensAppToForeground: true }
    },
    {
      identifier: ACTION_REJECT,
      buttonTitle: 'Reject',
      options: { opensAppToForeground: false }
    }
  ]);
}
