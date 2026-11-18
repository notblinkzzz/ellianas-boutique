<?php
// includes/IntegrationClient.php
require_once __DIR__ . '/../config/integrations.php';

class IntegrationClient {
    private int $timeoutSeconds;
    private int $maxRetries;

    public function __construct(int $timeoutSeconds = 3, int $maxRetries = 2) {
        $this->timeoutSeconds = $timeoutSeconds;
        $this->maxRetries = $maxRetries;
    }

    private function logEvent(string $service, string $endpoint, int $httpCode, float $latencyMs, string $status, string $detail = ''): void {
        $dir = dirname(INTEGRATION_LOG_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $entry = sprintf(
            "[%s] SERVICE=%s | ENDPOINT=%s | HTTP=%d | LATENCY=%.2fms | STATUS=%s | DETAIL=%s\n",
            date('Y-m-d H:i:s'),
            $service,
            $endpoint,
            $httpCode,
            $latencyMs,
            $status,
            $detail
        );
        file_put_contents(INTEGRATION_LOG_FILE, $entry, FILE_APPEND);
    }

    private function sendRequest(string $service, string $url, string $body, array $headers): array {
        $attempt = 0;
        $lastError = 'Unknown error';

        while ($attempt <= $this->maxRetries) {
            $attempt++;
            $start = microtime(true);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);

            $rawResponse = curl_exec($ch);
            $curlErrNo = curl_errno($ch);
            $curlErr = curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $latencyMs = (microtime(true) - $start) * 1000;

            if ($curlErrNo !== 0) {
                $lastError = "Network/Timeout Error (cURL #$curlErrNo): $curlErr";
                $this->logEvent($service, $url, 0, $latencyMs, 'TIMEOUT_OR_NET_ERR', "Attempt $attempt: $lastError");
                if ($attempt <= $this->maxRetries) {
                    usleep(200000 * $attempt);
                    continue;
                }
                return ['success' => false, 'error_type' => 'TIMEOUT', 'message' => $lastError];
            }

            if ($httpCode >= 500) {
                $lastError = "Upstream HTTP $httpCode";
                $this->logEvent($service, $url, $httpCode, $latencyMs, 'UPSTREAM_5XX', "Attempt $attempt");
                if ($attempt <= $this->maxRetries) {
                    usleep(200000 * $attempt);
                    continue;
                }
                return ['success' => false, 'error_type' => 'HTTP_500', 'message' => $lastError];
            }

            $decoded = json_decode($rawResponse, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                $this->logEvent($service, $url, $httpCode, $latencyMs, 'MALFORMED_JSON', substr((string)$rawResponse, 0, 100));
                return ['success' => false, 'error_type' => 'MALFORMED_DATA', 'message' => 'Invalid JSON schema returned by dependency'];
            }

            if ($httpCode >= 400) {
                $this->logEvent($service, $url, $httpCode, $latencyMs, 'CLIENT_4XX', json_encode($decoded));
                return ['success' => false, 'error_type' => 'HTTP_4XX', 'message' => 'Rejected by upstream service', 'data' => $decoded];
            }

            $this->logEvent($service, $url, $httpCode, $latencyMs, 'SUCCESS', "Attempt $attempt");
            return ['success' => true, 'data' => $decoded];
        }

        return ['success' => false, 'error_type' => 'EXHAUSTED', 'message' => $lastError];
    }

    /**
     * Payment Intent Integration Method
     */
    public function createPaymentIntent(float $amountPhp, string $paymentMethod, string $description): array {
        $jsonBody = json_encode([
            'amount' => $amountPhp,
            'currency' => 'PHP',
            'payment_method' => $paymentMethod,
            'description' => $description
        ]);

        $headers = [
            'X-Api-Key: ' . LOGISTICS_API_KEY,
            'Content-Type: application/json'
        ];

        $res = $this->sendRequest('PAYMENT_API', STRIPE_BASE_URL . '/payments', $jsonBody, $headers);

        if (!$res['success']) {
            return $res;
        }

        $paymentRef = $res['data']['payment_reference'] ?? null;
        if (!$paymentRef) {
            return ['success' => false, 'error_type' => 'SCHEMA_MISMATCH', 'message' => 'Missing payment reference'];
        }

        return ['success' => true, 'payment_reference' => $paymentRef];
    }

    /**
     * Logistics Waybill Integration Method
     */
    public function requestWaybill(string $courier, string $city, string $zip): array {
        $jsonBody = json_encode([
            'courier' => $courier,
            'city' => $city,
            'zip' => $zip
        ]);

        $headers = [
            'X-Api-Key: ' . LOGISTICS_API_KEY,
            'Content-Type: application/json'
        ];

        $res = $this->sendRequest('LOGISTICS_API', LOGISTICS_API_URL, $jsonBody, $headers);

        if (!$res['success']) {
            return $res;
        }

        $tracking = $res['data']['tracking_number'] ?? null;
        $fee = $res['data']['calculated_fee'] ?? null;

        if (!$tracking || !is_numeric($fee)) {
            return ['success' => false, 'error_type' => 'SCHEMA_MISMATCH', 'message' => 'Malformed waybill fields'];
        }

        return [
            'success' => true,
            'tracking_number' => $tracking,
            'shipping_fee' => (float) $fee
        ];
    }
}