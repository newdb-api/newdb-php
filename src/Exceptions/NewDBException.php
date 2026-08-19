<?php

namespace NewDB\Exceptions;

use Exception;

class NewDBException extends Exception
{
}

class AuthenticationException extends NewDBException
{
}

class TimeoutException extends NewDBException
{
}

class APIResponseException extends NewDBException
{
    private int $statusCode;
    private array $responseBody;

    public function __construct(string $message, int $statusCode, array $responseBody = [])
    {
        parent::__construct("[{$statusCode}] {$message}", $statusCode);
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): array
    {
        return $this->responseBody;
    }
}
