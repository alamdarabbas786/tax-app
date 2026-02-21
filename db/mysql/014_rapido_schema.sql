-- 014_rapido_schema.sql
-- WARNING: This script rebuilds core tables. Use on a fresh database or after backups.
SET NAMES utf8mb4;

DROP TABLE IF EXISTS ratings;
DROP TABLE IF EXISTS rides;
DROP TABLE IF EXISTS vehicle_pricing;
DROP TABLE IF EXISTS auth_tokens;
DROP TABLE IF EXISTS auth_otps;
DROP TABLE IF EXISTS drivers;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS admins;

CREATE TABLE customers (
  id BINARY(16) PRIMARY KEY,
  phone VARCHAR(32) NOT NULL UNIQUE,
  email VARCHAR(255) NULL UNIQUE,
  full_name VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_customers_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE admins (
  id BINARY(16) PRIMARY KEY,
  phone VARCHAR(32) NULL UNIQUE,
  email VARCHAR(255) NULL UNIQUE,
  full_name VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE drivers (
  id BINARY(16) PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(32) NOT NULL UNIQUE,
  email VARCHAR(255) NULL UNIQUE,
  vehicle_type ENUM('bike','mini','toto','auto','sedan','xl') NOT NULL,
  vehicle_number VARCHAR(32) NOT NULL,
  license_number VARCHAR(64) NOT NULL UNIQUE,
  address VARCHAR(255) NOT NULL,
  city VARCHAR(80) NOT NULL,
  pin_code VARCHAR(12) NOT NULL,
  aadhaar_number VARCHAR(20) NOT NULL,
  vehicle_rc_path VARCHAR(255) NOT NULL,
  driving_license_path VARCHAR(255) NOT NULL,
  aadhaar_card_path VARCHAR(255) NOT NULL,
  driver_photo_path VARCHAR(255) NOT NULL,
  is_verified TINYINT(1) NOT NULL DEFAULT 0,
  is_available TINYINT(1) NOT NULL DEFAULT 0,
  is_blocked TINYINT(1) NOT NULL DEFAULT 0,
  verification_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  verification_reason TEXT NULL,
  rating DECIMAL(3,2) NOT NULL DEFAULT 0.0,
  total_rides INT NOT NULL DEFAULT 0,
  current_lat DECIMAL(10,7) NULL,
  current_lng DECIMAL(10,7) NULL,
  last_ping_at DATETIME NULL,
  fcm_token VARCHAR(255) NULL,
  penalty_until DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_drivers_status (is_verified, is_available, is_blocked, vehicle_type),
  INDEX idx_drivers_location (current_lat, current_lng),
  INDEX idx_drivers_last_ping (last_ping_at),
  INDEX idx_drivers_penalty (penalty_until)
) ENGINE=InnoDB;

CREATE TABLE auth_otps (
  id BINARY(16) PRIMARY KEY,
  phone VARCHAR(32) NOT NULL,
  role ENUM('customer','driver','admin') NOT NULL,
  otp_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_otps_phone_role (phone, role),
  INDEX idx_otps_expires (expires_at)
) ENGINE=InnoDB;

CREATE TABLE auth_tokens (
  id BINARY(16) PRIMARY KEY,
  role ENUM('customer','driver','admin') NOT NULL,
  subject_id BINARY(16) NULL,
  phone VARCHAR(32) NOT NULL,
  token VARCHAR(80) NOT NULL UNIQUE,
  expires_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tokens_subject (subject_id, role),
  INDEX idx_tokens_phone_role (phone, role)
) ENGINE=InnoDB;

CREATE TABLE vehicle_pricing (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vehicle_type ENUM('bike','mini','toto','auto','sedan','xl') NOT NULL UNIQUE,
  cost_per_km DECIMAL(10,2) NOT NULL,
  cost_per_min DECIMAL(10,2) NOT NULL,
  minimum_fare DECIMAL(10,2) NOT NULL,
  driver_profit_margin DECIMAL(5,2) NOT NULL,
  platform_fee DECIMAL(10,2) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_profit_margin CHECK (driver_profit_margin >= 0.22 AND driver_profit_margin <= 0.30)
) ENGINE=InnoDB;

CREATE TABLE rides (
  id BINARY(16) PRIMARY KEY,
  customer_id BINARY(16) NOT NULL,
  driver_id BINARY(16) NULL,
  pickup_lat DECIMAL(10,7) NOT NULL,
  pickup_lng DECIMAL(10,7) NOT NULL,
  drop_lat DECIMAL(10,7) NOT NULL,
  drop_lng DECIMAL(10,7) NOT NULL,
  pickup_address VARCHAR(255) NOT NULL,
  drop_address VARCHAR(255) NOT NULL,
  vehicle_type ENUM('bike','mini','toto','auto','sedan','xl') NOT NULL,
  distance_km DECIMAL(10,3) NOT NULL,
  duration_min DECIMAL(10,2) NOT NULL,
  fare DECIMAL(10,2) NOT NULL,
  driver_cost DECIMAL(10,2) NOT NULL,
  driver_profit DECIMAL(10,2) NOT NULL,
  driver_earning DECIMAL(10,2) NOT NULL,
  platform_fee DECIMAL(10,2) NOT NULL,
  status ENUM('requested','assigned','arrived','enroute','completed','cancelled','timeout') NOT NULL DEFAULT 'requested',
  cancelled_by ENUM('customer','driver','admin') NULL,
  cancel_reason VARCHAR(255) NULL,
  assigned_at DATETIME NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_rides_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_rides_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE SET NULL,
  CONSTRAINT chk_fare_breakup CHECK (
    driver_cost >= 0 AND driver_profit >= 0 AND platform_fee >= 0 AND fare = driver_cost + driver_profit + platform_fee AND driver_earning = driver_cost + driver_profit
  ),
  INDEX idx_rides_status_vehicle (status, vehicle_type),
  INDEX idx_rides_customer_created (customer_id, created_at),
  INDEX idx_rides_driver_created (driver_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE ratings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ride_id BINARY(16) NOT NULL,
  driver_id BINARY(16) NOT NULL,
  customer_id BINARY(16) NOT NULL,
  rating TINYINT NOT NULL,
  comment VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ratings_ride FOREIGN KEY (ride_id) REFERENCES rides(id) ON DELETE CASCADE,
  CONSTRAINT fk_ratings_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE,
  CONSTRAINT fk_ratings_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  INDEX idx_ratings_driver (driver_id),
  INDEX idx_ratings_customer (customer_id)
) ENGINE=InnoDB;
