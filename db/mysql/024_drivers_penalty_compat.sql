-- 024_drivers_penalty_compat.sql
-- Fix: Unknown column 'penalty_until' in drivers table

SET NAMES utf8mb4;

-- Add penalty_until only if it does not exist.
SELECT COUNT(*) INTO @has_penalty_until
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'drivers'
  AND COLUMN_NAME = 'penalty_until';

SET @sql_penalty_until = IF(
  @has_penalty_until = 0,
  'ALTER TABLE drivers ADD COLUMN penalty_until DATETIME NULL',
  'SELECT 1'
);
PREPARE stmt_penalty_until FROM @sql_penalty_until;
EXECUTE stmt_penalty_until;
DEALLOCATE PREPARE stmt_penalty_until;

-- Optional index for faster penalty checks.
SELECT COUNT(*) INTO @has_idx_penalty
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'drivers'
  AND INDEX_NAME = 'idx_drivers_penalty_until';

SET @sql_idx_penalty = IF(
  @has_idx_penalty = 0,
  'ALTER TABLE drivers ADD INDEX idx_drivers_penalty_until (penalty_until)',
  'SELECT 1'
);
PREPARE stmt_idx_penalty FROM @sql_idx_penalty;
EXECUTE stmt_idx_penalty;
DEALLOCATE PREPARE stmt_idx_penalty;

