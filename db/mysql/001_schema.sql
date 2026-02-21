-- 001_create_users.sql
CREATE TABLE users (
  id BINARY(16) PRIMARY KEY,
  role ENUM('customer','driver','admin') NOT NULL,
  email VARCHAR(255) NOT NULL UNIQUE,
  phone VARCHAR(32) UNIQUE,
  full_name VARCHAR(120) NOT NULL,
  api_token VARCHAR(80) UNIQUE,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_users_role_created (role, created_at)
) ENGINE=InnoDB;

CREATE TABLE user_otps (
  id BINARY(16) PRIMARY KEY,
  phone VARCHAR(32) NOT NULL UNIQUE,
  otp_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_otps_phone (phone),
  INDEX idx_otps_expires (expires_at)
) ENGINE=InnoDB;

-- 002_create_drivers.sql
CREATE TABLE drivers (
  id BINARY(16) PRIMARY KEY,
  user_id BINARY(16) NOT NULL UNIQUE,
  license_number VARCHAR(64) NOT NULL UNIQUE,
  vehicle_make VARCHAR(60) NOT NULL,
  vehicle_model VARCHAR(60) NOT NULL,
  vehicle_plate VARCHAR(24) NOT NULL UNIQUE,
  vehicle_number VARCHAR(32) NOT NULL,
  vehicle_capacity TINYINT NOT NULL,
  cost_per_km DECIMAL(10,4) NOT NULL,
  cost_per_min DECIMAL(10,4) NOT NULL,
  address_line VARCHAR(255) NOT NULL,
  city VARCHAR(80) NOT NULL,
  pin_code VARCHAR(12) NOT NULL,
  aadhaar_number VARCHAR(20) NOT NULL,
  rc_file VARCHAR(255) NOT NULL,
  driving_license_file VARCHAR(255) NOT NULL,
  aadhaar_file VARCHAR(255) NOT NULL,
  driver_photo_path VARCHAR(255) NOT NULL,
  is_available BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_drivers_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT chk_driver_costs CHECK (cost_per_km >= 0 AND cost_per_min >= 0),
  CONSTRAINT chk_vehicle_capacity CHECK (vehicle_capacity BETWEEN 1 AND 8),

  INDEX idx_drivers_created (created_at),
  INDEX idx_drivers_available (is_available)
) ENGINE=InnoDB;

-- 003_create_flights.sql
CREATE TABLE flights (
  id BINARY(16) PRIMARY KEY,
  airline_code CHAR(3) NOT NULL,
  flight_number VARCHAR(8) NOT NULL,
  scheduled_at DATETIME NOT NULL,
  airport_code CHAR(3) NOT NULL,
  direction ENUM('arrival','departure') NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_flights_airport_schedule (airport_code, scheduled_at),
  INDEX idx_flights_direction_schedule (direction, scheduled_at)
) ENGINE=InnoDB;

-- 004_create_rides.sql
CREATE TABLE rides (
  id BINARY(16) PRIMARY KEY,
  customer_id BINARY(16) NOT NULL,
  driver_id BINARY(16),
  flight_id BINARY(16),

  pickup_airport_code CHAR(3) NOT NULL,
  dropoff_airport_code CHAR(3) NOT NULL,
  scheduled_at DATETIME NOT NULL,
  status ENUM('requested','assigned','enroute','completed','cancelled') NOT NULL DEFAULT 'requested',

  pricing_currency CHAR(3) NOT NULL DEFAULT 'USD',
  driver_cost DECIMAL(10,2) NOT NULL,
  driver_profit DECIMAL(10,2) NOT NULL,
  platform_fee DECIMAL(10,2) NOT NULL,
  total_fare DECIMAL(10,2) NOT NULL,

  distance_km DECIMAL(10,3),
  duration_min DECIMAL(10,2),

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_rides_customer FOREIGN KEY (customer_id) REFERENCES users(id),
  CONSTRAINT fk_rides_driver FOREIGN KEY (driver_id) REFERENCES drivers(id),
  CONSTRAINT fk_rides_flight FOREIGN KEY (flight_id) REFERENCES flights(id),

  CONSTRAINT chk_airport_only CHECK (pickup_airport_code <> dropoff_airport_code),
  CONSTRAINT chk_fare_breakup CHECK (
    driver_cost >= 0 AND driver_profit >= 0 AND platform_fee >= 0
    AND total_fare = driver_cost + driver_profit + platform_fee
  ),

  INDEX idx_rides_status_created (status, created_at),
  INDEX idx_rides_customer_created (customer_id, created_at),
  INDEX idx_rides_driver_created (driver_id, created_at),
  INDEX idx_rides_flight (flight_id),
  INDEX idx_rides_airports_schedule (pickup_airport_code, dropoff_airport_code, scheduled_at)
) ENGINE=InnoDB;

-- 005_create_payments.sql
CREATE TABLE payments (
  id BINARY(16) PRIMARY KEY,
  ride_id BINARY(16) NOT NULL UNIQUE,
  payer_id BINARY(16) NOT NULL,
  amount_total DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  provider VARCHAR(50),
  provider_ref VARCHAR(100),
  status ENUM('pending','authorized','captured','refunded','failed') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  driver_cost DECIMAL(10,2) NOT NULL,
  driver_profit DECIMAL(10,2) NOT NULL,
  platform_fee DECIMAL(10,2) NOT NULL,

  CONSTRAINT fk_payments_ride FOREIGN KEY (ride_id) REFERENCES rides(id),
  CONSTRAINT fk_payments_payer FOREIGN KEY (payer_id) REFERENCES users(id),
  CONSTRAINT chk_payment_amounts CHECK (
    amount_total >= 0 AND driver_cost >= 0 AND driver_profit >= 0 AND platform_fee >= 0
    AND amount_total = driver_cost + driver_profit + platform_fee
  ),

  INDEX idx_payments_status_created (status, created_at),
  INDEX idx_payments_payer_created (payer_id, created_at)
) ENGINE=InnoDB;