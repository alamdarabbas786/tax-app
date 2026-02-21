-- 015_vehicle_pricing_seed.sql
INSERT INTO vehicle_pricing (vehicle_type, cost_per_km, cost_per_min, minimum_fare, driver_profit_margin, platform_fee, is_active)
VALUES
('bike', 6.00, 1.00, 40.00, 0.22, 20.00, 1),
('mini', 10.00, 1.50, 70.00, 0.22, 40.00, 1),
('toto', 11.00, 1.60, 80.00, 0.22, 40.00, 1),
('auto', 12.00, 1.80, 90.00, 0.23, 50.00, 1),
('sedan', 14.00, 2.00, 110.00, 0.24, 60.00, 1),
('xl', 16.00, 2.40, 140.00, 0.25, 70.00, 1)
ON DUPLICATE KEY UPDATE
cost_per_km=VALUES(cost_per_km),
cost_per_min=VALUES(cost_per_min),
minimum_fare=VALUES(minimum_fare),
driver_profit_margin=VALUES(driver_profit_margin),
platform_fee=VALUES(platform_fee),
is_active=VALUES(is_active);
