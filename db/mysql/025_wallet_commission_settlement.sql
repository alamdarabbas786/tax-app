-- 025_wallet_commission_settlement.sql
-- Commission settlement + wallet ledger support.

ALTER TABLE drivers
  ADD COLUMN IF NOT EXISTS wallet_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_rides,
  ADD COLUMN IF NOT EXISTS total_earnings DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER wallet_balance;

ALTER TABLE rides
  ADD COLUMN IF NOT EXISTS total_fare DECIMAL(10,2) NULL AFTER fare,
  ADD COLUMN IF NOT EXISTS commission_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_fare,
  ADD COLUMN IF NOT EXISTS payment_mode ENUM('cash','online') NOT NULL DEFAULT 'cash' AFTER commission_amount,
  ADD COLUMN IF NOT EXISTS wallet_settled TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_mode,
  ADD COLUMN IF NOT EXISTS wallet_settled_at DATETIME NULL AFTER wallet_settled;

UPDATE rides
SET payment_mode = CASE
    WHEN LOWER(TRIM(COALESCE(payment_method, 'cash'))) = 'online' THEN 'online'
    ELSE 'cash'
  END
WHERE payment_mode IS NULL OR payment_mode = '';

CREATE TABLE IF NOT EXISTS wallet_transactions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  driver_id VARBINARY(16) NULL,
  ride_id VARBINARY(16) NULL,
  transaction_type ENUM('credit', 'debit') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  description VARCHAR(255) NOT NULL,
  balance_before DECIMAL(12,2) NULL,
  balance_after DECIMAL(12,2) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_wallet_driver_created (driver_id, created_at),
  INDEX idx_wallet_ride (ride_id),
  INDEX idx_wallet_type (transaction_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
