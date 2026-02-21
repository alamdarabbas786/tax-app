import React, { useState } from 'react';
import { View, Text, TextInput, TouchableOpacity, ActivityIndicator, Alert } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { styles } from '../styles';
import { apiPost, apiGet } from '../apiClient';

const toBool = (value) => value === true || value === 1 || value === '1';

export default function LoginScreen({ navigation, apiBase, sessionKey }) {
  const [phone, setPhone] = useState('');
  const [otp, setOtp] = useState('');
  const [loading, setLoading] = useState(false);
  const [otpSent, setOtpSent] = useState(false);
  
  const requestOtp = async () => {
    const cleanPhone = String(phone || '').trim();
    if (!cleanPhone) return Alert.alert('Error', 'Phone is required');
    setLoading(true);
    try {
      const res = await apiPost(apiBase, '/api/auth/request-otp', null, { phone: cleanPhone, role: 'driver' });
      if (res?.status === 'ok') {
        if (res?.needs_registration === true) {
          navigation.replace('CreateAccount', { phone: cleanPhone });
          return;
        }
        setOtpSent(true);
        Alert.alert('OTP', 'Mock OTP is 1234');
      } else {
        Alert.alert('Error', res?.message || 'Failed');
      }
    } catch (e) {
      Alert.alert('Error', e.message || 'Failed');
    } finally {
      setLoading(false);
    }
  };

  const verifyOtp = async () => {
    const cleanPhone = String(phone || '').trim();
    const cleanOtp = String(otp || '').trim();
    if (!cleanPhone) return Alert.alert('Error', 'Phone is required');
    if (!cleanOtp) return Alert.alert('Error', 'OTP is required');
    setLoading(true);
    try {
      const res = await apiPost(apiBase, '/api/auth/verify-otp', null, {
        phone: cleanPhone,
        otp: cleanOtp,
        role: 'driver',
        full_name: 'Driver'
      });
      const token = res.token;
      if (!token) {
        Alert.alert('Error', res.message || 'OTP failed');
        setLoading(false);
        return;
      }
      // Backend may return needs_registration=true for some legacy schemas even when
      // a driver profile exists. Always verify with /driver/me before redirecting.
      let driver = null;
      try {
        const driverRes = await apiGet(apiBase, '/api/driver/me', token);
        driver = driverRes?.driver || null;
      } catch (e) {
        driver = null;
      }

      if (!driver) {
        navigation.replace('CreateAccount', { phone: cleanPhone, token });
        setLoading(false);
        return;
      }

      try {
        await AsyncStorage.setItem(sessionKey, JSON.stringify({ token, driver }));
      } catch (e) {
        // ignore
      }

      if (!toBool(driver?.is_verified)) {
        navigation.replace('VerificationPending', { driver, token });
      } else {
        navigation.replace('DriverHome', { driver, token });
      }
    } catch (e) {
      Alert.alert('Error', e.message || 'OTP failed');
    } finally {
      setLoading(false);
    }
  };

  return (
    <View style={styles.formContainer}>
      <View style={styles.headerRow}>
        <Text style={styles.title}>Captain Login</Text>
        <View style={styles.badge}>
          <Text style={styles.badgeText}></Text>
        </View>
      </View>
      <Text style={styles.cardLine}>Drive, earn, and stay safe on every ride.</Text>

      <Text style={styles.label}>Mobile Number</Text>
      <TextInput
        style={styles.input}
        placeholder="Enter 10-digit number"
        value={phone}
        onChangeText={setPhone}
        keyboardType="phone-pad"
        maxLength={10}
      />

      {!otpSent && (
        <TouchableOpacity style={styles.primaryBtn} onPress={() => requestOtp(false)} disabled={loading}>
          {loading ? <ActivityIndicator  /> : <Text style={styles.primaryBtnText}>Send OTP</Text>}
        </TouchableOpacity>
      )}

      {otpSent && (
        <>
          <Text style={styles.label}>OTP</Text>
          <TextInput
            style={styles.input}
            placeholder="Enter OTP"
            value={otp}
            onChangeText={setOtp}
            keyboardType="number-pad"
            maxLength={4}
          />
          <TouchableOpacity style={styles.primaryBtn} onPress={verifyOtp} disabled={loading}>
            {loading ? <ActivityIndicator  /> : <Text style={styles.primaryBtnText}>Verify & Continue</Text>}
          </TouchableOpacity>
        </>
      )}

      <TouchableOpacity style={styles.linkBtn}
          activeOpacity={0.9}
          onPress={() => navigation.navigate('CreateAccount', { phone })}>
        <Text style={styles.linkText}>Create Account</Text>
      </TouchableOpacity>
    </View>
  );
}










