<?php

namespace App\Domain\SpecialLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 既存の特別休暇データの引き継ぎ(special_leave_account.migrated)。本変更前の付与・消化記録の現在の状態を
 * 今の時点に一度だけ追記する(運用コマンド special-leave:migrate-to-account)。利用者IDは末尾。
 */
class SpecialLeaveAccountMigrated extends ShouldBeStored
{
    public function __construct(
        public readonly array $grants,
        public readonly array $usages,
        public readonly ?string $userId = null,
    ) {}
}
