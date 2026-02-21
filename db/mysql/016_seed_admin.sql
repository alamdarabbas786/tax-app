-- 016_seed_admin.sql
-- Creates an initial admin record for OTP-based admin access
INSERT INTO admins (id, phone, email, full_name, is_active)
VALUES (UNHEX(REPLACE(UUID(),'-','')), '9999999999', 'admin@quickgo.in', 'QuickGo Admin', 1)
ON DUPLICATE KEY UPDATE full_name=VALUES(full_name), is_active=VALUES(is_active);
