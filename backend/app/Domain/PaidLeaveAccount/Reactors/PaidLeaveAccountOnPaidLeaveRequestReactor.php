<?php

namespace App\Domain\PaidLeaveAccount\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeave\Events\PaidLeaveRequestApproved;
use App\Domain\PaidLeave\Events\PaidLeaveRequestCancelled;
use App\Domain\PaidLeave\Events\PaidLeaveRequestReturned;
use App\Domain\PaidLeave\Events\PaidLeaveUsageDesignated as OldPaidLeaveUsageDesignated;
use App\Domain\PaidLeaveAccount\Commands\CancelPaidLeaveUsage;
use App\Domain\PaidLeaveAccount\Commands\ConfirmPaidLeaveUsage;
use App\Domain\PaidLeaveAccount\Commands\DesignatePaidLeaveUsage;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveType;
use App\Models\PaidLeaveAccountUsage;
use App\Models\WorkflowRequest;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * Phase 4(docs/changesets/20260906-paid-leave-domain-redesign/spec.md「Workflow連携」):
 * 既存の有給申請Workflow(`App\Domain\PaidLeave\Aggregates\PaidLeaveRequestAggregate`/
 * `PaidLeaveGrantAggregate`)は現状どおり動かしたまま、この集約が発行するイベントを
 * 横で受けて、新設`PaidLeaveAccountAggregate`(社員単位の年休台帳)へも並行して
 * `DesignatePaidLeaveUsage`/`ConfirmPaidLeaveUsage`/`CancelPaidLeaveUsage`を発行する
 * (strangler fig。旧ドメインの挙動・Projectionは一切変更しない、加算のみ)。
 *
 * 新ドメインの読み取り(`paid_leave_grants`/`paid_leave_requests`等)はまだ画面に
 * 露出しない(Controllerは引き続き旧Projectionを参照する)。
 *
 * ## 有給申請1件(=1日)と新ドメインUsage1件の対応関係
 * 旧`PaidLeaveRequestAggregate`は1日1申請(`paid_leave_requests`1行)の単位で動くため、
 * 申請時点でDesignateした新ドメインUsageも1申請=1Usageの対応になる。旧ドメインの
 * `PaidLeaveRequestApproved`/`Returned`/`Cancelled`イベントは`paid_leave_requests.id`を
 * aggregateRootUuidとして持つのみでUsageIdを直接運ばないため、
 * `(user_id, used_on)`の組(1日1件しか有効なUsageを持てない前提。旧ドメイン側も
 * 同日重複申請を拒否するため一意)で`paid_leave_usages.usage_id`を逆引きする
 * (`resolveUsageId()`)。
 *
 * ## 時間単位有給(hourly)の扱い
 * spec.mdの対象外節により時間単位有給は新ドメインの対象外。
 * `PaidLeaveUsageDesignated`(旧)の`usageType`が`hourly`の場合は新ドメインへの
 * Usage作成そのものをスキップする(以降のApprove/Return/Cancelは
 * `resolveUsageId()`がnullを返すため自然に何もしない)。full/am_half/pm_half以外の
 * 未知の値が来た場合は、日数を誤って丸めてUsageデータを壊すより先に例外で止める。
 */
class PaidLeaveAccountOnPaidLeaveRequestReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onPaidLeaveUsageDesignated(OldPaidLeaveUsageDesignated $event): void
    {
        if ($event->usageType === PaidLeaveType::HOURLY) {
            return;
        }

        if (! in_array($event->usageType, [PaidLeaveType::FULL, PaidLeaveType::AM_HALF, PaidLeaveType::PM_HALF], true)) {
            throw new DomainRuleException("未対応の有給取得区分のため新ドメインへのUsage作成を中止しました: {$event->usageType}");
        }

        $requestId = $event->aggregateRootUuid();

        $workflowRequest = WorkflowRequest::query()
            ->where('subject_type', WorkflowRequestNotificationContent::PAID_LEAVE_REQUEST)
            ->where('subject_id', $requestId)
            ->first();

        $this->commandBus->dispatch(new DesignatePaidLeaveUsage(
            userId: $event->userId,
            workflowRequestId: $workflowRequest?->id,
            attendanceDayId: $event->attendanceDayId,
            usedOn: $event->usedOn,
            usedDays: $event->usedDays,
        ));
    }

    public function onPaidLeaveRequestApproved(PaidLeaveRequestApproved $event): void
    {
        [$request, $usageId] = $this->resolveRequestAndUsageId($event->aggregateRootUuid());

        if ($request === null || $usageId === null) {
            return;
        }

        $this->commandBus->dispatch(new ConfirmPaidLeaveUsage(
            userId: $request->user_id,
            usageId: $usageId,
            confirmedByUserId: $event->approvedByUserId,
        ));
    }

    public function onPaidLeaveRequestReturned(PaidLeaveRequestReturned $event): void
    {
        $this->cancelUsageFor($event->aggregateRootUuid(), $event->returnedByUserId, '有給申請が差戻されました。');
    }

    public function onPaidLeaveRequestCancelled(PaidLeaveRequestCancelled $event): void
    {
        $this->cancelUsageFor($event->aggregateRootUuid(), $event->cancelledByUserId, '有給申請が取り消されました。');
    }

    private function cancelUsageFor(string $paidLeaveRequestId, ?string $byUserId, string $reason): void
    {
        [$request, $usageId] = $this->resolveRequestAndUsageId($paidLeaveRequestId);

        if ($request === null || $usageId === null) {
            return;
        }

        $this->commandBus->dispatch(new CancelPaidLeaveUsage(
            userId: $request->user_id,
            usageId: $usageId,
            cancelledByUserId: $byUserId,
            reason: $reason,
        ));
    }

    /**
     * `paid_leave_requests.id`から、対応する新ドメインUsage(未取消)のIDを逆引きする。
     * 新ドメイン側のUsageは旧`paid_leave_request_id`を保持しないため、1日1件しか有効な
     * Usageを持てない前提の`(user_id, used_on)`で照合する(クラスdoc参照)。
     * 時間単位有給などそもそもUsageを作成しなかった申請の場合はUsageId側がnullになる。
     *
     * @return array{0: ?PaidLeaveRequest, 1: ?string}
     */
    private function resolveRequestAndUsageId(string $paidLeaveRequestId): array
    {
        $request = PaidLeaveRequest::query()->find($paidLeaveRequestId);

        if ($request === null) {
            return [null, null];
        }

        $usageId = PaidLeaveAccountUsage::query()
            ->where('user_id', $request->user_id)
            ->whereDate('used_on', $request->target_date)
            ->whereNotNull('usage_id')
            ->where('cancelled', false)
            ->value('usage_id');

        return [$request, $usageId];
    }
}
