<?php

namespace NewDB;

use NewDB\Exceptions\AuthenticationException;
use NewDB\Exceptions\TimeoutException;
use NewDB\Exceptions\APIResponseException;

class Client
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeoutSeconds;

    public function __construct(string $apiKey, string $baseUrl = 'https://api.newdb.net/v2', int $timeoutSeconds = 60)
    {
        $this->apiKey = trim($apiKey);
        if (empty($this->apiKey)) {
            throw new AuthenticationException('API key (token) is required.');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeoutSeconds = $timeoutSeconds;
    }

    /**
     * Get token balance.
     */
    public function getBalance(): array
    {
        $response = $this->sendRequest('GET', '/balance');
        return $response;
    }

    /**
     * Execute arbitrary NewDB method.
     */
    public function execute(array $params, ?string $requestId = null, ?string $webhook = null): array
    {
        $reqId = $requestId ?? $this->generateUuid();
        $payload = [
            'requestId' => $reqId,
            'params' => $params,
        ];
        if ($webhook !== null) {
            $payload['webhook'] = $webhook;
        }

        return $this->sendRequest('POST', '', $payload);
    }

    /**
     * Get task state by requestId.
     */
    public function getTask(string $requestId): array
    {
        return $this->execute([], $requestId);
    }

    /**
     * Poll until task reaches complete or failed state.
     */
    public function waitForResult(string $requestId, int $timeoutSeconds = 120, int $pollIntervalSeconds = 2): array
    {
        $startTime = time();
        while (time() - $startTime < $timeoutSeconds) {
            $task = $this->getTask($requestId);
            $state = strtolower($task['state'] ?? 'unknown');
            if ($state === 'complete' || $state === 'failed') {
                return $task;
            }
            sleep($pollIntervalSeconds);
        }

        throw new TimeoutException("Task {$requestId} did not finish within {$timeoutSeconds} seconds.");
    }

    // --- Helper methods for Physical Persons ---

    public function checkPassportMvd(string $seria, string $number, string $firstname, string $lastname, array $extra = []): array
    {
        return $this->execute(array_merge([
            'method' => 'passport_mvd',
            'country' => 'ru',
            'seria' => $seria,
            'number' => $number,
            'firstname' => $firstname,
            'lastname' => $lastname,
        ], $extra));
    }

    public function checkPassportFns(string $seria, string $number, string $firstname, string $lastname, string $dob, array $extra = []): array
    {
        return $this->execute(array_merge([
            'method' => 'passport_fns',
            'country' => 'ru',
            'seria' => $seria,
            'number' => $number,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'dob' => $dob,
        ], $extra));
    }

    public function checkFssp(string $firstname, string $lastname, string $dob, string $regioncode = '100', array $extra = []): array
    {
        return $this->execute(array_merge([
            'method' => 'fssp_person',
            'country' => 'ru',
            'firstname' => $firstname,
            'lastname' => $lastname,
            'dob' => $dob,
            'regioncode' => $regioncode,
        ], $extra));
    }

    public function complexPassportCheck(string $seria, string $number, string $firstname, string $lastname, array $extra = []): array
    {
        return $this->execute(array_merge([
            'method' => 'complex_by_passport',
            'country' => 'ru',
            'seria' => $seria,
            'number' => $number,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'regioncode' => '100',
        ], $extra));
    }

    // --- Helper methods for Legal Entities ---

    public function checkEgrul(string $inn, array $extra = []): array
    {
        return $this->execute(array_merge([
            'method' => 'egrul',
            'country' => 'ru',
            'inn' => $inn,
        ], $extra));
    }

    public function checkFnsBlock(string $inn, ?string $bik = null, array $extra = []): array
    {
        $params = array_merge([
            'method' => 'fns_block',
            'country' => 'ru',
            'inn' => $inn,
        ], $extra);
        if ($bik !== null) {
            $params['bik'] = $bik;
        }
        return $this->execute($params);
    }

    public function complexCompanyCheck(string $inn, array $extra = []): array
    {
        return $this->execute(array_merge([
            'method' => 'complex_by_inn',
            'country' => 'ru',
            'inn' => $inn,
        ], $extra));
    }

    public function monitorKadCase(string $caseNumber, array $extra = []): array
    {
        return $this->execute(array_merge([
            'method' => 'kad_event_monitor',
            'country' => 'ru',
            'case_number' => $caseNumber,
        ], $extra));
    }

    // --- Internal HTTP transport ---

    private function sendRequest(string $method, string $path, ?array $body = null): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);

        $headers = [
            'X-API-KEY: ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutSeconds);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
            }
        }

        $rawResponse = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false) {
            throw new APIResponseException("cURL error: {$curlError}", 0);
        }

        if ($statusCode === 401 || $statusCode === 403) {
            throw new AuthenticationException('Invalid X-API-KEY token.');
        }

        $data = json_decode($rawResponse, true);
        if (!is_array($data)) {
            throw new APIResponseException("Invalid JSON response: {$rawResponse}", $statusCode);
        }

        if ($statusCode >= 500) {
            throw new APIResponseException($rawResponse, $statusCode, $data);
        }

        return $data;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
