<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/fcm.php';
require_once __DIR__ . '/notification_store.php';

api_guard();
$data = json_input();

$notification = $data['notification'] ?? null;
if (!is_array($notification) || empty($notification['title']) || empty($notification['body'])) {
    json_response(422, ['status' => 'error', 'message' => 'notification.title and notification.body required']);
    exit;
}

$targetToken = isset($data['token']) ? trim((string)$data['token']) : '';
$role = strtolower(trim((string)($data['role'] ?? '')));
$recipientId = isset($data['recipient_id']) ? (int)$data['recipient_id'] : 0;
$payload = is_array($data['data'] ?? null) ? $data['data'] : [];
$eventType = trim((string)($data['event_type'] ?? 'generic_notification'));
$rideId = isset($data['ride_id']) ? (int)$data['ride_id'] : null;
$ttlSeconds = isset($data['ttl_seconds']) ? (int)$data['ttl_seconds'] : 60;

if ($targetToken === '' && !($recipientId > 0 && in_array($role, ['driver', 'customer'], true))) {
    json_response(422, ['status' => 'error', 'message' => 'Provide token or (role + recipient_id)']);
    exit;
}

try {
    $pdo = db();
    if ($targetToken !== '') {
        $result = fcm_send_to_token($targetToken, $notification, $payload, $ttlSeconds, [
            'role' => $role !== '' ? $role : null,
            'recipient_id' => $recipientId > 0 ? $recipientId : null,
            'event_type' => $eventType
        ]);
    } else {
        $result = fcm_send_to_entity($pdo, $role, $recipientId, $notification, $payload, $ttlSeconds, [
            'role' => $role,
            'recipient_id' => $recipientId,
            'event_type' => $eventType
        ]);
    }

    $notificationId = notification_insert(
        $pdo,
        $rideId,
        in_array($role, ['driver', 'customer'], true) ? $role : 'system',
        $recipientId > 0 ? $recipientId : null,
        $eventType,
        'fcm',
        (string)$notification['title'],
        (string)$notification['body'],
        $payload,
        ($result['status'] ?? '') === 'ok' ? 'sent' : 'failed',
        $result['error'] ?? null
    );
    if ($notificationId) {
        foreach (($result['attempts'] ?? []) as $attempt) {
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
        'event_type' => $eventType,
        'result' => $result
    ]);
} catch (Throwable $e) {
    json_response(500, ['status' => 'error', 'message' => $e->getMessage()]);
}

