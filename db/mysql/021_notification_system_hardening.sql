-- 021_notification_system_hardening.sql
-- Dedicated device-token registry + notification history/attempt tracking

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS fcm_device_tokens (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  role ENUM('driver', 'customer') NOT NULL,
  entity_id BIGINT NOT NULL,
  device_id VARCHAR(120) NOT NULL DEFAULT 'default',
  platform ENUM('android', 'ios', 'web', 'unknown') NOT NULL DEFAULT 'unknown',
  fcm_token VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fcm_role_entity_device (role, entity_id, device_id),
  UNIQUE KEY uq_fcm_token (fcm_token),
  INDEX idx_fcm_entity_active (role, entity_id, is_active, last_seen_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ride_id BIGINT NULL,
  recipient_role ENUM('driver', 'customer', 'system') NOT NULL DEFAULT 'system',
  recipient_id BIGINT NULL,
  event_type VARCHAR(64) NOT NULL,
  channel ENUM('fcm', 'websocket', 'system') NOT NULL,
  title VARCHAR(160) NULL,
  body VARCHAR(255) NULL,
  payload JSON NULL,
  status ENUM('queued', 'sent', 'failed', 'expired') NOT NULL DEFAULT 'queued',
  error_message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  failed_at DATETIME NULL,
  INDEX idx_notifications_ride (ride_id, created_at),
  INDEX idx_notifications_recipient (recipient_role, recipient_id, created_at),
  INDEX idx_notifications_event (event_type, channel, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notification_delivery_attempts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  notification_id BIGINT NOT NULL,
  attempt_no INT NOT NULL,
  status_code INT NULL,
  is_success TINYINT(1) NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  response_body TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_nda_notification FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
  INDEX idx_nda_notification (notification_id, attempt_no)
) ENGINE=InnoDB;
