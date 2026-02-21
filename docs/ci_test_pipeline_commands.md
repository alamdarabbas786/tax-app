# CI Test Pipeline Command Set

Run these from project root (`Taxi_project`).

## 1) Backend

1. Install dependencies:
```bash
composer install
```

2. Apply schema SQLs:
```bash
mysql -u appuser -p airport_taxi < db/mysql/018_rapido_core_api.sql
mysql -u appuser -p airport_taxi < db/mysql/020_realtime_notifications.sql
mysql -u appuser -p airport_taxi < db/mysql/021_notification_system_hardening.sql
mysql -u appuser -p airport_taxi < db/mysql/022_schema_unification.sql
```

3. Run server:
```bash
php -S 0.0.0.0:3000 -t public public/router.php
```

4. Run unit tests (new terminal):
```bash
composer test
```

5. Run integration tests (new terminal):
```bash
composer test:integration
```

6. Run strict integration tests (staging/prod-like, no skips):
```bash
composer test:integration:strict
```

## 2) Driver App Jest (login/registration/home flow)

```bash
cd frontend/driver-app
npm install
npm test
```

## 3) WebSocket Dispatch Tests

```bash
cd scripts/realtime
npm install
npm test
```

## 4) Full local test run (PowerShell)

```powershell
composer test
composer test:integration
npm --prefix frontend/driver-app test
npm --prefix scripts/realtime test
```
