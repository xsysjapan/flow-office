<?php

namespace App\Domain\CompensatoryLeaveAccount\Aggregates;

use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountGrantCancelled;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountGrantConfirmed;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountGrantManuallyGranted;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountGrantRemoved;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountGrantSynced;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountMigrated;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountUsageCancelled;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountUsageConfirmed;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountUsageDesignated;
use App\Domain\CompensatoryLeaveAccount\Support\CompensatoryLeaveAllocationPlanner;
use App\Domain\CompensatoryLeaveAccount\Support\CompensatoryLeaveGrantConversion;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Support\UserManagementStreamId;
use Illuminate\Support\Carbon;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * 利用者単位の代休口座集約。付与(休日出勤からの同期・手動付与)・消化記録(申請ごと)・充当の残数を
 * 一括で持ち、不変条件を自身の内部状態(replayされたイベント)だけで保証する(Eloquent/Projectionへは
 * アクセスしない)。現行ルールの移植元は各メソッドのdocを参照する。
 *
 * 集約IDは利用者IDから決定的に派生させる(streamIdFor)。特別休暇の口座(`special_leave_account`)・
 * 有給の口座(集約ID=userId)と衝突しない。
 *
 * 付与の単位: grantedMinutes === null の付与は日単位(daily・half_day)、非nullは時間単位(hourly)。
 * 消化記録の単位: usageType === 'hourly' は時間単位(充当は時間単位の付与のみ)、それ以外は日単位。
 *
 * 状態(replay):
 * - grants: grantId => [source(sync|manual), sourceWorkDate, grantedDays, grantedMinutes,
 *           status(draft|confirmed|cancelled), expiresOn, allocations(usageId => [allocatedDays, allocatedMinutes])]
 *   ※ 下書きの削除(GrantRemoved)は連想配列から取り除く。
 * - usages: usageId => [requestId, usedOn, usageType, usedDays, usedMinutes, status(designated|confirmed|cancelled),
 *           allocations(grantId => [allocatedDays, allocatedMinutes]), unallocatedDays(float), unallocatedMinutes(int)]
 * - requestIndex: requestId => 最後に作成されたusageId(再申請で新しい消化記録に差し替わる)
 *
 * 不変条件:
 * 1. 付与の残数(付与量 - 充当合計)は0未満にならない(充当は残数の範囲内のみ)。
 * 2. 同じ申請の有効な消化記録は1件だけ(取消後の再申請は新しいusageIdで作成可能)。
 * 3. 確定は1回だけ・取消済みは確定不可、取消は1回だけ。取消で充当を全て解除する。
 * 4. 消化済み(充当合計>0)の付与は取り消せない。
 * 5. 確定済みの付与・取消済みの付与は充当に使わない(利用日時点で失効していない確定済みの付与だけを使う)。
 * 6. 残数が不足しても承認はブロックしない(ユーザー決定)。充当できた分だけ充当し、残りは
 *    未充当量(日数・分)として確定時に記録する。
 */
class CompensatoryLeaveAccountAggregate extends AggregateRoot
{
    private const GRANT_SOURCE_SYNC = 'sync';

    private const GRANT_SOURCE_MANUAL = 'manual';

    private const GRANT_DRAFT = 'draft';

    private const GRANT_CONFIRMED = 'confirmed';

    private const GRANT_CANCELLED = 'cancelled';

    private const USAGE_DESIGNATED = 'designated';

    private const USAGE_CONFIRMED = 'confirmed';

    private const USAGE_CANCELLED = 'cancelled';

    /** App\Models\PaidLeaveType::HOURLY と同じ値。ドメインモデルへの依存を避けて文字列で持つ。 */
    private const USAGE_TYPE_HOURLY = 'hourly';

    /** 現行の同期Handlerが下書きを削除するときの理由文言(SyncCompensatoryLeaveGrantHandler参照)。 */
    private const REMOVE_REASON_NO_HOLIDAY_WORK = '休日出勤の実績が取り消されたため';

    /** @var array<string, array{source: string, sourceWorkDate: string, grantedDays: float, grantedMinutes: ?int, status: string, expiresOn: ?string, allocations: array<string, array{allocatedDays: float, allocatedMinutes: int}>}> */
    private array $grants = [];

    /** @var array<string, array{requestId: string, usedOn: string, usageType: string, usedDays: float, usedMinutes: ?int, status: string, allocations: array<string, array{allocatedDays: float, allocatedMinutes: int}>, unallocatedDays: float, unallocatedMinutes: int}> */
    private array $usages = [];

