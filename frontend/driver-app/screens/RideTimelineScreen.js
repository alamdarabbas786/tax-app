import React from 'react';
import { View, Text } from 'react-native';
import { styles } from '../styles';

const STEPS = [
  { key: 'searching', label: 'Searching' },
  { key: 'driver_assigned', label: 'Driver Assigned' },
  { key: 'driver_arrived', label: 'Driver Arrived' },
  { key: 'ride_started', label: 'Ride Started' },
  { key: 'ride_completed', label: 'Ride Completed' }
];

export default function RideTimelineScreen({ route }) {
  const ride = route.params?.ride;
  const status = route.params?.status || 'searching';

  const currentIndex = STEPS.findIndex(s => s.key === status);

  return (
    <View style={styles.formContainer}>
      <Text style={styles.title}>Ride Timeline</Text>
      {ride ? (
        <View style={styles.card}>
          <Text style={styles.cardTitle}>Ride</Text>
          <Text style={styles.cardLine}>Pickup: {ride.pickup_address}</Text>
          <Text style={styles.cardLine}>Drop: {ride.drop_address}</Text>
          <Text style={styles.cardLine}>Driver Earning: Rs {ride.driver_earning}</Text>
        </View>
      ) : (
        <Text style={styles.label}>No active ride</Text>
      )}

      <View style={styles.timeline}>
        {STEPS.map((step, index) => {
          const active = index <= currentIndex;
          return (
            <View key={step.key} style={styles.timelineRow}>
              <View style={[styles.timelineDot, active && styles.timelineDotActive]} />
              <Text style={[styles.timelineText, active && styles.timelineTextActive]}>{step.label}</Text>
            </View>
          );
        })}
      </View>
    </View>
  );
}
