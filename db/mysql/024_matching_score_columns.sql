-- Matching priority metadata for ride dispatch.
ALTER TABLE ride_driver_requests
  ADD COLUMN IF NOT EXISTS eta_min DECIMAL(8,2) NULL AFTER distance_km,
  ADD COLUMN IF NOT EXISTS match_score DECIMAL(10,4) NULL AFTER eta_min;

ALTER TABLE ride_driver_requests
  ADD INDEX IF NOT EXISTS idx_rdr_priority (ride_id, status, match_score, eta_min, distance_km);
