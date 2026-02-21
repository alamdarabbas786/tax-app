-- 026_offers_coupons.sql
-- Backend-managed offers/coupons for customer payment screen.

CREATE TABLE IF NOT EXISTS offers (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL UNIQUE,
  title VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  discount_type ENUM('flat','percent') NOT NULL DEFAULT 'flat',
  discount_value DECIMAL(10,2) NOT NULL,
  max_discount DECIMAL(10,2) NULL,
  min_fare DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  payment_mode ENUM('any','cash','online') NOT NULL DEFAULT 'any',
  vehicle_type VARCHAR(40) NULL,
  new_user_only TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  start_at DATETIME NULL,
  end_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_offers_active_time (is_active, start_at, end_at),
  INDEX idx_offers_mode_vehicle (payment_mode, vehicle_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO offers (code, title, description, discount_type, discount_value, max_discount, min_fare, payment_mode, vehicle_type, new_user_only, is_active, start_at, end_at)
SELECT 'SAVE20', 'Save 20% (Up to Rs 30)', 'Get 20% discount up to Rs 30', 'percent', 20, 30, 80, 'any', NULL, 0, 1, NOW(), NULL
WHERE NOT EXISTS (SELECT 1 FROM offers WHERE code = 'SAVE20');

INSERT INTO offers (code, title, description, discount_type, discount_value, max_discount, min_fare, payment_mode, vehicle_type, new_user_only, is_active, start_at, end_at)
SELECT 'FLAT25', 'Flat Rs 25 Off', 'Flat Rs 25 off on your ride fare', 'flat', 25, NULL, 120, 'any', NULL, 0, 1, NOW(), NULL
WHERE NOT EXISTS (SELECT 1 FROM offers WHERE code = 'FLAT25');

INSERT INTO offers (code, title, description, discount_type, discount_value, max_discount, min_fare, payment_mode, vehicle_type, new_user_only, is_active, start_at, end_at)
SELECT 'NEW15', 'New User Rs 15 Off', 'First ride discount for new customers', 'flat', 15, NULL, 60, 'any', NULL, 1, 1, NOW(), NULL
WHERE NOT EXISTS (SELECT 1 FROM offers WHERE code = 'NEW15');
