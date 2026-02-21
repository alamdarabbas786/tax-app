import React, { useState } from 'react';
import { View, Text, TextInput, ScrollView, TouchableOpacity, ActivityIndicator, Alert, Keyboard } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import * as DocumentPicker from 'expo-document-picker';
import * as ImagePicker from 'expo-image-picker';
import { styles } from '../styles';
import { apiPostForm, apiGet } from '../apiClient';

const MAX_FILE_SIZE = 4 * 1024 * 1024;

const pickPdf = async (setter) => {
  try {
    const res = await DocumentPicker.getDocumentAsync({
      type: 'application/pdf',
      copyToCacheDirectory: true
    });
    if (res.assets && res.assets.length > 0) {
      setter(res.assets[0]);
    }
  } catch (err) {
    Alert.alert('Error', err.message || 'Unable to pick document');
  }
};

const pickPhoto = async (setter) => {
  try {
    const res = await DocumentPicker.getDocumentAsync({
      type: 'image/*',
      copyToCacheDirectory: true
    });
    if (res.assets && res.assets.length > 0) {
      setter(res.assets[0]);
    }
  } catch (err) {
    Alert.alert('Error', err.message || 'Unable to pick photo');
  }
};

export default function CreateAccountScreen({ navigation, route, apiBase, sessionKey }) {
  const presetPhone = route?.params?.phone || '';
  const token = route?.params?.token || '';
  const [form, setForm] = useState({
    name: '',
    phone: presetPhone,
    email: '',
    vehicle_type: '',
    vehicle_number: '',
    license_number: '',
    address: '',
    city: '',
    pin_code: '',
    aadhaar_number: ''
  });
  const [vehicleRc, setVehicleRc] = useState(null);
  const [drivingLicense, setDrivingLicense] = useState(null);
  const [aadhaarCard, setAadhaarCard] = useState(null);
  const [driverPhoto, setDriverPhoto] = useState(null);
  const [insuranceDoc, setInsuranceDoc] = useState(null);
  const [pucDoc, setPucDoc] = useState(null);
  const [confirm, setConfirm] = useState(false);
  const [errors, setErrors] = useState({});
  const [loading, setLoading] = useState(false);

  const setField = (key, value) => {
    if (key === 'vehicle_number' || key === 'license_number') {
      setForm((prev) => ({ ...prev, [key]: String(value || '').toUpperCase() }));
      return;
    }
    setForm((prev) => ({ ...prev, [key]: value }));
  };

  const validate = () => {
    const e = {};
    if (!form.name) e.name = 'Name is required';
    if (!form.email) e.email = 'Email is required';
    if (!form.vehicle_type) e.vehicle_type = 'Vehicle type is required';
    if (!form.vehicle_number) e.vehicle_number = 'Vehicle number is required';
    if (!form.license_number) e.license_number = 'License number is required';
    if (!form.address) e.address = 'Address is required';
    if (!form.city) e.city = 'City is required';
    if (!form.pin_code) e.pin_code = 'Pin code is required';
    if (!form.aadhaar_number) e.aadhaar_number = 'Aadhaar number is required';

    if (!vehicleRc) e.vehicle_rc = 'Vehicle RC required';
    if (!drivingLicense) e.driving_license = 'Driving license required';
    if (!aadhaarCard) e.aadhaar_card = 'Aadhaar card required';
    if (!driverPhoto) e.driver_photo = 'Driver photo required';
    if (!confirm) e.confirm = 'Please confirm details';

    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const validateFile = (file) => {
    if (file?.size && file.size > MAX_FILE_SIZE) return 'File must be < 4 MB';
    return null;
  };

  const submit = async () => {
    if (!validate()) return;

    const rcErr = validateFile(vehicleRc);
    const dlErr = validateFile(drivingLicense);
    const adErr = validateFile(aadhaarCard);
    const phErr = validateFile(driverPhoto);
    if (rcErr || dlErr || adErr || phErr) {
      Alert.alert('File Error', rcErr || dlErr || adErr || phErr);
      return;
    }

    setLoading(true);
    try {
      const data = new FormData();
      Object.entries(form).forEach(([k, v]) => data.append(k, v));
      data.append('vehicle_rc', { uri: vehicleRc.uri, type: 'application/pdf', name: 'vehicle_rc.pdf' });
      data.append('driving_license', { uri: drivingLicense.uri, type: 'application/pdf', name: 'driving_license.pdf' });
      data.append('aadhaar_card', { uri: aadhaarCard.uri, type: 'application/pdf', name: 'aadhaar_card.pdf' });
      data.append('driver_photo', { uri: driverPhoto.uri, type: 'image/jpeg', name: 'driver_photo.jpg' });
      if (insuranceDoc) data.append('insurance_doc', { uri: insuranceDoc.uri, type: 'application/pdf', name: 'insurance.pdf' });
      if (pucDoc) data.append('puc_doc', { uri: pucDoc.uri, type: 'application/pdf', name: 'puc.pdf' });

      const res = await apiPostForm(apiBase, '/api/driver/register', token, data);
      if (res.status === 'ok') {
        let driver = { name: form.name, phone: form.phone, email: form.email, vehicle_type: form.vehicle_type, is_verified: false };
        Alert.alert('Success', 'Account created. Waiting for verification.');
        try {
          if (token) {
            await AsyncStorage.setItem(sessionKey, JSON.stringify({ token, driver }));
          }
        } catch (e) {
          // ignore
        }
        navigation.replace('VerificationPending', { driver, token });
      } else {
        Alert.alert('Error', res.message || 'Registration failed');
      }
    } catch (err) {
      Alert.alert('Error', err.message || 'Registration failed');
    } finally {
      setLoading(false);
    }
  };

  return (
    <ScrollView contentContainerStyle={[styles.formContainer, { paddingBottom: 40 }]} keyboardShouldPersistTaps="always">
      <Text style={styles.title}>Create Captain Account</Text>
      <Text style={styles.cardLine}>Complete your profile to start accepting rides.</Text>

      <Text style={styles.label}>Full Name</Text>
      <TextInput style={styles.input} placeholder="Full name" value={form.name} onChangeText={(v) => setField('name', v)} />
      {errors.name && <Text style={styles.error}>{errors.name}</Text>}

      <Text style={styles.label}>Phone (OTP verified)</Text>
      <TextInput style={styles.input} placeholder="Phone" value={form.phone} onChangeText={(v) => setField('phone', v)} keyboardType="phone-pad" editable={!presetPhone} />

      <Text style={styles.label}>Email</Text>
      <TextInput style={styles.input} placeholder="Email" value={form.email} onChangeText={(v) => setField('email', v)} keyboardType="email-address" />
      {errors.email && <Text style={styles.error}>{errors.email}</Text>}

      <Text style={styles.label}>Vehicle Type</Text>
      <View style={styles.segmentRow}>
        {['bike','auto','mini','sedan','xl'].map((t) => (
          <TouchableOpacity
            key={t}
            style={[styles.segmentBtn, form.vehicle_type === t && styles.segmentBtnActive]}
            onPress={() => setField('vehicle_type', t)}
          >
            <Text style={[styles.segmentText, form.vehicle_type === t && styles.segmentTextActive]}>{t.toUpperCase()}</Text>
          </TouchableOpacity>
        ))}
      </View>
      {errors.vehicle_type && <Text style={styles.error}>{errors.vehicle_type}</Text>}

      <Text style={styles.label}>Vehicle Number</Text>
      <TextInput
        style={styles.input}
        placeholder="Vehicle number"
        value={form.vehicle_number}
        onChangeText={(v) => setField('vehicle_number', v)}
        autoCapitalize="characters"
      />
      {errors.vehicle_number && <Text style={styles.error}>{errors.vehicle_number}</Text>}

      <Text style={styles.label}>License Number</Text>
      <TextInput
        style={styles.input}
        placeholder="License number"
        value={form.license_number}
        onChangeText={(v) => setField('license_number', v)}
        autoCapitalize="characters"
      />
      {errors.license_number && <Text style={styles.error}>{errors.license_number}</Text>}

      <Text style={styles.label}>City</Text>
      <TextInput style={styles.input} placeholder="City" value={form.city} onChangeText={(v) => setField('city', v)} />
      {errors.city && <Text style={styles.error}>{errors.city}</Text>}

      <Text style={styles.label}>Pin Code</Text>
      <TextInput style={styles.input} placeholder="Pin code" value={form.pin_code} onChangeText={(v) => setField('pin_code', v)} keyboardType="number-pad" />
      {errors.pin_code && <Text style={styles.error}>{errors.pin_code}</Text>}

      <Text style={styles.label}>Aadhaar Number</Text>
      <TextInput style={styles.input} placeholder="Aadhaar number" value={form.aadhaar_number} onChangeText={(v) => setField('aadhaar_number', v)} keyboardType="number-pad" />
      {errors.aadhaar_number && <Text style={styles.error}>{errors.aadhaar_number}</Text>}

      <Text style={styles.label}>Address</Text>
      <TextInput style={[styles.input, styles.textArea]} placeholder="Address" value={form.address} onChangeText={(v) => setField('address', v)} multiline />
      {errors.address && <Text style={styles.error}>{errors.address}</Text>}

      <Text style={styles.label}>Documents (PDF/JPG)</Text>
      <TouchableOpacity style={styles.uploadBtn} onPress={() => pickPdf(setVehicleRc)}>
        <Text style={styles.uploadText}>Upload Vehicle RC (PDF)</Text>
      </TouchableOpacity>
      {vehicleRc?.name ? <Text style={styles.cardLine}>{vehicleRc.name}</Text> : null}
      {errors.vehicle_rc && <Text style={styles.error}>{errors.vehicle_rc}</Text>}

      <TouchableOpacity style={styles.uploadBtn} onPress={() => pickPdf(setDrivingLicense)}>
        <Text style={styles.uploadText}>Upload Driving License (PDF)</Text>
      </TouchableOpacity>
      {drivingLicense?.name ? <Text style={styles.cardLine}>{drivingLicense.name}</Text> : null}
      {errors.driving_license && <Text style={styles.error}>{errors.driving_license}</Text>}

      <TouchableOpacity style={styles.uploadBtn} onPress={() => pickPdf(setAadhaarCard)}>
        <Text style={styles.uploadText}>Upload Aadhaar Card (PDF)</Text>
      </TouchableOpacity>
      {aadhaarCard?.name ? <Text style={styles.cardLine}>{aadhaarCard.name}</Text> : null}
      {errors.aadhaar_card && <Text style={styles.error}>{errors.aadhaar_card}</Text>}

      <TouchableOpacity style={styles.uploadBtn} onPress={() => {pickPhoto(setDriverPhoto)}}>
        <Text style={styles.uploadText}>Upload Driver Photo (JPG)</Text>
      </TouchableOpacity>
      {driverPhoto?.uri ? <Text style={styles.cardLine}>Photo selected</Text> : null}
      {errors.driver_photo && <Text style={styles.error}>{errors.driver_photo}</Text>}

      <Text style={styles.label}>Optional</Text>
      <TouchableOpacity style={styles.uploadBtn} onPress={() => pickPdf(setInsuranceDoc)}>
        <Text style={styles.uploadText}>Upload Insurance (PDF)</Text>
      </TouchableOpacity>
      {insuranceDoc?.name ? <Text style={styles.cardLine}>{insuranceDoc.name}</Text> : null}

      <TouchableOpacity style={styles.uploadBtn} onPress={() => pickPdf(setPucDoc)}>
        <Text style={styles.uploadText}>Upload PUC (PDF)</Text>
      </TouchableOpacity>
      {pucDoc?.name ? <Text style={styles.cardLine}>{pucDoc.name}</Text> : null}

      <TouchableOpacity style={styles.checkbox} onPress={() => setConfirm(!confirm)}>
        <View style={[styles.checkboxBox, confirm && styles.checkboxBoxActive]} />
        <Text style={styles.checkboxText}>I confirm all details are correct</Text>
      </TouchableOpacity>
      {errors.confirm && <Text style={styles.error}>{errors.confirm}</Text>}

      <TouchableOpacity style={styles.primaryBtn} onPress={submit} disabled={loading}>
        {loading ? <ActivityIndicator  /> : <Text style={styles.primaryBtnText}>Submit for Verification</Text>}
      </TouchableOpacity>
    </ScrollView>
  );
}






















