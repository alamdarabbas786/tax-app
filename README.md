# Mobility MVP (Customer + Driver + Admin)

## Backend (PHP + MySQL)
1. Start MySQL:
   ```
   docker compose -f docker-compose.mysql.yml up -d
   ```
2. Apply schema:
   ```
   mysql -u appuser -p mobility < db/mysql/014_rapido_schema.sql
   mysql -u appuser -p mobility < db/mysql/015_vehicle_pricing_seed.sql
   mysql -u appuser -p mobility < db/mysql/025_wallet_commission_settlement.sql
   mysql -u appuser -p mobility < db/mysql/026_offers_coupons.sql
   ```
3. Seed initial admin (optional):
   ```
   mysql -u appuser -p mobility < db/mysql/016_seed_admin.sql
   # or
   powershell -ExecutionPolicy Bypass -File scripts/seed_admin.ps1
   ```
4. Start PHP server:
   ```
   composer run serve
   ```
5. Admin panel:
   ```
   http://localhost:3000/admin
   ```
   - Basic auth: `ADMIN_WEB_USER` / `ADMIN_WEB_PASS`

## Environment
Set these in `.env`:
- `MYSQL_DSN`, `MYSQL_USER`, `MYSQL_PASSWORD`
- `GOOGLE_MAPS_API_KEY`
- `FCM_SERVICE_ACCOUNT_PATH` (path to Firebase service account JSON)
- Admin auth keys (for /mysql-health/full and admin web)

## API Endpoints
- POST `/api/auth/request-otp` { phone, role }
- POST `/api/auth/verify-otp` { phone, otp, role, full_name, email }
- POST `/api/maps/route` { pickup, dropoff }
- POST `/api/fare/estimate` { distance_km, duration_minutes }
- GET `/api/offers/list` (Bearer customer)
- POST `/api/offers/apply` (Bearer customer)
- POST `/api/rides` (Bearer customer)
- GET `/api/rides/{rideId}` (Bearer customer/driver/admin)
- POST `/api/ratings` (Bearer customer)

Driver:
- POST `/api/driver/register` (Bearer driver, multipart)
- GET `/api/driver/me` (Bearer driver)
- GET `/api/driver/stats` (Bearer driver)
- GET `/api/driver/earnings` (Bearer driver)
- POST `/api/driver/location` (Bearer driver)
- POST `/api/driver/push-token` (Bearer driver)
- GET `/api/driver/requests` (Bearer driver)
- POST `/api/driver/availability` (Bearer driver)
- POST `/api/driver/rides/{rideId}/accept` (Bearer driver)
- POST `/api/driver/rides/{rideId}/arrived` (Bearer driver)
- POST `/api/driver/rides/{rideId}/start` (Bearer driver)
- POST `/api/driver/rides/{rideId}/complete` (Bearer driver)
- POST `/api/complete_ride.php` (Bearer driver, body: `ride_id`, optional `payment_mode`)
- POST `/api/driver/rides/{rideId}/cancel` (Bearer driver)

Wallet & Commission:
- POST `/api/calculate_commission.php` (Bearer customer/driver/admin)
- POST `/api/update_wallet.php` (Bearer admin)
- GET `/api/driver_wallet_summary.php` (Bearer driver/admin)
- POST `/api/payout_driver.php` (Bearer admin)

Admin Web:
- GET `/admin` (overview)
- GET `/admin/pricing`
- GET `/admin/rides`
- GET `/admin/drivers`
- GET `/admin/verification`
- GET `/admin/coupons`
- GET `/admin/fcm`
- POST `/admin/pricing-update`
- POST `/admin/coupon-generate`
- POST `/admin/approve-driver`
- POST `/admin/reject-driver`
- POST `/admin/block-driver`
- POST `/admin/unblock-driver`
- POST `/admin/ride-cancel`
- POST `/admin/ride-unassign`

## Frontend
### Customer app
```
cd frontend/customer-app
npm install
npm run start
```

### Driver app
```
cd frontend/driver-app
npm install
npm run start
```

Notes:
- Update API base URL in the apps if testing on a real device (use your machine LAN IP).
- Set `GOOGLE_PLACES_API_KEY` in `frontend/customer-app/App.js`.
- Driver app uses GPS; allow location permission when prompted.