    /** @var array<string, string> */
    private array $requestIndex = [];

    private bool $migrated = false;

    /** 集約が対象とする利用者(forUserで束縛する。イベントの利用者IDに使う)。 */
    private ?string $userId = null;

    /**
     * 利用者IDから代休口座の集約IDを決定的に派生させる。方式は既存の`UserManagementStreamId`(UUIDv5)に従う。
     */
    public static function streamIdFor(string $userId): string
    {
        return UserManagementStreamId::for('compensatory_leave_account', $userId);
    }

    /**
     * この集約が対象とする利用者を束縛する(集約IDは`streamIdFor`で決める)。全ての操作の前に呼ぶ。
     */
    public function forUser(string $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    private function requireUserId(): string
    {
        if ($this->userId === null) {
            throw new \LogicException('代休口座の操作の前に利用者を束縛してください(forUser)。');
        }

        return $this->userId;
    }

    // ---- 付与 ----

    /**
     * 休日出勤の計算結果から代休の下書き付与を同期する(作成・更新・削除)。
     * 移植元: `SyncCompensatoryLeaveGrantHandler::handle`。
     *
     * - `enabled=false`(代休機能が無効): 何もしない(現行どおり既存の下書きも削除しない)。
     * - 同じ休日出勤日の同期付与が確定済み・取消済みなら何もしない(現行は`status !== draft`で戻る)。
     * - 休日出勤でない(休日区分でない・実労働0分)なら、同期付与の下書きがあれば削除し、無ければ何もしない。
     * - 休日出勤なら、下書きがあれば更新、無ければ`$newGrantId`で作成する。
     *
     * @param  string  $newGrantId  新規作成時に使う付与ID(呼び出し側が生成する。既存の下書きがあれば使わない)
     * @param  bool  $isHolidayDay  休日区分(法定休日・所定休日)かどうか
     * @param  int  $workMinutes  実労働分(休日出勤の判定と換算に使う)
     * @param  ?string  $unit  代休の取得単位設定(daily|half_day|hourly)。null・未知の値は日単位扱い
     */
    public function syncGrantFromHolidayWork(
        string $newGrantId,
        string $sourceWorkDate,
        bool $enabled,
        bool $isHolidayDay,
        int $workMinutes,
        ?string $unit,
        ?int $halfDayThresholdMinutes,
    ): self {
        if (! $enabled) {
            return $this;
        }

        $existingGrantId = $this->syncGrantIdOn($sourceWorkDate);

        if ($existingGrantId !== null && $this->grants[$existingGrantId]['status'] !== self::GRANT_DRAFT) {
            return $this;
        }

        if (! $this->isHolidayWork($isHolidayDay, $workMinutes)) {
            if ($existingGrantId !== null) {
                $this->recordThat(new CompensatoryLeaveAccountGrantRemoved(
                    userId: $this->requireUserId(),
                    grantId: $existingGrantId,
                    reason: self::REMOVE_REASON_NO_HOLIDAY_WORK,
                ));
            }

            return $this;
        }

        if ($existingGrantId === null && isset($this->grants[$newGrantId])) {
            throw new DomainRuleException("Grant [{$newGrantId}] は既に存在します。");
        }

        [$grantedDays, $grantedMinutes] = (new CompensatoryLeaveGrantConversion)->resolve($unit, $halfDayThresholdMinutes, $workMinutes);

        $this->recordThat(new CompensatoryLeaveAccountGrantSynced(
            userId: $this->requireUserId(),
            grantId: $existingGrantId ?? $newGrantId,
            sourceWorkDate: $sourceWorkDate,
            grantedDays: $grantedDays,
            grantedMinutes: $grantedMinutes,
        ));

        return $this;
    }

    /**
     * 月次提出で、対象期間(休日出勤日が期間内)の下書き付与を全て確定する。
     * 移植元: `ConfirmCompensatoryLeaveGrantsForMonthHandler::handle`。
     *
     * 判定は文字列の前方一致ではなく日付範囲(両端を含む)で行う(仕様確定事項I)。
     * 失効日は確定日(`$confirmedAt`の日付部分)+ `$validDays`日。`$validDays`がnullなら無期限。
     *
     * @param  string  $periodStart  対象期間の初日(Y-m-d)
     * @param  string  $periodEnd  対象期間の末日(Y-m-d)
     * @param  string  $confirmedAt  確定日時(提出日時。現行の`submittedAt`と同じ値)
     */
    public function confirmGrantsForPeriod(string $periodStart, string $periodEnd, string $confirmedAt, ?int $validDays): self
    {
        $expiresOn = $validDays !== null
            ? Carbon::parse($confirmedAt)->addDays($validDays)->toDateString()
            : null;

        foreach ($this->grants as $grantId => $grant) {
            if ($grant['status'] !== self::GRANT_DRAFT) {
                continue;
            }

            if ($grant['sourceWorkDate'] < $periodStart || $grant['sourceWorkDate'] > $periodEnd) {
                continue;
            }

            $this->recordThat(new CompensatoryLeaveAccountGrantConfirmed(
                userId: $this->requireUserId(),
                grantId: (string) $grantId,
                confirmedAt: $confirmedAt,
                expiresOn: $expiresOn,
            ));
        }

        return $this;
    }

    /**
     * 管理者が休日出勤の対象日を指定して代休を手動付与する。作成と同時に確定する。
     * 移植元: `GrantCompensatoryLeaveHandler::handle`(休日出勤の判定・換算の部分)。
     *
     * 勤怠日の存在確認(日がない場合の例外)は呼び出し側が行う。ここでは休日出勤の実績の有無
     * (休日区分かつ実労働分>0)を判定する。
     *
     * @throws DomainRuleException 休日出勤の実績がない・付与IDが重複
     */
    public function grantManually(
        string $newGrantId,
        string $sourceWorkDate,
        bool $isHolidayDay,
        int $workMinutes,
        ?string $unit,
        ?int $halfDayThresholdMinutes,
        ?string $expiresOn,
        ?string $grantReason,
    ): self {
        if (isset($this->grants[$newGrantId])) {
            throw new DomainRuleException("Grant [{$newGrantId}] は既に存在します。");
        }

        if (! $this->isHolidayWork($isHolidayDay, $workMinutes)) {
            throw new DomainRuleException('指定日は休日出勤の実績がないため、代休を付与できません。');
        }

        [$grantedDays, $grantedMinutes] = (new CompensatoryLeaveGrantConversion)->resolve($unit, $halfDayThresholdMinutes, $workMinutes);

        $this->recordThat(new CompensatoryLeaveAccountGrantManuallyGranted(
            userId: $this->requireUserId(),
            grantId: $newGrantId,
            sourceWorkDate: $sourceWorkDate,
            grantedDays: $grantedDays,
            grantedMinutes: $grantedMinutes,
            expiresOn: $expiresOn,
            grantReason: $grantReason,
        ));

        return $this;
    }

    /**
     * 未使用の確定済み付与を取り消す。移植元: `CancelCompensatoryLeaveGrantHandler::handle`
     * (確定済みのみ、未使用のみ。承認不要設定時・承認後の取消の両方から呼ばれる前提で、ここでも再検証する)。
     *
     * @throws DomainRuleException 存在しない・確定済みでない・消化済み
     */
    public function cancelGrant(string $grantId, string $cancelledByUserId, ?string $reason): self
    {
        $grant = $this->grantOrFail($grantId);

        if ($grant['status'] !== self::GRANT_CONFIRMED) {
            throw new DomainRuleException('確定済みの代休のみ取消できます。');
        }

        if ($this->usedDaysOf($grantId) > 0 || $this->usedMinutesOf($grantId) > 0) {
            throw new DomainRuleException('既に使用された代休は取り消せません。');
        }

        $this->recordThat(new CompensatoryLeaveAccountGrantCancelled(
            userId: $this->requireUserId(),
            grantId: $grantId,
            cancelledByUserId: $cancelledByUserId,
            reason: $reason,
        ));

        return $this;
    }

    // ---- 消化記録 ----

    /**
     * 休暇申請に対応する消化記録を作成する(申請時。充当は行わない)。
     * 同じ申請に有効な消化記録(designated/confirmed)が既にあれば拒否する。
     * 時間単位(usageType=hourly)の消化記録は分数が必須(充当は分数で行うため)。
     *
     * @throws DomainRuleException 消化記録IDの重複・同じ申請の有効な消化記録が既にある・時間単位で分数がない
     */
    public function designateUsage(
        string $usageId,
        string $requestId,
        string $usedOn,
        string $usageType,
        float $usedDays,
        ?int $usedMinutes,
    ): self {
        if (isset($this->usages[$usageId])) {
            throw new DomainRuleException("Usage [{$usageId}] は既に存在します。");
        }

        if ($usageType === self::USAGE_TYPE_HOURLY && $usedMinutes === null) {
            throw new DomainRuleException('時間単位の代休消化には消化分数が必要です。');
        }

        $currentUsageId = $this->requestIndex[$requestId] ?? null;

        if ($currentUsageId !== null && $this->activeUsage($currentUsageId)) {
            throw new DomainRuleException("申請 [{$requestId}] の消化記録は既に存在します。");
        }

        $this->recordThat(new CompensatoryLeaveAccountUsageDesignated(
            userId: $this->requireUserId(),
            usageId: $usageId,
            requestId: $requestId,
            usedOn: $usedOn,
            usageType: $usageType,
            usedDays: $usedDays,
            usedMinutes: $usedMinutes,
        ));

        return $this;
    }

    /**
     * 消化記録を確定する(承認時)。移植元: `ApproveCompensatoryLeaveRequestHandler::planConsumption`。
     *
     * 利用日時点で失効していない確定済みの付与のうち、同じ単位(時間単位の消化は分単位の付与、
     * それ以外は日単位の付与)のものへ、失効日の近い順(無期限は最後)に充当する。
     * 残数が不足しても例外にせず、充当できた分だけを充当して確定する(ユーザー決定)。
     * 残りは未充当量として記録する(時間単位の消化は分、それ以外は日。`unallocatedFor`で参照)。
     * 付与が無い場合は充当0・未充当=全量。
     *
     * @throws DomainRuleException 存在しない・取消済み・確定済み
     */
    public function confirmUsage(string $usageId): self
    {
        $usage = $this->usageOrFail($usageId);

        if ($usage['status'] === self::USAGE_CANCELLED) {
            throw new DomainRuleException('取消済みの消化記録は確定できません。');
        }

        if ($usage['status'] === self::USAGE_CONFIRMED) {
            throw new DomainRuleException('既に確定済みの消化記録です。');
        }

        $hourly = $usage['usageType'] === self::USAGE_TYPE_HOURLY;
        $requiredAmount = $hourly ? (float) $usage['usedMinutes'] : $usage['usedDays'];

        $plan = (new CompensatoryLeaveAllocationPlanner)->plan(
            usedOn: $usage['usedOn'],
            requiredAmount: $requiredAmount,
            grants: $this->grantsSnapshot($hourly),
        );

        $allocatedAmount = (float) array_sum(array_column($plan, 'allocatedAmount'));

        $unallocatedAmount = max(0.0, $requiredAmount - $allocatedAmount);

        $allocations = array_map(
            fn (array $item) => $hourly
                ? ['grantId' => $item['grantId'], 'allocatedDays' => 0.0, 'allocatedMinutes' => (int) round($item['allocatedAmount'])]
                : ['grantId' => $item['grantId'], 'allocatedDays' => $item['allocatedAmount'], 'allocatedMinutes' => 0],
            $plan,
        );

        $this->recordThat(new CompensatoryLeaveAccountUsageConfirmed(
            userId: $this->requireUserId(),
            usageId: $usageId,
            allocations: $allocations,
            unallocatedDays: $hourly ? 0.0 : $unallocatedAmount,
            unallocatedMinutes: $hourly ? (int) round($unallocatedAmount) : 0,
        ));

        return $this;
    }

    /**
     * 消化記録を取り消す(差戻し・取消)。確定済みなら充当を全て解除して残数を戻す。
     * 移植元: `CancelCompensatoryLeaveRequestHandler`(承認済みの取消のみ消化を取り消す。
     * 未承認の取消は確定前の消化記録を取り消すのみ)。
     *
     * @throws DomainRuleException 存在しない・取消済み
     */
    public function cancelUsage(string $usageId, ?string $reason): self
    {
        $usage = $this->usageOrFail($usageId);

        if ($usage['status'] === self::USAGE_CANCELLED) {
            throw new DomainRuleException('既に取消済みの消化記録です。');
        }

        $releasedAllocations = [];

        foreach ($usage['allocations'] as $grantId => $amount) {
            $releasedAllocations[] = [
                'grantId' => (string) $grantId,
                'releasedDays' => $amount['allocatedDays'],
                'releasedMinutes' => $amount['allocatedMinutes'],
            ];
        }

        $this->recordThat(new CompensatoryLeaveAccountUsageCancelled(
            userId: $this->requireUserId(),
            usageId: $usageId,
            releasedAllocations: $releasedAllocations,
            reason: $reason,
        ));

        return $this;
    }

    // ---- 引き継ぎ ----

    /**
     * 既存データの引き継ぎ。口座が引き継ぎ済みでないときだけ1回実行できる。移行前に新しい流れで登録された付与・
     * 消化記録は呼び出し側(運用コマンド)が移行データから除外する(既存の付与ID・消化記録IDとの重複だけを拒否する)。
     *
     * 引き継ぎ対象の消化記録は申請中(designated)・確定(confirmed)のみ。差戻し・取消済みは含めない
     * (呼び出し側で除外する)。現行の不足のまま承認された履歴(充当合計<消化日数)はそのまま引き継ぐ
     * (過去の承認の事実を変えないため。残数の不足チェックは行わない)。
     *
     * @param  array<int, array{grantId: string, source: string, sourceWorkDate: string, grantedDays: float, grantedMinutes: ?int, status: string, expiresOn: ?string}>  $grants
     * @param  array<int, array{usageId: string, requestId: string, usedOn: string, usageType: string, usedDays: float, usedMinutes: ?int, status: string, allocations: array<int, array{grantId: string, allocatedDays: float, allocatedMinutes: int}>}>  $usages
     *
     * @throws DomainRuleException 口座が空でない・ID重複・存在しない/確定していない付与への充当・付与超過・
     *                             申請中の消化記録への充当・時間単位の分数がない
     */
    public function migrate(array $grants, array $usages): self
    {
        if ($this->migrated) {
            throw new DomainRuleException('この代休口座は既に引き継ぎ済みです。');
        }

        $grantsById = [];

        foreach ($grants as $grant) {
            if (isset($grantsById[$grant['grantId']])) {
                throw new DomainRuleException("移行データ内でGrant ID [{$grant['grantId']}] が重複しています。");
            }

            if (isset($this->grants[$grant['grantId']])) {
                throw new DomainRuleException("Grant [{$grant['grantId']}] は既に口座に存在します。");
            }

            if (! in_array($grant['source'], [self::GRANT_SOURCE_SYNC, self::GRANT_SOURCE_MANUAL], true)) {
                throw new DomainRuleException('移行できる付与の由来は sync・manual のみです。');
            }

            if (! in_array($grant['status'], [self::GRANT_DRAFT, self::GRANT_CONFIRMED, self::GRANT_CANCELLED], true)) {
                throw new DomainRuleException('移行できる付与の状態は draft・confirmed・cancelled のみです。');
            }

            $grantsById[$grant['grantId']] = $grant;
        }

        $usageIds = [];
        $requestIds = [];
        $allocatedDaysByGrant = [];
        $allocatedMinutesByGrant = [];

        foreach ($usages as $usage) {
            if (isset($this->usages[$usage['usageId']])) {
                throw new DomainRuleException("Usage [{$usage['usageId']}] は既に口座に存在します。");
            }

            if (in_array($usage['usageId'], $usageIds, true)) {
                throw new DomainRuleException("移行データ内でUsage ID [{$usage['usageId']}] が重複しています。");
            }

            if (in_array($usage['requestId'], $requestIds, true)) {
                throw new DomainRuleException("移行データ内で申請 [{$usage['requestId']}] の消化記録が重複しています。");
            }

            if (! in_array($usage['status'], [self::USAGE_DESIGNATED, self::USAGE_CONFIRMED], true)) {
                throw new DomainRuleException('移行できる消化記録の状態は申請中(designated)・確定(confirmed)のみです。');
            }

            if ($usage['status'] === self::USAGE_DESIGNATED && $usage['allocations'] !== []) {
                throw new DomainRuleException('申請中の消化記録には充当を含められません。');
            }

            if ($usage['usageType'] === self::USAGE_TYPE_HOURLY && $usage['usedMinutes'] === null) {
                throw new DomainRuleException('時間単位の代休消化には消化分数が必要です。');
            }

            foreach ($usage['allocations'] as $allocation) {
                $grant = $grantsById[$allocation['grantId']] ?? null;

                if ($grant === null || $grant['status'] !== self::GRANT_CONFIRMED) {
                    throw new DomainRuleException("移行データの充当先 Grant [{$allocation['grantId']}] が存在しないか確定していません。");
                }

                if ($allocation['allocatedMinutes'] > 0 && $grant['grantedMinutes'] === null) {
                    throw new DomainRuleException("Grant [{$allocation['grantId']}] は日単位のため、分単位の充当はできません。");
                }

                $allocatedDaysByGrant[$allocation['grantId']] = ($allocatedDaysByGrant[$allocation['grantId']] ?? 0.0) + $allocation['allocatedDays'];
                $allocatedMinutesByGrant[$allocation['grantId']] = ($allocatedMinutesByGrant[$allocation['grantId']] ?? 0) + $allocation['allocatedMinutes'];
            }

            $usageIds[] = $usage['usageId'];
            $requestIds[] = $usage['requestId'];
        }

        foreach ($allocatedDaysByGrant as $grantId => $days) {
            if ($days > $grantsById[$grantId]['grantedDays']) {
                throw new DomainRuleException("Grant [{$grantId}] の充当合計(日)が付与日数を超えています。");
            }
        }

        foreach ($allocatedMinutesByGrant as $grantId => $minutes) {
            if ($minutes > (int) $grantsById[$grantId]['grantedMinutes']) {
                throw new DomainRuleException("Grant [{$grantId}] の充当合計(分)が付与分数を超えています。");
            }
        }

        $this->recordThat(new CompensatoryLeaveAccountMigrated(
            userId: $this->requireUserId(),
            grants: $grants,
            usages: $usages,
        ));

        return $this;
    }

    // ---- 問い合わせ ----

    /**
     * 申請に対応する最後の消化記録のusageIdを返す(取消済みも含む)。無ければnull。
     */
    public function usageIdForRequest(string $requestId): ?string
    {
        return $this->requestIndex[$requestId] ?? null;
    }

    /** 既存データの引き継ぎ(migrate)が済んでいるか。 */
    public function isMigrated(): bool
    {
        return $this->migrated;
    }

    public function hasUsage(string $usageId): bool
    {
        return isset($this->usages[$usageId]);
    }

    /**
     * 消化記録の状態(designated|confirmed|cancelled)。存在しなければnull。
     */
    public function usageStatus(string $usageId): ?string
    {
        return $this->usages[$usageId]['status'] ?? null;
    }

    /**
     * 確定時に充当できなかった未充当量。日単位の消化は`unallocatedDays`、時間単位の消化は
     * `unallocatedMinutes`に値を持つ(もう一方は0)。確定していない・取消済み・存在しない消化記録は両方0。
     *
     * @return array{unallocatedDays: float, unallocatedMinutes: int}
     */
    public function unallocatedFor(string $usageId): array
    {
        return [
            'unallocatedDays' => $this->usages[$usageId]['unallocatedDays'] ?? 0.0,
            'unallocatedMinutes' => $this->usages[$usageId]['unallocatedMinutes'] ?? 0,
        ];
    }

    /**
     * 付与の状態(draft|confirmed|cancelled)。存在しなければnull(下書きの削除後も存在しない扱い)。
     */
    public function grantStatus(string $grantId): ?string
    {
        return $this->grants[$grantId]['status'] ?? null;
    }

    /**
     * 付与が未使用か(充当の合計が0。日単位・時間単位とも)。付与が無ければfalse。
     */
    public function isGrantUnused(string $grantId): bool
    {
        if (! isset($this->grants[$grantId])) {
            return false;
        }

        return $this->usedDaysOf($grantId) <= 0 && $this->usedMinutesOf($grantId) <= 0;
    }

    /**
     * 指定日時点で利用可能な日単位の付与(確定済み・取消済みでない・失効していない)の残数の合計。
     * 失効日当日は利用可能。充当計画と同じ判定を使う。
     */
    public function remainingDays(string $today): float
    {
        $total = 0.0;

        foreach ($this->grants as $grantId => $grant) {
            if (! $this->isUsableOn($grant, $today) || $grant['grantedMinutes'] !== null) {
                continue;
            }

            $total += $grant['grantedDays'] - $this->usedDaysOf((string) $grantId);
        }

        return $total;
    }

    /**
     * 指定日時点で利用可能な時間単位の付与の残数(分)の合計。判定は`remainingDays`と同じ。
     */
    public function remainingMinutes(string $today): int
    {
        $total = 0;

        foreach ($this->grants as $grantId => $grant) {
            if (! $this->isUsableOn($grant, $today) || $grant['grantedMinutes'] === null) {
                continue;
            }

            $total += $grant['grantedMinutes'] - $this->usedMinutesOf((string) $grantId);
        }

        return $total;
    }

    // ---- replay(状態の反映) ----

    protected function applyCompensatoryLeaveAccountGrantSynced(CompensatoryLeaveAccountGrantSynced $event): void
    {
        $this->userId = $event->userId;

        $this->grants[$event->grantId] = [
            'source' => self::GRANT_SOURCE_SYNC,
            'sourceWorkDate' => $event->sourceWorkDate,
            'grantedDays' => $event->grantedDays,
            'grantedMinutes' => $event->grantedMinutes,
            'status' => self::GRANT_DRAFT,
            'expiresOn' => null,
            'allocations' => [],
        ];
    }

    protected function applyCompensatoryLeaveAccountGrantRemoved(CompensatoryLeaveAccountGrantRemoved $event): void
    {
        $this->userId = $event->userId;

        unset($this->grants[$event->grantId]);
    }

    protected function applyCompensatoryLeaveAccountGrantConfirmed(CompensatoryLeaveAccountGrantConfirmed $event): void
    {
        $this->userId = $event->userId;

        $this->grants[$event->grantId]['status'] = self::GRANT_CONFIRMED;
        $this->grants[$event->grantId]['expiresOn'] = $event->expiresOn;
    }

    protected function applyCompensatoryLeaveAccountGrantManuallyGranted(CompensatoryLeaveAccountGrantManuallyGranted $event): void
    {
        $this->userId = $event->userId;

        $this->grants[$event->grantId] = [
            'source' => self::GRANT_SOURCE_MANUAL,
            'sourceWorkDate' => $event->sourceWorkDate,
            'grantedDays' => $event->grantedDays,
            'grantedMinutes' => $event->grantedMinutes,
            'status' => self::GRANT_CONFIRMED,
            'expiresOn' => $event->expiresOn,
            'allocations' => [],
        ];
    }

    protected function applyCompensatoryLeaveAccountGrantCancelled(CompensatoryLeaveAccountGrantCancelled $event): void
    {
        $this->userId = $event->userId;

        $this->grants[$event->grantId]['status'] = self::GRANT_CANCELLED;
    }

    protected function applyCompensatoryLeaveAccountUsageDesignated(CompensatoryLeaveAccountUsageDesignated $event): void
    {
        $this->userId = $event->userId;

        $this->usages[$event->usageId] = [
            'requestId' => $event->requestId,
            'usedOn' => $event->usedOn,
            'usageType' => $event->usageType,
            'usedDays' => $event->usedDays,
            'usedMinutes' => $event->usedMinutes,
            'status' => self::USAGE_DESIGNATED,
            'allocations' => [],
            'unallocatedDays' => 0.0,
            'unallocatedMinutes' => 0,
        ];

        $this->requestIndex[$event->requestId] = $event->usageId;
    }

    protected function applyCompensatoryLeaveAccountUsageConfirmed(CompensatoryLeaveAccountUsageConfirmed $event): void
    {
        $this->userId = $event->userId;

        $this->usages[$event->usageId]['status'] = self::USAGE_CONFIRMED;
        $this->usages[$event->usageId]['unallocatedDays'] = $event->unallocatedDays;
        $this->usages[$event->usageId]['unallocatedMinutes'] = $event->unallocatedMinutes;

        foreach ($event->allocations as $allocation) {
            $this->addAllocation($event->usageId, $allocation['grantId'], $allocation['allocatedDays'], $allocation['allocatedMinutes']);
        }
    }

    protected function applyCompensatoryLeaveAccountUsageCancelled(CompensatoryLeaveAccountUsageCancelled $event): void
    {
        $this->userId = $event->userId;

        $this->usages[$event->usageId]['status'] = self::USAGE_CANCELLED;
        $this->usages[$event->usageId]['unallocatedDays'] = 0.0;
        $this->usages[$event->usageId]['unallocatedMinutes'] = 0;

        foreach ($event->releasedAllocations as $released) {
            unset($this->usages[$event->usageId]['allocations'][$released['grantId']]);
            unset($this->grants[$released['grantId']]['allocations'][$event->usageId]);
        }
    }

    protected function applyCompensatoryLeaveAccountMigrated(CompensatoryLeaveAccountMigrated $event): void
    {
        $this->userId = $event->userId;

        $this->migrated = true;

        foreach ($event->grants as $grant) {
            $this->grants[$grant['grantId']] = [
                'source' => $grant['source'],
                'sourceWorkDate' => $grant['sourceWorkDate'],
                'grantedDays' => $grant['grantedDays'],
                'grantedMinutes' => $grant['grantedMinutes'],
                'status' => $grant['status'],
                'expiresOn' => $grant['expiresOn'],
                'allocations' => [],
            ];
        }

        foreach ($event->usages as $usage) {
            $this->usages[$usage['usageId']] = [
                'requestId' => $usage['requestId'],
                'usedOn' => $usage['usedOn'],
                'usageType' => $usage['usageType'],
                'usedDays' => $usage['usedDays'],
                'usedMinutes' => $usage['usedMinutes'],
                'status' => $usage['status'],
                'allocations' => [],
                'unallocatedDays' => 0.0,
                'unallocatedMinutes' => 0,
            ];

            $this->requestIndex[$usage['requestId']] = $usage['usageId'];

            foreach ($usage['allocations'] as $allocation) {
                $this->addAllocation($usage['usageId'], $allocation['grantId'], $allocation['allocatedDays'], $allocation['allocatedMinutes']);
            }
        }
    }

    // ---- 内部 ----

    /**
     * @return array{source: string, sourceWorkDate: string, grantedDays: float, grantedMinutes: ?int, status: string, expiresOn: ?string, allocations: array<string, array{allocatedDays: float, allocatedMinutes: int}>}
     */
    private function grantOrFail(string $grantId): array
    {
        $grant = $this->grants[$grantId] ?? null;

        if ($grant === null) {
            throw new DomainRuleException("Grant [{$grantId}] は存在しません。");
        }

        return $grant;
    }

    private function usageOrFail(string $usageId): array
    {
        $usage = $this->usages[$usageId] ?? null;

        if ($usage === null) {
            throw new DomainRuleException("Usage [{$usageId}] は存在しません。");
        }

        return $usage;
    }

    private function activeUsage(string $usageId): bool
    {
        return in_array($this->usages[$usageId]['status'] ?? null, [self::USAGE_DESIGNATED, self::USAGE_CONFIRMED], true);
    }

    private function isHolidayWork(bool $isHolidayDay, int $workMinutes): bool
    {
        return $isHolidayDay && $workMinutes > 0;
    }

    /**
     * 同じ休日出勤日の同期付与(由来=sync・削除されていないもの)のIDを返す。無ければnull。
     */
    private function syncGrantIdOn(string $sourceWorkDate): ?string
    {
        foreach ($this->grants as $grantId => $grant) {
            if ($grant['source'] === self::GRANT_SOURCE_SYNC && $grant['sourceWorkDate'] === $sourceWorkDate) {
                return (string) $grantId;
            }
        }

        return null;
    }

    /**
     * 利用可能な付与か(確定済み・失効していない)。失効日当日は利用可能。
     *
     * @param  array{status: string, expiresOn: ?string}  $grant
     */
    private function isUsableOn(array $grant, string $date): bool
    {
        return $grant['status'] === self::GRANT_CONFIRMED
            && ($grant['expiresOn'] === null || $grant['expiresOn'] >= $date);
    }

    private function addAllocation(string $usageId, string $grantId, float $days, int $minutes): void
    {
        $current = $this->usages[$usageId]['allocations'][$grantId] ?? ['allocatedDays' => 0.0, 'allocatedMinutes' => 0];

        $allocation = [
            'allocatedDays' => $current['allocatedDays'] + $days,
            'allocatedMinutes' => $current['allocatedMinutes'] + $minutes,
        ];

        $this->usages[$usageId]['allocations'][$grantId] = $allocation;
        $this->grants[$grantId]['allocations'][$usageId] = $allocation;
    }

    private function usedDaysOf(string $grantId): float
    {
        return (float) array_sum(array_column($this->grants[$grantId]['allocations'], 'allocatedDays'));
    }

    private function usedMinutesOf(string $grantId): int
    {
        return (int) array_sum(array_column($this->grants[$grantId]['allocations'], 'allocatedMinutes'));
    }

    /**
     * 充当計画へ渡す、確定済みの付与の一覧(登録順)。取消済み・下書きは含めない。
     * 時間単位の消化は分単位の付与のみ、それ以外は日単位の付与のみを対象にする。
     *
     * @return array<int, array{grantId: string, expiresOn: ?string, available: float}>
     */
    private function grantsSnapshot(bool $hourly): array
    {
        $snapshot = [];

        foreach ($this->grants as $grantId => $grant) {
            if ($grant['status'] !== self::GRANT_CONFIRMED || ($grant['grantedMinutes'] !== null) !== $hourly) {
                continue;
            }

            $available = $hourly
                ? (float) ($grant['grantedMinutes'] - $this->usedMinutesOf((string) $grantId))
                : $grant['grantedDays'] - $this->usedDaysOf((string) $grantId);

            $snapshot[] = [
                'grantId' => (string) $grantId,
                'expiresOn' => $grant['expiresOn'],
                'available' => $available,
            ];
        }

        return $snapshot;
    }
}
