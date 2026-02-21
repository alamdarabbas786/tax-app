<?php

namespace App;

use App\Controllers\HealthController;
use App\Controllers\MysqlHealthController;
use App\Controllers\RidesController;
use App\Controllers\AuthController;
use App\Controllers\DriverController;
use App\Controllers\AdminController;
use App\Controllers\MapsController;
use App\Controllers\FareController;
use App\Controllers\RatingsController;
use App\Controllers\WalletController;
use App\Controllers\OffersController;
use App\Controllers\PaymentGatewayController;
use App\Auth\AdminAuth;
use App\Auth\AdminWebAuth;

class App
{
    public function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

        if ($method === 'GET' && $path === '/health') {
            (new HealthController())->handle();
            return;
        }

        if ($method === 'GET' && $path === '/mysql-health') {
            (new MysqlHealthController())->handle(false);
            return;
        }

        if ($method === 'GET' && $path === '/mysql-health/full') {
            if (!AdminAuth::check()) {
                AdminAuth::unauthorized();
                return;
            }
            (new MysqlHealthController())->handle(true);
            return;
        }

        if ($method === 'POST' && $path === '/api/admin/fcm-test') {
            if (!AdminAuth::check()) {
                AdminAuth::unauthorized();
                return;
            }
            (new AdminController())->fcmTest();
            return;
        }

        if ($method === 'POST' && $path === '/api/auth/request-otp') {
            (new AuthController())->requestOtp();
            return;
        }

        if ($method === 'POST' && $path === '/api/auth/verify-otp') {
            (new AuthController())->verifyOtp();
            return;
        }

        if ($method === 'POST' && $path === '/api/check-mobile') {
            (new AuthController())->checkMobile();
            return;
        }

        if ($method === 'POST' && $path === '/api/send-otp') {
            (new AuthController())->sendOtp();
            return;
        }

        if ($method === 'POST' && $path === '/api/verify-otp') {
            (new AuthController())->verifyOtpSimple();
            return;
        }

        if ($method === 'POST' && $path === '/api/customer/register') {
            (new AuthController())->registerCustomer();
            return;
        }



        if ($method === 'POST' && $path === '/api/maps/route') {
            (new MapsController())->route();
            return;
        }

        if ($method === 'POST' && $path === '/api/fare/estimate') {
            (new FareController())->estimate();
            return;
        }

        if ($method === 'GET' && $path === '/api/offers/list') {
            (new OffersController())->list();
            return;
        }

        if ($method === 'POST' && $path === '/api/offers/apply') {
            (new OffersController())->apply();
            return;
        }

        if ($method === 'POST' && $path === '/api/payments/create-order') {
            (new PaymentGatewayController())->createOrder();
            return;
        }

        if ($method === 'POST' && $path === '/api/payments/create-link') {
            (new PaymentGatewayController())->createPaymentLink();
            return;
        }

        if ($method === 'POST' && $path === '/api/payments/verify') {
            (new PaymentGatewayController())->verifyPayment();
            return;
        }

        if ($method === 'GET' && $path === '/api/payments/status') {
            (new PaymentGatewayController())->paymentStatus();
            return;
        }

        if ($method === 'POST' && $path === '/api/payments/webhook/razorpay') {
            (new PaymentGatewayController())->razorpayWebhook();
            return;
        }

        if ($method === 'POST' && $path === '/api/rides') {
            (new RidesController())->create();
            return;
        }

        if ($method === 'POST' && $path === '/api/rides/request') {
            (new RidesController())->create();
            return;
        }

        if ($method === 'GET' && preg_match('#^/api/rides/([0-9a-f\-]+)$#', $path, $m)) {
            (new RidesController())->getRide($m[1]);
            return;
        }
        if ($method === 'GET' && $path === '/api/rides/active') {
            (new RidesController())->getActiveRide();
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/rides/([0-9a-f\-]+)/cancel$#', $path, $m)) {
            (new RidesController())->cancelRide($m[1]);
            return;
        }

        if ($method === 'POST' && $path === '/api/ratings') {
            (new RatingsController())->submit();
            return;
        }

        if ($method === 'POST' && $path === '/api/driver/register') {
            (new DriverController())->register();
            return;
        }

        if ($method === 'GET' && $path === '/api/driver/me') {
            (new DriverController())->me();
            return;
        }

        if ($method === 'GET' && $path === '/api/driver/stats') {
            (new DriverController())->stats();
            return;
        }

        if ($method === 'GET' && $path === '/api/driver/earnings') {
            (new DriverController())->earningsHistory();
            return;
        }

        if ($method === 'POST' && $path === '/api/driver/location') {
            (new DriverController())->updateLocation();
            return;
        }

        if ($method === 'POST' && $path === '/api/driver/push-token') {
            (new DriverController())->updatePushToken();
            return;
        }

        if ($method === 'GET' && $path === '/api/driver/requests') {
            (new DriverController())->nearbyRequests();
            return;
        }

        if ($method === 'POST' && $path === '/api/driver/availability') {
            (new DriverController())->setAvailability();
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/driver/rides/([0-9a-f\-]+)/accept$#', $path, $m)) {
            (new DriverController())->acceptRide($m[1]);
            return;
        }
		if ($method === 'POST' && preg_match('#^/api/driver/rides/([0-9a-f\-]+)/reject$#', $path, $m)) {
			(new DriverController())->rejectRide($m[1]);
			return;
		}

        if ($method === 'POST' && preg_match('#^/api/driver/rides/([0-9a-f\-]+)/arrived$#', $path, $m)) {
            (new DriverController())->arrivedRide($m[1]);
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/driver/rides/([0-9a-f\-]+)/start$#', $path, $m)) {
            (new DriverController())->startRide($m[1]);
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/driver/rides/([0-9a-f\-]+)/progress$#', $path, $m)) {
            (new DriverController())->updateRideProgress($m[1]);
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/driver/rides/([0-9a-f\-]+)/complete$#', $path, $m)) {
            (new DriverController())->completeRide($m[1]);
            return;
        }

