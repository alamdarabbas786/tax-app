-- 017_ride_lifecycle.sql
-- Adds ride lifecycle fields and matching support

ALTER TABLE vehicle_pricing
  ADD COLUMN waiting_rate_per_min DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER platform_fee;

ALTER TABLE drivers
  ADD COLUMN current_ride_id BINARY(16) NULL AFTER last_ping_at,
  ADD INDEX idx_drivers_current_ride (current_ride_id);

ALTER TABLE rides
  MODIFY status ENUM(
    'searching','driver_assigned','driver_arriving','driver_arrived','waiting','ride_started','ride_completed','ride_closed','no_driver_found','cancelled'
  ) NOT NULL DEFAULT 'searching',
  ADD COLUMN otp_code VARCHAR(4) NULL AFTER platform_fee,
  ADD COLUMN otp_expires_at DATETIME NULL AFTER otp_code,
  ADD COLUMN waiting_started_at DATETIME NULL AFTER otp_expires_at,
  ADD COLUMN waiting_minutes INT NOT NULL DEFAULT 0 AFTER waiting_started_at,
  ADD COLUMN waiting_charge DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER waiting_minutes,
  ADD COLUMN searching_started_at DATETIME NULL AFTER waiting_charge,
  ADD COLUMN driver_arriving_at DATETIME NULL AFTER searching_started_at,
  ADD COLUMN driver_arrived_at DATETIME NULL AFTER driver_arriving_at,
  ADD COLUMN ride_started_at DATETIME NULL AFTER driver_arrived_at,
  ADD COLUMN ride_completed_at DATETIME NULL AFTER ride_started_at,
  ADD COLUMN ride_closed_at DATETIME NULL AFTER ride_completed_at,
  ADD COLUMN no_driver_found_at DATETIME NULL AFTER ride_closed_at;

CREATE TABLE IF NOT EXISTS ride_status_history (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ride_id BINARY(16) NOT NULL,
  status VARCHAR(40) NOT NULL,
  changed_by_role ENUM('customer','driver','admin','system') NOT NULL DEFAULT 'system',
  changed_by_id BINARY(16) NULL,
  note VARCHAR(255) NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ride_status_history_ride (ride_id, changed_at),
  CONSTRAINT fk_ride_status_history_ride FOREIGN KEY (ride_id) REFERENCES rides(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ride_driver_requests (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ride_id BINARY(16) NOT NULL,
  driver_id BINARY(16) NOT NULL,
  status ENUM('queued','pending','accepted','rejected','expired') NOT NULL DEFAULT 'queued',
  distance_km DECIMAL(10,2) NOT NULL,
  sent_at DATETIME NULL,
  responded_at DATETIME NULL,
  expires_at DATETIME NULL,
  UNIQUE KEY uq_ride_driver (ride_id, driver_id),
  INDEX idx_driver_requests_ride (ride_id, status),
  INDEX idx_driver_requests_driver (driver_id, status),
  CONSTRAINT fk_driver_requests_ride FOREIGN KEY (ride_id) REFERENCES rides(id) ON DELETE CASCADE,
  CONSTRAINT fk_driver_requests_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE
) ENGINE=InnoDB;
