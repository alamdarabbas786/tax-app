import React, { useEffect, useMemo, useState } from 'react';
import { ActivityIndicator, Alert, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { WebView } from 'react-native-webview';
import { apiGet } from '../apiClient';
import { API_BASE } from '../config';

export default function PaymentCheckoutScreen({ navigation, route }) {
  const {
    rideId,
    token,
    apiBase,
    session,
    pickup,
    drop,
    paymentUrl,
  } = route?.params || {};

  const resolvedBase = apiBase || API_BASE;
  const authToken = String(token || session?.token || '').trim();
  const url = useMemo(() => {
    const raw = String(paymentUrl || '').trim();
    if (raw === '') return '';
    return raw.startsWith('http://') || raw.startsWith('https://') ? raw : `https://${raw}`;
  }, [paymentUrl]);
  const [checking, setChecking] = useState(false);
  const [statusText, setStatusText] = useState('Complete payment in this screen.');

  const goSearching = () => {
    navigation.replace('RideSearching', {
      rideId,
      token: authToken,
      apiBase: resolvedBase,
      session,
      pickup,
      drop,
    });
  };

  const checkPayment = async () => {
    if (!rideId || !authToken) return false;
    setChecking(true);
    try {
      const res = await apiGet(
        resolvedBase,
        `/api/payments/status?ride_id=${encodeURIComponent(String(rideId))}`,
        authToken
      );
      const paymentStatus = String(res?.payment?.status || '').toLowerCase().trim();
      if (paymentStatus === 'paid' || paymentStatus === 'verified') {
        setStatusText('Payment confirmed. Finding drivers...');
        goSearching();
        return true;
      }
      setStatusText('Payment is pending. Please complete payment and try again.');
      return false;
    } catch (_) {
      setStatusText('Unable to verify payment now. Please retry.');
      return false;
    } finally {
      setChecking(false);
    }
  };

  useEffect(() => {
    let timer;
    if (rideId && authToken) {
      timer = setInterval(() => {
        checkPayment();
      }, 4000);
    }
    return () => {
      if (timer) clearInterval(timer);
    };
  }, [rideId, authToken]);

  if (!url) {
    return (
      <View style={styles.center}>
        <Text style={styles.infoText}>Payment link not available.</Text>
        <TouchableOpacity style={styles.primaryBtn} onPress={checkPayment} disabled={checking}>
          {checking ? <ActivityIndicator color="#111827" /> : <Text style={styles.btnText}>Check Payment Status</Text>}
        </TouchableOpacity>
      </View>
    );
  }

  return (
    <View style={styles.screen}>
      <WebView
        source={{ uri: url }}
        startInLoadingState
        renderLoading={() => (
          <View style={styles.loadingWrap}>
            <ActivityIndicator color="#111827" />
            <Text style={styles.loadingText}>Opening secure payment...</Text>
          </View>
        )}
        onError={() => {
          Alert.alert('Payment', 'Unable to load payment page. You can retry status check below.');
        }}
      />
      <View style={styles.footer}>
        <Text style={styles.statusText}>{statusText}</Text>
        <TouchableOpacity style={styles.primaryBtn} onPress={checkPayment} disabled={checking}>
          {checking ? <ActivityIndicator color="#111827" /> : <Text style={styles.btnText}>I Have Completed Payment</Text>}
        </TouchableOpacity>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  screen: {
    flex: 1,
    backgroundColor: '#FFFFFF',
  },
  loadingWrap: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#FFFFFF',
  },
  loadingText: {
    marginTop: 8,
    color: '#6B7280',
    fontWeight: '700',
  },
  footer: {
    paddingHorizontal: 12,
    paddingVertical: 10,
    borderTopWidth: 1,
    borderTopColor: '#E5E7EB',
    backgroundColor: '#FFFFFF',
  },
  statusText: {
    fontSize: 12,
    color: '#4B5563',
    marginBottom: 8,
    textAlign: 'center',
  },
  primaryBtn: {
    backgroundColor: '#FFD100',
    borderRadius: 12,
    height: 48,
    alignItems: 'center',
    justifyContent: 'center',
  },
  btnText: {
    fontSize: 14,
    fontWeight: '800',
    color: '#111827',
  },
  center: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 20,
    backgroundColor: '#FFFFFF',
  },
  infoText: {
    fontSize: 14,
    color: '#111827',
    marginBottom: 12,
    fontWeight: '700',
  },
});
