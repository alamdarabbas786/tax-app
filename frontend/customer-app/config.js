<?php
  import { Platform, NativeModules } from 'react-native';

const DEFAULT_API_BASE = 'https://tax-app-production-3500.up.railway.app';

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

  return Platform.OS === 'android'
    ? 'http://10.0.2.2:3000'
    : 'http://127.0.0.1:3000';
}

export const API_BASE =
  (process.env.EXPO_PUBLIC_API_BASE || '').trim() ||
  (__DEV__ ? defaultDevApiBase() : DEFAULT_API_BASE);

?>
