<?php

namespace App\Controllers;

use App\Auth\ApiAuth;
use App\Db\Mysql;
use App\Utils\Uuid;

class RatingsController
{
    public function submit(): void
    {
        $auth = ApiAuth::requireRole('customer');
        if (!$auth) {
            return;
        }

        $body = file_get_contents('php://input');
        $data = json_decode($body ?: '', true);
        if (!is_array($data)) {
            $this->respond(400, ['status' => 'error', 'message' => 'Invalid JSON']);
            return;
        }

        $rideId = $data['ride_id'] ?? '';
        $driverId = $data['driver_id'] ?? '';
        $rating = $data['rating'] ?? null;
        $comment = $data['comment'] ?? null;

        if (!is_string($rideId) || $rideId === '' || !is_string($driverId) || $driverId === '') {
            $this->respond(422, ['status' => 'error', 'message' => 'ride_id and driver_id required']);
            return;
        }
        if (!is_numeric($rating) || (int)$rating < 1 || (int)$rating > 5) {
            $this->respond(422, ['status' => 'error', 'message' => 'rating must be 1-5']);
            return;
        }

        $pdo = Mysql::connection();
        $stmt = $pdo->prepare('INSERT INTO ratings (ride_id, driver_id, customer_id, rating, comment) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([Uuid::fromString($rideId), Uuid::fromString($driverId), $auth['subject_id'], (int)$rating, $comment]);

        $avg = $pdo->prepare('SELECT AVG(rating) AS avg_rating FROM ratings WHERE driver_id = ?');
        $avg->execute([Uuid::fromString($driverId)]);
        $row = $avg->fetch(\PDO::FETCH_ASSOC);
        if ($row && $row['avg_rating']) {
            $upd = $pdo->prepare('UPDATE drivers SET rating = ? WHERE id = ?');
            $upd->execute([round((float)$row['avg_rating'], 2), Uuid::fromString($driverId)]);
        }

        $this->respond(200, ['status' => 'ok']);
    }

    private function respond(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload);
    }
}
