<?php

namespace NewDB;

use NewDB\Exceptions\AuthenticationException;
use NewDB\Exceptions\TimeoutException;
use NewDB\Exceptions\APIResponseException;

class Client
{
    public const DEFAULT_BASE_URL = 'https://api.newdb.net/v2';
    public const TEST_BASE_URL = 'https://api.newdb.net/test/v2';
    public const DEFAULT_TEST_TOKEN = 'test_token_newdb_sandbox';

    private string $apiKey;
    private string $baseUrl;
    private int $timeoutSeconds;
    private bool $testMode;

    public function __construct(?string $apiKey = null, ?string $baseUrl = null, int $timeoutSeconds = 60, bool $testMode = false)
    {
        $envTest = in_array(strtolower((string) getenv('NEWDB_TEST_MODE')), ['1', 'true', 'yes'], true);
        $this->testMode = $testMode || $envTest;

        $envKey = getenv('NEWDB_API_KEY') ?: null;
        $resolvedKey = trim((string) ($apiKey ?? $envKey ?? ($this->testMode ? self::DEFAULT_TEST_TOKEN : '')));

        if (empty($resolvedKey)) {
            throw new AuthenticationException('API key (token) is required.');
        }
        $this->apiKey = $resolvedKey;

        $envBaseUrl = getenv('NEWDB_BASE_URL') ?: null;
        $defaultUrl = $this->testMode ? self::TEST_BASE_URL : ($envBaseUrl ?: self::DEFAULT_BASE_URL);
        $this->baseUrl = rtrim((string) ($baseUrl ?? $defaultUrl), '/');
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

    /** Download an HTML or PDF report for one completed request. */
    public function generateReport(string $requestId, string $format = 'html', ?string $reportType = null): string
    {
        $query = ['requestId' => $requestId, 'format' => $format];
        if ($reportType !== null) {
            $query['report_type'] = $reportType;
        }
        return $this->sendRawRequest('GET', '/report?' . http_build_query($query));
    }

    /** Download an aggregated HTML or PDF report. */
    public function generateAggregatedReport(array $requestIds, string $reportType, string $format = 'html'): string
    {
        return $this->sendRawRequest('POST', '/report', [
            'requestIds' => $requestIds,
            'report_type' => $reportType,
            'format' => $format,
        ]);
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

    public function checkDriverLicense(string $num, string $lastname, string $firstname, string $birthdate, array $extra = []): array
    {
        return $this->execute(array_merge([
            'method' => 'driver_license',
            'country' => 'ru',
            'num' => $num,
            'lastname' => $lastname,
            'firstname' => $firstname,
            'birthdate' => $birthdate,
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

    public function checkDisqualified(string $query, array $extra = []): array
    {
        return $this->execute(array_merge([
            'method' => 'disqualified_person', 'country' => 'ru', 'query' => $query,
        ], $extra));
    }

    public function checkFsinWanted(string $fio, array $extra = []): array
    {
        return $this->execute(array_merge([
            'method' => 'fsin_wanted', 'country' => 'ru', 'fio' => $fio, 'get_details' => true,
        ], $extra));
    }

    public function checkCorporateRestrictions(array $params): array
    {
        return $this->execute(array_merge([
            'method' => 'corporate_restrictions_person', 'country' => 'ru',
        ], $params));
    }

    public function checkOpenSanctions(string $query, array $extra = []): array
    {
        return $this->execute(array_merge([
            'method' => 'opensanctions', 'country' => 'ru',
            'query' => $query, 'max_results' => 25,
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

    public function checkBo(string $inn, array $extra = []): array
    {
        return $this->execute(array_merge([
            'method' => 'fns_bo',
            'country' => 'ru',
            'inn' => $inn,
        ], $extra));
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

    // --- Helper methods for Property / Vehicles ---

    public function checkPledgeVin(string $vin, array $extra = []): array
    {
        return $this->execute(array_merge([
            'method' => 'pledge_vin',
            'country' => 'ru',
            'vin' => $vin,
        ], $extra));
    }

    public function checkVin(string $vin, int $getScreen = 0, array $extra = []): array
    {
        return $this->execute(array_merge([
            'method' => 'vin_check',
            'vin' => $vin,
            'get_screen' => $getScreen,
        ], $extra));
    }

    public function checkIntellectualProperty(array $params = []): array
    {
        return $this->execute(array_merge([
            'method' => 'intellectual_property',
            'search_type' => 'all',
            'limit' => 10,
            'offset' => 0,
            'country' => 'ru',
        ], $params));
    }

    /**
     * Арбитраж по компаниям физлица: ЕГРЮЛ-связи + агрегация дел КАД со скорингом субсидиарного риска.
     */
    public function checkCourtArbitration(string $innfiz, ?int $companyLimit = null, array $extra = []): array
    {
        $params = array_merge(['method' => 'court_arbitration', 'innfiz' => $innfiz, 'country' => 'ru'], $extra);
        if ($companyLimit !== null) {
            $params['company_limit'] = $companyLimit;
        }
        return $this->execute($params);
    }

    /**
     * Сумма задолженностей физлица по арбитражным делам КАД (агрегат debt_summary).
     */
    public function checkArbitrDebtSum(string $innfiz, ?int $maxCases = null, array $extra = []): array
    {
        $params = array_merge(['method' => 'arbitr_debt_sum', 'innfiz' => $innfiz, 'country' => 'ru'], $extra);
        if ($maxCases !== null) {
            $params['max_cases'] = $maxCases;
        }
        return $this->execute($params);
    }

    /**
     * Долги ФССП по связанным компаниям физлица (ЕГРЮЛ-связи + ФССП по компаниям).
     */
    public function checkFsspCompany(string $inn, ?int $maxCompanies = null, ?bool $onlyActive = null, array $extra = []): array
    {
        $params = array_merge(['method' => 'fssp_company', 'inn' => $inn, 'country' => 'ru'], $extra);
        if ($maxCompanies !== null) {
            $params['max_companies'] = $maxCompanies;
        }
        if ($onlyActive !== null) {
            $params['only_active'] = $onlyActive;
        }
        return $this->execute($params);
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

    private function sendRawRequest(string $method, string $path, ?array $body = null): string
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutSeconds);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-API-KEY: ' . $this->apiKey,
            'Content-Type: application/json',
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new APIResponseException("cURL error: {$curlError}", 0);
        }
        if ($statusCode === 401 || $statusCode === 403) {
            throw new AuthenticationException('Invalid X-API-KEY token.');
        }
        if ($statusCode !== 200) {
            throw new APIResponseException($response, $statusCode);
        }
        return $response;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
