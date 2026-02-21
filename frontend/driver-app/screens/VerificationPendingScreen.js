import React, { useEffect, useState } from 'react';
import { View, Text, TouchableOpacity, ActivityIndicator, Alert } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { styles } from '../styles';
import { apiGet, apiPost } from '../apiClient';
import { registerForPushTokenAsync } from '../notifications';

const toBool = (value) => value === true || value === 1 || value === '1';

export default function VerificationPendingScreen({ navigation, route, apiBase, sessionKey }) {
  const [token, setToken] = useState(route.params?.token || '');
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    const loadToken = async () => {
      if (token) return;
      try {
        const raw = await AsyncStorage.getItem(sessionKey || 'driver_session_v1');
        if (raw) {
          const data = JSON.parse(raw);
          if (data?.token) setToken(data.token);
        }
      } catch (e) {
        // ignore
      }
    };
    loadToken();
  }, []);

  const checkStatus = async () => {
    if (!token) {
      Alert.alert('Login required', 'Please login again to check verification status.');
      return;
    }
    setLoading(true);
    try {
      const res = await apiGet(apiBase, '/api/driver/me', token);
      if (toBool(res?.driver?.is_verified)) {
        navigation.replace('DriverHome', { driver: res.driver, token });
      } else {
        Alert.alert('Pending', 'Verification still pending');
      }
    } catch (e) {
      Alert.alert('Error', e.message || 'Failed');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!token) return;
    const timer = setInterval(checkStatus, 15000);
    return () => clearInterval(timer);
  }, [token]);

  useEffect(() => {
    let cancelled = false;
    const syncPushToken = async () => {
      if (!token) return;
      try {
        const push = await registerForPushTokenAsync();
        const pushToken = String(push?.token || '').trim();
        if (cancelled || !pushToken) return;
        await apiPost(apiBase, '/api/driver/push-token', token, { fcm_token: pushToken });
      } catch (_) {
        // Ignore push-token sync failures on pending screen.
      }
    };
    syncPushToken();
    return () => {
      cancelled = true;
    };
  }, [token, apiBase]);

  return (
    <View style={styles.formContainer}>
      <View style={styles.headerRow}>
        <Text style={styles.title}>Verification Pending</Text>
        <View style={styles.badge}>
          <Text style={styles.badgeText}>PENDING</Text>
        </View>
      </View>
      <Text style={styles.cardLine}>We are verifying your documents. This usually takes a few hours.</Text>
      {!token ? <Text style={styles.cardLine}>Please login again to check status.</Text> : null}

      <TouchableOpacity style={styles.primaryBtn} onPress={checkStatus} disabled={loading || !token}>
        {loading ? <ActivityIndicator color='#111827' /> : <Text style={styles.primaryBtnText}>Check Status</Text>}
      </TouchableOpacity>
      <TouchableOpacity style={styles.ghostBtn} onPress={() => navigation.replace('Login')}>
        <Text style={styles.ghostBtnText}>Back to Login</Text>
      </TouchableOpacity>
    </View>
  );
}




