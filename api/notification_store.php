<?php

declare(strict_types=1);

function notification_insert(
    PDO $pdo,
    ?int $rideId,
    string $recipientRole,
    ?int $recipientId,
    string $eventType,
    string $channel,
    ?string $title,
    ?string $body,
    array $payload,
    string $status,
    ?string $error = null
): ?int {
    try {
        $stmt = $pdo->prepare('INSERT INTO notifications
            (ride_id, recipient_role, recipient_id, event_type, channel, title, body, payload, status, error_message, sent_at, failed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $isSent = $status === 'sent';
        $isFailed = $status === 'failed' || $status === 'expired';
        $stmt->execute([
            $rideId,
            $recipientRole,
            $recipientId,
            $eventType,
            $channel,
            $title,
            $body,
            json_encode($payload, JSON_UNESCAPED_SLASHES),
            $status,
            $error,
            $isSent ? date('Y-m-d H:i:s') : null,
            $isFailed ? date('Y-m-d H:i:s') : null
        ]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        return null;
    }
}

function notification_insert_attempt(PDO $pdo, int $notificationId, array $attempt): void
{
    try {
        $stmt = $pdo->prepare('INSERT INTO notification_delivery_attempts
            (notification_id, attempt_no, status_code, is_success, error_message, response_body)
            VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $notificationId,
            (int)($attempt['attempt'] ?? 1),
            $attempt['status_code'] ?? null,
            !empty($attempt['is_success']) ? 1 : 0,
            $attempt['error'] ?? null,
            $attempt['response'] ?? null
        ]);
    } catch (Throwable $e) {
        // Notification analytics must not break flow.
    }
}

