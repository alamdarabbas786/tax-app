-- 008_alter_rides_status.sql
ALTER TABLE rides
  MODIFY COLUMN status ENUM('requested','assigned','arrived','enroute','completed','cancelled') NOT NULL DEFAULT 'requested';