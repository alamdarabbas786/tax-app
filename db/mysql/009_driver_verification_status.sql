-- 009_driver_verification_status.sql
ALTER TABLE drivers
  ADD COLUMN verification_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  ADD COLUMN verification_reason TEXT NULL;