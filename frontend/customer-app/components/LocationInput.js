import React from 'react';
import { View, Text, TouchableOpacity, StyleSheet } from 'react-native';
export default function LocationInput({ label, value, placeholder, onPress, onClear }) {
  const showClear = Boolean(value);
  return (
    <TouchableOpacity style={styles.container} activeOpacity={0.9} onPress={onPress}>
      <View style={styles.iconBox}>
        <Text style={styles.iconText}>@</Text>
      </View>
      <View style={styles.textBox}>
        <Text style={styles.label}>{label}</Text>
        <Text style={[styles.value, !value && styles.placeholder]} numberOfLines={1}>
          {value || placeholder}
        </Text>
      </View>
      {showClear ? (
        <TouchableOpacity style={styles.clearBtn} onPress={onClear} hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}>
          <Text style={styles.clearText}>x</Text>
        </TouchableOpacity>
      ) : null}
    </TouchableOpacity>
  );
}
const styles = StyleSheet.create({
  container: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#FFFFFF',
    borderRadius: 16,
    paddingVertical: 12,
    paddingHorizontal: 12,
    borderWidth: 1,
    borderColor: '#EEF0F3',
    shadowColor: '#000',
    shadowOpacity: 0.04,
    shadowRadius: 8,
    shadowOffset: { width: 0, height: 4 },
    elevation: 2
  },
  iconBox: {
    width: 34,
    height: 34,
    borderRadius: 12,
    backgroundColor: '#FFF4BF',
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: 10
  },
  iconText: {
    color: '#111827',
    fontWeight: '800'
  },
  textBox: {
    flex: 1
  },
  label: {
    fontSize: 11,
    color: '#6B7280',
    fontWeight: '700',
    letterSpacing: 0.3
  },
  value: {
    fontSize: 15,
    color: '#111827',
    fontWeight: '700',
    marginTop: 2
  },
  placeholder: {
    color: '#9AA3AD',
    fontWeight: '600'
  },
  clearBtn: {
    width: 28,
    height: 28,
    borderRadius: 14,
    backgroundColor: '#F3F4F6',
    alignItems: 'center',
    justifyContent: 'center'
  },
  clearText: {
    color: '#6B7280',
    fontWeight: '700'
  }
});
