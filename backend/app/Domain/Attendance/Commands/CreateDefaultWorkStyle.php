<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class CreateDefaultWorkStyle implements Command
{
    /**
     * @param  array<string, mixed>  $overrides  標準値(通常勤務: 月〜金9:00-18:00、休憩12:00-13:00)
     *                                           のうち、オンボーディング画面で編集された属性のみ。
     */
    public function __construct(
        public readonly array $overrides,
        public readonly int $createdByUserId,
    ) {}
}
