import React from 'react';
import { render, fireEvent } from '@testing-library/react-native';
import CreateAccountScreen from '../screens/CreateAccountScreen';

describe('CreateAccountScreen', () => {
  test('forces uppercase for vehicle and license number fields', () => {
    const navigation = { replace: jest.fn(), navigate: jest.fn() };
    const route = { params: { phone: '9876543210', token: '' } };

    const { getByPlaceholderText } = render(
      <CreateAccountScreen
        navigation={navigation}
        route={route}
        apiBase="http://localhost:3000"
        sessionKey="driver_session_v1"
      />
    );

    const vehicle = getByPlaceholderText('Vehicle number');
    const license = getByPlaceholderText('License number');

    fireEvent.changeText(vehicle, 'ab12cd3456');
    fireEvent.changeText(license, 'dl01x12345');

    expect(vehicle.props.value).toBe('AB12CD3456');
    expect(license.props.value).toBe('DL01X12345');
  });
});



