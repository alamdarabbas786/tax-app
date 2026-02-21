-- 020_realtime_notifications.sql
-- Adds logs and indexes for real-time ride dispatch

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS notification_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ride_id BIGINT NULL,
  driver_id BIGINT NULL,
  event_type VARCHAR(50) NOT NULL,
  fcm_status_code INT NULL,
  fcm_status VARCHAR(20) NULL,
  fcm_error TEXT NULL,
  payload JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notification_logs_ride (ride_id, created_at),
  INDEX idx_notification_logs_driver (driver_id, created_at)
) ENGINE=InnoDB;

SET @table_exists := (
  SELECT COUNT(1)
  FROM information_schema.tables
  WHERE table_schema = DATABASE()
    AND table_name = 'ride_requests_tracking'
);

SET @idx_exists := (
  SELECT COUNT(1)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'ride_requests_tracking'
    AND index_name = 'idx_rrt_driver_status'
);

SET @ddl := IF(
  @table_exists = 0,
  'SELECT ''ride_requests_tracking missing - run 018_rapido_core_api.sql first'' AS message',
  IF(
    @idx_exists = 0,
  'ALTER TABLE ride_requests_tracking ADD INDEX idx_rrt_driver_status (driver_id, status)',
  'SELECT ''idx_rrt_driver_status already exists'' AS message'
  )
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
