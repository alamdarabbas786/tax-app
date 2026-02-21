# Commission Settlement & Wallet Adjustment

## Commission Function
- Default commission rate: `20%` (override via `COMMISSION_RATE_PERCENT`).
- Formula:
  - `commission_amount = total_fare * (commission_rate / 100)`
  - `driver_earning = total_fare - commission_amount`

## Wallet Settlement Rules
- Cash ride:
  - Driver takes full cash from customer.
  - Platform commission is debited from driver wallet.
  - Ledger entry:
    - `transaction_type = debit`
    - `description = Commission Deduction`
- Online ride:
  - Platform receives payment.
  - Driver earning after commission is credited to driver wallet.
  - Ledger entry:
    - `transaction_type = credit`
    - `description = Ride Earnings After Commission`

## Duplicate Safety
- Ride settlement uses row lock (`FOR UPDATE`) on `rides`.
- `rides.wallet_settled` + `rides.wallet_settled_at` stop duplicate settlement.
- Full ledger is written in `wallet_transactions`.

## Endpoints
- `POST /api/complete_ride.php`
- `POST /api/calculate_commission.php`
- `POST /api/update_wallet.php`
- `GET /api/driver_wallet_summary.php`
- `POST /api/payout_driver.php`

## Sample JSON
### 1) Calculate Commission
Request:
```json
{
  "total_fare": 100,
  "payment_mode": "cash"
}
```
Response:
```json
{
  "status": "ok",
  "payment_mode": "cash",
  "total_fare": 100,
  "commission_rate_percent": 20,
  "commission_amount": 20,
  "driver_earning": 80
}
```

### 2) Complete Ride
Request:
```json
{
  "ride_id": "f9328281-d351-44ea-a9c0-7059b982834b",
  "payment_mode": "online"
}
```
Response (excerpt):
```json
{
  "status": "ok",
  "message": "Ride completed",
  "fare": 240,
  "wallet_settlement": {
    "payment_mode": "online",
    "commission_amount": 48,
    "driver_earning": 192,
    "wallet_delta": 192,
    "wallet_balance_before": 120,
    "wallet_balance_after": 312,
    "transaction_type": "credit"
  }
}
```

### 3) Manual Wallet Update (Admin)
Request:
```json
{
  "driver_id": "2",
  "transaction_type": "debit",
  "amount": 50,
  "description": "Penalty Adjustment"
}
```
Response:
```json
{
  "status": "ok",
  "message": "Wallet updated",
  "adjustment": {
    "driver_id": 2,
    "transaction_type": "debit",
    "amount": 50,
    "wallet_balance_before": 300,
    "wallet_balance_after": 250
  }
}
```

### 4) Driver Wallet Summary
Request:
- Driver token: `GET /api/driver_wallet_summary.php`
- Admin token: `GET /api/driver_wallet_summary.php?driver_id=2`

Response:
```json
{
  "status": "ok",
  "wallet": {
    "driver_id": 2,
    "wallet_balance": 250,
    "total_earnings": 5420,
    "total_credits": 7100,
    "total_debits": 6850,
    "transactions_count": 74
  }
}
```

### 5) Payout Driver (Admin)
Request:
```json
{
  "driver_id": "2",
  "amount": 200
}
```
Response:
```json
{
  "status": "ok",
  "message": "Payout processed",
  "payout": {
    "driver_id": 2,
    "amount": 200,
    "wallet_balance_after": 50
  }
}
```
