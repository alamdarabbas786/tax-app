# Scalable Ride Booking Backend (10k RPS Design)

## Architecture

```text
Mobile Apps
   |
[Load Balancer]
   |
   +--> PHP API Instance A (stateless)
   +--> PHP API Instance B (stateless)
   +--> PHP API Instance N (stateless)
            |
            +--> Redis (rate limit, locks, GEO, queue)
            +--> MySQL Shard Router (city_id -> shard DB)
            +--> Queue Workers (dispatch + FCM)
                       |
                       +--> FCM
```

## Key Design Points

- Stateless API: no local sessions/filesystem state for request flow.
- Horizontal scale: any API instance can handle any request.
- City sharding: `city_id` routes request to a dedicated MySQL shard.
- Driver lookup: Redis `GEOSEARCH` in city-specific GEO keys.
- Async dispatch: API enqueues ride job; worker sends FCM.
- Retry safety: exponential retry from delayed queue.
- Contention safety: Redis distributed lock + SQL transaction (`FOR UPDATE`).

## Implemented Files

- `api/shard_db.php`: city-based shard routing + persistent PDO pool.
- `api/rate_limit.php`: Redis per-user per-minute limit.
- `api/queue.php`: Redis queue and delayed retry queue.
- `api/create_ride_scalable.php`: stateless ride create endpoint.
- `scripts/realtime/dispatch_queue_worker.php`: async dispatch + FCM retries.
- `api/redis.php`: city GEO helpers using `GEOSEARCH`.

## Environment Variables

```env
SHARD_CITY_MAP_JSON={"1":{"dsn":"mysql:host=10.0.1.10;port=3306;dbname=taxi_city1;charset=utf8mb4","user":"appuser","password":"AppPass123!"},"2":{"dsn":"mysql:host=10.0.2.10;port=3306;dbname=taxi_city2;charset=utf8mb4","user":"appuser","password":"AppPass123!"}}
RIDE_REQUESTS_PER_MINUTE=6
DISPATCH_QUEUE_NAME=taxi:queue:ride_dispatch
DISPATCH_DELAYED_ZSET=taxi:queue:ride_dispatch:delayed
DISPATCH_JOB_MAX_RETRY=5
DISPATCH_RADIUS_KM=3
```

