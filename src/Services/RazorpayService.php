<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;

final class RazorpayService
{
    private string $keyId;
    private string $keySecret;
    private string $webhookSecret;
    private string $baseUrl = 'https://api.razorpay.com/v1';

    public function __construct()
    {
        $this->keyId = (string)(Env::get('RAZORPAY_KEY_ID', '') ?? '');
        $this->keySecret = (string)(Env::get('RAZORPAY_KEY_SECRET', '') ?? '');
        $this->webhookSecret = (string)(Env::get('RAZORPAY_WEBHOOK_SECRET', '') ?? '');
    }

    public function isConfigured(): bool
    {
        return $this->keyId !== '' && $this->keySecret !== '';
    }

    public function getPublicKey(): string
    {
        return $this->keyId;
    }

    public function createOrder(float $amount, string $currency, string $receipt, array $notes = []): array
    {
        $payload = [
            'amount' => max(1, (int)round($amount * 100)),
            'currency' => strtoupper(trim($currency)) ?: 'INR',
            'receipt' => $receipt,
            'notes' => (object)$notes,
            'payment_capture' => 1
        ];
        return $this->request('POST', '/orders', $payload);
    }

    public function createPaymentLink(float $amount, string $currency, string $referenceId, string $description, ?array $customer = null): array
    {
        $hasContact = is_array($customer) && !empty(trim((string)($customer['contact'] ?? '')));
        $hasEmail = is_array($customer) && !empty(trim((string)($customer['email'] ?? '')));
        $payload = [
            'amount' => max(1, (int)round($amount * 100)),
            'currency' => strtoupper(trim($currency)) ?: 'INR',
            'description' => $description,
            'reference_id' => $referenceId,
            'accept_partial' => false,
            'notify' => [
                'sms' => $hasContact,
                'email' => $hasEmail
            ],
            'reminder_enable' => true
        ];
        if ($customer && !empty($customer)) {
            $payload['customer'] = $customer;
        }
        return $this->request('POST', '/payment_links', $payload);
    }

    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        $payload = $orderId . '|' . $paymentId;
        $expected = hash_hmac('sha256', $payload, $this->keySecret);
        return hash_equals($expected, $signature);
    }

    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        if ($this->webhookSecret === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $this->webhookSecret);
        return hash_equals($expected, $signature);
    }

    public function fetchPaymentLink(string $paymentLinkId): array
    {
        $id = trim($paymentLinkId);
        if ($id === '') {
            throw new \RuntimeException('payment_link_id is required');
        }
        return $this->request('GET', '/payment_links/' . rawurlencode($id), null);
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Razorpay keys are not configured');
        }
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize HTTP client');
        }

        $headers = ['Content-Type: application/json'];
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERPWD, $this->keyId . ':' . $this->keySecret);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException($err !== '' ? $err : 'Razorpay API call failed');
        }

        $json = json_decode((string)$raw, true);
        if (!is_array($json)) {
            throw new \RuntimeException('Invalid Razorpay response');
        }
        if ($code < 200 || $code >= 300) {
            $msg = (string)($json['error']['description'] ?? $json['error']['reason'] ?? $json['error']['code'] ?? 'Razorpay API error');
            throw new \RuntimeException($msg);
        }
        return $json;
    }
}
