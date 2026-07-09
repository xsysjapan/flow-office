<?php

namespace App\Domain\EventSourcing\Exceptions;

use RuntimeException;

/**
 * 同一集約に対して並行して書き込みが発生し、バージョンの整合性が取れなかった場合に投げる。
 */
class ConcurrencyException extends RuntimeException {}
