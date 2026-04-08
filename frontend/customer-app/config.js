import { Platform, NativeModules } from 'react-native';

<<<<<<< HEAD
const PROD_API = 'https://tax-app-production-3500.up.railway.app';

function detectDevHost() {
  if (!__DEV__) return null;

  const scriptURL = NativeModules?.SourceCode?.scriptURL || '';
  const match = scriptURL.match(/^https?:\/\/([^/:]+)(?::\d+)?\//i);
  return match ? match[1] : null;
}

function getDevApi() {
  const host = detectDevHost();

  if (host) {
    return `http://${host}:3000`;
  }

  return Platform.OS === 'android'
    ? 'http://10.0.2.2:3000'
    : 'http://127.0.0.1:3000';
}

//  FINAL EXPORT
export const API_BASE =
  (process.env.EXPO_PUBLIC_API_BASE || '').trim() ||
  PROD_API; //  ALWAYS Railway use karega
=======
export const API_BASE = (process.env.EXPO_PUBLIC_API_BASE || '').trim() || DEFAULT_API_BASE;
>>>>>>> 029df88 (fix api routing and mobile login)
