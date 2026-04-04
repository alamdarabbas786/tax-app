import React, { useEffect, useMemo, useState } from 'react';
import { StyleSheet, Text, TouchableOpacity, View, FlatList, Alert, ActivityIndicator, TextInput } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { apiGet, apiPost } from '../apiClient';
import { API_BASE } from '../config';

const ACTIVE_RIDE_KEY = 'customer_active_ride_v1';

export default function PaymentMethodAndOffersScreen({ navigation, route }) {
  const {
    vehicle,
    fare,
    pickupText,
    dropText,
    pickup,
    drop,
    distance_km,
    duration_minutes,
    session,
    apiBase
  } = route?.params || {};
  const [payment, setPayment] = useState('Cash');
  const [availableOffers, setAvailableOffers] = useState([]);
  const [appliedOffer, setAppliedOffer] = useState(null);
  const [couponCode, setCouponCode] = useState('');
  const [loadingOffers, setLoadingOffers] = useState(false);
  const [applyingCoupon, setApplyingCoupon] = useState(false);
  const [loading, setLoading] = useState(false);
  const resolvedBase = apiBase || API_BASE;
  const vehicleType = vehicle?.key || vehicle?.label?.toLowerCase() || 'mini';
  const paymentMode = payment === 'Cash' ? 'cash' : 'online';

  const totalFare = useMemo(() => {
    const base = Number(fare || 0);
    if (appliedOffer?.final_fare != null) return Number(appliedOffer.final_fare);
    return base;
  }, [fare, appliedOffer]);

  const ensureSession = async () => {
    let liveSession = session;
    if (!liveSession?.token) {
      try {
        const raw = await AsyncStorage.getItem('customer_session_v1');
        if (raw) {
          const parsed = JSON.parse(raw);
          if (parsed?.token) {
            liveSession = parsed;
          }
        }
      } catch (e) {
        // ignore
      }
    }
    return liveSession;
  };

  const loadOffers = async (liveToken) => {
    if (!liveToken) return;
    setLoadingOffers(true);
    try {
      const query = `/api/offers/list?fare=${encodeURIComponent(Number(fare || 0))}&payment_mode=${encodeURIComponent(paymentMode)}&vehicle_type=${encodeURIComponent(vehicleType)}`;
      const res = await apiGet(resolvedBase, query, liveToken);
      const rows = Array.isArray(res?.offers) ? res.offers : [];
      setAvailableOffers(rows);
    } catch (_e) {
      setAvailableOffers([]);
    } finally {
      setLoadingOffers(false);
    }
  };

  useEffect(() => {
    let mounted = true;
    (async () => {
      const liveSession = await ensureSession();
      const token = String(liveSession?.token || '').trim();
      if (!token || !mounted) return;
      setAppliedOffer(null);
      await loadOffers(token);
    })();
    return () => {
      mounted = false;
    };
  }, [fare, paymentMode, vehicleType, resolvedBase]);

  const applyCoupon = async (inputCode) => {
    const code = String(inputCode || '').trim().toUpperCase();
    if (!code) {
      Alert.alert('Coupon', 'Please enter coupon code');
      return;
    }

    const liveSession = await ensureSession();
    const authToken = String(liveSession?.token || '').trim();
    if (!authToken) {
      Alert.alert('Login required', 'Please login again to apply coupon.');
      navigation.replace('Login');
      return;
    }

    setApplyingCoupon(true);
    try {
      const res = await apiPost(resolvedBase, '/api/offers/apply', authToken, {
        code,
        fare: Number(fare || 0),
        payment_mode: paymentMode,
        vehicle_type: vehicleType
      });
      if (res?.status !== 'ok' || !res?.offer) {
        throw new Error(res?.message || 'Coupon apply failed');
      }
      setAppliedOffer(res.offer);
      setCouponCode(String(res.offer.code || code));
      Alert.alert('Coupon Applied', `${res.offer.code} applied successfully`);
    } catch (e) {
      setAppliedOffer(null);
      Alert.alert('Coupon Error', String(e?.message || e));
    } finally {
      setApplyingCoupon(false);
    }
  };

  const onConfirm = async () => {
    const liveSession = await ensureSession();
    const authToken = String(liveSession?.token || '').trim();
    if (!authToken) {
      Alert.alert('Login required', 'Please login again to book ride.');
      navigation.replace('Login');
      return;
    }
    if (!pickup?.lat || !pickup?.lng || !drop?.lat || !drop?.lng) {
      Alert.alert('Missing location', 'Please select pickup and drop location again.');
      return;
    }

    setLoading(true);
    try {
      const res = await apiPost(resolvedBase, '/api/rides/request', authToken, {
        pickup_lat: Number(pickup.lat),
        pickup_lng: Number(pickup.lng),
        drop_lat: Number(drop.lat),
        drop_lng: Number(drop.lng),
        pickup_address: pickup.address || pickup.name || pickupText || '',
        drop_address: drop.address || drop.name || dropText || '',
        vehicle_type: vehicleType,
        distance_km: Number(distance_km || 0),
        duration_minutes: Number(duration_minutes || 0),
        payment_method: paymentMode,
        payment_mode: paymentMode,
        offer_code: appliedOffer?.code || '',
        discount_amount: Number(appliedOffer?.discount_amount || 0)
      });

      if (res?.status !== 'ok' || !res?.ride_id) {
        throw new Error(res?.message || 'Ride request failed');
      }

      if (paymentMode === 'online') {
        try {
          const payRes = await apiPost(resolvedBase, '/api/payments/create-link', authToken, {
            ride_id: res.ride_id,
            amount: Number(totalFare || fare || 0),
            currency: 'INR',
            description: `Taxi ride payment for ride ${res.ride_id}`
          });
          let payUrl = String(payRes?.payment_url || '').trim();
          if (!payUrl) {
            const statusRes = await apiGet(
              resolvedBase,
              `/api/payments/status?ride_id=${encodeURIComponent(String(res.ride_id || ''))}`,
              authToken
            );
            payUrl = String(statusRes?.payment?.payment_link_url || '').trim();
          }
          if (payUrl) {
            try {
              await AsyncStorage.setItem(
                ACTIVE_RIDE_KEY,
                JSON.stringify({
                  rideId: res.ride_id,
                  screen: 'PaymentCheckout',
                  apiBase: resolvedBase,
                  pickup,
                  drop,
                  paymentUrl: payUrl,
                  ts: Date.now(),
                })
              );
            } catch (_) {
              // ignore
            }
            navigation.replace('PaymentCheckout', {
              rideId: res.ride_id,
              token: authToken,
              apiBase: resolvedBase,
              session: liveSession,
              pickup,
              drop,
              paymentUrl: payUrl,
            });
            return;
          } else {
            throw new Error('Payment link not available');
          }
        } catch (payErr) {
          const reason = String(payErr?.message || payErr || '').trim();
          Alert.alert(
            'Payment Pending',
            reason
              ? `Unable to open payment page right now (${reason}). You can continue and complete payment later.`
              : 'Unable to open payment page right now. You can continue and complete payment later.'
          );
          return;
        }
      }

      // Persist active ride so app can resume RideSearching/LiveRide after restart.
      try {
        await AsyncStorage.setItem(
          ACTIVE_RIDE_KEY,
          JSON.stringify({
              rideId: res.ride_id,
              screen: 'RideSearching',
              apiBase: resolvedBase,
              pickup,
              drop,
              ts: Date.now(),
          })
        );
      } catch (_) {
        // ignore
      }

      navigation.replace('RideSearching', {
        rideId: res.ride_id,
        token: authToken,
        apiBase: resolvedBase,
        session: liveSession,
        pickup,
        drop
      });
    } catch (e) {
      const msg = String(e?.message || e || 'Request failed');
      if (msg.toLowerCase().includes('unauthorized')) {
        try {
          await AsyncStorage.removeItem('customer_session_v1');
        } catch (_) {}
        Alert.alert('Session expired', 'Please login again.');
        navigation.replace('Login');
        return;
      }
      Alert.alert('Error', msg);
    } finally {
      setLoading(false);
    }
  };

  const renderOffer = ({ item }) => (
    <TouchableOpacity
      style={[styles.offerRow, appliedOffer?.code === item.code && styles.offerRowActive]}
      onPress={() => {
        setCouponCode(String(item.code || ''));
        applyCoupon(item.code);
      }}
      activeOpacity={0.9}
    >
      <View style={styles.offerDot} />
      <View style={styles.offerTextWrap}>
        <Text style={styles.offerTitle}>{item.title || item.code}</Text>
        {!!item.description && <Text style={styles.offerText}>{item.description}</Text>}
      </View>
      <TouchableOpacity style={styles.applyChip} onPress={() => applyCoupon(item.code)} disabled={applyingCoupon}>
        <Text style={styles.applyChipText}>Apply</Text>
      </TouchableOpacity>
    </TouchableOpacity>
  );

  return (
    <View style={styles.screen}>
      <View style={styles.card}>
        <Text style={styles.sectionTitle}>Trip Summary</Text>
        <Text style={styles.summaryLine}>Vehicle: {vehicle?.label || '--'}</Text>
        <Text style={styles.summaryLine}>Pickup: {pickupText || '--'}</Text>
        <Text style={styles.summaryLine}>Drop: {dropText || '--'}</Text>
        <Text style={styles.summaryFare}>Fare: Rs {Number(fare || 0)}</Text>
      </View>

      <View style={styles.card}>
        <Text style={styles.sectionTitle}>Payment Method</Text>
        {['Cash', 'Credit/Debit Card', 'UPI'].map((method) => (
          <TouchableOpacity
            key={method}
            style={styles.radioRow}
            onPress={() => setPayment(method)}
            activeOpacity={0.9}
          >
            <View style={[styles.radioOuter, payment === method && styles.radioOuterActive]}>
              {payment === method ? <View style={styles.radioInner} /> : null}
            </View>
            <Text style={styles.radioText}>{method}</Text>
          </TouchableOpacity>
        ))}
      </View>

      <View style={styles.card}>
        <Text style={styles.sectionTitle}>Offers</Text>
        <View style={styles.couponRow}>
          <TextInput
            style={styles.couponInput}
            placeholder="Enter coupon code"
            value={couponCode}
            onChangeText={(v) => setCouponCode(String(v || '').toUpperCase())}
            autoCapitalize="characters"
          />
          <TouchableOpacity style={styles.couponApplyBtn} onPress={() => applyCoupon(couponCode)} disabled={applyingCoupon}>
            {applyingCoupon ? <ActivityIndicator color="#111827" /> : <Text style={styles.couponApplyText}>Apply</Text>}
          </TouchableOpacity>
        </View>
        {loadingOffers ? <ActivityIndicator style={{ marginTop: 8 }} /> : null}
        <FlatList
          data={availableOffers}
          keyExtractor={(item) => String(item.code || item.id)}
          renderItem={renderOffer}
          ItemSeparatorComponent={() => <View style={styles.offerSeparator} />}
          ListEmptyComponent={!loadingOffers ? <Text style={styles.emptyOffers}>No coupons available right now</Text> : null}
        />
      </View>

      <View style={styles.totalWrap}>
        <Text style={styles.totalLabel}>Total</Text>
        <Text style={styles.totalValue}>Rs {Number(totalFare || 0).toFixed(2)}</Text>
      </View>

      <TouchableOpacity style={styles.primaryBtn} activeOpacity={0.9} onPress={onConfirm} disabled={loading}>
        {loading ? <ActivityIndicator color="#111827" /> : <Text style={styles.primaryBtnText}>Confirm & Continue</Text>}
      </TouchableOpacity>
    </View>
  );
}

