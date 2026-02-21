-- 022_schema_unification.sql
-- Purpose: reconcile mixed legacy/new schemas so runtime code paths stop breaking.
-- Safe to run multiple times.

SET NAMES utf8mb4;

DELIMITER $$

DROP PROCEDURE IF EXISTS migrate_022 $$
CREATE PROCEDURE migrate_022()
BEGIN
  DECLARE v_rides_id_type VARCHAR(64) DEFAULT 'binary(16)';
  DECLARE v_drivers_id_type VARCHAR(64) DEFAULT 'binary(16)';
  DECLARE v_has_drivers INT DEFAULT 0;

  -- Core auth tables used by API auth.
  CREATE TABLE IF NOT EXISTS auth_otps (
    id BINARY(16) PRIMARY KEY,
    phone VARCHAR(32) NOT NULL,
    role ENUM('customer','driver','admin') NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_otps_phone_role (phone, role),
    INDEX idx_otps_expires (expires_at)
  ) ENGINE=InnoDB;

  CREATE TABLE IF NOT EXISTS auth_tokens (
    id BINARY(16) PRIMARY KEY,
    role ENUM('customer','driver','admin') NOT NULL,
    subject_id VARBINARY(32) NULL,
    phone VARCHAR(32) NOT NULL,
    token VARCHAR(80) NOT NULL UNIQUE,
    expires_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tokens_subject (subject_id, role),
    INDEX idx_tokens_phone_role (phone, role)
  ) ENGINE=InnoDB;

  SELECT COUNT(*) INTO v_has_drivers
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drivers';

  IF v_has_drivers = 1 THEN
    -- Add compatibility columns expected by new API paths.
    ALTER TABLE drivers ADD COLUMN IF NOT EXISTS name VARCHAR(120) NULL;
    ALTER TABLE drivers ADD COLUMN IF NOT EXISTS phone VARCHAR(32) NULL;
    ALTER TABLE drivers ADD COLUMN IF NOT EXISTS email VARCHAR(255) NULL;
    ALTER TABLE drivers ADD COLUMN IF NOT EXISTS vehicle_type VARCHAR(24) NULL;
    ALTER TABLE drivers ADD COLUMN IF NOT EXISTS address VARCHAR(255) NULL;
    ALTER TABLE drivers ADD COLUMN IF NOT EXISTS vehicle_rc_path VARCHAR(255) NULL;
    ALTER TABLE drivers ADD COLUMN IF NOT EXISTS driving_license_path VARCHAR(255) NULL;
    ALTER TABLE drivers ADD COLUMN IF NOT EXISTS aadhaar_card_path VARCHAR(255) NULL;
    ALTER TABLE drivers ADD COLUMN IF NOT EXISTS driver_photo_path VARCHAR(255) NULL;
    ALTER TABLE drivers ADD COLUMN IF NOT EXISTS insurance_doc_path VARCHAR(255) NULL;
    ALTER TABLE drivers ADD COLUMN IF NOT EXISTS puc_doc_path VARCHAR(255) NULL;
    ALTER TABLE drivers ADD COLUMN IF NOT EXISTS is_verified TINYINT(1) NOT NULL DEFAULT 0;
    ALTER TABLE drivers ADD COLUMN IF NOT EXISTS is_blocked TINYINT(1) NOT NULL DEFAULT 0;
    ALTER TABLE drivers ADD COLUMN IF NOT EXISTS verification_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending';
    ALTER TABLE drivers ADD COLUMN IF NOT EXISTS verification_reason TEXT NULL;
    ALTER TABLE drivers ADD COLUMN IF NOT EXISTS current_lat DECIMAL(10,7) NULL;
    ALTER TABLE drivers ADD COLUMN IF NOT EXISTS current_lng DECIMAL(10,7) NULL;
    ALTER TABLE drivers ADD COLUMN IF NOT EXISTS last_ping_at DATETIME NULL;
    ALTER TABLE drivers ADD COLUMN IF NOT EXISTS current_ride_id VARBINARY(32) NULL;

    -- Legacy aliases.
    IF EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drivers' AND COLUMN_NAME = 'latitude'
    ) THEN
      UPDATE drivers SET current_lat = COALESCE(current_lat, latitude);
    END IF;
    IF EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drivers' AND COLUMN_NAME = 'longitude'
    ) THEN
      UPDATE drivers SET current_lng = COALESCE(current_lng, longitude);
    END IF;
    IF EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drivers' AND COLUMN_NAME = 'last_seen_at'
    ) THEN
      UPDATE drivers SET last_ping_at = COALESCE(last_ping_at, last_seen_at);
    END IF;
    IF EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drivers' AND COLUMN_NAME = 'rc_file'
    ) THEN
      UPDATE drivers SET vehicle_rc_path = COALESCE(vehicle_rc_path, rc_file);
    END IF;
    IF EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drivers' AND COLUMN_NAME = 'driving_license_file'
    ) THEN
      UPDATE drivers SET driving_license_path = COALESCE(driving_license_path, driving_license_file);
    END IF;
    IF EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drivers' AND COLUMN_NAME = 'aadhaar_file'
    ) THEN
      UPDATE drivers SET aadhaar_card_path = COALESCE(aadhaar_card_path, aadhaar_file);
    END IF;
    IF EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drivers' AND COLUMN_NAME = 'address_line'
    ) THEN
      UPDATE drivers SET address = COALESCE(address, address_line);
    END IF;

    -- Backfill phone/name/email from users where possible.
    IF EXISTS (
      SELECT 1 FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
    ) AND EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drivers' AND COLUMN_NAME = 'user_id'
    ) THEN
      UPDATE drivers d
      JOIN users u ON u.id = d.user_id
      SET
        d.phone = COALESCE(d.phone, u.phone),
        d.name = COALESCE(d.name, u.full_name),
        d.email = COALESCE(d.email, u.email);
    END IF;
  END IF;

  -- Ensure customers table for customer role code paths.
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers'
  ) THEN
    CREATE TABLE customers (
      id BINARY(16) PRIMARY KEY,
      phone VARCHAR(32) NOT NULL UNIQUE,
      email VARCHAR(255) NULL UNIQUE,
      full_name VARCHAR(120) NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;
  END IF;

  -- Dynamic id types for request/history tables.
  IF EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rides' AND COLUMN_NAME = 'id'
  ) THEN
    SELECT COLUMN_TYPE INTO v_rides_id_type
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rides' AND COLUMN_NAME = 'id'
    LIMIT 1;
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drivers' AND COLUMN_NAME = 'id'
  ) THEN
    SELECT COLUMN_TYPE INTO v_drivers_id_type
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drivers' AND COLUMN_NAME = 'id'
    LIMIT 1;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ride_driver_requests'
  ) THEN
    SET @sql_req = CONCAT(
      'CREATE TABLE ride_driver_requests (',
      'id BIGINT AUTO_INCREMENT PRIMARY KEY,',
      'ride_id ', v_rides_id_type, ' NOT NULL,',
      'driver_id ', v_drivers_id_type, ' NOT NULL,',
      'status ENUM(\"queued\",\"pending\",\"accepted\",\"rejected\",\"expired\") NOT NULL DEFAULT \"queued\",',
      'distance_km DECIMAL(8,3) NOT NULL DEFAULT 0,',
      'sent_at DATETIME NULL,',
      'expires_at DATETIME NULL,',
      'responded_at DATETIME NULL,',
      'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,',
      'updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,',
      'UNIQUE KEY uq_ride_driver (ride_id, driver_id),',
      'INDEX idx_rdr_ride_status (ride_id, status),',
      'INDEX idx_rdr_driver_status (driver_id, status),',
      'INDEX idx_rdr_expiry (status, expires_at)',
      ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    PREPARE stmt_req FROM @sql_req;
    EXECUTE stmt_req;
    DEALLOCATE PREPARE stmt_req;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ride_status_history'
  ) THEN
    SET @sql_hist = CONCAT(
      'CREATE TABLE ride_status_history (',
      'id BIGINT AUTO_INCREMENT PRIMARY KEY,',
      'ride_id ', v_rides_id_type, ' NOT NULL,',
      'status VARCHAR(40) NOT NULL,',
      'changed_by_role ENUM(\"customer\",\"driver\",\"admin\",\"system\") NOT NULL DEFAULT \"system\",',
      'changed_by_id ', v_drivers_id_type, ' NULL,',
      'note VARCHAR(255) NULL,',
      'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,',
      'INDEX idx_rsh_ride_created (ride_id, created_at)',
      ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    PREPARE stmt_hist FROM @sql_hist;
    EXECUTE stmt_hist;
    DEALLOCATE PREPARE stmt_hist;
  END IF;

END $$

CALL migrate_022() $$
DROP PROCEDURE migrate_022 $$

DELIMITER ;
