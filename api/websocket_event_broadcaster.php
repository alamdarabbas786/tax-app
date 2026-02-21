<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/realtime_events.php';
require_once __DIR__ . '/fcm.php';
require_once __DIR__ . '/notification_store.php';

api_guard();
$data = json_input();
$missing = require_fields($data, ['event', 'ride_id']);
if ($missing) {
    json_response(422, ['status' => 'error', 'message' => 'event and ride_id required']);
    exit;
}

$event = trim((string)$data['event']);
$rideId = (int)$data['ride_id'];
$payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
$payload['ride_id'] = $rideId;

if ($event === '' || $rideId <= 0) {
    json_response(422, ['status' => 'error', 'message' => 'Invalid event or ride_id']);
    exit;
}

try {
    $pdo = db();
    $published = emit_ride_event($event, $rideId, $payload);

    $recipientRole = strtolower(trim((string)($data['recipient_role'] ?? 'system')));
    $recipientId = isset($data['recipient_id']) ? (int)$data['recipient_id'] : null;
    if (!in_array($recipientRole, ['driver', 'customer'], true)) {
        $recipientRole = 'system';
        $recipientId = null;
    }

    $fallbackResult = null;
    $fallback = $data['fallback_fcm'] ?? null;
    if (!$published && is_array($fallback)) {
        $title = trim((string)($fallback['title'] ?? 'Ride Update'));
        $body = trim((string)($fallback['body'] ?? 'Ride status changed'));
        if ($recipientRole !== 'system' && $recipientId && $title !== '' && $body !== '') {
            $fcmData = is_array($fallback['data'] ?? null) ? $fallback['data'] : [];
            $fcmData = array_merge($fcmData, [
                'ride_id' => (string)$rideId,
                'event' => $event
            ]);
            $fallbackResult = fcm_send_to_entity(
                $pdo,
                $recipientRole,
                (int)$recipientId,
                ['title' => $title, 'body' => $body],
                $fcmData,
                60,
                [
                    'role' => $recipientRole,
                    'recipient_id' => (int)$recipientId,
                    'event_type' => $event
                ]
            );
        }
    }

    $notificationId = notification_insert(
        $pdo,
        $rideId,
        $recipientRole,
        $recipientId,
        $event,
        $published ? 'websocket' : 'system',
        null,
        null,
        $payload,
        $published ? 'sent' : 'failed',
        $published ? null : 'websocket_offline'
    );

    if ($notificationId && is_array($fallbackResult) && !empty($fallbackResult['attempts'])) {
        foreach ($fallbackResult['attempts'] as $attempt) {
            notification_insert_attempt($pdo, $notificationId, [
                'attempt' => $attempt['attempt'] ?? 1,
                'status_code' => $attempt['status_code'] ?? null,
                'is_success' => ($attempt['status'] ?? '') === 'ok',
                'error' => $attempt['error'] ?? null,
                'response' => $attempt['response'] ?? null
            ]);
        }
    }

    json_response(200, [
        'status' => 'ok',
        'published' => $published,
        'fallback_fcm' => $fallbackResult
    ]);
} catch (Throwable $e) {
    json_response(500, ['status' => 'error', 'message' => $e->getMessage()]);
}

