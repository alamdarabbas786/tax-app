<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

api_guard();
$data = json_input();
$missing = require_fields($data, ['role', 'id', 'fcm_token']);
if ($missing) {
    json_response(422, ['status' => 'error', 'message' => 'role, id, fcm_token required']);
    exit;
}

$role = strtolower(trim((string)$data['role']));
$id = (int)$data['id'];
$token = trim((string)$data['fcm_token']);
$deviceId = trim((string)($data['device_id'] ?? 'default'));
$platform = strtolower(trim((string)($data['platform'] ?? 'unknown')));
if (!in_array($platform, ['android', 'ios', 'web', 'unknown'], true)) {
    $platform = 'unknown';
}

if ($id <= 0 || $token === '') {
    json_response(422, ['status' => 'error', 'message' => 'Invalid id or token']);
    exit;
}

$table = null;
if ($role === 'driver') {
    $table = 'drivers';
} elseif ($role === 'customer' || $role === 'user') {
    $table = 'users';
}
if ($table === null) {
    json_response(422, ['status' => 'error', 'message' => 'role must be driver or customer']);
    exit;
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    // In case a refreshed token was previously attached to another entity, deactivate old link.
    try {
        $pdo->prepare('UPDATE fcm_device_tokens
            SET is_active = 0, updated_at = NOW()
            WHERE fcm_token = ? AND NOT (role = ? AND entity_id = ?)')
            ->execute([$token, $role, $id]);
    } catch (Throwable $e) {
        // Optional table may not exist before migration.
    }

    try {
        $upsert = $pdo->prepare('INSERT INTO fcm_device_tokens
            (role, entity_id, device_id, platform, fcm_token, is_active, last_seen_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW(), NOW())
            ON DUPLICATE KEY UPDATE
              platform = VALUES(platform),
              fcm_token = VALUES(fcm_token),
              is_active = 1,
              last_seen_at = NOW(),
              updated_at = NOW()');
        $upsert->execute([$role, $id, $deviceId === '' ? 'default' : $deviceId, $platform, $token]);
    } catch (Throwable $e) {
        // Continue with legacy column update for backward compatibility.
    }

    $stmt = $pdo->prepare('UPDATE ' . $table . ' SET fcm_token = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$token, $id]);
    if ($stmt->rowCount() < 1) {
        $pdo->rollBack();
        json_response(404, ['status' => 'error', 'message' => ucfirst($role) . ' not found']);
        exit;
    }
    $pdo->commit();

    json_response(200, [
        'status' => 'ok',
        'message' => 'FCM token updated',
        'role' => $role,
        'id' => $id,
        'device_id' => $deviceId === '' ? 'default' : $deviceId,
        'platform' => $platform
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(500, ['status' => 'error', 'message' => $e->getMessage()]);
}
