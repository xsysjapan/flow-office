<?php

namespace App\Mcp\Support;

use Exception;

/**
 * backend/ APIが返した非2xxレスポンスを表す。mcp-server/(旧TS実装)のFlowOfficeApiErrorに対応する。
 */
class BackendApiException extends Exception
{
    public function __construct(string $message, public readonly int $status, public readonly mixed $body)
    {
        parent::__construct($message);
    }
}
