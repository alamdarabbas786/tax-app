-- 006_alter_driver_ride_geo.sql
ALTER TABLE drivers
  ADD COLUMN current_lat DECIMAL(10,7) NULL,
  ADD COLUMN current_lng DECIMAL(10,7) NULL,
  ADD COLUMN last_ping_at DATETIME NULL,
  ADD COLUMN fcm_token VARCHAR(255) NULL;

ALTER TABLE rides
  ADD COLUMN pickup_address VARCHAR(255) NULL,
  ADD COLUMN dropoff_address VARCHAR(255) NULL,
  ADD COLUMN pickup_lat DECIMAL(10,7) NULL,
  ADD COLUMN pickup_lng DECIMAL(10,7) NULL,
  ADD COLUMN dropoff_lat DECIMAL(10,7) NULL,
  ADD COLUMN dropoff_lng DECIMAL(10,7) NULL;