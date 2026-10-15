<?php

namespace App\Domain\EventSourcing\Rebuild\Reconcile;

use App\Domain\EventSourcing\Rebuild\UnsupportedEventSequence;

/**
 * company_calendars.is_default の照合(論点19 決定8-3)。マイグレーション(2026_08_10_020900)がイベントを経ずに設定していた。
 * キーは暦の id(集約UUID。company_calendar.* の集約ID)。
 *
 * イベントによる最終状態: CompanyCalendarProjector と同じ規則で、全ての company_calendar.created(is_default=false で作る)・
 * company_calendar.default_changed(previousDefaultCompanyCalendarId の暦を false、集約の暦を true にする)・
 * company_calendar.deleted(暦を除く)を id順に畳み込んだもの。
 * - 行の is_default=true の暦(既定の暦)が、畳み込みの結果でも唯一の true なら何もしない。
 * - 違うとき: 既定の暦の集約の最後のイベントの直後に、company_calendar.default_changed(previousDefaultCompanyCalendarId は
 *   畳み込みの結果で true だった別の暦、changedByUserId は null)を1件導出する。他の暦の default_changed が既定の暦の最後のイベントより
 *   後ろにあるときは、それより前に置くと再生で上書きされるため、その直後に置く。既定の暦に集約のイベントが無ければ列の先頭
 *   (発生日時は行の created_at)。
 * - company_calendars に行があるのに is_default=true の行が1件も無い・複数ある場合は、既定を決められない並びとして止める。
 *   行が無ければ何もしない。
 * - 畳み込みの結果で、既定の暦以外に true の暦が2件以上ある場合は、1件の default_changed では外せないため止める。
 */
final class CompanyCalendarDefaultReconciler
{
    public const TABLE = 'company_calendars';

    public function reconcile(ReconcileContext $ctx): void
    {
        $rows = array_map(fn ($row) => Norm::row($row), $ctx->current->companyCalendars);
        if ($rows === []) {
            return;
        }

        $ctx->guard('company_calendars is_default', fn () => $this->run($ctx, $rows));
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function run(ReconcileContext $ctx, array $rows): void
    {
        $defaults = array_values(array_filter($rows, fn (array $row) => Norm::bool($row['is_default'] ?? false)));
        if (count($defaults) !== 1) {
            throw new UnsupportedEventSequence('company_calendars is_default', 'is_default=true の暦が '.count($defaults).' 件ある(1件でなければ既定を決められない)');
        }
        $row = $defaults[0];
        $id = (string) $row['id'];

        /** @var array<string, bool> $state */
        $state = [];
        $lastDefaultChanged = null;
        foreach ($ctx->rows() as $event) {
            if ($event->eventClass === 'company_calendar.created' && $event->aggregateUuid !== null) {
                $state[$event->aggregateUuid] ??= false;
            } elseif ($event->eventClass === 'company_calendar.default_changed' && $event->aggregateUuid !== null) {
                $previous = Norm::str($event->get('previousDefaultCompanyCalendarId'));
                if ($previous !== null) {
                    $state[$previous] = false;
                }
                $state[$event->aggregateUuid] = true;
                $lastDefaultChanged = Slot::ofRow($event);
            } elseif ($event->eventClass === 'company_calendar.deleted' && $event->aggregateUuid !== null) {
                unset($state[$event->aggregateUuid]);
            }
        }

        $trueIds = array_keys(array_filter($state));
        $others = array_values(array_filter($trueIds, fn (string $other) => $other !== $id));
        if (($state[$id] ?? false) && $others === []) {
            return;
        }
        if (count($others) > 1) {
            throw new UnsupportedEventSequence('company_calendars is_default', 'イベントによる最終状態で既定の暦以外にも is_default=true の暦が '.count($others).' 件ある(1件の default_changed では外せない)');
        }

        if ($ctx->rowsOf($id) !== []) {
            $ctx->mismatch(self::TABLE, $id, 'is_default: イベント '.ReconcileContext::show($state[$id] ?? false).' → 行 '.ReconcileContext::show(true)
                .($others === [] ? '' : '(イベントでは既定の暦は他に '.implode(', ', $others).')'), ['company_calendar.default_changed']);
        }

        $slot = $ctx->latest($ctx->afterLast($id), $lastDefaultChanged) ?? $ctx->head(Norm::str($row['created_at'] ?? null));
        $ctx->emit(self::TABLE, $slot, 'company_calendar.default_changed', $id, [
            'previousDefaultCompanyCalendarId' => $others[0] ?? null,
            'changedByUserId' => null,
        ]);
    }
}
