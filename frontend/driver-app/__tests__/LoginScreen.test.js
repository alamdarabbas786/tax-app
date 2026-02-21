import React from 'react';
import { render, fireEvent, waitFor } from '@testing-library/react-native';
import LoginScreen from '../screens/LoginScreen';
import * as apiClient from '../apiClient';

jest.mock('../apiClient');

describe('LoginScreen', () => {
  const navigation = { replace: jest.fn(), navigate: jest.fn() };
  const props = { navigation, apiBase: 'http://localhost:3000', sessionKey: 'driver_session_v1' };

  beforeEach(() => {
    jest.clearAllMocks();
  });

  test('navigates to registration when driver does not exist on request otp', async () => {
    apiClient.apiPost.mockResolvedValueOnce({
      status: 'ok',
      needs_registration: true,
      otp_required: false
    });

    const { getByPlaceholderText, getByText } = render(<LoginScreen {...props} />);
    fireEvent.changeText(getByPlaceholderText('Enter 10-digit number'), '9999999999');
    fireEvent.press(getByText('Send OTP'));

    await waitFor(() => {
      expect(navigation.replace).toHaveBeenCalledWith('CreateAccount', { phone: '9999999999' });
    });
  }, 15000);

  test('navigates to home when otp verifies and driver exists', async () => {
    apiClient.apiPost.mockResolvedValueOnce({
      status: 'ok',
      needs_registration: false,
      otp_required: true
    });
    apiClient.apiPost.mockResolvedValueOnce({
      status: 'ok',
      token: 'driver-token',
      role: 'driver',
      needs_registration: false
    });
    apiClient.apiGet.mockResolvedValueOnce({
      status: 'ok',
      driver: { id: '1', name: 'Driver', is_verified: true }
    });

    const { getByPlaceholderText, getByText } = render(<LoginScreen {...props} />);
    fireEvent.changeText(getByPlaceholderText('Enter 10-digit number'), '8888888888');
    fireEvent.press(getByText('Send OTP'));
    await waitFor(() => expect(getByText('Verify & Continue')).toBeTruthy());

    fireEvent.changeText(getByPlaceholderText('Enter OTP'), '1234');
    fireEvent.press(getByText('Verify & Continue'));

    await waitFor(() => {
      expect(navigation.replace).toHaveBeenCalledWith(
        'DriverHome',
        expect.objectContaining({ token: 'driver-token' })
      );
    });
  }, 15000);
});


