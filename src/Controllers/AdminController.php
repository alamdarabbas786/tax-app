<?php

namespace App\Controllers;

use App\Db\Mysql;
use App\Services\FcmService;
use App\Utils\Uuid;

class AdminController
{
    private static array $schemaCache = [];

    public function overview(): void
    {
        $this->renderOverviewPage();
    }

    public function pricingPage(): void
    {
        $this->renderPricingPage();
    }

    public function ridesPage(): void
    {
        $this->renderRidesPage();
    }

    public function driversPage(): void
    {
        $this->renderDriversPage();
    }

    public function verificationPage(): void
    {
        $this->renderVerificationPage();
    }

    public function fcmPage(): void
    {
        $this->renderFcmPage();
    }

    public function couponsPage(): void
    {
        $this->renderCouponsPage();
    }

    public function dashboard(): void
    {
        $this->renderOverviewPage();
        return;

        $pdo = Mysql::connection();
        $activeOnly = isset($_GET['active']) && $_GET['active'] === '1';
        $onlineOnly = isset($_GET['online']) && $_GET['online'] === '1';

        $rideWhere = $activeOnly ? "WHERE r.status IN ('requested','assigned','arrived','enroute')" : '';
        $onlineFilterExpr = $this->columnExists($pdo, 'drivers', 'is_available')
            ? 'is_available = 1'
            : ($this->columnExists($pdo, 'drivers', 'availability') ? 'availability = 1' : '');
        $driverWhere = ($onlineOnly && $onlineFilterExpr !== '') ? ('WHERE ' . $onlineFilterExpr) : '';

        $rideSelect = [
            'r.id',
            'r.status',
            $this->selectExpr($pdo, 'rides', ['fare', 'total_fare', 'final_fare'], 'fare', '0'),
            $this->selectExpr($pdo, 'rides', ['driver_earning', 'driver_profit'], 'driver_earning', '0'),
            $this->selectExpr($pdo, 'rides', ['platform_fee'], 'platform_fee', '0'),
            $this->selectExpr($pdo, 'rides', ['pickup_address', 'pickup_airport_code'], 'pickup_address', "''"),
            $this->selectExpr($pdo, 'rides', ['drop_address', 'dropoff_airport_code'], 'drop_address', "''"),
            $this->selectExpr($pdo, 'rides', ['pickup_lat'], 'pickup_lat', 'NULL'),
            $this->selectExpr($pdo, 'rides', ['pickup_lng'], 'pickup_lng', 'NULL'),
            $this->selectExpr($pdo, 'rides', ['drop_lat'], 'drop_lat', 'NULL'),
            $this->selectExpr($pdo, 'rides', ['drop_lng'], 'drop_lng', 'NULL'),
            'r.created_at',
            $this->selectExprFrom($pdo, 'drivers', ['name', 'full_name'], 'driver_name', 'd', "''"),
            $this->selectExprFrom($pdo, 'drivers', ['phone'], 'driver_phone', 'd', "''"),
            $this->selectExpr($pdo, 'drivers', ['current_lat', 'latitude'], 'current_lat', 'NULL'),
            $this->selectExpr($pdo, 'drivers', ['current_lng', 'longitude'], 'current_lng', 'NULL')
        ];
        $rides = $pdo->query(
            'SELECT ' . implode(', ', $rideSelect)
            . ' FROM rides r LEFT JOIN drivers d ON r.driver_id = d.id '
            . $rideWhere . ' ORDER BY r.created_at DESC LIMIT 100'
        )->fetchAll(\PDO::FETCH_ASSOC);

        if ($this->tableExists($pdo, 'customers')) {
            $customers = $pdo->query('SELECT COUNT(*) as total FROM customers')->fetch(\PDO::FETCH_ASSOC);
        } elseif ($this->tableExists($pdo, 'users')) {
            $customers = $pdo->query('SELECT COUNT(*) as total FROM users')->fetch(\PDO::FETCH_ASSOC);
        } else {
            $customers = ['total' => 0];
        }

        $drivers = $pdo->query('SELECT COUNT(*) as total FROM drivers')->fetch(\PDO::FETCH_ASSOC);
        $onlineDriverSelect = [
            'id',
            $this->selectExpr($pdo, 'drivers', ['name', 'full_name'], 'name', "''"),
            $this->selectExpr($pdo, 'drivers', ['phone'], 'phone', "''"),
            $this->selectExpr($pdo, 'drivers', ['current_lat', 'latitude'], 'current_lat', 'NULL'),
            $this->selectExpr($pdo, 'drivers', ['current_lng', 'longitude'], 'current_lng', 'NULL'),
            $this->selectExpr($pdo, 'drivers', ['last_ping_at', 'last_seen_at', 'updated_at'], 'last_ping_at', 'NULL')
        ];
        $onlineDrivers = $pdo->query(
            'SELECT ' . implode(', ', $onlineDriverSelect)
            . ' FROM drivers ' . $driverWhere . ' ORDER BY last_ping_at DESC LIMIT 100'
        )->fetchAll(\PDO::FETCH_ASSOC);

        if ($this->columnExists($pdo, 'drivers', 'verification_status')) {
            $pendingDrivers = $pdo->query(
                'SELECT id, '
                . $this->selectExpr($pdo, 'drivers', ['name', 'full_name'], 'name', "''") . ', '
                . $this->selectExpr($pdo, 'drivers', ['phone'], 'phone', "''") . ', '
                . $this->selectExpr($pdo, 'drivers', ['email'], 'email', "''") . ', '
                . $this->selectExpr($pdo, 'drivers', ['city'], 'city', "''") . ', '
                . $this->selectExpr($pdo, 'drivers', ['created_at'], 'created_at', 'NULL') . ', '
                . $this->selectExpr($pdo, 'drivers', ['vehicle_rc_path', 'rc_file'], 'vehicle_rc_path', "''") . ', '
                . $this->selectExpr($pdo, 'drivers', ['driving_license_path', 'driving_license_file'], 'driving_license_path', "''") . ', '
                . $this->selectExpr($pdo, 'drivers', ['aadhaar_card_path', 'aadhaar_file'], 'aadhaar_card_path', "''") . ', '
                . $this->selectExpr($pdo, 'drivers', ['driver_photo_path'], 'driver_photo_path', "''") . ', '
                . $this->selectExpr($pdo, 'drivers', ['insurance_doc_path'], 'insurance_doc_path', "''") . ', '
                . $this->selectExpr($pdo, 'drivers', ['puc_doc_path'], 'puc_doc_path', "''")
                . ' FROM drivers WHERE verification_status = "pending" ORDER BY created_at DESC LIMIT 50'
            )->fetchAll(\PDO::FETCH_ASSOC);
        } else {
            $pendingDrivers = [];
        }

        $driverRows = $pdo->query(
            'SELECT id, '
            . $this->selectExpr($pdo, 'drivers', ['name', 'full_name'], 'name', "''") . ', '
            . $this->selectExpr($pdo, 'drivers', ['phone'], 'phone', "''") . ', '
            . $this->selectExpr($pdo, 'drivers', ['rating'], 'rating', '0') . ', '
            . $this->selectExpr($pdo, 'drivers', ['total_rides'], 'total_rides', '0') . ', '
            . $this->selectExpr($pdo, 'drivers', ['is_blocked'], 'is_blocked', '0') . ', '
            . $this->selectExpr($pdo, 'drivers', ['is_verified'], 'is_verified', '1')
            . ' FROM drivers ORDER BY '
            . ($this->columnExists($pdo, 'drivers', 'created_at') ? 'created_at' : 'id')
            . ' DESC LIMIT 50'
        )->fetchAll(\PDO::FETCH_ASSOC);

        $pricingRows = $this->tableExists($pdo, 'vehicle_pricing')
            ? $pdo->query('SELECT vehicle_type, cost_per_km, cost_per_min, minimum_fare, driver_profit_margin, platform_fee, is_active FROM vehicle_pricing ORDER BY FIELD(vehicle_type, "bike","mini","toto","auto","sedan","xl")')->fetchAll(\PDO::FETCH_ASSOC)
            : [];

        $earnings = $pdo->query(
            'SELECT COUNT(*) AS rides, '
            . 'COALESCE(SUM(' . $this->pickColumn($pdo, 'rides', ['fare', 'total_fare', 'final_fare'], '0') . '),0) AS total_fare, '
            . 'COALESCE(SUM(' . $this->pickColumn($pdo, 'rides', ['platform_fee'], '0') . '),0) AS platform_fee, '
            . 'COALESCE(SUM(' . $this->pickColumn($pdo, 'rides', ['driver_earning', 'driver_profit'], '0') . '),0) AS driver_earning '
            . 'FROM rides'
        )->fetch(\PDO::FETCH_ASSOC);

        $offerRows = $this->tableExists($pdo, 'offers')
            ? $pdo->query('SELECT id, code, title, discount_type, discount_value, max_discount, min_fare, payment_mode, vehicle_type, new_user_only, is_active, start_at, end_at, created_at FROM offers ORDER BY created_at DESC LIMIT 100')->fetchAll(\PDO::FETCH_ASSOC)
            : [];

        header('Content-Type: text/html');
        echo '<!doctype html><html><head><meta charset="utf-8" />';
        echo '<title>Mobility Admin</title>';
        echo '<style>body{font-family:Arial, sans-serif;background:#0b0b0b;color:#fff;margin:0;} .wrap{padding:16px;} .stat{margin-right:12px;display:inline-block;} .filters a{color:#fff;margin-right:10px;} table{border-collapse:collapse;width:100%;margin-top:12px;} th,td{border:1px solid #222;padding:8px;font-size:13px;} .btn{background:#1ED760;color:#0b0b0b;border:none;padding:6px 10px;border-radius:6px;cursor:pointer;} .btn-reject{background:#ef4444;color:#fff;} .btn-warn{background:#f59e0b;color:#0b0b0b;} .btn-muted{background:#374151;color:#fff;} .doc a{color:#60a5fa;} input,select,textarea{background:#111;color:#fff;border:1px solid #333;padding:6px;border-radius:6px;} textarea{min-height:34px;}</style>';
        echo '</head><body>';

        echo '<div class="wrap">';
        echo '<h1>Mobility Admin</h1>';
        $fcmTestStatus = (string)($_GET['fcm_test'] ?? '');
        $fcmHttp = (string)($_GET['fcm_http'] ?? '');
        $fcmCode = (string)($_GET['fcm_code'] ?? '');
        $fcmMsg = (string)($_GET['fcm_msg'] ?? '');
        if ($fcmTestStatus !== '') {
            $color = $fcmTestStatus === 'ok' ? '#22c55e' : '#ef4444';
            echo '<div style="margin:8px 0 12px;padding:10px;border:1px solid ' . $color . ';border-radius:8px;">';
            echo '<strong>FCM Test: ' . htmlspecialchars(strtoupper($fcmTestStatus)) . '</strong>';
            if ($fcmHttp !== '') {
                echo ' | HTTP: ' . htmlspecialchars($fcmHttp);
            }
            if ($fcmCode !== '') {
                echo ' | Code: ' . htmlspecialchars($fcmCode);
            }
            if ($fcmMsg !== '') {
                echo ' | ' . htmlspecialchars($fcmMsg);
            }
            echo '</div>';
        }
        $pricingStatus = (string)($_GET['pricing_status'] ?? '');
        $pricingMsg = (string)($_GET['pricing_msg'] ?? '');
        if ($pricingStatus !== '') {
            $color = $pricingStatus === 'ok' ? '#22c55e' : '#ef4444';
            echo '<div style="margin:8px 0 12px;padding:10px;border:1px solid ' . $color . ';border-radius:8px;">';
            echo '<strong>Pricing Update: ' . htmlspecialchars(strtoupper($pricingStatus)) . '</strong>';
            if ($pricingMsg !== '') {
                echo ' | ' . htmlspecialchars($pricingMsg);
            }
            echo '</div>';
        }
        $couponStatus = (string)($_GET['coupon_status'] ?? '');
        $couponMsg = (string)($_GET['coupon_msg'] ?? '');
        if ($couponStatus !== '') {
            $color = $couponStatus === 'ok' ? '#22c55e' : '#ef4444';
            echo '<div style="margin:8px 0 12px;padding:10px;border:1px solid ' . $color . ';border-radius:8px;">';
            echo '<strong>Coupon: ' . htmlspecialchars(strtoupper($couponStatus)) . '</strong>';
            if ($couponMsg !== '') {
                echo ' | ' . htmlspecialchars($couponMsg);
            }
            echo '</div>';
        }
        echo '<div class="stat">Customers: ' . ($customers['total'] ?? 0) . '</div>';
        echo '<div class="stat">Drivers: ' . ($drivers['total'] ?? 0) . '</div>';
        echo '<div class="filters">Filters: ';
        echo '<a href="/admin">All</a>';
        echo '<a href="/admin?active=1">Active Rides</a>';
        echo '<a href="/admin?online=1">Online Drivers</a>';
        echo '<a href="/admin?active=1&online=1">Active + Online</a>';
        echo '</div>';

        echo '<div style="margin-top:12px;padding:12px;border:1px solid #333;border-radius:8px;">';
        echo '<h3 style="margin:0 0 8px;">Send FCM Test</h3>';
        echo '<form method="POST" action="/admin/fcm-test" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">';
        echo '<input type="text" name="driver_phone" placeholder="Driver phone (preferred)" style="min-width:200px;" />';
        echo '<input type="text" name="driver_id" placeholder="Driver UUID (optional)" style="min-width:260px;" />';
        echo '<input type="text" name="title" placeholder="Title" value="New Ride Request" style="min-width:200px;" />';
        echo '<input type="text" name="body" placeholder="Body" value="Pickup and drop details. Tap to view details" style="min-width:340px;" />';
        echo '<button class="btn" type="submit">Send Test Push</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';

        echo '<div class="wrap">';
        echo '<h2>Pricing Management</h2>';
        echo '<p style="color:#9ca3af;margin:6px 0 12px;">Driver profit margin is fixed at 22% and cannot be changed.</p>';
        if (empty($pricingRows)) {
            echo '<p>No pricing configured.</p>';
        } else {
            echo '<table><tr><th>Vehicle</th><th>Cost/KM</th><th>Cost/Min</th><th>Min Fare</th><th>Driver Profit %</th><th>Platform Fee</th><th>Active</th><th>Save</th></tr>';
            foreach ($pricingRows as $p) {
                echo '<tr>';
                echo '<form method="POST" action="/admin/pricing-update">';
                echo '<td>' . htmlspecialchars($p['vehicle_type']) . '<input type="hidden" name="vehicle_type" value="' . htmlspecialchars($p['vehicle_type']) . '" /></td>';
                echo '<td><input name="cost_per_km" value="' . htmlspecialchars((string)$p['cost_per_km']) . '" /></td>';
                echo '<td><input name="cost_per_min" value="' . htmlspecialchars((string)$p['cost_per_min']) . '" /></td>';
                echo '<td><input name="minimum_fare" value="' . htmlspecialchars((string)$p['minimum_fare']) . '" /></td>';
                echo '<td><input name="driver_profit_margin" value="22" readonly /></td>';
                echo '<td><input name="platform_fee" value="' . htmlspecialchars((string)$p['platform_fee']) . '" /></td>';
                echo '<td><input type="checkbox" name="is_active" value="1" ' . ($p['is_active'] ? 'checked' : '') . ' /></td>';
                echo '<td><button class="btn" type="submit">Update</button></td>';
                echo '</form>';
                echo '</tr>';
            }
            echo '</table>';
        }
        echo '</div>';

        echo '<div class="wrap">';
        echo '<h2>Coupon Management</h2>';
        echo '<form method="POST" action="/admin/coupon-generate" style="display:grid;grid-template-columns:repeat(4,minmax(160px,1fr));gap:8px;align-items:end;">';
        echo '<div><div style="margin-bottom:4px;font-size:12px;">Code</div><input name="code" required placeholder="SAVE50" /></div>';
        echo '<div><div style="margin-bottom:4px;font-size:12px;">Title</div><input name="title" required placeholder="Flat Rs 50 Off" /></div>';
        echo '<div><div style="margin-bottom:4px;font-size:12px;">Discount Type</div><select name="discount_type"><option value="flat">flat</option><option value="percent">percent</option></select></div>';
        echo '<div><div style="margin-bottom:4px;font-size:12px;">Discount Value</div><input name="discount_value" required placeholder="50" /></div>';
        echo '<div><div style="margin-bottom:4px;font-size:12px;">Max Discount</div><input name="max_discount" placeholder="30 (for percent)" /></div>';
        echo '<div><div style="margin-bottom:4px;font-size:12px;">Min Fare</div><input name="min_fare" value="0" /></div>';
        echo '<div><div style="margin-bottom:4px;font-size:12px;">Payment Mode</div><select name="payment_mode"><option value="any">any</option><option value="cash">cash</option><option value="online">online</option></select></div>';
        echo '<div><div style="margin-bottom:4px;font-size:12px;">Vehicle Type</div><input name="vehicle_type" placeholder="(optional)" /></div>';
        echo '<div><div style="margin-bottom:4px;font-size:12px;">Start At (YYYY-MM-DD HH:MM:SS)</div><input name="start_at" placeholder="optional" /></div>';
        echo '<div><div style="margin-bottom:4px;font-size:12px;">End At (YYYY-MM-DD HH:MM:SS)</div><input name="end_at" placeholder="optional" /></div>';
        echo '<div><div style="margin-bottom:4px;font-size:12px;">New User Only</div><select name="new_user_only"><option value="0">No</option><option value="1">Yes</option></select></div>';
        echo '<div><div style="margin-bottom:4px;font-size:12px;">Active</div><select name="is_active"><option value="1">Yes</option><option value="0">No</option></select></div>';
        echo '<div style="grid-column:span 4;"><div style="margin-bottom:4px;font-size:12px;">Description</div><textarea name="description" placeholder="Coupon details"></textarea></div>';
        echo '<div><button class="btn" type="submit">Generate Coupon</button></div>';
        echo '</form>';

        if (empty($offerRows)) {
            echo '<p style="margin-top:12px;">No coupons found.</p>';
        } else {
            echo '<table><tr><th>Code</th><th>Title</th><th>Type</th><th>Value</th><th>Max</th><th>Min Fare</th><th>Mode</th><th>Vehicle</th><th>New User</th><th>Active</th><th>Start</th><th>End</th></tr>';
            foreach ($offerRows as $offer) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars((string)$offer['code']) . '</td>';
                echo '<td>' . htmlspecialchars((string)$offer['title']) . '</td>';
                echo '<td>' . htmlspecialchars((string)$offer['discount_type']) . '</td>';
                echo '<td>' . htmlspecialchars((string)$offer['discount_value']) . '</td>';
                echo '<td>' . htmlspecialchars((string)($offer['max_discount'] ?? '')) . '</td>';
                echo '<td>' . htmlspecialchars((string)$offer['min_fare']) . '</td>';
                echo '<td>' . htmlspecialchars((string)$offer['payment_mode']) . '</td>';
                echo '<td>' . htmlspecialchars((string)($offer['vehicle_type'] ?? '')) . '</td>';
                echo '<td>' . ((int)($offer['new_user_only'] ?? 0) === 1 ? 'Yes' : 'No') . '</td>';
                echo '<td>' . ((int)($offer['is_active'] ?? 0) === 1 ? 'Yes' : 'No') . '</td>';
                echo '<td>' . htmlspecialchars((string)($offer['start_at'] ?? '')) . '</td>';
                echo '<td>' . htmlspecialchars((string)($offer['end_at'] ?? '')) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }
        echo '</div>';

        echo '<div class="wrap">';
        echo '<h2>Earnings Report</h2>';
        echo '<div class="stat">Total Rides: ' . ($earnings['rides'] ?? 0) . '</div>';
        echo '<div class="stat">Total Fare: ' . ($earnings['total_fare'] ?? 0) . '</div>';
        echo '<div class="stat">Platform Fee: ' . ($earnings['platform_fee'] ?? 0) . '</div>';
        echo '<div class="stat">Driver Earnings: ' . ($earnings['driver_earning'] ?? 0) . '</div>';
        echo '</div>';

        echo '<div class="wrap">';
        echo '<h2>Rides (Latest 100)</h2>';
        echo '<table><tr><th>ID</th><th>Status</th><th>Fare</th><th>Driver Earning</th><th>Platform Fee</th><th>Pickup</th><th>Drop</th><th>Driver</th><th>Actions</th></tr>';
        foreach ($rides as $r) {
            $rid = $this->idToString($r['id'] ?? null);
            echo '<tr>';
            echo '<td>' . htmlspecialchars($rid) . '</td>';
            echo '<td>' . htmlspecialchars($r['status']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$r['fare']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$r['driver_earning']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$r['platform_fee']) . '</td>';
            echo '<td>' . htmlspecialchars($r['pickup_address']) . '</td>';
            echo '<td>' . htmlspecialchars($r['drop_address']) . '</td>';
            echo '<td>' . htmlspecialchars($r['driver_name'] ?? '-') . '</td>';
            echo '<td>';
            echo '<form method="POST" action="/admin/ride-cancel" style="display:inline-block;">';
            echo '<input type="hidden" name="ride_id" value="' . htmlspecialchars($rid) . '" />';
            echo '<button class="btn btn-reject" type="submit">Cancel</button>';
            echo '</form> ';
            echo '<form method="POST" action="/admin/ride-unassign" style="display:inline-block;">';
            echo '<input type="hidden" name="ride_id" value="' . htmlspecialchars($rid) . '" />';
            echo '<button class="btn btn-warn" type="submit">Reassign</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '</div>';

        echo '<div class="wrap">';
        echo '<h2>Driver Management</h2>';
        echo '<table><tr><th>Name</th><th>Phone</th><th>Rating</th><th>Total Rides</th><th>Verified</th><th>Blocked</th><th>Action</th></tr>';
        foreach ($driverRows as $d) {
            $idVal = $this->idToString($d['id'] ?? null);
            $blocked = (int)$d['is_blocked'] === 1;
            echo '<tr>';
            echo '<td>' . htmlspecialchars($d['name'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($d['phone'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars((string)$d['rating']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$d['total_rides']) . '</td>';
            echo '<td>' . ((int)$d['is_verified'] === 1 ? 'Yes' : 'No') . '</td>';
            echo '<td>' . ($blocked ? 'Yes' : 'No') . '</td>';
            echo '<td>';
            if ($blocked) {
                echo '<form method="POST" action="/admin/unblock-driver">';
                echo '<input type="hidden" name="driver_id" value="' . htmlspecialchars($idVal) . '" />';
                echo '<button class="btn btn-muted" type="submit">Unblock</button>';
                echo '</form>';
            } else {
                echo '<form method="POST" action="/admin/block-driver">';
                echo '<input type="hidden" name="driver_id" value="' . htmlspecialchars($idVal) . '" />';
                echo '<button class="btn btn-reject" type="submit">Block</button>';
                echo '</form>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '</div>';

        echo '<div class="wrap">';
        echo '<h2>Pending Driver Verification</h2>';
        if (count($pendingDrivers) === 0) {
            echo '<p>No pending drivers.</p>';
        } else {
            echo '<table><tr><th>Name</th><th>Phone</th><th>Email</th><th>City</th><th>Docs</th><th>Applied</th><th>Approve</th><th>Reject</th></tr>';
            foreach ($pendingDrivers as $d) {
                $idVal = $this->idToString($d['id'] ?? null);
                echo '<tr>';
                echo '<td>' . htmlspecialchars($d['name'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($d['phone'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($d['email'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($d['city'] ?? '') . '</td>';
                echo '<td class="doc">';
                if (!empty($d['vehicle_rc_path'])) {
                    echo '<a href="/admin/driver-doc?file=' . urlencode($d['vehicle_rc_path']) . '" target="_blank">RC</a>';
                } else {
                    echo 'RC: N/A';
                }
                echo ' | ';
                if (!empty($d['driving_license_path'])) {
                    echo '<a href="/admin/driver-doc?file=' . urlencode($d['driving_license_path']) . '" target="_blank">DL</a>';
                } else {
                    echo 'DL: N/A';
                }
                echo ' | ';
                if (!empty($d['aadhaar_card_path'])) {
                    echo '<a href="/admin/driver-doc?file=' . urlencode($d['aadhaar_card_path']) . '" target="_blank">Aadhaar</a>';
                } else {
                    echo 'Aadhaar: N/A';
                }
                echo ' | ';
                if (!empty($d['driver_photo_path'])) {
                    echo '<a href="/admin/driver-doc?file=' . urlencode($d['driver_photo_path']) . '" target="_blank">Photo</a>';
                } else {
                    echo 'Photo: N/A';
                }
                echo ' | ';
                if (!empty($d['insurance_doc_path'])) {
                    echo '<a href="/admin/driver-doc?file=' . urlencode($d['insurance_doc_path']) . '" target="_blank">Insurance</a>';
                } else {
                    echo 'Insurance: N/A';
                }
                echo ' | ';
                if (!empty($d['puc_doc_path'])) {
                    echo '<a href="/admin/driver-doc?file=' . urlencode($d['puc_doc_path']) . '" target="_blank">PUC</a>';
                } else {
                    echo 'PUC: N/A';
                }
                echo '</td>';
                echo '<td>' . htmlspecialchars($d['created_at'] ?? '') . '</td>';
                echo '<td>';
                echo '<form method="POST" action="/admin/approve-driver">';
                echo '<input type="hidden" name="driver_id" value="' . htmlspecialchars($idVal) . '" />';
                echo '<button class="btn" type="submit">Approve</button>';
                echo '</form>';
                echo '</td>';
                echo '<td>';
                echo '<form method="POST" action="/admin/reject-driver">';
                echo '<input type="hidden" name="driver_id" value="' . htmlspecialchars($idVal) . '" />';
                echo '<input type="text" name="reason" placeholder="Reason" />';
                echo '<button class="btn btn-reject" type="submit">Reject</button>';
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }
        echo '</div>';

        echo '</body></html>';
    }

    public function pricingUpdate(): void
    {
        $vehicleType = $_POST["vehicle_type"] ?? "";
        $costPerKm = $_POST["cost_per_km"] ?? "";
        $costPerMin = $_POST["cost_per_min"] ?? "";
        $minimumFare = $_POST["minimum_fare"] ?? "";
        $platformFee = $_POST["platform_fee"] ?? "";
        $isActive = isset($_POST["is_active"]) ? 1 : 0;
        $profit = 0.22;

        if ($vehicleType === "" || !is_numeric($costPerKm) || !is_numeric($costPerMin) || !is_numeric($minimumFare) || !is_numeric($platformFee)) {
            header('Location: /admin/pricing?pricing_status=error&pricing_msg=' . urlencode('Invalid input'));
            return;
        }

        $pdo = Mysql::connection();
        $all = $pdo->query("SELECT vehicle_type, cost_per_km, cost_per_min, minimum_fare, platform_fee, is_active FROM vehicle_pricing")
            ->fetchAll(\PDO::FETCH_ASSOC);

        $map = [];
        foreach ($all as $row) {
            $map[$row["vehicle_type"]] = $row;
        }

        $map[$vehicleType] = [
            "vehicle_type" => $vehicleType,
            "cost_per_km" => (float) $costPerKm,
            "cost_per_min" => (float) $costPerMin,
            "minimum_fare" => (float) $minimumFare,
            "platform_fee" => (float) $platformFee,
            "is_active" => $isActive
        ];

        // Enforce a monotonic hierarchy using configured service tiers:
        // bike <= mini <= toto <= auto <= sedan <= xl
        // This matches admin listing/seed order and avoids false violations.
        $order = ["bike", "mini", "toto", "auto", "sedan", "xl"];
        $prev = null;
        foreach ($order as $type) {
            if (!isset($map[$type])) {
                continue;
            }
            $cur = $map[$type];
            if ((int)($cur["is_active"] ?? 1) !== 1) {
                // Skip inactive rows from hierarchy checks.
                continue;
            }
            if ($prev) {
                // Enforce monotonic (non-decreasing) pricing. Equal values are allowed; decreases are blocked.
                // This avoids blocking valid cases like equal platform fees (e.g., mini=40 and toto=40).
                $violations = [];
                if ($cur["cost_per_km"] < $prev["cost_per_km"]) {
                    $violations[] = 'cost_per_km ' . $prev["vehicle_type"] . '=' . $prev["cost_per_km"] . ' > ' . $type . '=' . $cur["cost_per_km"];
                }
                if ($cur["cost_per_min"] < $prev["cost_per_min"]) {
                    $violations[] = 'cost_per_min ' . $prev["vehicle_type"] . '=' . $prev["cost_per_min"] . ' > ' . $type . '=' . $cur["cost_per_min"];
                }
                if ($cur["minimum_fare"] < $prev["minimum_fare"]) {
                    $violations[] = 'minimum_fare ' . $prev["vehicle_type"] . '=' . $prev["minimum_fare"] . ' > ' . $type . '=' . $cur["minimum_fare"];
                }
                if ($cur["platform_fee"] < $prev["platform_fee"]) {
                    $violations[] = 'platform_fee ' . $prev["vehicle_type"] . '=' . $prev["platform_fee"] . ' > ' . $type . '=' . $cur["platform_fee"];
                }
                if (!empty($violations)) {
                    header('Location: /admin/pricing?pricing_status=error&pricing_msg=' . urlencode('Pricing hierarchy violated: ' . implode(' | ', $violations)));
                    return;
                }
            }
            $prev = $cur;
        }

        $stmt = $pdo->prepare("UPDATE vehicle_pricing SET cost_per_km=?, cost_per_min=?, minimum_fare=?, driver_profit_margin=?, platform_fee=?, is_active=? WHERE vehicle_type=?");
        $stmt->execute([(float) $costPerKm, (float) $costPerMin, (float) $minimumFare, $profit, (float) $platformFee, $isActive, $vehicleType]);
        header('Location: /admin/pricing?pricing_status=ok&pricing_msg=' . urlencode('Updated ' . $vehicleType));
    }

    public function couponGenerate(): void
    {
        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $discountType = strtolower(trim((string)($_POST['discount_type'] ?? 'flat')));
        $discountValue = $_POST['discount_value'] ?? '';
        $maxDiscount = trim((string)($_POST['max_discount'] ?? ''));
        $minFare = $_POST['min_fare'] ?? '0';
        $paymentMode = strtolower(trim((string)($_POST['payment_mode'] ?? 'any')));
        $vehicleType = strtolower(trim((string)($_POST['vehicle_type'] ?? '')));
        $newUserOnly = (int)(($_POST['new_user_only'] ?? '0') === '1');
        $isActive = (int)(($_POST['is_active'] ?? '1') === '1');
        $startAt = trim((string)($_POST['start_at'] ?? ''));
        $endAt = trim((string)($_POST['end_at'] ?? ''));

        if ($code === '' || $title === '' || !is_numeric($discountValue) || !is_numeric($minFare)) {
            header('Location: /admin/coupons?coupon_status=error&coupon_msg=' . urlencode('Invalid coupon input'));
            return;
        }
        if (!in_array($discountType, ['flat', 'percent'], true)) {
            header('Location: /admin/coupons?coupon_status=error&coupon_msg=' . urlencode('Invalid discount type'));
            return;
        }
        if (!in_array($paymentMode, ['any', 'cash', 'online'], true)) {
            header('Location: /admin/coupons?coupon_status=error&coupon_msg=' . urlencode('Invalid payment mode'));
            return;
        }

        $discountValNum = (float)$discountValue;
        $minFareNum = (float)$minFare;
        if ($discountValNum <= 0 || $minFareNum < 0) {
            header('Location: /admin/coupons?coupon_status=error&coupon_msg=' . urlencode('Discount/min fare invalid'));
            return;
        }
        if ($discountType === 'percent' && $discountValNum > 100) {
            header('Location: /admin/coupons?coupon_status=error&coupon_msg=' . urlencode('Percent discount cannot exceed 100'));
            return;
        }

        $maxDiscountVal = null;
        if ($maxDiscount !== '') {
            if (!is_numeric($maxDiscount) || (float)$maxDiscount < 0) {
                header('Location: /admin/coupons?coupon_status=error&coupon_msg=' . urlencode('Invalid max discount'));
                return;
            }
            $maxDiscountVal = (float)$maxDiscount;
        }

        $formatDate = static function (string $raw): ?string {
            $raw = trim($raw);
            if ($raw === '') {
                return null;
            }
            $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $raw);
            if ($dt === false) {
                return null;
            }
            return $dt->format('Y-m-d H:i:s');
        };

        $startAtNorm = $formatDate($startAt);
        if ($startAt !== '' && $startAtNorm === null) {
            header('Location: /admin/coupons?coupon_status=error&coupon_msg=' . urlencode('Invalid start_at format'));
            return;
        }
        $endAtNorm = $formatDate($endAt);
        if ($endAt !== '' && $endAtNorm === null) {
            header('Location: /admin/coupons?coupon_status=error&coupon_msg=' . urlencode('Invalid end_at format'));
            return;
        }

        $pdo = Mysql::connection();
        if (!$this->tableExists($pdo, 'offers')) {
            header('Location: /admin/coupons?coupon_status=error&coupon_msg=' . urlencode('Offers table missing. Run migration 026.'));
            return;
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO offers
                (code, title, description, discount_type, discount_value, max_discount, min_fare, payment_mode, vehicle_type, new_user_only, is_active, start_at, end_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $code,
                $title,
                $description,
                $discountType,
                round($discountValNum, 2),
                $maxDiscountVal !== null ? round($maxDiscountVal, 2) : null,
                round($minFareNum, 2),
                $paymentMode,
                $vehicleType !== '' ? $vehicleType : null,
                $newUserOnly,
                $isActive,
                $startAtNorm,
                $endAtNorm
            ]);
            header('Location: /admin/coupons?coupon_status=ok&coupon_msg=' . urlencode('Coupon created: ' . $code));
        } catch (\Throwable $e) {
            $msg = (string)$e->getMessage();
            if (stripos($msg, 'Duplicate') !== false) {
                $msg = 'Coupon code already exists';
            }
            header('Location: /admin/coupons?coupon_status=error&coupon_msg=' . urlencode($msg));
        }
    }

    public function approveDriver(): void
    {
        $driverIdRaw = $_POST['driver_id'] ?? '';
        $driverId = $this->parseEntityId($driverIdRaw);
        if ($driverId === null) {
            http_response_code(400);
            echo 'Invalid driver id';
            return;
        }

        $pdo = Mysql::connection();
        $stmt = $pdo->prepare('UPDATE drivers SET is_verified = 1, verification_status = "approved", verification_reason = NULL WHERE id = ?');
        $stmt->execute([$driverId]);

        header('Location: /admin/verification');
    }

    public function rejectDriver(): void
    {
        $driverIdRaw = $_POST['driver_id'] ?? '';
        $reason = trim((string)($_POST['reason'] ?? ''));
        $driverId = $this->parseEntityId($driverIdRaw);
        if ($driverId === null) {
            http_response_code(400);
            echo 'Invalid driver id';
            return;
        }
        if ($reason === '') {
            http_response_code(400);
            echo 'Reason required';
            return;
        }

        $pdo = Mysql::connection();
        $stmt = $pdo->prepare('UPDATE drivers SET is_verified = 0, verification_status = "rejected", verification_reason = ? WHERE id = ?');
        $stmt->execute([$reason, $driverId]);

        header('Location: /admin/verification');
    }

    public function blockDriver(): void
    {
        $driverIdRaw = $_POST['driver_id'] ?? '';
        $driverId = $this->parseEntityId($driverIdRaw);
        if ($driverId === null) {
            http_response_code(400);
            echo 'Invalid driver id';
            return;
        }
        $pdo = Mysql::connection();
        $stmt = $pdo->prepare('UPDATE drivers SET is_blocked = 1, is_available = 0 WHERE id = ?');
        $stmt->execute([$driverId]);
        header('Location: /admin/drivers');
    }

    public function unblockDriver(): void
    {
        $driverIdRaw = $_POST['driver_id'] ?? '';
        $driverId = $this->parseEntityId($driverIdRaw);
        if ($driverId === null) {
            http_response_code(400);
            echo 'Invalid driver id';
            return;
        }
        $pdo = Mysql::connection();
        $stmt = $pdo->prepare('UPDATE drivers SET is_blocked = 0 WHERE id = ?');
        $stmt->execute([$driverId]);
        header('Location: /admin/drivers');
    }

    public function rideCancel(): void
    {
        $rideIdRaw = (string)($_POST['ride_id'] ?? '');
        $rideIdCandidates = $this->entityIdCandidates($rideIdRaw);
        if (empty($rideIdCandidates)) {
            header('Location: /admin/rides?ride_action=error&ride_msg=' . urlencode('Invalid ride id'));
            return;
        }
        $pdo = Mysql::connection();
        try {
            $pdo->beginTransaction();
            $selectedRide = null;
            foreach ($rideIdCandidates as $rideId) {
                $check = $pdo->prepare('SELECT id, status, customer_id, driver_id FROM rides WHERE id = ? LIMIT 1 FOR UPDATE');
                $check->execute([$rideId]);
                $row = $check->fetch(\PDO::FETCH_ASSOC);
                if ($row) {
                    $selectedRide = $row;
                    break;
                }
            }
            if (!$selectedRide) {
                $pdo->rollBack();
                header('Location: /admin/rides?ride_action=error&ride_msg=' . urlencode('Ride not found'));
                return;
            }

            $targetStatus = $this->resolveRideStatusValue($pdo, 'cancelled', ['cancelled', 'rejected', 'expired']);
            if ($targetStatus === null) {
                $pdo->rollBack();
                header('Location: /admin/rides?ride_action=error&ride_msg=' . urlencode('Cancel status not supported in rides.status enum'));
                return;
            }

            $setParts = ['status = ?'];
            $params = [$targetStatus];
            if ($this->columnExists($pdo, 'rides', 'cancelled_by')) {
                $setParts[] = 'cancelled_by = "admin"';
            }
            if ($this->columnExists($pdo, 'rides', 'cancel_reason')) {
                $setParts[] = 'cancel_reason = "Admin cancelled"';
            }
            if ($this->columnExists($pdo, 'rides', 'cancelled_at')) {
                $setParts[] = 'cancelled_at = NOW()';
            }
            $params[] = $selectedRide['id'];
            $stmt = $pdo->prepare('UPDATE rides SET ' . implode(', ', $setParts) . ' WHERE id = ?');
            $stmt->execute($params);

            $rideIdPublic = $this->idToString($selectedRide['id'] ?? null);
            $driverToken = '';
            $customerToken = '';
            if (!empty($selectedRide['driver_id']) && $this->tableExists($pdo, 'drivers') && $this->columnExists($pdo, 'drivers', 'fcm_token')) {
                $dStmt = $pdo->prepare('SELECT fcm_token FROM drivers WHERE id = ? LIMIT 1');
                $dStmt->execute([$selectedRide['driver_id']]);
                $driverToken = trim((string)(($dStmt->fetch(\PDO::FETCH_ASSOC) ?: [])['fcm_token'] ?? ''));
            }
            if (!empty($selectedRide['customer_id'])) {
                if ($this->tableExists($pdo, 'customers') && $this->columnExists($pdo, 'customers', 'fcm_token')) {
                    $cStmt = $pdo->prepare('SELECT fcm_token FROM customers WHERE id = ? LIMIT 1');
                    $cStmt->execute([$selectedRide['customer_id']]);
                    $customerToken = trim((string)(($cStmt->fetch(\PDO::FETCH_ASSOC) ?: [])['fcm_token'] ?? ''));
                }
                if ($customerToken === '' && $this->tableExists($pdo, 'users') && $this->columnExists($pdo, 'users', 'fcm_token')) {
                    $uStmt = $pdo->prepare('SELECT fcm_token FROM users WHERE id = ? LIMIT 1');
                    $uStmt->execute([$selectedRide['customer_id']]);
                    $customerToken = trim((string)(($uStmt->fetch(\PDO::FETCH_ASSOC) ?: [])['fcm_token'] ?? ''));
                }
            }
            $tokens = array_values(array_filter(array_unique([$driverToken, $customerToken])));
            if (!empty($tokens)) {
                try {
                    $fcm = new FcmService();
                    $fcm->sendToTokens(
                        $tokens,
                        [
                            'title' => 'Ride Cancelled',
                            'body' => 'Admin cancelled the ride request.'
                        ],
                        [
                            'type' => 'ride_cancelled',
                            'ride_id' => $rideIdPublic,
                            'cancelled_by' => 'admin',
                            'reason' => 'Admin cancelled the ride request.'
                        ],
                        [
                            'android' => [
                                'priority' => 'HIGH',
                                'ttl' => '120s',
                                'notification' => [
                                    'channel_id' => 'ride_updates',
                                    'tag' => 'ride_cancel_admin_' . $rideIdPublic
                                ]
                            ]
                        ]
                    );
                } catch (\Throwable $e) {
                    // Do not fail cancel flow due to push failure.
                }
            }

            $driverId = $selectedRide['driver_id'] ?? null;
            if ($driverId !== null && $driverId !== '') {
                $driverSet = [];
                if ($this->columnExists($pdo, 'drivers', 'current_ride_id')) {
                    $driverSet[] = 'current_ride_id = NULL';
                }
                if ($this->columnExists($pdo, 'drivers', 'is_available')) {
                    $driverSet[] = 'is_available = 1';
                }
                if ($this->columnExists($pdo, 'drivers', 'availability')) {
                    $driverSet[] = 'availability = 1';
                }
                if (!empty($driverSet)) {
                    $pdo->prepare('UPDATE drivers SET ' . implode(', ', $driverSet) . ' WHERE id = ?')->execute([$driverId]);
                }
            }

            $pdo->commit();
            header('Location: /admin/rides?ride_action=ok&ride_msg=' . urlencode('Ride cancelled'));
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            header('Location: /admin/rides?ride_action=error&ride_msg=' . urlencode((string)$e->getMessage()));
        }
    }

    public function rideUnassign(): void
    {
        $rideIdRaw = (string)($_POST['ride_id'] ?? '');
        $rideIdCandidates = $this->entityIdCandidates($rideIdRaw);
        if (empty($rideIdCandidates)) {
            header('Location: /admin/rides?ride_action=error&ride_msg=' . urlencode('Invalid ride id'));
            return;
        }
        $pdo = Mysql::connection();
        try {
            $pdo->beginTransaction();

            $selectedRide = null;
            foreach ($rideIdCandidates as $rideId) {
                $check = $pdo->prepare('SELECT id, status, driver_id FROM rides WHERE id = ? LIMIT 1 FOR UPDATE');
                $check->execute([$rideId]);
                $row = $check->fetch(\PDO::FETCH_ASSOC);
                if ($row) {
                    $selectedRide = $row;
                    break;
                }
            }
            if (!$selectedRide) {
                $pdo->rollBack();
                header('Location: /admin/rides?ride_action=error&ride_msg=' . urlencode('Ride not found'));
                return;
            }

            $status = strtolower(trim((string)($selectedRide['status'] ?? '')));
            if (in_array($status, ['ride_started', 'in_progress', 'enroute', 'ride_completed', 'completed', 'ride_closed', 'cancelled', 'no_driver_found'], true)) {
                $pdo->rollBack();
                header('Location: /admin/rides?ride_action=error&ride_msg=' . urlencode('Ride cannot be reassigned in current status'));
                return;
            }

            $reassignStatus = $this->resolveRideStatusValue($pdo, 'searching', ['searching', 'requested', 'pending']);
            if ($reassignStatus === null) {
                $pdo->rollBack();
                header('Location: /admin/rides?ride_action=error&ride_msg=' . urlencode('Reassign status not supported in rides.status enum'));
                return;
            }

            $setParts = ['driver_id = NULL', 'status = ?'];
            $setParams = [$reassignStatus];
            if ($this->columnExists($pdo, 'rides', 'assigned_at')) {
                $setParts[] = 'assigned_at = NULL';
            }
            if ($this->columnExists($pdo, 'rides', 'driver_arrived_at')) {
                $setParts[] = 'driver_arrived_at = NULL';
            }
            if ($this->columnExists($pdo, 'rides', 'waiting_started_at')) {
                $setParts[] = 'waiting_started_at = NULL';
            }
            if ($this->columnExists($pdo, 'rides', 'searching_started_at')) {
                $setParts[] = 'searching_started_at = NOW()';
            }
            if ($this->columnExists($pdo, 'rides', 'cancelled_by')) {
                $setParts[] = 'cancelled_by = NULL';
            }
            if ($this->columnExists($pdo, 'rides', 'cancel_reason')) {
                $setParts[] = 'cancel_reason = NULL';
            }
            if ($this->columnExists($pdo, 'rides', 'cancelled_at')) {
                $setParts[] = 'cancelled_at = NULL';
            }
            $setParams[] = $selectedRide['id'];
            $stmt = $pdo->prepare('UPDATE rides SET ' . implode(', ', $setParts) . ' WHERE id = ?');
            $stmt->execute($setParams);

            $driverId = $selectedRide['driver_id'] ?? null;
            if ($driverId !== null && $driverId !== '') {
                $driverSet = [];
                if ($this->columnExists($pdo, 'drivers', 'current_ride_id')) {
                    $driverSet[] = 'current_ride_id = NULL';
                }
                if ($this->columnExists($pdo, 'drivers', 'is_available')) {
                    $driverSet[] = 'is_available = 1';
                }
                if ($this->columnExists($pdo, 'drivers', 'availability')) {
                    $driverSet[] = 'availability = 1';
                }
                if (!empty($driverSet)) {
                    $pdo->prepare('UPDATE drivers SET ' . implode(', ', $driverSet) . ' WHERE id = ?')->execute([$driverId]);
                }
            }

            $pdo->commit();
            header('Location: /admin/rides?ride_action=ok&ride_msg=' . urlencode('Ride reassigned to queue'));
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            header('Location: /admin/rides?ride_action=error&ride_msg=' . urlencode((string)$e->getMessage()));
        }
    }

    public function rideDelete(): void
    {
        $rideIdRaw = (string)($_POST['ride_id'] ?? '');
        $rideIdCandidates = $this->entityIdCandidates($rideIdRaw);
        if (empty($rideIdCandidates)) {
            http_response_code(400);
            echo 'Invalid ride id';
            return;
        }

        $pdo = Mysql::connection();
        $deleted = false;
        foreach ($rideIdCandidates as $rideId) {
            $stmt = $pdo->prepare('DELETE FROM rides WHERE id = ?');
            $stmt->execute([$rideId]);
            if ($stmt->rowCount() > 0) {
                $deleted = true;
                break;
            }
        }
        if (!$deleted) {
            http_response_code(404);
            echo 'Ride not found';
            return;
        }
        header('Location: /admin/rides');
    }

    public function rideDeleteAll(): void
    {
        $pdo = Mysql::connection();
        $pdo->exec('DELETE FROM rides');
        header('Location: /admin/rides');
    }

    public function serveDriverDoc(): void
    {
        $file = (string)($_GET['file'] ?? '');
        $file = urldecode($file);
        $file = str_replace(['\\', '/'], '/', $file);
        $basename = basename($file);
        if ($basename === '') {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Not found']);
            return;
        }

        $fullPath = dirname(__DIR__, 2) . '/uploads/driver_docs/' . $basename;
        if (!file_exists($fullPath)) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Not found']);
            return;
        }

        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $mime = $ext === 'pdf' ? 'application/pdf' : ($ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'application/octet-stream');
        header('Content-Type: ' . $mime);
        readfile($fullPath);
    }

    public function fcmTest(): void
    {
        $data = $this->jsonBody();
        $result = $this->runFcmTestInternal($data);
        $this->respondJson($result['http_status'], $result['payload']);
    }

    public function fcmTestFromAdmin(): void
    {
        $data = [
            'driver_id' => $_POST['driver_id'] ?? '',
            'driver_phone' => $_POST['driver_phone'] ?? '',
            'title' => $_POST['title'] ?? 'New Ride Request',
            'body' => $_POST['body'] ?? 'Pickup and drop details. Tap to view details'
        ];
        $result = $this->runFcmTestInternal($data);
        $payload = $result['payload'];

        $status = (string)($payload['status'] ?? 'error');
        $http = (string)($payload['fcm']['results'][0]['status_code'] ?? '');
        $code = (string)($payload['fcm']['results'][0]['fcm_error_code'] ?? '');
        $msg = (string)($payload['message'] ?? '');

        header('Location: /admin/fcm?fcm_test=' . urlencode($status)
            . '&fcm_http=' . urlencode($http)
            . '&fcm_code=' . urlencode($code)
            . '&fcm_msg=' . urlencode($msg));
    }

    private function runFcmTestInternal(array $data): array
    {
        $driverIdRaw = trim((string)($data['driver_id'] ?? ''));
        $driverPhone = trim((string)($data['driver_phone'] ?? ''));

        if ($driverIdRaw === '' && $driverPhone === '') {
            return [
                'http_status' => 422,
                'payload' => ['status' => 'error', 'message' => 'driver_id or driver_phone required']
            ];
        }

        $pdo = Mysql::connection();
        if ($driverIdRaw !== '') {
            $driverDbId = $this->parseEntityId($driverIdRaw);
            if ($driverDbId === null) {
                return [
                    'http_status' => 422,
                    'payload' => ['status' => 'error', 'message' => 'Invalid driver_id']
                ];
            }
            $stmt = $pdo->prepare(
                'SELECT id, '
                . $this->selectExpr($pdo, 'drivers', ['name', 'full_name'], 'name', "''") . ', '
                . $this->selectExpr($pdo, 'drivers', ['phone'], 'phone', "''") . ', '
                . $this->selectExpr($pdo, 'drivers', ['fcm_token'], 'fcm_token', "''")
                . ' FROM drivers WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$driverDbId]);
        } else {
            if ($this->columnExists($pdo, 'drivers', 'phone')) {
                $stmt = $pdo->prepare(
                    'SELECT id, '
                    . $this->selectExpr($pdo, 'drivers', ['name', 'full_name'], 'name', "''") . ', '
                    . $this->selectExpr($pdo, 'drivers', ['phone'], 'phone', "''") . ', '
                    . $this->selectExpr($pdo, 'drivers', ['fcm_token'], 'fcm_token', "''")
                    . ' FROM drivers WHERE phone = ? LIMIT 1'
                );
                $stmt->execute([$driverPhone]);
            } elseif ($this->tableExists($pdo, 'users') && $this->columnExists($pdo, 'drivers', 'user_id') && $this->columnExists($pdo, 'users', 'phone')) {
                $stmt = $pdo->prepare(
                    'SELECT d.id, '
                    . $this->selectExprFrom($pdo, 'drivers', ['name', 'full_name'], 'name', 'd', "''") . ', '
                    . 'u.phone AS phone, '
                    . $this->selectExprFrom($pdo, 'drivers', ['fcm_token'], 'fcm_token', 'd', "''")
                    . ' FROM drivers d JOIN users u ON u.id = d.user_id WHERE u.phone = ? LIMIT 1'
                );
                $stmt->execute([$driverPhone]);
            } else {
                $stmt = $pdo->prepare('SELECT NULL AS id, "" AS name, "" AS phone, "" AS fcm_token LIMIT 0');
                $stmt->execute();
            }
        }

        $driver = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$driver) {
            return [
                'http_status' => 404,
                'payload' => ['status' => 'error', 'message' => 'Driver not found']
            ];
        }

        $fcmToken = trim((string)($driver['fcm_token'] ?? ''));
        if ($fcmToken === '') {
            return [
                'http_status' => 422,
                'payload' => [
                    'status' => 'error',
                    'message' => 'Driver has no fcm_token. Open driver app on physical Android device (not Expo Go), allow notification permission, and login once to sync token.',
                    'driver' => [
                        'id' => $this->idToString($driver['id'] ?? null),
                        'name' => $driver['name'],
                        'phone' => $driver['phone']
                    ]
                ]
            ];
        }

        $rideId = trim((string)($data['ride_id'] ?? '')) ?: Uuid::toString(Uuid::v4Binary());
        $pickupLat = (string)($data['pickup_lat'] ?? '28.6139');
        $pickupLng = (string)($data['pickup_lng'] ?? '77.2090');
        $dropLat = (string)($data['drop_lat'] ?? '28.5355');
        $dropLng = (string)($data['drop_lng'] ?? '77.3910');
        $fare = (string)($data['fare'] ?? '220');

        $title = trim((string)($data['title'] ?? 'New Ride Request'));
        $body = trim((string)($data['body'] ?? 'Pickup and drop details. Tap to view details'));

        $fcm = new FcmService();
        $result = $fcm->sendToTokens(
            [$fcmToken],
            [
                'title' => $title,
                'body' => $body
            ],
            [
                'ride_id' => $rideId,
                'pickup_lat' => $pickupLat,
                'pickup_lng' => $pickupLng,
                'drop_lat' => $dropLat,
                'drop_lng' => $dropLng,
                'fare' => $fare,
                'accept_endpoint' => '/api/driver/rides/' . $rideId . '/accept',
                'reject_endpoint' => '/api/driver/rides/' . $rideId . '/reject',
                'expires_in_sec' => 120
            ],
            [
                'android' => [
                    'priority' => 'HIGH',
                    'ttl' => '120s',
                    'notification' => [
                        'channel_id' => 'ride_request',
                        'tag' => 'ride_' . $rideId,
                        'sound' => 'ride_request',
                        'click_action' => 'OPEN_RIDE_REQUEST'
                    ]
                ]
            ]
        );

        $statusCode = (int)($result['results'][0]['status_code'] ?? 0);
        $fcmErrorCode = strtoupper((string)($result['results'][0]['fcm_error_code'] ?? ''));
        if (($statusCode === 400 || $statusCode === 404) &&
            in_array($fcmErrorCode, ['UNREGISTERED', 'REGISTRATION_TOKEN_NOT_REGISTERED'], true)) {
            $pdo->prepare('UPDATE drivers SET fcm_token = NULL WHERE id = ?')->execute([$driver['id']]);
        }

        return [
            'http_status' => 200,
            'payload' => [
                'status' => 'ok',
                'driver' => [
                    'id' => $this->idToString($driver['id'] ?? null),
                    'name' => $driver['name'],
                    'phone' => $driver['phone']
                ],
                'fcm' => $result
            ]
        ];
    }

    private function renderOverviewPage(): void
    {
        $pdo = Mysql::connection();
        $customerTotal = 0;
        if ($this->tableExists($pdo, 'customers')) {
            $customerTotal = (int)($pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn() ?: 0);
        } elseif ($this->tableExists($pdo, 'users')) {
            $customerTotal = (int)($pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() ?: 0);
        }
        $driverTotal = (int)($pdo->query('SELECT COUNT(*) FROM drivers')->fetchColumn() ?: 0);
        $rideTotal = (int)($pdo->query('SELECT COUNT(*) FROM rides')->fetchColumn() ?: 0);
        $pendingVerification = 0;
        if ($this->columnExists($pdo, 'drivers', 'verification_status')) {
            $pendingVerification = (int)($pdo->query('SELECT COUNT(*) FROM drivers WHERE verification_status = "pending"')->fetchColumn() ?: 0);
        }

        $this->renderAdminPage('Overview', function () use ($customerTotal, $driverTotal, $rideTotal, $pendingVerification): void {
            echo '<div class="wrap">';
            echo '<h2>Platform Snapshot</h2>';
            echo '<div class="stat">Customers: ' . $customerTotal . '</div>';
            echo '<div class="stat">Drivers: ' . $driverTotal . '</div>';
            echo '<div class="stat">Total Rides: ' . $rideTotal . '</div>';
            echo '<div class="stat">Pending Verification: ' . $pendingVerification . '</div>';
            echo '<p style="margin-top:14px;color:#9ca3af;">Use top menu for page-wise modules.</p>';
            echo '</div>';
        });
    }

    private function renderPricingPage(): void
    {
        $pdo = Mysql::connection();
        $pricingRows = $this->tableExists($pdo, 'vehicle_pricing')
            ? $pdo->query('SELECT vehicle_type, cost_per_km, cost_per_min, minimum_fare, driver_profit_margin, platform_fee, is_active FROM vehicle_pricing ORDER BY FIELD(vehicle_type, "bike","mini","toto","auto","sedan","xl")')->fetchAll(\PDO::FETCH_ASSOC)
            : [];

        $this->renderAdminPage('Pricing', function () use ($pricingRows): void {
            echo '<div class="wrap">';
            echo '<h2>Pricing Management</h2>';
            if (empty($pricingRows)) {
                echo '<p>No pricing configured.</p>';
            } else {
                echo '<table><tr><th>Vehicle</th><th>Cost/KM</th><th>Cost/Min</th><th>Min Fare</th><th>Driver Profit %</th><th>Platform Fee</th><th>Active</th><th>Save</th></tr>';
                foreach ($pricingRows as $p) {
                    echo '<tr><form method="POST" action="/admin/pricing-update">';
                    echo '<td>' . htmlspecialchars($p['vehicle_type']) . '<input type="hidden" name="vehicle_type" value="' . htmlspecialchars($p['vehicle_type']) . '" /></td>';
                    echo '<td><input name="cost_per_km" value="' . htmlspecialchars((string)$p['cost_per_km']) . '" /></td>';
                    echo '<td><input name="cost_per_min" value="' . htmlspecialchars((string)$p['cost_per_min']) . '" /></td>';
                    echo '<td><input name="minimum_fare" value="' . htmlspecialchars((string)$p['minimum_fare']) . '" /></td>';
                    echo '<td><input name="driver_profit_margin" value="22" readonly /></td>';
                    echo '<td><input name="platform_fee" value="' . htmlspecialchars((string)$p['platform_fee']) . '" /></td>';
                    echo '<td><input type="checkbox" name="is_active" value="1" ' . ($p['is_active'] ? 'checked' : '') . ' /></td>';
                    echo '<td><button class="btn" type="submit">Update</button></td>';
                    echo '</form></tr>';
                }
                echo '</table>';
            }
            echo '</div>';
        });
    }

    private function renderRidesPage(): void
    {
        $pdo = Mysql::connection();
        $rideSelect = [
            'r.id',
            'r.status',
            'r.driver_id',
            $this->selectExpr($pdo, 'rides', ['fare', 'total_fare', 'final_fare'], 'fare', '0'),
            $this->selectExpr($pdo, 'rides', ['driver_earning', 'driver_profit'], 'driver_earning', '0'),
            $this->selectExpr($pdo, 'rides', ['platform_fee'], 'platform_fee', '0'),
            $this->selectExpr($pdo, 'rides', ['pickup_address', 'pickup_airport_code'], 'pickup_address', "''"),
            $this->selectExpr($pdo, 'rides', ['drop_address', 'dropoff_airport_code'], 'drop_address', "''"),
            $this->selectExpr($pdo, 'rides', ['pickup_lat'], 'pickup_lat', 'NULL'),
            $this->selectExpr($pdo, 'rides', ['pickup_lng'], 'pickup_lng', 'NULL'),
            $this->selectExpr($pdo, 'rides', ['assigned_at'], 'assigned_at', 'NULL'),
            $this->selectExpr($pdo, 'rides', ['driver_arrived_at', 'waiting_started_at'], 'arrived_at', 'NULL'),
            $this->selectExpr($pdo, 'rides', ['ride_started_at', 'started_at', 'ride_start_time'], 'started_at', 'NULL'),
            $this->selectExpr($pdo, 'rides', ['ride_completed_at', 'completed_at', 'ride_end_time'], 'completed_at', 'NULL'),
            $this->selectExprFrom($pdo, 'drivers', ['name', 'full_name'], 'driver_name', 'd', "''"),
            $this->selectExprFrom($pdo, 'drivers', ['current_lat', 'latitude'], 'driver_lat', 'd', 'NULL'),
            $this->selectExprFrom($pdo, 'drivers', ['current_lng', 'longitude'], 'driver_lng', 'd', 'NULL')
        ];
        $rides = $pdo->query('SELECT ' . implode(', ', $rideSelect) . ' FROM rides r LEFT JOIN drivers d ON r.driver_id = d.id ORDER BY r.created_at DESC LIMIT 100')->fetchAll(\PDO::FETCH_ASSOC);
        $driverMarkers = [];
        $rideMarkers = [];
        foreach ($rides as $r) {
            $status = $this->normalizeRideStatusForAdmin($r);
            if (is_numeric($r['driver_lat'] ?? null) && is_numeric($r['driver_lng'] ?? null)) {
                $driverMarkers[] = [
                    'name' => (string)($r['driver_name'] ?? ''),
                    'lat' => (float)$r['driver_lat'],
                    'lng' => (float)$r['driver_lng'],
                ];
            }
            if (is_numeric($r['pickup_lat'] ?? null) && is_numeric($r['pickup_lng'] ?? null)) {
                $rideMarkers[] = [
                    'pickup' => (string)($r['pickup_address'] ?? ''),
                    'status' => $status,
                    'fare' => (string)($r['fare'] ?? ''),
                    'lat' => (float)$r['pickup_lat'],
                    'lng' => (float)$r['pickup_lng'],
                ];
            }
        }

        $this->renderAdminPage('Rides', function () use ($rides, $driverMarkers, $rideMarkers): void {
            echo '<div class="wrap"><h2>Rides (Latest 100)</h2>';
            echo '<div style="display:flex;justify-content:flex-end;gap:8px;margin:0 0 10px 0;">';
            echo '<form method="POST" action="/admin/ride-delete-all" onsubmit="return confirm(\'Are you sure you want to delete ALL rides?\');">';
            echo '<button class="btn btn-reject" type="submit">Delete All Rides</button>';
            echo '</form>';
            echo '</div>';
            echo '<div id="admin-map" style="height:360px;border:1px solid #222;border-radius:10px;margin-bottom:12px;"></div>';
            echo '<table><tr><th>ID</th><th>Status</th><th>Fare</th><th>Driver Earning</th><th>Platform Fee</th><th>Pickup</th><th>Drop</th><th>Driver</th><th>Actions</th></tr>';
            foreach ($rides as $r) {
                $rid = $this->idToString($r['id'] ?? null);
                $status = $this->normalizeRideStatusForAdmin($r);
                echo '<tr>';
                echo '<td>' . htmlspecialchars($rid) . '</td><td>' . htmlspecialchars($status) . '</td><td>' . htmlspecialchars((string)$r['fare']) . '</td><td>' . htmlspecialchars((string)$r['driver_earning']) . '</td><td>' . htmlspecialchars((string)$r['platform_fee']) . '</td><td>' . htmlspecialchars((string)$r['pickup_address']) . '</td><td>' . htmlspecialchars((string)$r['drop_address']) . '</td><td>' . htmlspecialchars((string)($r['driver_name'] ?? '-')) . '</td>';
                echo '<td><form method="POST" action="/admin/ride-cancel" style="display:inline-block;" onsubmit="return confirm(\'Are you sure you want to cancel this ride?\');"><input type="hidden" name="ride_id" value="' . htmlspecialchars($rid) . '" /><button class="btn btn-reject" type="submit">Cancel</button></form> <form method="POST" action="/admin/ride-unassign" style="display:inline-block;" onsubmit="return confirm(\'Are you sure you want to reassign this ride?\');"><input type="hidden" name="ride_id" value="' . htmlspecialchars($rid) . '" /><button class="btn btn-warn" type="submit">Reassign</button></form> <form method="POST" action="/admin/ride-delete" style="display:inline-block;" onsubmit="return confirm(\'Are you sure you want to delete this ride?\');"><input type="hidden" name="ride_id" value="' . htmlspecialchars($rid) . '" /><button class="btn btn-muted" type="submit">Delete</button></form></td>';
                echo '</tr>';
            }
            echo '</table></div>';
            echo '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />';
            echo '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>';
            echo '<script>';
            echo '(() => {';
            echo 'const mapEl = document.getElementById("admin-map"); if (!mapEl || !window.L) return;';
            echo 'const drivers = ' . json_encode($driverMarkers) . ';';
            echo 'const rides = ' . json_encode($rideMarkers) . ';';
            echo 'const map = L.map("admin-map").setView([28.6139, 77.2090], 11);';
            echo 'L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 19 }).addTo(map);';
            echo 'drivers.forEach(d => L.circleMarker([d.lat, d.lng], {color:"#22c55e", radius:6}).addTo(map).bindPopup("Driver: " + (d.name || "")));';
            echo 'rides.forEach(r => L.circleMarker([r.lat, r.lng], {color:"#60a5fa", radius:6}).addTo(map).bindPopup("Pickup: " + (r.pickup || "") + "<br/>Status: " + (r.status || "") + "<br/>Fare: " + (r.fare || "")));';
            echo 'if (navigator.geolocation) {';
            echo 'navigator.geolocation.getCurrentPosition(pos => {';
            echo 'const lat = pos.coords.latitude; const lng = pos.coords.longitude;';
            echo 'const icon = L.divIcon({className:"admin-current-location", html:"<div style=\\"width:16px;height:16px;border-radius:50%;background:#ef4444;border:2px solid #fff;box-shadow:0 0 8px rgba(239,68,68,0.8);\\"></div>", iconSize:[16,16], iconAnchor:[8,8]});';
            echo 'L.marker([lat, lng], {icon}).addTo(map).bindPopup("Current Location").openPopup();';
            echo '}, () => {}, {enableHighAccuracy:true, timeout:5000});';
            echo '}';
            echo '})();';
            echo '</script>';
        });
    }

    private function renderDriversPage(): void
    {
        $pdo = Mysql::connection();
        $driverRows = $pdo->query(
            'SELECT id, '
            . $this->selectExpr($pdo, 'drivers', ['name', 'full_name'], 'name', "''") . ', '
            . $this->selectExpr($pdo, 'drivers', ['phone'], 'phone', "''") . ', '
            . $this->selectExpr($pdo, 'drivers', ['rating'], 'rating', '0') . ', '
            . $this->selectExpr($pdo, 'drivers', ['total_rides'], 'total_rides', '0') . ', '
            . $this->selectExpr($pdo, 'drivers', ['is_blocked'], 'is_blocked', '0') . ', '
            . $this->selectExpr($pdo, 'drivers', ['is_verified'], 'is_verified', '1')
            . ' FROM drivers ORDER BY ' . ($this->columnExists($pdo, 'drivers', 'created_at') ? 'created_at' : 'id') . ' DESC LIMIT 100'
        )->fetchAll(\PDO::FETCH_ASSOC);

        $this->renderAdminPage('Drivers', function () use ($driverRows): void {
            echo '<div class="wrap"><h2>Driver Management</h2>';
            echo '<table><tr><th>Name</th><th>Phone</th><th>Rating</th><th>Total Rides</th><th>Verified</th><th>Blocked</th><th>Action</th></tr>';
            foreach ($driverRows as $d) {
                $idVal = $this->idToString($d['id'] ?? null);
                $blocked = (int)$d['is_blocked'] === 1;
                echo '<tr><td>' . htmlspecialchars((string)$d['name']) . '</td><td>' . htmlspecialchars((string)$d['phone']) . '</td><td>' . htmlspecialchars((string)$d['rating']) . '</td><td>' . htmlspecialchars((string)$d['total_rides']) . '</td><td>' . ((int)$d['is_verified'] === 1 ? 'Yes' : 'No') . '</td><td>' . ($blocked ? 'Yes' : 'No') . '</td><td>';
                if ($blocked) {
                    echo '<form method="POST" action="/admin/unblock-driver"><input type="hidden" name="driver_id" value="' . htmlspecialchars($idVal) . '" /><button class="btn btn-muted" type="submit">Unblock</button></form>';
                } else {
                    echo '<form method="POST" action="/admin/block-driver"><input type="hidden" name="driver_id" value="' . htmlspecialchars($idVal) . '" /><button class="btn btn-reject" type="submit">Block</button></form>';
                }
                echo '</td></tr>';
            }
            echo '</table></div>';
        });
    }

    private function renderVerificationPage(): void
    {
        $pdo = Mysql::connection();
        $pendingDrivers = [];
        if ($this->columnExists($pdo, 'drivers', 'verification_status')) {
            $pendingDrivers = $pdo->query(
                'SELECT id, '
                . $this->selectExpr($pdo, 'drivers', ['name', 'full_name'], 'name', "''") . ', '
                . $this->selectExpr($pdo, 'drivers', ['phone'], 'phone', "''") . ', '
                . $this->selectExpr($pdo, 'drivers', ['email'], 'email', "''") . ', '
                . $this->selectExpr($pdo, 'drivers', ['city'], 'city', "''") . ', '
                . $this->selectExpr($pdo, 'drivers', ['created_at'], 'created_at', 'NULL') . ', '
                . $this->selectExpr($pdo, 'drivers', ['vehicle_rc_path', 'rc_file'], 'vehicle_rc_path', "''") . ', '
                . $this->selectExpr($pdo, 'drivers', ['driving_license_path', 'driving_license_file'], 'driving_license_path', "''") . ', '
                . $this->selectExpr($pdo, 'drivers', ['aadhaar_card_path', 'aadhaar_file'], 'aadhaar_card_path', "''") . ', '
                . $this->selectExpr($pdo, 'drivers', ['driver_photo_path'], 'driver_photo_path', "''") . ', '
                . $this->selectExpr($pdo, 'drivers', ['insurance_doc_path'], 'insurance_doc_path', "''") . ', '
                . $this->selectExpr($pdo, 'drivers', ['puc_doc_path'], 'puc_doc_path', "''")
                . ' FROM drivers WHERE verification_status = "pending" ORDER BY created_at DESC LIMIT 50'
            )->fetchAll(\PDO::FETCH_ASSOC);
        }

        $this->renderAdminPage('Verification', function () use ($pendingDrivers): void {
            echo '<div class="wrap"><h2>Pending Driver Verification</h2>';
            if (count($pendingDrivers) === 0) {
                echo '<p>No pending drivers.</p>';
                echo '</div>';
                return;
            }
            echo '<table><tr><th>Name</th><th>Phone</th><th>Email</th><th>City</th><th>Docs</th><th>Applied</th><th>Approve</th><th>Reject</th></tr>';
            foreach ($pendingDrivers as $d) {
                $idVal = $this->idToString($d['id'] ?? null);
                echo '<tr><td>' . htmlspecialchars((string)$d['name']) . '</td><td>' . htmlspecialchars((string)$d['phone']) . '</td><td>' . htmlspecialchars((string)$d['email']) . '</td><td>' . htmlspecialchars((string)$d['city']) . '</td><td class="doc">';
                echo !empty($d['vehicle_rc_path']) ? '<a href="/admin/driver-doc?file=' . urlencode($d['vehicle_rc_path']) . '" target="_blank">RC</a>' : 'RC: N/A';
                echo ' | ' . (!empty($d['driving_license_path']) ? '<a href="/admin/driver-doc?file=' . urlencode($d['driving_license_path']) . '" target="_blank">DL</a>' : 'DL: N/A');
                echo ' | ' . (!empty($d['aadhaar_card_path']) ? '<a href="/admin/driver-doc?file=' . urlencode($d['aadhaar_card_path']) . '" target="_blank">Aadhaar</a>' : 'Aadhaar: N/A');
                echo ' | ' . (!empty($d['driver_photo_path']) ? '<a href="/admin/driver-doc?file=' . urlencode($d['driver_photo_path']) . '" target="_blank">Photo</a>' : 'Photo: N/A');
                echo ' | ' . (!empty($d['insurance_doc_path']) ? '<a href="/admin/driver-doc?file=' . urlencode($d['insurance_doc_path']) . '" target="_blank">Insurance</a>' : 'Insurance: N/A');
                echo ' | ' . (!empty($d['puc_doc_path']) ? '<a href="/admin/driver-doc?file=' . urlencode($d['puc_doc_path']) . '" target="_blank">PUC</a>' : 'PUC: N/A');
                echo '</td><td>' . htmlspecialchars((string)$d['created_at']) . '</td>';
                echo '<td><form method="POST" action="/admin/approve-driver"><input type="hidden" name="driver_id" value="' . htmlspecialchars($idVal) . '" /><button class="btn" type="submit">Approve</button></form></td>';
                echo '<td><form method="POST" action="/admin/reject-driver"><input type="hidden" name="driver_id" value="' . htmlspecialchars($idVal) . '" /><input type="text" name="reason" placeholder="Reason" /><button class="btn btn-reject" type="submit">Reject</button></form></td></tr>';
            }
            echo '</table></div>';
        });
    }

    private function renderFcmPage(): void
    {
        $this->renderAdminPage('FCM Test', function (): void {
            echo '<div class="wrap"><h2>Send FCM Test</h2>';
            echo '<form method="POST" action="/admin/fcm-test" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">';
            echo '<input type="text" name="driver_phone" placeholder="Driver phone (preferred)" style="min-width:200px;" />';
            echo '<input type="text" name="driver_id" placeholder="Driver UUID (optional)" style="min-width:260px;" />';
            echo '<input type="text" name="title" placeholder="Title" value="New Ride Request" style="min-width:200px;" />';
            echo '<input type="text" name="body" placeholder="Body" value="Pickup and drop details. Tap to view details" style="min-width:340px;" />';
            echo '<button class="btn" type="submit">Send Test Push</button>';
            echo '</form></div>';
        });
    }

    private function renderCouponsPage(): void
    {
        $pdo = Mysql::connection();
        $offerRows = $this->tableExists($pdo, 'offers')
            ? $pdo->query('SELECT id, code, title, discount_type, discount_value, max_discount, min_fare, payment_mode, vehicle_type, new_user_only, is_active, start_at, end_at, created_at FROM offers ORDER BY created_at DESC LIMIT 100')->fetchAll(\PDO::FETCH_ASSOC)
            : [];

        $this->renderAdminPage('Coupons', function () use ($offerRows): void {
            echo '<div class="wrap"><h2>Coupon Management</h2>';
            echo '<form method="POST" action="/admin/coupon-generate" style="display:grid;grid-template-columns:repeat(4,minmax(160px,1fr));gap:8px;align-items:end;">';
            echo '<div><div style="margin-bottom:4px;font-size:12px;">Code</div><input name="code" required placeholder="SAVE50" /></div>';
            echo '<div><div style="margin-bottom:4px;font-size:12px;">Title</div><input name="title" required placeholder="Flat Rs 50 Off" /></div>';
            echo '<div><div style="margin-bottom:4px;font-size:12px;">Discount Type</div><select name="discount_type"><option value="flat">flat</option><option value="percent">percent</option></select></div>';
            echo '<div><div style="margin-bottom:4px;font-size:12px;">Discount Value</div><input name="discount_value" required placeholder="50" /></div>';
            echo '<div><div style="margin-bottom:4px;font-size:12px;">Max Discount</div><input name="max_discount" placeholder="30 (for percent)" /></div>';
            echo '<div><div style="margin-bottom:4px;font-size:12px;">Min Fare</div><input name="min_fare" value="0" /></div>';
            echo '<div><div style="margin-bottom:4px;font-size:12px;">Payment Mode</div><select name="payment_mode"><option value="any">any</option><option value="cash">cash</option><option value="online">online</option></select></div>';
            echo '<div><div style="margin-bottom:4px;font-size:12px;">Vehicle Type</div><input name="vehicle_type" placeholder="(optional)" /></div>';
            echo '<div><div style="margin-bottom:4px;font-size:12px;">Start At (YYYY-MM-DD HH:MM:SS)</div><input name="start_at" placeholder="optional" /></div>';
            echo '<div><div style="margin-bottom:4px;font-size:12px;">End At (YYYY-MM-DD HH:MM:SS)</div><input name="end_at" placeholder="optional" /></div>';
            echo '<div><div style="margin-bottom:4px;font-size:12px;">New User Only</div><select name="new_user_only"><option value="0">No</option><option value="1">Yes</option></select></div>';
            echo '<div><div style="margin-bottom:4px;font-size:12px;">Active</div><select name="is_active"><option value="1">Yes</option><option value="0">No</option></select></div>';
            echo '<div style="grid-column:span 4;"><div style="margin-bottom:4px;font-size:12px;">Description</div><textarea name="description" placeholder="Coupon details"></textarea></div>';
            echo '<div><button class="btn" type="submit">Generate Coupon</button></div></form>';
            if (empty($offerRows)) {
                echo '<p style="margin-top:12px;">No coupons found.</p></div>';
                return;
            }
            echo '<table><tr><th>Code</th><th>Title</th><th>Type</th><th>Value</th><th>Max</th><th>Min Fare</th><th>Mode</th><th>Vehicle</th><th>New User</th><th>Active</th><th>Start</th><th>End</th></tr>';
            foreach ($offerRows as $offer) {
                echo '<tr><td>' . htmlspecialchars((string)$offer['code']) . '</td><td>' . htmlspecialchars((string)$offer['title']) . '</td><td>' . htmlspecialchars((string)$offer['discount_type']) . '</td><td>' . htmlspecialchars((string)$offer['discount_value']) . '</td><td>' . htmlspecialchars((string)($offer['max_discount'] ?? '')) . '</td><td>' . htmlspecialchars((string)$offer['min_fare']) . '</td><td>' . htmlspecialchars((string)$offer['payment_mode']) . '</td><td>' . htmlspecialchars((string)($offer['vehicle_type'] ?? '')) . '</td><td>' . ((int)($offer['new_user_only'] ?? 0) === 1 ? 'Yes' : 'No') . '</td><td>' . ((int)($offer['is_active'] ?? 0) === 1 ? 'Yes' : 'No') . '</td><td>' . htmlspecialchars((string)($offer['start_at'] ?? '')) . '</td><td>' . htmlspecialchars((string)($offer['end_at'] ?? '')) . '</td></tr>';
            }
            echo '</table></div>';
        });
    }

    private function renderAdminPage(string $title, callable $content): void
    {
        header('Content-Type: text/html');
        echo '<!doctype html><html><head><meta charset="utf-8" /><title>Mobility Admin - ' . htmlspecialchars($title) . '</title>';
        echo '<style>body{font-family:Arial,sans-serif;background:#0b0b0b;color:#fff;margin:0;} .wrap{padding:16px;} .stat{margin-right:12px;display:inline-block;} .filters a{color:#fff;margin-right:10px;} table{border-collapse:collapse;width:100%;margin-top:12px;} th,td{border:1px solid #222;padding:8px;font-size:13px;} .btn{background:#1ED760;color:#0b0b0b;border:none;padding:6px 10px;border-radius:6px;cursor:pointer;} .btn-reject{background:#ef4444;color:#fff;} .btn-warn{background:#f59e0b;color:#0b0b0b;} .btn-muted{background:#374151;color:#fff;} .doc a{color:#60a5fa;} input,select,textarea{background:#111;color:#fff;border:1px solid #333;padding:6px;border-radius:6px;} textarea{min-height:34px;}</style>';
        echo '</head><body><div class="wrap"><h1>Mobility Admin</h1><div class="filters" style="margin:8px 0 14px;">';
        echo '<a href="/admin">Overview</a><a href="/admin/pricing">Pricing</a><a href="/admin/rides">Rides</a><a href="/admin/drivers">Drivers</a><a href="/admin/verification">Verification</a><a href="/admin/coupons">Coupons</a><a href="/admin/fcm">FCM Test</a>';
        echo '</div>';
        $this->renderAdminFlashBanners();
        echo '</div>';
        $content();
        echo '</body></html>';
    }

    private function renderAdminFlashBanners(): void
    {
        $pricingStatus = (string)($_GET['pricing_status'] ?? '');
        $pricingMsg = (string)($_GET['pricing_msg'] ?? '');
        if ($pricingStatus !== '') {
            $color = $pricingStatus === 'ok' ? '#22c55e' : '#ef4444';
            echo '<div class="wrap"><div style="margin:8px 0 12px;padding:10px;border:1px solid ' . $color . ';border-radius:8px;"><strong>Pricing Update: ' . htmlspecialchars(strtoupper($pricingStatus)) . '</strong>' . ($pricingMsg !== '' ? (' | ' . htmlspecialchars($pricingMsg)) : '') . '</div></div>';
        }
        $couponStatus = (string)($_GET['coupon_status'] ?? '');
        $couponMsg = (string)($_GET['coupon_msg'] ?? '');
        if ($couponStatus !== '') {
            $color = $couponStatus === 'ok' ? '#22c55e' : '#ef4444';
            echo '<div class="wrap"><div style="margin:8px 0 12px;padding:10px;border:1px solid ' . $color . ';border-radius:8px;"><strong>Coupon: ' . htmlspecialchars(strtoupper($couponStatus)) . '</strong>' . ($couponMsg !== '' ? (' | ' . htmlspecialchars($couponMsg)) : '') . '</div></div>';
        }
        $fcmTestStatus = (string)($_GET['fcm_test'] ?? '');
        $fcmMsg = (string)($_GET['fcm_msg'] ?? '');
        if ($fcmTestStatus !== '') {
            $color = $fcmTestStatus === 'ok' ? '#22c55e' : '#ef4444';
            echo '<div class="wrap"><div style="margin:8px 0 12px;padding:10px;border:1px solid ' . $color . ';border-radius:8px;"><strong>FCM Test: ' . htmlspecialchars(strtoupper($fcmTestStatus)) . '</strong>' . ($fcmMsg !== '' ? (' | ' . htmlspecialchars($fcmMsg)) : '') . '</div></div>';
        }
        $rideActionStatus = (string)($_GET['ride_action'] ?? '');
        $rideMsg = (string)($_GET['ride_msg'] ?? '');
        if ($rideActionStatus !== '') {
            $color = $rideActionStatus === 'ok' ? '#22c55e' : '#ef4444';
            echo '<div class="wrap"><div style="margin:8px 0 12px;padding:10px;border:1px solid ' . $color . ';border-radius:8px;"><strong>Ride Action: ' . htmlspecialchars(strtoupper($rideActionStatus)) . '</strong>' . ($rideMsg !== '' ? (' | ' . htmlspecialchars($rideMsg)) : '') . '</div></div>';
        }
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $cacheKey = 'table:' . strtolower($table);
        if (array_key_exists($cacheKey, self::$schemaCache)) {
            return self::$schemaCache[$cacheKey];
        }
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        $stmt->execute([$table]);
        $exists = (bool) $stmt->fetchColumn();
        self::$schemaCache[$cacheKey] = $exists;
        return $exists;
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $cacheKey = 'col:' . strtolower($table) . ':' . strtolower($column);
        if (array_key_exists($cacheKey, self::$schemaCache)) {
            return self::$schemaCache[$cacheKey];
        }
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $stmt->execute([$table, $column]);
        $exists = (bool) $stmt->fetchColumn();
        self::$schemaCache[$cacheKey] = $exists;
        return $exists;
    }

    private function pickColumn(\PDO $pdo, string $table, array $candidates, string $default = 'NULL'): string
    {
        foreach ($candidates as $candidate) {
            if ($this->columnExists($pdo, $table, $candidate)) {
                return '`' . str_replace('`', '', $candidate) . '`';
            }
        }
        return $default;
    }

    private function selectExpr(\PDO $pdo, string $table, array $candidates, string $alias, string $default = 'NULL'): string
    {
        return $this->pickColumn($pdo, $table, $candidates, $default) . ' AS `' . str_replace('`', '', $alias) . '`';
    }

    private function selectExprFrom(\PDO $pdo, string $table, array $candidates, string $alias, string $fromAlias, string $default = 'NULL'): string
    {
        foreach ($candidates as $candidate) {
            if ($this->columnExists($pdo, $table, $candidate)) {
                return '`' . str_replace('`', '', $fromAlias) . '`.`' . str_replace('`', '', $candidate) . '` AS `' . str_replace('`', '', $alias) . '`';
            }
        }
        return $default . ' AS `' . str_replace('`', '', $alias) . '`';
    }

    private function parseEntityId(string $raw)
    {
        $id = trim($raw);
        if ($id === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $id)) {
            return $id;
        }
        if (preg_match('/^[0-9a-f\-]{36}$/i', $id)) {
            try {
                return Uuid::fromString($id);
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
    }

    private function entityIdCandidates(string $raw): array
    {
        $id = trim($raw);
        if ($id === '') {
            return [];
        }
        if (preg_match('/^\d+$/', $id)) {
            return [(int)$id, $id];
        }
        if (preg_match('/^[0-9a-f\-]{36}$/i', $id)) {
            $candidates = [$id];
            try {
                $candidates[] = Uuid::fromString($id);
            } catch (\Throwable $e) {
                // ignore binary variant when conversion fails
            }
            return $candidates;
        }
        return [$id];
    }

    private function resolveRideStatusValue(\PDO $pdo, string $preferred, array $fallbacks = []): ?string
    {
        $stmt = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "rides" AND COLUMN_NAME = "status" LIMIT 1');
        $stmt->execute();
        $columnType = strtolower((string)($stmt->fetchColumn() ?: ''));
        $values = [];
        if (preg_match_all("/'([^']+)'/", $columnType, $m) && !empty($m[1])) {
            $values = array_map(static fn ($v) => strtolower((string)$v), $m[1]);
        }
        if (empty($values)) {
            return $preferred;
        }
        $preferred = strtolower(trim($preferred));
        if (in_array($preferred, $values, true)) {
            return $preferred;
        }
        foreach ($fallbacks as $candidate) {
            $candidate = strtolower(trim((string)$candidate));
            if ($candidate !== '' && in_array($candidate, $values, true)) {
                return $candidate;
            }
        }
        return null;
    }

    private function normalizeRideStatusForAdmin(array $ride): string
    {
        $status = strtolower(trim((string)($ride['status'] ?? '')));
        if ($status !== '') {
            return $status;
        }
        if (!empty($ride['completed_at'])) {
            return 'ride_completed';
        }
        if (!empty($ride['started_at'])) {
            return 'ride_started';
        }
        if (!empty($ride['arrived_at'])) {
            return 'driver_arrived';
        }
        if (!empty($ride['assigned_at']) || !empty($ride['driver_id'])) {
            return 'driver_assigned';
        }
        return 'searching';
    }

    private function idToString($id): string
    {
        if ($id === null) {
            return '';
        }
        if (is_int($id) || (is_string($id) && preg_match('/^\d+$/', $id))) {
            return (string) $id;
        }
        if (is_string($id) && strlen($id) === 16) {
            return Uuid::toString($id);
        }
        return (string) $id;
    }

    private function jsonBody(): array
    {
        $body = file_get_contents('php://input');
        $data = json_decode($body ?: '', true);
        return is_array($data) ? $data : [];
    }

    private function respondJson(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload);
    }
}
