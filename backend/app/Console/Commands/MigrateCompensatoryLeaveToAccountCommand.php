<?php

namespace App\Console\Commands;

use App\Domain\CompensatoryLeaveAccount\Aggregates\CompensatoryLeaveAccountAggregate;
use App\Domain\CompensatoryLeaveAccount\Commands\MigrateCompensatoryLeaveAccount;
use App\Domain\EventSourcing\CommandBus;
use App\Models\CompensatoryLeaveGrant;
use App\Models\CompensatoryLeaveRequest;
use App\Models\CompensatoryLeaveRequestStatus;
use App\Models\CompensatoryLeaveUsage;
use App\Models\PaidLeaveType;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * 既存の代休データ(本変更前の付与・消化記録の現在の状態)を利用者単位の代休口座へ引き継ぐ運用コマンド
 * (仕様確定事項D・H・I。特別休暇のspecial-leave:migrate-to-accountと同じ方式)。
 *
 * - 対象は利用者ごと。口座が移行済み(compensatory_leave_account.migratedがある)利用者は対象外(冪等)。
 *   移行前に新しい流れで登録された付与・申請は口座に既にあるため移行対象から除き、旧付与・旧申請だけを引き継ぐ。
 * - 付与(取消済み・下書きを含む)と、申請中・承認済みの申請の消化記録の現在の状態を`compensatory_leave_account.migrated`
 *   として今の時点に追記する。差し戻し・取消された申請の消化記録は含めない。
 * - 既定は試し実行(件数の表示のみ)。`--apply`を付けたときだけ追記する。
 * - 1利用者の失敗で止めず、失敗は最後にまとめて表示する。
 *
 * 承認済みの消化記録の未充当量は0として引き継ぐ(特別休暇と同じ。リハーサルの件数で決める確認事項)。
 */
class MigrateCompensatoryLeaveToAccountCommand extends Command
{
    protected $signature = 'compensatory-leave:migrate-to-account {--apply : 実際に口座へ引き継ぐ(指定しない場合は試し実行)}';

    protected $description = '既存の代休の付与・消化記録を利用者単位の代休口座へ引き継ぐ(既定は試し実行、--applyで実行、冪等)';

    public function handle(CommandBus $commandBus): int
    {
        $apply = (bool) $this->option('apply');

        $userIds = CompensatoryLeaveGrant::query()->distinct()->pluck('user_id')
            ->merge(
                CompensatoryLeaveRequest::query()
                    ->whereIn('status', [CompensatoryLeaveRequestStatus::SUBMITTED, CompensatoryLeaveRequestStatus::APPROVED])
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
            $aggregate = CompensatoryLeaveAccountAggregate::retrieve(CompensatoryLeaveAccountAggregate::streamIdFor($userId))
                ->forUser($userId);

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
                    $commandBus->dispatch(new MigrateCompensatoryLeaveAccount(
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
     * 付与の現在の状態(取消済み・下書きを含む)。口座に既にある付与(移行前に新しい流れで登録された付与)は除く。
     *
     * @return array<int, array{grantId: string, source: string, sourceWorkDate: string, grantedDays: float, grantedMinutes: ?int, status: string, expiresOn: ?string}>
     */
    private function grantsOf(string $userId, CompensatoryLeaveAccountAggregate $aggregate): array
    {
        return CompensatoryLeaveGrant::query()
            ->where('user_id', $userId)
            ->orderBy('work_date')
            ->get()
            ->reject(fn (CompensatoryLeaveGrant $grant) => $aggregate->grantStatus((string) $grant->id) !== null)
            ->map(fn (CompensatoryLeaveGrant $grant) => [
                'grantId' => (string) $grant->id,
                'source' => $grant->source === 'manual' ? 'manual' : 'sync',
                'sourceWorkDate' => $this->date($grant->work_date),
                'grantedDays' => (float) $grant->granted_days,
                'grantedMinutes' => $grant->granted_minutes !== null ? (int) $grant->granted_minutes : null,
                'status' => (string) $grant->status,
                'expiresOn' => $this->date($grant->expires_on),
            ])
            ->values()
            ->all();
    }

    /**
     * 申請中・承認済みの申請の消化記録(申請ごとに1件)。差し戻し・取消された申請は含めない。
     * 口座に既に消化記録がある申請(移行前に新しい流れで申請されたもの)は除く。
     *
     * @return array<int, array{usageId: string, requestId: string, usedOn: string, usageType: string, usedDays: float, usedMinutes: ?int, status: string, allocations: array<int, array{grantId: string, allocatedDays: float, allocatedMinutes: int}>}>
     */
    private function usagesOf(string $userId, CompensatoryLeaveAccountAggregate $aggregate): array
    {
        $usages = [];

        $requests = CompensatoryLeaveRequest::query()
            ->where('user_id', $userId)
            ->whereIn('status', [CompensatoryLeaveRequestStatus::SUBMITTED, CompensatoryLeaveRequestStatus::APPROVED])
            ->orderBy('target_date')
            ->get()
            ->reject(fn (CompensatoryLeaveRequest $request) => $aggregate->usageIdForRequest((string) $request->id) !== null);

        foreach ($requests as $request) {
            $isApproved = $request->status === CompensatoryLeaveRequestStatus::APPROVED;
            $isHourly = $request->leave_type === PaidLeaveType::HOURLY;

            // 旧系統は承認時に付与ごとの行を持つため、申請単位・付与単位にまとめる(承認済みの申請だけ)。
            $allocatedByGrant = [];

            if ($isApproved) {
                $rows = CompensatoryLeaveUsage::query()
                    ->where('compensatory_leave_request_id', $request->id)
                    ->where('is_confirmed', true)
                    ->whereNotNull('compensatory_leave_grant_id')
                    ->get();

                foreach ($rows as $row) {
                    $grantId = (string) $row->compensatory_leave_grant_id;
                    $current = $allocatedByGrant[$grantId] ?? ['allocatedDays' => 0.0, 'allocatedMinutes' => 0];

                    $allocatedByGrant[$grantId] = [
                        'allocatedDays' => $current['allocatedDays'] + (float) $row->used_days,
                        'allocatedMinutes' => $current['allocatedMinutes'] + (int) $row->used_minutes,
                    ];
                }
            }

            $allocations = [];

            foreach ($allocatedByGrant as $grantId => $amount) {
                $allocations[] = [
                    'grantId' => (string) $grantId,
                    'allocatedDays' => (float) $amount['allocatedDays'],
                    'allocatedMinutes' => (int) $amount['allocatedMinutes'],
                ];
            }

            $usages[] = [
                'usageId' => (string) Str::uuid(),
                'requestId' => (string) $request->id,
                'usedOn' => $this->date($request->target_date),
                'usageType' => (string) $request->leave_type,
                'usedDays' => $isHourly ? 0.0 : (float) $request->requested_days,
                'usedMinutes' => $isHourly ? (int) $request->requested_minutes : null,
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
