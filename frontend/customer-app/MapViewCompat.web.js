import React, { forwardRef, useEffect, useImperativeHandle } from 'react';
import { StyleSheet, Text, View } from 'react-native';

const noop = () => {};

const MapView = forwardRef(function MapView({ style, children, onMapReady }, ref) {
  useImperativeHandle(
    ref,
    () => ({
      animateToRegion: noop,
      fitToCoordinates: noop,
      animateCamera: noop,
      setCamera: noop,
    }),
    []
  );

  useEffect(() => {
    if (typeof onMapReady === 'function') {
      onMapReady();
    }
  }, [onMapReady]);

  return (
    <View style={[styles.map, style]}>
      <View style={styles.notice}>
        <Text style={styles.noticeText}>Map preview is limited on web.</Text>
      </View>
      {children}
    </View>
  );
});

export const Marker = ({ children }) => <>{children}</>;
export const Polyline = () => null;
export const PROVIDER_GOOGLE = undefined;

const styles = StyleSheet.create({
  map: {
    backgroundColor: '#E6F0FF',
    overflow: 'hidden',
  },
  notice: {
    position: 'absolute',
    top: 10,
    left: 10,
    right: 10,
    paddingVertical: 8,
    paddingHorizontal: 10,
    borderRadius: 8,
    backgroundColor: 'rgba(15, 23, 42, 0.72)',
    zIndex: 2,
  },
  noticeText: {
    color: '#FFFFFF',
    fontSize: 12,
    textAlign: 'center',
  },
});

export default MapView;
