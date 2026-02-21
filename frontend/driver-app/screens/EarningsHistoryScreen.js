import React, { useEffect, useMemo, useState } from 'react';
import { View, Text, FlatList } from 'react-native';
import { styles } from '../styles';
import { apiGet } from '../apiClient';

const toDate = (v) => (v ? new Date(v) : null);
const getDynamicAmount = (ride) => {
  const fare = Number(ride?.fare_amount ?? ride?.fare ?? 0);
  if (fare > 0) return fare;
  return Number(ride?.driver_earning ?? 0);
};

export default function EarningsHistoryScreen({ route, apiBase }) {
  const token = route.params?.token || '';
  const [rides, setRides] = useState([]);

  useEffect(() => {
    const load = async () => {
      const res = await apiGet(apiBase, '/api/driver/earnings?limit=120', token);
      if (res.status === 'ok') setRides(res.rides || []);
    };
    load();
  }, [apiBase]);

  const summary = useMemo(() => {
    const now = new Date();
    const startOfDay = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const startOfWeek = new Date(now);
    startOfWeek.setDate(now.getDate() - now.getDay());
    startOfWeek.setHours(0, 0, 0, 0);
    const startOfMonth = new Date(now.getFullYear(), now.getMonth(), 1);

    let today = 0;
    let week = 0;
    let month = 0;
    rides.forEach((r) => {
      const d = toDate(r.updated_at);
      const earn = getDynamicAmount(r);
      if (!d) return;
      if (d >= startOfDay) today += earn;
      if (d >= startOfWeek) week += earn;
      if (d >= startOfMonth) month += earn;
    });
    return { today, week, month };
  }, [rides]);

  const chart = useMemo(() => {
    const days = [];
    const now = new Date();
    for (let i = 6; i >= 0; i -= 1) {
      const d = new Date(now.getFullYear(), now.getMonth(), now.getDate() - i);
      days.push({
        key: d.toISOString().slice(0, 10),
        label: d.toLocaleDateString('en-IN', { weekday: 'short' }),
        value: 0
      });
    }

    rides.forEach((r) => {
      const d = toDate(r.updated_at);
      if (!d) return;
      const key = d.toISOString().slice(0, 10);
      const item = days.find((x) => x.key === key);
      if (item) item.value += getDynamicAmount(r);
    });

    const max = Math.max(1, ...days.map((d) => d.value));
    return { days, max };
  }, [rides]);

  return (
    <View style={styles.formContainer}>
      <Text style={styles.title}>Earnings</Text>
      <View style={styles.card}>
        <Text style={styles.cardTitle}>Summary</Text>
        <Text style={styles.cardLine}>Today Fare: Rs {summary.today.toFixed(0)}</Text>
        <Text style={styles.cardLine}>This Week Fare: Rs {summary.week.toFixed(0)}</Text>
        <Text style={styles.cardLine}>This Month Fare: Rs {summary.month.toFixed(0)}</Text>
      </View>

      <View style={styles.card}>
        <Text style={styles.cardTitle}>Last 7 Days</Text>
        <View style={{ flexDirection: 'row', alignItems: 'flex-end', gap: 8, height: 120, marginTop: 10 }}>
          {chart.days.map((d) => (
            <View key={d.key} style={{ alignItems: 'center', flex: 1 }}>
              <View
                style={{
                  width: '100%',
                  height: Math.max(8, (d.value / chart.max) * 90),
                  backgroundColor: '#FFD100',
                  borderRadius: 8
                }}
              />
              <Text style={{ fontSize: 11, marginTop: 6, color: '#6b7280' }}>{d.label}</Text>
            </View>
          ))}
        </View>
      </View>

      <FlatList
        data={rides}
        keyExtractor={(item) => String(item.id)}
        renderItem={({ item }) => (
          <View style={styles.card}>
            <Text style={styles.cardTitle}>{item.pickup_address} -> {item.drop_address}</Text>
            <Text style={styles.cardLine}>Fare: Rs {getDynamicAmount(item).toFixed(2)}</Text>
            <Text style={styles.cardLine}>Driver Earning: Rs {Number(item.driver_earning || 0).toFixed(2)}</Text>
            <Text style={styles.cardLine}>Status: {item.status}</Text>
          </View>
        )}
      />
    </View>
  );
}
