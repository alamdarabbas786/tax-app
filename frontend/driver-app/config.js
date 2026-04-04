import { NativeModules, Platform } from 'react-native';

const PROD_FALLBACK_API_BASE = 'http://REPLACE_WITH_SERVER_IP_OR_DOMAIN:3000';

function detectDevHost() {
  if (!__DEV__) return null;

  if (Platform.OS === 'web' && typeof window !== 'undefined') {
    const host = (window.location && window.location.hostname) || '';
    return host.trim() || null;
  }

  const scriptURL = NativeModules?.SourceCode?.scriptURL || '';
  const match = scriptURL.match(/^https?:\/\/([^/:]+)(?::\d+)?\//i);
  return match ? match[1] : null;
}

function defaultDevApiBase() {
  const host = detectDevHost();
  if (host) return `http://${host}:3000`;
  return Platform.OS === 'android' ? 'http://10.0.2.2:3000' : 'http://127.0.0.1:3000';
}

export const API_BASE =
  (process.env.EXPO_PUBLIC_API_BASE || '').trim() ||
  (__DEV__ ? defaultDevApiBase() : PROD_FALLBACK_API_BASE);
