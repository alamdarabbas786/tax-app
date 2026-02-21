import React from 'react';
import { View, Text, TouchableOpacity, Alert } from 'react-native';
import { styles } from '../styles';

export default function SupportScreen() {
  const handleSOS = () => {
    Alert.alert('SOS', 'Emergency alert sent to support team.');
  };

  return (
    <View style={styles.formContainer}>
      <Text style={styles.title}>Safety & Support</Text>
      <View style={styles.card}>
        <Text style={styles.cardTitle}>SOS</Text>
        <Text style={styles.cardLine}>Press if you feel unsafe during a ride.</Text>
        <TouchableOpacity style={styles.rejectBtn} onPress={handleSOS}>
          <Text style={styles.rejectText}>Send SOS</Text>
        </TouchableOpacity>
      </View>

      <View style={styles.card}>
        <Text style={styles.cardTitle}>Contact Support</Text>
        <Text style={styles.cardLine}>Email: support@yourapp.com</Text>
        <Text style={styles.cardLine}>Phone: +91 90000 00000</Text>
      </View>

      <View style={styles.card}>
        <Text style={styles.cardTitle}>FAQs</Text>
        <Text style={styles.cardLine}>• How to accept ride requests?</Text>
        <Text style={styles.cardLine}>• How earnings are calculated?</Text>
        <Text style={styles.cardLine}>• What if rider cancels?</Text>
      </View>
    </View>
  );
}
