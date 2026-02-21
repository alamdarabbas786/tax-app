-- 010_vehicle_pricing.sql
CREATE TABLE IF NOT EXISTS vehicle_pricing (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vehicle_type ENUM('bike','mini','toto','auto','sedan','xl') NOT NULL UNIQUE,
  cost_per_km DECIMAL(10,2) NOT NULL,
  cost_per_min DECIMAL(10,2) NOT NULL,
  minimum_fare DECIMAL(10,2) NOT NULL,
  driver_profit_margin DECIMAL(5,2) NOT NULL,
  platform_fee DECIMAL(10,2) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO vehicle_pricing (vehicle_type, cost_per_km, cost_per_min, minimum_fare, driver_profit_margin, platform_fee)
VALUES
('bike', 6.00, 1.00, 40.00, 0.22, 20.00),
('mini', 10.00, 1.50, 70.00, 0.22, 40.00),
('toto', 11.00, 1.60, 80.00, 0.22, 40.00),
('auto', 12.00, 1.80, 90.00, 0.23, 50.00),
('sedan', 14.00, 2.00, 110.00, 0.24, 60.00),
('xl', 16.00, 2.40, 140.00, 0.25, 70.00)
ON DUPLICATE KEY UPDATE
cost_per_km=VALUES(cost_per_km),
cost_per_min=VALUES(cost_per_min),
minimum_fare=VALUES(minimum_fare),
driver_profit_margin=VALUES(driver_profit_margin),
platform_fee=VALUES(platform_fee);
