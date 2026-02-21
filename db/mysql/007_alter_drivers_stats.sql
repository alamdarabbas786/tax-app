-- 007_alter_drivers_stats.sql
ALTER TABLE drivers
  ADD COLUMN rating FLOAT NOT NULL DEFAULT 0.0,
  ADD COLUMN total_rides INT NOT NULL DEFAULT 0;
