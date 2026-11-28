<?php

namespace App\Domain\EventSourcing;

use RuntimeException;

/**
 * 一括操作で1件でも処理できない対象があったときに投げる例外(全部か無しか。全体が戻る)。
 * bootstrap/app.phpでAPIレスポンス422(code: bulk_operation_rejected)にマッピングされる。
 */
class BulkOperationRejected extends RuntimeException
{
    public const CODE = 'bulk_operation_rejected';

    /**
     * @param  list<array{id: int|string, label?: string, message: string}>  $failures
     */
    public function __construct(private readonly array $failures)
    {
        parent::__construct('選んだ対象のうち、処理できないものがあります。');
    }

    /**
     * @return list<array{id: int|string, label?: string, message: string}>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * @return array{message: string, code: string, errors: array<string, list<string>>, items: list<array{id: int|string, label?: string, message: string}>}
     */
    public function toResponseBody(): array
    {
        $errors = [];
        foreach ($this->failures as $failure) {
            $errors['items.'.$failure['id']][] = $failure['message'];
        }

        return [
            'message' => $this->getMessage(),
            'code' => self::CODE,
            'errors' => $errors,
            'items' => $this->failures,
        ];
    }
}
