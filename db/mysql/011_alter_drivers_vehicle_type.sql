-- 011_alter_drivers_vehicle_type.sql
ALTER TABLE drivers
  ADD COLUMN vehicle_type ENUM('bike','mini','toto','auto','sedan','xl') NULL AFTER email,
  ADD COLUMN is_blocked TINYINT(1) NOT NULL DEFAULT 0 AFTER is_verified,
  ADD COLUMN fcm_token VARCHAR(255) NULL AFTER last_ping_at;
