<?php

namespace App\Domain\SpecialLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 既存の特別休暇データ(付与・消化記録の現在の状態)を利用者の口座へ引き継ぐ(運用コマンド special-leave:migrate-to-account)。
 * 空の口座に一度だけ実行できる(口座集約が拒否する)。
 *
 * @param  array<int, array{grantId: string, specialLeaveTypeId: int, grantedOn: string, expiresOn: ?string, grantedDays: float, revoked: bool}>  $grants
 * @param  array<int, array{usageId: string, requestId: string, specialLeaveTypeId: int, usedOn: string, usageType: string, usedDays: float, usedMinutes: ?int, status: string, allocations: array<int, array{grantId: string, allocatedDays: float}>}>  $usages
 */
class MigrateSpecialLeaveAccount implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly array $grants,
        public readonly array $usages,
    ) {}
}
