const DEFAULT_API_BASE = 'https://tax-app-production-3df5.up.railway.app';

export const API_BASE = (process.env.EXPO_PUBLIC_API_BASE || '').trim() || DEFAULT_API_BASE;
