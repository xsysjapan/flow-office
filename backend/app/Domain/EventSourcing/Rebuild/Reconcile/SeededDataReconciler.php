<?php

namespace App\Domain\EventSourcing\Rebuild\Reconcile;

use App\Domain\EventSourcing\Rebuild\RebuiltEvent;
use App\Domain\EventSourcing\Rebuild\StoredEventRow;

/**
 * 照合による補完(論点19 決定1)。シーダー・マイグレーションがイベントを経ずに作った行と、シーダーの再実行などで
 * イベントの最終状態とずれた行について、現在のReadModelの行との差を埋める導出イベントを作り直しの中に置く。
 * Projectorは実行せず、元の stored_events の該当イベントのpayloadを畳み込んで「イベントによる最終状態」を求める。
 *
 * 処理順は外部キーの依存順(依存先の導出イベントが依存元より前になる)。論点19の表の「順」から、users を
 * 外部キー(created_by・assigned_by・published_by_user_id)で参照されるテーブルより前にしている。
 *   group_types → field_authorities → users → groups → roles → company_calendar_years → permission_role・role_features →
 *   role_assignments → group_feature_assignments → memberships・external_identities → company_calendar_days →
 *   company_calendars.is_default(論点19 決定8-3。暦の既定。CompanyCalendarDefaultReconciler)
 * features・permissions・permission_scope_types は参照マスタ(Projectorの書き込み先ではない)のため読まない。
 */
final class SeededDataReconciler
{
    /**
     * @param  list<StoredEventRow>  $rows  stored_events の全行(作り直しの入力)
     */
    public function reconcile(array $rows, SeededDataCurrent $current): ReconcileResult
    {
        $remapped = (new CalendarDayAggregateRemapper)->remap($rows, $current);
        $ctx = new ReconcileContext($remapped['rows'], $current);
        foreach ($remapped['issues'] as $issue) {
            $ctx->issue($issue['key'], $issue['reason']);
        }
        $this->registerOriginalRefs($ctx);

        (new GroupTypeReconciler)->reconcile($ctx);
        (new FieldAuthorityReconciler)->reconcile($ctx);
        (new UserReconciler)->reconcile($ctx);
        (new GroupReconciler)->reconcile($ctx);
        (new RoleReconciler)->reconcile($ctx);
        (new CalendarYearReconciler)->reconcile($ctx);
        RoleSetReconciler::permissions()->reconcile($ctx);
        RoleSetReconciler::features()->reconcile($ctx);
        (new RoleAssignmentReconciler)->reconcile($ctx);
        (new GroupFeatureAssignmentReconciler)->reconcile($ctx);
        (new MembershipReconciler)->reconcile($ctx);
        (new ExternalIdentityReconciler)->reconcile($ctx);
        (new CalendarDayReconciler)->reconcile($ctx);
        (new CompanyCalendarDefaultReconciler)->reconcile($ctx);

        $events = array_map(fn (StoredEventRow $row) => RebuiltEvent::passThrough($row), $remapped['replaced']);
        array_push($events, ...$ctx->events);

        return new ReconcileResult(
            events: $events,
            consumedIds: array_map(fn (StoredEventRow $row) => $row->id, $remapped['replaced']),
            issues: $ctx->issues,
            derivedByTable: $ctx->derivedByTable,
            mismatches: $ctx->mismatches,
            remapped: $remapped['remapped'],
        );
    }

    /**
     * 既存の作成イベント(依存先)の位置を登録する。同じ対象に複数あれば最初のもの。
     */
    private function registerOriginalRefs(ReconcileContext $ctx): void
    {
        foreach ($ctx->rows() as $row) {
            $slot = Slot::ofRow($row);
            $class = $row->eventClass;
            if ($class === 'group_type.created' && $row->get('groupTypeId') !== null) {
                $ctx->registerRef('group_type:'.(int) $row->get('groupTypeId'), $slot);
            } elseif ($class === 'group.created' && $row->aggregateUuid !== null) {
                $ctx->registerRef('group:'.$row->aggregateUuid, $slot);
            } elseif ($class === 'role.created' && $row->get('roleId') !== null) {
                $ctx->registerRef('role:'.(int) $row->get('roleId'), $slot);
            } elseif ($class === 'company_calendar_year.created' && $row->aggregateUuid !== null) {
                $ctx->registerRef('calendar_year:'.$row->aggregateUuid, $slot);
            } elseif (in_array($class, UserReconciler::CREATION_CLASSES, true) && $row->aggregateUuid !== null) {
                $ctx->registerRef('user:'.$row->aggregateUuid, $slot);
            } elseif ($class === 'external_hr.import_applied') {
                foreach ((array) $row->get('rows', []) as $importRow) {
                    if (isset($importRow['user_id'])) {
                        $ctx->registerRef('user:'.(string) $importRow['user_id'], $slot);
                    }
                }
            }
        }
    }
}
