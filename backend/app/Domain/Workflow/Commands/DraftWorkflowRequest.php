<?php

namespace App\Domain\Workflow\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-W002: 社員が申請する(下書き保存)。
 */
class DraftWorkflowRequest implements Command
{
    /**
     * @param  array<string, mixed>  $formData
     */
    public function __construct(
        public readonly string $requestTypeCode,
        public readonly int $applicantUserId,
        public readonly string $title,
        public readonly array $formData,
        public readonly ?int $approverUserId = null,
    ) {}
}