        if ($method === 'POST' && $path === '/api/complete_ride.php') {
            (new DriverController())->completeRideFromBody();
            return;
        }

        if ($method === 'POST' && $path === '/api/calculate_commission.php') {
            (new WalletController())->calculateCommission();
            return;
        }

        if ($method === 'POST' && $path === '/api/update_wallet.php') {
            (new WalletController())->updateWallet();
            return;
        }

        if ($method === 'GET' && $path === '/api/driver_wallet_summary.php') {
            (new WalletController())->driverWalletSummary();
            return;
        }

        if ($method === 'POST' && $path === '/api/payout_driver.php') {
            (new WalletController())->payoutDriver();
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/driver/rides/([0-9a-f\-]+)/cancel$#', $path, $m)) {
            (new DriverController())->cancelRide($m[1]);
            return;
        }

        if ($method === 'GET' && $path === '/admin') {
            if (!AdminWebAuth::check()) {
                AdminWebAuth::requireAuth();
                return;
            }
            (new AdminController())->overview();
            return;
        }

        if ($method === 'GET' && $path === '/admin/overview') {
            if (!AdminWebAuth::check()) {
                AdminWebAuth::requireAuth();
                return;
            }
            (new AdminController())->overview();
            return;
        }

        if ($method === 'GET' && $path === '/admin/pricing') {
            if (!AdminWebAuth::check()) {
                AdminWebAuth::requireAuth();
                return;
            }
            (new AdminController())->pricingPage();
            return;
        }

        if ($method === 'GET' && $path === '/admin/rides') {
            if (!AdminWebAuth::check()) {
                AdminWebAuth::requireAuth();
                return;
            }
            (new AdminController())->ridesPage();
            return;
        }

        if ($method === 'GET' && $path === '/admin/drivers') {
            if (!AdminWebAuth::check()) {
                AdminWebAuth::requireAuth();
                return;
            }
            (new AdminController())->driversPage();
            return;
        }

        if ($method === 'GET' && $path === '/admin/verification') {
            if (!AdminWebAuth::check()) {
                AdminWebAuth::requireAuth();
                return;
            }
            (new AdminController())->verificationPage();
            return;
        }

        if ($method === 'GET' && $path === '/admin/fcm') {
            if (!AdminWebAuth::check()) {
                AdminWebAuth::requireAuth();
                return;
            }
            (new AdminController())->fcmPage();
            return;
        }

        if ($method === 'GET' && $path === '/admin/coupons') {
            if (!AdminWebAuth::check()) {
                AdminWebAuth::requireAuth();
                return;
            }
            (new AdminController())->couponsPage();
            return;
        }

        if ($method === 'POST' && $path === '/admin/pricing-update') {
            if (!AdminWebAuth::check()) {
                AdminWebAuth::requireAuth();
                return;
            }
            (new AdminController())->pricingUpdate();
            return;
        }

        if ($method === 'POST' && $path === '/admin/coupon-generate') {
            if (!AdminWebAuth::check()) {
                AdminWebAuth::requireAuth();
                return;
            }
            (new AdminController())->couponGenerate();
            return;
        }

        if ($method === 'POST' && $path === '/admin/approve-driver') {
            if (!AdminWebAuth::check()) {
                AdminWebAuth::requireAuth();
                return;
            }
            (new AdminController())->approveDriver();
            return;
        }

        if ($method === 'POST' && $path === '/admin/reject-driver') {
            if (!AdminWebAuth::check()) {
                AdminWebAuth::requireAuth();
                return;
            }
            (new AdminController())->rejectDriver();
            return;
        }

        if ($method === 'POST' && $path === '/admin/block-driver') {
            if (!AdminWebAuth::check()) {
                AdminWebAuth::requireAuth();
                return;
            }
            (new AdminController())->blockDriver();
            return;
        }

        if ($method === 'POST' && $path === '/admin/unblock-driver') {
            if (!AdminWebAuth::check()) {
                AdminWebAuth::requireAuth();
                return;
            }
            (new AdminController())->unblockDriver();
            return;
        }

        if ($method === 'POST' && $path === '/admin/ride-cancel') {
            if (!AdminWebAuth::check()) {
                AdminWebAuth::requireAuth();
                return;
            }
            (new AdminController())->rideCancel();
            return;
        }

        if ($method === 'POST' && $path === '/admin/ride-unassign') {
            if (!AdminWebAuth::check()) {
                AdminWebAuth::requireAuth();
                return;
            }
            (new AdminController())->rideUnassign();
            return;
        }

        if ($method === 'POST' && $path === '/admin/ride-delete') {
            if (!AdminWebAuth::check()) {
                AdminWebAuth::requireAuth();
                return;
            }
            (new AdminController())->rideDelete();
            return;
        }

        if ($method === 'POST' && $path === '/admin/ride-delete-all') {
            if (!AdminWebAuth::check()) {
                AdminWebAuth::requireAuth();
                return;
            }
            (new AdminController())->rideDeleteAll();
            return;
        }

        if ($method === 'POST' && $path === '/admin/fcm-test') {
            if (!AdminWebAuth::check()) {
                AdminWebAuth::requireAuth();
                return;
            }
            (new AdminController())->fcmTestFromAdmin();
            return;
        }

        if ($method === 'GET' && $path === '/admin/driver-doc') {
            if (!AdminWebAuth::check()) {
                AdminWebAuth::requireAuth();
                return;
            }
            (new AdminController())->serveDriverDoc();
            return;
        }

        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Not found']);
    }
}



