<?php

namespace App\Domain\PaidLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 旧システム/旧ドメインからのcutover移行専用Command。管理者権限限定(ルート側
 * `permission:leave.manage,any`で認可、docs/changesets/20260906-paid-leave-domain-redesign/
 * spec.md「Command/Handler」参照)。口座(userId)ごとに一度きりの操作。
 *
 * 3モードいずれの入力も同じ形で受け付ける(依頼書§46):
 * - A. Grant単位で完全に分かる: originalGrantedOn/originalGrantedDaysを指定。
 * - B. 前年度繰越+当年度残高: 繰越分・当年度分をそれぞれ1件のGrantとして指定
 *   (originalGrantedOnが分かる範囲で指定、remainingDaysAtCutoverは各Grantの残日数)。
 * - C. 残高と有効期限のみ: originalGrantedOn/originalGrantedDaysをnullのまま指定。
 *
 * @param  array<int, array{grantId?: ?string, originalGrantedOn?: ?string, originalGrantedDays?: ?float, remainingDaysAtCutover: float, expiresOn: string, mode: string, notes?: ?string}>  $grants
 */
class MigratePaidLeaveAccount implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $cutoverDate,
        public readonly array $grants,
    ) {}
}
