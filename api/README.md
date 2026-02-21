# Core PHP Real-Time Taxi API

Backend stack implemented:
- `Core PHP + MySQL`
- `Redis GEO + locks + pub/sub`
- `FCM HTTP v1`
- `WebSocket bridge (Socket.IO server in scripts/realtime/ws-server.js)`

## Files

```text
api/
  config.php
  redis.php
  realtime_events.php
  fcm.php
  ride_dispatch.php
  create_ride.php
  find_driver.php
  accept_ride.php
  reject_ride.php
  arrived_ride.php
  cancel_ride.php
  process_timeouts.php
  update_driver_location.php
  update_fcm_token.php
  send_notification.php
  send_fcm_notification.php
  driver_accept.php
  websocket_event_broadcaster.php
  notification_store.php
```

## Driver Matching Flow

1. `create_ride.php` inserts ride with `status = searching`.
2. `dispatch_next_driver()` in `ride_dispatch.php`:
   - queries nearest drivers via Redis GEO within `2 km`.
   - fallback to MySQL Haversine if Redis unavailable.
   - queues all candidates in `ride_requests_tracking` as `queued`.
   - promotes nearest candidate to `pending`.
3. FCM push is sent with:
   - high priority
   - both `notification` + `data`
   - TTL `60s`
   - accept/reject endpoints in data

## Retry Logic

- Pending offer timeout: `30 seconds`.
- Configurable via `.env`: `RIDE_OFFER_TIMEOUT_SECONDS` (bounded `30..60`).
- `process_timeouts.php`:
  - marks expired pending offers.
  - dispatches next queued driver.
  - uses Redis lock key `taxi:lock:retry:{ride_id}` to avoid duplicate assignment.
  - FCM retries use exponential backoff (`FCM_MAX_RETRIES`, `FCM_BACKOFF_BASE_MS`).

## Driver Accept Logic (Race-safe)

`accept_ride.php`:
- Redis lock `taxi:lock:accept:{ride_id}`
- SQL transaction + `FOR UPDATE`
- atomic update: ride moves from `searching` to `accepted` once only
- other queued/pending offers become `expired`
- customer notified via FCM and websocket event

## WebSocket Events

Published to Redis channel `taxi:events`, consumed by `scripts/realtime/ws-server.js`.

Implemented event names:
- `ride_requested`
- `ride_accepted`
- `ride_rejected_by_driver`
- `ride_started`
- `ride_completed`
- `ride_closed`
- `ride_search_expired`
- `driver_location_updated`

Room strategy:
- ride room: `{ride_id}`
- driver room: `driver:{driver_id}`
- customer room: `customer:{customer_id}`

## New Endpoints

### `POST /api/update_fcm_token.php`
```json
{
  "role": "driver",
  "id": 52,
  "fcm_token": "token_here",
  "device_id": "pixel-7-pro",
  "platform": "android"
}
```

### `POST /api/send_fcm_notification.php`
```json
{
  "role": "driver",
  "recipient_id": 52,
  "ride_id": 9001,
  "event_type": "ride_requested",
  "ttl_seconds": 60,
  "notification": {
    "title": "New Ride Request",
    "body": "Pickup request received."
  },
  "data": {
    "ride_id": "9001",
    "accept_endpoint": "/api/driver_accept.php",
    "reject_endpoint": "/api/reject_ride.php"
  }
}
```

### `POST /api/websocket_event_broadcaster.php`
```json
{
  "event": "ride_started",
  "ride_id": 9001,
  "recipient_role": "customer",
  "recipient_id": 101,
  "payload": {
    "driver_id": 52
  },
  "fallback_fcm": {
    "title": "Ride Started",
    "body": "Your trip has started",
    "data": {
      "status": "in_progress"
    }
  }
}
```

### `POST /api/send_notification.php`
```json
{
  "driver_id": 52,
  "ride_id": 9001,
  "pickup_lat": 28.6139,
  "pickup_lng": 77.2090,
  "drop_lat": 28.7041,
  "drop_lng": 77.1025,
  "estimated_fare": 235.50,
  "driver_profit": 188.40
}
```

## Sample JSON Responses

### create ride
```json
{
  "status": "ok",
  "ride_id": 9001,
  "ride_status": "searching",
  "dispatch": {
    "status": "ok",
    "driver_id": 52,
    "distance_km": 1.127
  }
}
```

### accept ride
```json
{
  "status": "ok",
  "message": "Ride accepted"
}
```

### timeout worker
```json
{
  "status": "ok",
  "processed": 4
}
```

## Required Migration

Run:
```sql
SOURCE db/mysql/020_realtime_notifications.sql;
SOURCE db/mysql/021_notification_system_hardening.sql;
```

## Run WebSocket Server

```bash
cd scripts/realtime
npm install
npm start
```

## Run Retry Worker (CLI)

```bash
php scripts/realtime/retry_worker.php 2000
```
