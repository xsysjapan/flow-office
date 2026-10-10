<?php

namespace App\Console\Commands;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\SpecialLeaveAccount\Aggregates\SpecialLeaveAccountAggregate;
use App\Domain\SpecialLeaveAccount\Commands\MigrateSpecialLeaveAccount;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveGrantStatus;
use App\Models\SpecialLeaveRequest;
use App\Models\SpecialLeaveRequestStatus;
use App\Models\SpecialLeaveUsage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * 既存の特別休暇データ(本変更前の付与・消化記録の現在の状態)を利用者単位の特別休暇口座へ引き継ぐ運用コマンド
 * (仕様確定事項D・I。変更セット20261009-keep-leave-work-type-on-edit)。
 *
 * - 対象は利用者ごと。口座が移行済み(special_leave_account.migratedがある)利用者は対象外(冪等)。
 *   移行前に新しい流れで登録された付与・申請は口座に既にあるため移行対象から除き、旧付与・旧申請だけを引き継ぐ。
 * - 付与(取消済みを含む)と、申請中・承認済みの申請の消化記録の現在の状態を`special_leave_account.migrated`として
 *   今の時点に追記する。差し戻し・取消された申請の消化記録は含めない。
 * - 既定は試し実行(件数の表示のみ)。`--apply`を付けたときだけ追記する。
 * - 1利用者の失敗で止めず、失敗は最後にまとめて表示する。
 *
 * 承認済みの消化記録の未充当量は現状0として引き継ぐ(リハーサルで件数を確認して決める。変更セットの確認事項)。
 */
class MigrateSpecialLeaveToAccountCommand extends Command
{
    protected $signature = 'special-leave:migrate-to-account {--apply : 実際に口座へ引き継ぐ(指定しない場合は試し実行)}';

    protected $description = '既存の特別休暇の付与・消化記録を利用者単位の特別休暇口座へ引き継ぐ(既定は試し実行、--applyで実行、冪等)';