const styles = StyleSheet.create({
  screen: {
    flex: 1,
    backgroundColor: '#F7F7F8',
    padding: 16
  },
  card: {
    backgroundColor: '#FFFFFF',
    borderRadius: 16,
    padding: 14,
    marginBottom: 12,
    shadowColor: '#000',
    shadowOpacity: 0.05,
    shadowRadius: 10,
    shadowOffset: { width: 0, height: 6 },
    elevation: 4
  },
  sectionTitle: {
    fontSize: 14,
    fontWeight: '800',
    color: '#111827',
    marginBottom: 8
  },
  summaryLine: {
    fontSize: 12,
    color: '#374151',
    marginBottom: 4
  },
  summaryFare: {
    marginTop: 6,
    fontSize: 14,
    fontWeight: '800',
    color: '#111827'
  },
  radioRow: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 6
  },
  radioOuter: {
    width: 18,
    height: 18,
    borderRadius: 9,
    borderWidth: 1.5,
    borderColor: '#111827',
    marginRight: 10,
    alignItems: 'center',
    justifyContent: 'center'
  },
  radioOuterActive: {
    borderColor: '#111827'
  },
  radioInner: {
    width: 9,
    height: 9,
    borderRadius: 4.5,
    backgroundColor: '#111827'
  },
  radioText: {
    fontSize: 13,
    fontWeight: '700',
    color: '#111827'
  },
  offerRow: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 8,
    paddingHorizontal: 8,
    borderRadius: 12
  },
  offerRowActive: {
    backgroundColor: '#FFF4BF'
  },
  offerDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
    backgroundColor: '#111827',
    marginRight: 10
  },
  offerTextWrap: {
    flex: 1
  },
  offerTitle: {
    fontSize: 12,
    color: '#111827',
    fontWeight: '800'
  },
  offerText: {
    fontSize: 11,
    color: '#4B5563',
    fontWeight: '600',
    marginTop: 2
  },
  applyChip: {
    borderWidth: 1,
    borderColor: '#111827',
    borderRadius: 999,
    paddingHorizontal: 10,
    paddingVertical: 4
  },
  applyChipText: {
    fontSize: 11,
    fontWeight: '800',
    color: '#111827'
  },
  offerSeparator: {
    height: 1,
    backgroundColor: '#F1F2F4'
  },
  couponRow: {
    flexDirection: 'row',
    alignItems: 'center',
    marginBottom: 10
  },
  couponInput: {
    flex: 1,
    borderWidth: 1,
    borderColor: '#D1D5DB',
    borderRadius: 10,
    paddingHorizontal: 12,
    height: 42,
    marginRight: 8,
    color: '#111827',
    fontWeight: '700'
  },
  couponApplyBtn: {
    height: 42,
    minWidth: 80,
    paddingHorizontal: 14,
    borderRadius: 10,
    backgroundColor: '#FFD100',
    alignItems: 'center',
    justifyContent: 'center'
  },
  couponApplyText: {
    fontSize: 13,
    color: '#111827',
    fontWeight: '800'
  },
  emptyOffers: {
    fontSize: 12,
    color: '#6B7280',
    paddingVertical: 8
  },
  totalWrap: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: 6,
    marginTop: 4,
    marginBottom: 10
  },
  totalLabel: {
    fontSize: 14,
    fontWeight: '800',
    color: '#111827'
  },
  totalValue: {
    fontSize: 16,
    fontWeight: '800',
    color: '#111827'
  },
  primaryBtn: {
    backgroundColor: '#FFD100',
    borderRadius: 16,
    height: 58,
    alignItems: 'center',
    justifyContent: 'center'
  },
  primaryBtnText: {
    fontSize: 15,
    fontWeight: '800',
    color: '#111827'
  }
});






