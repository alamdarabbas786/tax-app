import React from 'react';
import { render, waitFor } from '@testing-library/react-native';
import DriverHomeScreen from '../screens/DriverHomeScreen';
import * as apiClient from '../apiClient';

jest.mock('../apiClient');
jest.mock('../notifications', () => ({
  registerForPushTokenAsync: jest.fn(async () => ({ token: '', type: '' })),
  getRideActionIds: jest.fn(() => ({ categoryId: 'ride_actions', accept: 'accept', reject: 'reject' })),
  getRideChannelId: jest.fn(() => 'ride_request')
}));

describe('DriverHomeScreen', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  test('renders home dashboard for authenticated driver', async () => {
    apiClient.apiGet.mockResolvedValueOnce({
      status: 'ok',
      rating: 4.9,
      total_rides: 12,
      earnings_today: 540,
      is_verified: true
    });
    apiClient.apiGet.mockResolvedValue({
      status: 'ok',
      requests: []
    });
    apiClient.apiPost.mockResolvedValue({ status: 'ok' });

    const route = {
      params: {
        token: 'driver-token',
        driver: { id: '1', name: 'Driver', current_lat: 28.61, current_lng: 77.20, is_verified: true }
      }
    };
    const navigation = { setOptions: jest.fn(), navigate: jest.fn(), replace: jest.fn() };

    const { getByText } = render(
      <DriverHomeScreen
        route={route}
        navigation={navigation}
        apiBase="http://localhost:3000"
        sessionKey="driver_session_v1"
        session={{ token: 'driver-token', driver: route.params.driver }}
      />
    );

    await waitFor(() => {
      expect(getByText('Captain Dashboard')).toBeTruthy();
    });
  });
});