    public function handle(CommandBus $commandBus): int
    {
        $apply = (bool) $this->option('apply');

        $userIds = SpecialLeaveGrant::query()->distinct()->pluck('user_id')
            ->merge(
                SpecialLeaveRequest::query()
                    ->whereIn('status', [SpecialLeaveRequestStatus::SUBMITTED, SpecialLeaveRequestStatus::APPROVED])
                    ->distinct()
                    ->pluck('user_id'),
            )
            ->map(fn ($userId) => (string) $userId)
            ->unique()
            ->values();

        $successes = 0;
        $skipped = 0;
        $failures = [];
        $rows = [];

        foreach ($userIds as $userId) {
            $aggregate = SpecialLeaveAccountAggregate::retrieve(SpecialLeaveAccountAggregate::streamIdFor($userId));

            // 移行済みの利用者は対象外(再実行しても二重に引き継がない)。
            if ($aggregate->isMigrated()) {
                $skipped++;
                $rows[] = [$userId, 'skip(移行済み)', '-', '-'];

                continue;
            }

            try {
                $grants = $this->grantsOf($userId, $aggregate);
                $usages = $this->usagesOf($userId, $aggregate);

                if ($grants === [] && $usages === []) {
                    $skipped++;
                    $rows[] = [$userId, 'skip(引き継ぎ対象なし)', 0, 0];

                    continue;
                }

                if ($apply) {
                    $commandBus->dispatch(new MigrateSpecialLeaveAccount(
                        userId: $userId,
                        grants: $grants,
                        usages: $usages,
                    ));
                }

                $successes++;
                $rows[] = [$userId, $apply ? 'migrated' : 'dry-run', count($grants), count($usages)];
            } catch (Throwable $e) {
                $failures[] = ['user_id' => $userId, 'error' => $e->getMessage()];
                $rows[] = [$userId, 'NG', '-', '-'];
            }
        }

        $this->table(['user_id', 'result', 'grants', 'usages'], $rows);
        $this->info(($apply ? '' : '(dry-run) ')."対象 {$userIds->count()} 名 / 引き継ぎ {$successes} 名 / 対象外 {$skipped} 名 / 失敗 ".count($failures).' 名');

        if (count($failures) > 0) {
            $this->table(['user_id', 'error'], $failures);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * 付与の現在の状態(取消済みを含む)。口座に既にある付与(移行前に新しい流れで登録された付与)は除く。
     *
     * @return array<int, array{grantId: string, specialLeaveTypeId: int, grantedOn: string, expiresOn: ?string, grantedDays: float, revoked: bool}>
     */
    private function grantsOf(string $userId, SpecialLeaveAccountAggregate $aggregate): array
    {
        return SpecialLeaveGrant::query()
            ->where('user_id', $userId)
            ->orderBy('granted_on')
            ->get()
            ->reject(fn (SpecialLeaveGrant $grant) => $aggregate->hasGrant((string) $grant->id))
            ->map(fn (SpecialLeaveGrant $grant) => [
                'grantId' => (string) $grant->id,
                'specialLeaveTypeId' => (int) $grant->special_leave_type_id,
                'grantedOn' => $this->date($grant->granted_on),
                'expiresOn' => $this->date($grant->expires_on),
                'grantedDays' => (float) $grant->granted_days,
                'revoked' => $grant->status === SpecialLeaveGrantStatus::REVOKED,
            ])
            ->values()
            ->all();
    }

    /**
     * 申請中・承認済みの申請の消化記録(申請ごとに1件)。差し戻し・取消された申請は含めない。
     * 口座に既に消化記録がある申請(移行前に新しい流れで申請されたもの)は除く。
     *
     * @return array<int, array{usageId: string, requestId: string, specialLeaveTypeId: int, usedOn: string, usageType: string, usedDays: float, usedMinutes: ?int, status: string, allocations: array<int, array{grantId: string, allocatedDays: float}>}>
     */
    private function usagesOf(string $userId, SpecialLeaveAccountAggregate $aggregate): array
    {
        $usages = [];

        $requests = SpecialLeaveRequest::query()
            ->where('user_id', $userId)
            ->whereIn('status', [SpecialLeaveRequestStatus::SUBMITTED, SpecialLeaveRequestStatus::APPROVED])
            ->orderBy('target_date')
            ->get()
            ->reject(fn (SpecialLeaveRequest $request) => $aggregate->usageIdForRequest((string) $request->id) !== null);

        foreach ($requests as $request) {
            $isApproved = $request->status === SpecialLeaveRequestStatus::APPROVED;

            // 旧系統は申請1件に対して複数の付与へ行を分けて持つ(承認時に付与ごと)ため、申請単位にまとめる。
            $allocatedByGrant = [];

            if ($isApproved) {
                $rows = SpecialLeaveUsage::query()
                    ->where('special_leave_request_id', $request->id)
                    ->whereNotNull('special_leave_grant_id')
                    ->get();

                foreach ($rows as $row) {
                    $grantId = (string) $row->special_leave_grant_id;
                    $allocatedByGrant[$grantId] = ($allocatedByGrant[$grantId] ?? 0.0) + (float) $row->used_days;
                }
            }

            $allocations = [];
            foreach ($allocatedByGrant as $grantId => $days) {
                $allocations[] = ['grantId' => (string) $grantId, 'allocatedDays' => (float) $days];
            }

            $usages[] = [
                'usageId' => (string) Str::uuid(),
                'requestId' => (string) $request->id,
                'specialLeaveTypeId' => (int) $request->special_leave_type_id,
                'usedOn' => $this->date($request->target_date),
                'usageType' => (string) $request->leave_type,
                'usedDays' => (float) $request->requested_days,
                'usedMinutes' => $request->hours !== null ? (int) round((float) $request->hours * 60) : null,
                'status' => $isApproved ? 'confirmed' : 'designated',
                'allocations' => $allocations,
            ];
        }

        return $usages;
    }

    private function date(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->toDateString();
    }
}
