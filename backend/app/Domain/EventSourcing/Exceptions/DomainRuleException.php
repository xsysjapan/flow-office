<?php

namespace App\Domain\EventSourcing\Exceptions;

use RuntimeException;

/**
 * CommandHandlerが業務ルール違反を検知した際に投げる例外。
 * bootstrap/app.phpでAPIレスポンス422にマッピングされる(クライアント起因のエラーとして扱う)。
 */
class DomainRuleException extends RuntimeException {}
