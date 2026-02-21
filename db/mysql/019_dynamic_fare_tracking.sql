-- 019_dynamic_fare_tracking.sql
-- Server-side ride tracking fields for dynamic fare calculation.

ALTER TABLE rides
  ADD COLUMN planned_distance_km DECIMAL(10,3) NULL AFTER distance_km,
  ADD COLUMN planned_duration_min DECIMAL(10,2) NULL AFTER duration_min,
  ADD COLUMN estimated_fare DECIMAL(10,2) NULL AFTER fare,
  ADD COLUMN ride_start_time DATETIME NULL AFTER ride_started_at,
  ADD COLUMN ride_end_time DATETIME NULL AFTER ride_completed_at,
  ADD COLUMN start_lat DECIMAL(10,7) NULL AFTER ride_start_time,
  ADD COLUMN start_lng DECIMAL(10,7) NULL AFTER start_lat,
  ADD COLUMN last_lat DECIMAL(10,7) NULL AFTER start_lng,
  ADD COLUMN last_lng DECIMAL(10,7) NULL AFTER last_lat,
  ADD COLUMN end_lat DECIMAL(10,7) NULL AFTER last_lng,
  ADD COLUMN end_lng DECIMAL(10,7) NULL AFTER end_lat,
  ADD COLUMN total_distance_km DECIMAL(10,3) NOT NULL DEFAULT 0 AFTER end_lng,
  ADD COLUMN total_duration_min INT NOT NULL DEFAULT 0 AFTER total_distance_km,
  ADD COLUMN final_fare DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER total_duration_min,
  ADD COLUMN surge_multiplier DECIMAL(5,2) NOT NULL DEFAULT 1.00 AFTER final_fare,
  ADD INDEX idx_rides_tracking (status, ride_start_time);

