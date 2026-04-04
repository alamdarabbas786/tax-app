import React, { useEffect, useMemo, useState } from 'react';
import { ActivityIndicator, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { apiGet } from '../apiClient';
import { API_BASE } from '../config';

const ACTIVE_RIDE_KEY = 'customer_active_ride_v1';

export default function RideCompletedScreen({ navigation, route }) {
  const ride = route?.params?.ride || {};
  const session = route?.params?.session || null;
  const token = route?.params?.token || session?.token || '';
  const apiBase = route?.params?.apiBase || API_BASE;
  const summary = route?.params?.summary || {};
  const isOnlinePayment = useMemo(() => {
    const mode = String(ride?.payment_mode || ride?.payment_method || '').toLowerCase().trim();
    return mode === 'online';
  }, [ride?.payment_mode, ride?.payment_method]);
  const [paymentBadge, setPaymentBadge] = useState(() => {
    if (!isOnlinePayment) {
      return { label: 'Paid', tone: 'paid' };
    }
    const s = String(ride?.payment_status || '').toLowerCase().trim();
    if (s === 'paid' || s === 'captured' || s === 'verified') return { label: 'Paid', tone: 'paid' };
    if (s === 'failed') return { label: 'Failed', tone: 'failed' };
    return { label: 'Pending', tone: 'pending' };
  });
  const [paymentLoading, setPaymentLoading] = useState(false);
  const resolvedFare = Number(
    summary?.final_fare ??
    summary?.total_fare ??
    summary?.fare_total ??
    summary?.fare ??
    ride?.final_fare ??
    ride?.total_fare ??
    ride?.fare ??
    0
  );

  useEffect(() => {
    // Clear active ride state once completed.
    AsyncStorage.removeItem(ACTIVE_RIDE_KEY).catch(() => {});
  }, []);

  useEffect(() => {
    if (!isOnlinePayment || !ride?.id || !token) {
      return undefined;
    }
    let mounted = true;
    let timer;
    const fetchPaymentStatus = async () => {
      try {
        if (!mounted) return;
        setPaymentLoading(true);
        const res = await apiGet(apiBase, `/api/payments/status?ride_id=${encodeURIComponent(String(ride.id))}`, token);
        if (!mounted || res?.status !== 'ok') return;
        const rawStatus = String(res?.payment?.status || '').toLowerCase().trim();
        if (rawStatus === 'paid' || rawStatus === 'verified' || rawStatus === 'captured') {
          setPaymentBadge({ label: 'Paid', tone: 'paid' });
          return;
        }
        if (rawStatus === 'failed') {
          setPaymentBadge({ label: 'Failed', tone: 'failed' });
          return;
        }
        setPaymentBadge({ label: 'Pending', tone: 'pending' });
      } catch (_) {
        // keep last badge state on transient errors
      } finally {
        if (mounted) setPaymentLoading(false);
      }
    };
    fetchPaymentStatus();
    timer = setInterval(fetchPaymentStatus, 4000);
    return () => {
      mounted = false;
      if (timer) clearInterval(timer);
    };
  }, [isOnlinePayment, ride?.id, token, apiBase]);

  return (
    <View style={styles.screen}>
      <View style={styles.hero}>
        <Text style={styles.tick}>✓</Text>
        <Text style={styles.title}>Ride Completed</Text>
        <Text style={styles.subtitle}>Thanks for riding with QuickGo</Text>
      </View>

      <View style={styles.card}>
        <Text style={styles.fareLabel}>Total Fare</Text>
        <Text style={styles.fareValue}>Rs {resolvedFare.toFixed(2)}</Text>
        <View style={styles.badgeRow}>
          <Text style={styles.rowKey}>Payment</Text>
          <View
            style={[
              styles.paymentBadge,
              paymentBadge.tone === 'paid' ? styles.paymentBadgePaid : null,
              paymentBadge.tone === 'pending' ? styles.paymentBadgePending : null,
              paymentBadge.tone === 'failed' ? styles.paymentBadgeFailed : null,
            ]}
          >
            {paymentLoading ? <ActivityIndicator size="small" color="#111827" style={{ marginRight: 6 }} /> : null}
            <Text style={styles.paymentBadgeText}>{paymentBadge.label}</Text>
          </View>
        </View>

        <View style={styles.row}>
          <Text style={styles.rowKey}>Distance</Text>
          <Text style={styles.rowVal}>{Number(ride?.distance_km || 0).toFixed(2)} km</Text>
        </View>
        <View style={styles.row}>
          <Text style={styles.rowKey}>Duration</Text>
          <Text style={styles.rowVal}>{Number(ride?.duration_min || 0).toFixed(0)} min</Text>
        </View>
        <View style={styles.row}>
          <Text style={styles.rowKey}>Status</Text>
          <Text style={styles.rowVal}>{String(ride?.status || 'completed')}</Text>
        </View>
      </View>

      <TouchableOpacity
        style={styles.homeBtn}
        onPress={() =>
          navigation.reset({
            index: 0,
            routes: [{ name: 'Home', params: { session } }],
          })
        }
      >
        <Text style={styles.homeBtnText}>Back to Home</Text>
      </TouchableOpacity>
    </View>
  );
}

const styles = StyleSheet.create({
  screen: {
    flex: 1,
    backgroundColor: '#16A34A',
    padding: 20,
    justifyContent: 'space-between',
  },
  hero: {
    marginTop: 36,
    alignItems: 'center',
  },
  tick: {
    width: 76,
    height: 76,
    borderRadius: 38,
    textAlign: 'center',
    textAlignVertical: 'center',
    backgroundColor: '#FFFFFF',
    color: '#16A34A',
    fontSize: 40,
    fontWeight: '900',
    overflow: 'hidden',
  },
  title: {
    marginTop: 14,
    color: '#FFFFFF',
    fontSize: 30,
    fontWeight: '900',
  },
  subtitle: {
    marginTop: 6,
    color: '#DCFCE7',
    fontSize: 14,
    fontWeight: '600',
  },
  card: {
    backgroundColor: '#FFFFFF',
    borderRadius: 20,
    padding: 18,
  },
  fareLabel: {
    color: '#6B7280',
    fontSize: 13,
    textAlign: 'center',
  },
  fareValue: {
    marginTop: 6,
    color: '#111827',
    fontSize: 36,
    fontWeight: '900',
    textAlign: 'center',
  },
  row: {
    marginTop: 12,
    flexDirection: 'row',
    justifyContent: 'space-between',
  },
  badgeRow: {
    marginTop: 14,
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  paymentBadge: {
    borderRadius: 999,
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderWidth: 1,
    flexDirection: 'row',
    alignItems: 'center',
  },
  paymentBadgePaid: {
    backgroundColor: '#DCFCE7',
    borderColor: '#16A34A',
  },
  paymentBadgePending: {
    backgroundColor: '#FEF3C7',
    borderColor: '#D97706',
  },
  paymentBadgeFailed: {
    backgroundColor: '#FEE2E2',
    borderColor: '#DC2626',
  },
  paymentBadgeText: {
    color: '#111827',
    fontSize: 12,
    fontWeight: '800',
  },
  rowKey: {
    color: '#6B7280',
    fontSize: 13,
    fontWeight: '600',
  },
  rowVal: {
    color: '#111827',
    fontSize: 13,
    fontWeight: '800',
  },
  homeBtn: {
    marginBottom: 12,
    backgroundColor: '#FFFFFF',
    borderRadius: 14,
    paddingVertical: 14,
    alignItems: 'center',
  },
  homeBtnText: {
    color: '#166534',
    fontWeight: '900',
    fontSize: 15,
  },
});
