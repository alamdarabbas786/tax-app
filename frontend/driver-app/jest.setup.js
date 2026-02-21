import '@testing-library/jest-native/extend-expect';

jest.mock('react-native-maps', () => {
  const React = require('react');
  const { View } = require('react-native');
  const MockMap = (props) => React.createElement(View, props, props.children);
  const Marker = (props) => React.createElement(View, props, props.children);
  return {
    __esModule: true,
    default: MockMap,
    Marker,
    Polyline: Marker,
    PROVIDER_GOOGLE: 'google'
  };
});

jest.mock('expo-constants', () => ({
  __esModule: true,
  default: {
    expoConfig: { android: { config: { googleMaps: { apiKey: 'test-key' } } } },
    manifest2: { extra: { expoClient: { android: { config: { googleMaps: { apiKey: 'test-key' } } } } } }
  }
}));

jest.mock('expo-location', () => ({
  requestForegroundPermissionsAsync: jest.fn(async () => ({ status: 'granted' })),
  requestBackgroundPermissionsAsync: jest.fn(async () => ({ status: 'granted' })),
  watchPositionAsync: jest.fn(async (_opts, cb) => {
    if (cb) cb({ coords: { latitude: 28.6139, longitude: 77.209, altitude: 0, accuracy: 5 } });
    return { remove: jest.fn() };
  }),
  Accuracy: { High: 1, Balanced: 1 }
}));

jest.mock('expo-notifications', () => ({
  setNotificationHandler: jest.fn(),
  AndroidNotificationPriority: { MAX: 'max' },
  addPushTokenListener: jest.fn(() => ({ remove: jest.fn() })),
  addNotificationReceivedListener: jest.fn(() => ({ remove: jest.fn() })),
  addNotificationResponseReceivedListener: jest.fn(() => ({ remove: jest.fn() })),
  scheduleNotificationAsync: jest.fn(async () => 'nid-1'),
  dismissNotificationAsync: jest.fn(async () => true)
}));

jest.mock('@react-native-async-storage/async-storage', () => ({
  getItem: jest.fn(async () => null),
  setItem: jest.fn(async () => null),
  removeItem: jest.fn(async () => null)
}));

