-- 012_alter_rides_vehicle_type.sql
ALTER TABLE rides
  ADD COLUMN vehicle_type ENUM('bike','mini','toto','auto','sedan','xl') NULL AFTER dropoff_airport_code;
