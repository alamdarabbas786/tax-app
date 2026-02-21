-- 018_rapido_core_api.sql
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(32) NOT NULL UNIQUE,
  otp VARCHAR(4) NOT NULL DEFAULT '1234',
  fcm_token VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS drivers (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(32) NOT NULL UNIQUE,
  vehicle_number VARCHAR(32) NOT NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  online_status TINYINT(1) NOT NULL DEFAULT 0,
  availability TINYINT(1) NOT NULL DEFAULT 1,
  ride_status ENUM('free','busy') NOT NULL DEFAULT 'free',
  rating DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  fcm_token VARCHAR(255) NULL,
  last_seen_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_driver_online (online_status, availability, ride_status),
  INDEX idx_driver_location (latitude, longitude)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rides (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  customer_id BIGINT NOT NULL,
  driver_id BIGINT NULL,
  pickup_lat DECIMAL(10,7) NOT NULL,
  pickup_lng DECIMAL(10,7) NOT NULL,
  drop_lat DECIMAL(10,7) NOT NULL,
  drop_lng DECIMAL(10,7) NOT NULL,
  fare DECIMAL(10,2) NOT NULL,
  driver_profit DECIMAL(10,2) NOT NULL DEFAULT 0,
  payment_method VARCHAR(30) NOT NULL,
  status ENUM(
    'searching','accepted','arrived','ride_started','in_progress',
    'awaiting_payment','completed','rejected','expired'
  ) NOT NULL DEFAULT 'searching',
  accepted_at DATETIME NULL,
  started_at DATETIME NULL,
  in_progress_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_rides_user FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_rides_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE SET NULL,
  INDEX idx_rides_status (status),
  INDEX idx_rides_driver_status (driver_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ride_requests_tracking (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ride_id BIGINT NOT NULL,
  driver_id BIGINT NOT NULL,
  status ENUM('pending','accepted','rejected','expired','queued') NOT NULL DEFAULT 'pending',
  distance_km DECIMAL(8,3) NOT NULL,
  offered_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  responded_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_rrt_ride FOREIGN KEY (ride_id) REFERENCES rides(id) ON DELETE CASCADE,
  CONSTRAINT fk_rrt_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE,
  UNIQUE KEY uq_ride_driver_attempt (ride_id, driver_id),
  INDEX idx_rrt_ride_status (ride_id, status),
  INDEX idx_rrt_expiry (status, expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ride_id BIGINT NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  payment_status ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_payments_ride FOREIGN KEY (ride_id) REFERENCES rides(id) ON DELETE CASCADE,
  UNIQUE KEY uq_payment_ride (ride_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ratings (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ride_id BIGINT NOT NULL,
  customer_id BIGINT NOT NULL,
  driver_id BIGINT NOT NULL,
  rating TINYINT NOT NULL,
  feedback VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ratings_ride2 FOREIGN KEY (ride_id) REFERENCES rides(id) ON DELETE CASCADE,
  CONSTRAINT fk_ratings_user2 FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ratings_driver2 FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE,
  INDEX idx_driver_rating (driver_id),
  INDEX idx_customer_rating (customer_id)
) ENGINE=InnoDB;
