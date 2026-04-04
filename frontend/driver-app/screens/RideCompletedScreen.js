import React, { useEffect, useMemo, useState } from 'react';
import { ActivityIndicator, StyleSheet, View, Text, TouchableOpacity } from 'react-native';
import { apiGet } from '../apiClient';
import { API_BASE } from '../config';

export default function RideCompletedScreen({ navigation, route, apiBase }) {
  const ride = route.params?.ride;
  const token = route.params?.token || '';
  const driver = route.params?.driver || null;
  const summary = route.params?.summary || {};
  const resolvedBase = apiBase || API_BASE;
  const isOnlinePayment = useMemo(() => {
    const mode = String(ride?.payment_mode || ride?.payment_method || '').toLowerCase().trim();
    return mode === 'online';
  }, [ride?.payment_mode, ride?.payment_method]);
  const [paymentBadge, setPaymentBadge] = useState(() => {
    if (!isOnlinePayment) {
      return { label: 'Paid', tone: 'paid' };
    }
    const raw = String(ride?.payment_status || '').toLowerCase().trim();
    if (raw === 'paid' || raw === 'captured' || raw === 'verified') return { label: 'Paid', tone: 'paid' };
    if (raw === 'failed') return { label: 'Failed', tone: 'failed' };
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
    if (!isOnlinePayment || !ride?.id || !token) return undefined;
    let mounted = true;
    let timer;
    const fetchPaymentStatus = async () => {
      try {
        if (!mounted) return;
        setPaymentLoading(true);
        const res = await apiGet(resolvedBase, `/api/payments/status?ride_id=${encodeURIComponent(String(ride.id))}`, token);
        if (!mounted || res?.status !== 'ok') return;
        const rawStatus = String(res?.payment?.status || '').toLowerCase().trim();
        if (rawStatus === 'paid' || rawStatus === 'captured' || rawStatus === 'verified') {
          setPaymentBadge({ label: 'Paid', tone: 'paid' });
          return;
        }
        if (rawStatus === 'failed') {
          setPaymentBadge({ label: 'Failed', tone: 'failed' });
          return;
        }
        setPaymentBadge({ label: 'Pending', tone: 'pending' });
      } catch (_) {
        // keep last state on transient failures
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
  }, [isOnlinePayment, ride?.id, token, resolvedBase]);

  return (
    <View style={local.screen}>
      <View style={local.hero}>
        <Text style={local.tick}>✓</Text>
        <Text style={local.title}>Ride Completed</Text>
        <Text style={local.subtitle}>Payment received successfully</Text>
      </View>

      <View style={local.card}>
        <Text style={local.fareLabel}>Total Fare</Text>
        <Text style={local.fareValue}>Rs {resolvedFare.toFixed(2)}</Text>
        <View style={local.badgeRow}>
          <Text style={local.cardLineKey}>Payment</Text>
          <View
            style={[
              local.paymentBadge,
              paymentBadge.tone === 'paid' ? local.paymentBadgePaid : null,
              paymentBadge.tone === 'pending' ? local.paymentBadgePending : null,
              paymentBadge.tone === 'failed' ? local.paymentBadgeFailed : null,
            ]}
          >
            {paymentLoading ? <ActivityIndicator size="small" color="#111827" style={{ marginRight: 6 }} /> : null}
            <Text style={local.paymentBadgeText}>{paymentBadge.label}</Text>
          </View>
        </View>

        <Text style={local.cardLine}>Distance: {Number(ride?.distance_km || 0).toFixed(2)} km</Text>
        <Text style={local.cardLine}>Duration: {Number(ride?.duration_min || 0).toFixed(0)} min</Text>
        <Text style={local.cardLine}>Waiting: {summary.waiting_minutes || 0} min</Text>
        <Text style={local.cardLine}>Waiting Charge: Rs {summary.waiting_charge || 0}</Text>
      </View>

      <TouchableOpacity style={local.homeBtn} onPress={() => navigation.replace('DriverHome', { driver, token })}>
        <Text style={local.homeBtnText}>Back to Home</Text>
      </TouchableOpacity>
    </View>
  );
}

const local = StyleSheet.create({
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
  cardLine: {
    marginTop: 10,
    fontSize: 13,
    color: '#374151',
    fontWeight: '700',
  },
  badgeRow: {
    marginTop: 12,
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  cardLineKey: {
    fontSize: 13,
    color: '#374151',
    fontWeight: '700',
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
