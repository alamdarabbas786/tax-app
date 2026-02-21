-- 023_strict_runtime_hotfix.sql
-- Purpose: reconcile mixed airport schema with rapido-style API expectations
-- so strict integration tests can run without schema errors.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS customers (
  id BINARY(16) PRIMARY KEY,
  phone VARCHAR(32) NOT NULL UNIQUE,
  email VARCHAR(255) NULL,
  full_name VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_customers_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DELIMITER $$

DROP PROCEDURE IF EXISTS add_col_if_missing $$
CREATE PROCEDURE add_col_if_missing(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_def TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column
  ) THEN
    SET @s = CONCAT('ALTER TABLE ', p_table, ' ADD COLUMN ', p_column, ' ', p_def);
    PREPARE stmt FROM @s;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END $$

DELIMITER ;

CALL add_col_if_missing('drivers', 'name', 'VARCHAR(120) NULL');
CALL add_col_if_missing('drivers', 'phone', 'VARCHAR(32) NULL');
CALL add_col_if_missing('drivers', 'email', 'VARCHAR(255) NULL');
CALL add_col_if_missing('drivers', 'vehicle_type', 'VARCHAR(24) NULL');
CALL add_col_if_missing('drivers', 'address', 'VARCHAR(255) NULL');
CALL add_col_if_missing('drivers', 'vehicle_rc_path', 'VARCHAR(255) NULL');
CALL add_col_if_missing('drivers', 'driving_license_path', 'VARCHAR(255) NULL');
CALL add_col_if_missing('drivers', 'aadhaar_card_path', 'VARCHAR(255) NULL');
CALL add_col_if_missing('drivers', 'driver_photo_path', 'VARCHAR(255) NULL');
CALL add_col_if_missing('drivers', 'insurance_doc_path', 'VARCHAR(255) NULL');
CALL add_col_if_missing('drivers', 'puc_doc_path', 'VARCHAR(255) NULL');
CALL add_col_if_missing('drivers', 'is_verified', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL add_col_if_missing('drivers', 'is_blocked', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL add_col_if_missing('drivers', 'current_ride_id', 'BINARY(16) NULL');
CALL add_col_if_missing('drivers', 'penalty_until', 'DATETIME NULL');

UPDATE drivers d
JOIN users u ON u.id = d.user_id
SET
  d.name = COALESCE(d.name, u.full_name),
  d.phone = COALESCE(d.phone, u.phone),
  d.email = COALESCE(d.email, u.email),
  d.address = COALESCE(d.address, d.address_line),
  d.vehicle_rc_path = COALESCE(d.vehicle_rc_path, d.rc_file),
  d.driving_license_path = COALESCE(d.driving_license_path, d.driving_license_file),
  d.aadhaar_card_path = COALESCE(d.aadhaar_card_path, d.aadhaar_file);

ALTER TABLE rides
  MODIFY COLUMN pickup_airport_code CHAR(3) NULL,
  MODIFY COLUMN dropoff_airport_code CHAR(3) NULL,
  MODIFY COLUMN scheduled_at DATETIME NULL,
  MODIFY COLUMN status ENUM(
    'requested', 'assigned', 'arrived', 'enroute', 'completed', 'cancelled',
    'searching', 'driver_assigned', 'driver_arrived', 'ride_started', 'in_progress',
    'awaiting_payment', 'ride_completed', 'ride_closed', 'no_driver_found'
  ) NOT NULL DEFAULT 'searching';

CALL add_col_if_missing('rides', 'drop_lat', 'DECIMAL(10,7) NULL');
CALL add_col_if_missing('rides', 'drop_lng', 'DECIMAL(10,7) NULL');
CALL add_col_if_missing('rides', 'pickup_address', 'VARCHAR(255) NULL');
CALL add_col_if_missing('rides', 'drop_address', 'VARCHAR(255) NULL');
CALL add_col_if_missing('rides', 'distance_km', 'DECIMAL(10,3) NULL');
CALL add_col_if_missing('rides', 'duration_min', 'DECIMAL(10,2) NULL');
CALL add_col_if_missing('rides', 'vehicle_type', 'VARCHAR(24) NULL');
CALL add_col_if_missing('rides', 'fare', 'DECIMAL(10,2) NULL');
CALL add_col_if_missing('rides', 'driver_cost', 'DECIMAL(10,2) NULL');
CALL add_col_if_missing('rides', 'driver_earning', 'DECIMAL(10,2) NULL');
CALL add_col_if_missing('rides', 'platform_fee', 'DECIMAL(10,2) NULL');
CALL add_col_if_missing('rides', 'total_fare', 'DECIMAL(10,2) NULL');
CALL add_col_if_missing('rides', 'otp_code', 'VARCHAR(8) NULL');
CALL add_col_if_missing('rides', 'otp_expires_at', 'DATETIME NULL');
CALL add_col_if_missing('rides', 'searching_started_at', 'DATETIME NULL');
CALL add_col_if_missing('rides', 'no_driver_found_at', 'DATETIME NULL');
CALL add_col_if_missing('rides', 'assigned_at', 'DATETIME NULL');

UPDATE rides
SET
  pickup_address = COALESCE(pickup_address, CONCAT('Pickup (', pickup_lat, ',', pickup_lng, ')')),
  drop_lat = COALESCE(drop_lat, dropoff_lat),
  drop_lng = COALESCE(drop_lng, dropoff_lng),
  drop_address = COALESCE(drop_address, dropoff_address),
  fare = COALESCE(fare, total_fare),
  driver_cost = COALESCE(driver_cost, fare - driver_profit),
  driver_earning = COALESCE(driver_earning, driver_cost + driver_profit);

CREATE TABLE IF NOT EXISTS ride_driver_requests (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ride_id BINARY(16) NOT NULL,
  driver_id BINARY(16) NOT NULL,
  status ENUM('queued', 'pending', 'accepted', 'rejected', 'expired') NOT NULL DEFAULT 'queued',
  distance_km DECIMAL(8,3) NOT NULL DEFAULT 0,
  sent_at DATETIME NULL,
  expires_at DATETIME NULL,
  responded_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ride_driver (ride_id, driver_id),
  INDEX idx_rdr_ride_status (ride_id, status),
  INDEX idx_rdr_driver_status (driver_id, status),
  INDEX idx_rdr_expiry (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ride_status_history (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ride_id BINARY(16) NOT NULL,
  status VARCHAR(40) NOT NULL,
  changed_by_role ENUM('customer', 'driver', 'admin', 'system') NOT NULL DEFAULT 'system',
  changed_by_id VARBINARY(32) NULL,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rsh_ride_created (ride_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP PROCEDURE IF EXISTS add_col_if_missing;

-- rides.customer_id currently referenced users(id); API writes customers(id) for customer auth flow.
ALTER TABLE rides DROP FOREIGN KEY fk_rides_customer;
ALTER TABLE rides
  ADD CONSTRAINT fk_rides_customer_customers
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE;

DROP TRIGGER IF EXISTS trg_rides_fill_total_fare;
DELIMITER $$
CREATE TRIGGER trg_rides_fill_total_fare
BEFORE INSERT ON rides
FOR EACH ROW
BEGIN
  IF NEW.total_fare IS NULL OR NEW.total_fare = 0 THEN
    IF NEW.fare IS NOT NULL THEN
      SET NEW.total_fare = NEW.fare;
    ELSE
      SET NEW.total_fare = COALESCE(NEW.driver_cost, 0) + COALESCE(NEW.driver_profit, 0) + COALESCE(NEW.platform_fee, 0);
    END IF;
  END IF;
END $$
DELIMITER ;
