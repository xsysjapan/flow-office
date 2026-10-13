<?php

namespace App\Domain\EventSourcing\Rebuild\Reconcile;

use App\Domain\EventSourcing\Rebuild\UnsupportedEventSequence;

/**
 * company_calendar_years の照合(論点19 決定1)。集約UUIDは行の id。
 *
 * イベントによる最終状態: company_calendar_year.created(draft)に、fiscal_year_corrected(年度・期間)、published(published)、
 * unpublished(draft)、archived(archived)、deleted を順に畳み込んだもの。
 * - created が無い: 行の値から company_calendar_year.created を導出する(操作者 createdByUserId は null)。集約にイベントが無ければ、
 *   公開者の作成イベントの直後(無ければ列の先頭)、発生日時は行の created_at。イベントがあれば最初のイベントの直前。
 * - 行の status が published/archived で最終状態が違う: 最後のイベントの直後(イベントが無ければ作成の直後)に
 *   company_calendar_year.published(発生日時は行の published_at、操作者は published_by_user_id)・.archived を導出する。
 *   archived で行に published_at があるのに公開のイベントが無ければ、先に published を導出する。
 * - 行の status が draft で最終状態が違う: unpublished を導出する。操作者は published_by_user_id(published・archived)、無ければ null。
 * - 扱えない並び: イベントでは削除済み、created があるのに年度・期間・暦が行と違う。
 */
final class CalendarYearReconciler
{
    public const TABLE = 'company_calendar_years';

    public function reconcile(ReconcileContext $ctx): void
    {
        foreach ($ctx->current->calendarYears as $row) {
            $ctx->guard('company_calendar_year '.$row['id'], fn () => $this->one($ctx, Norm::row($row)));
        }
    }

    /** @param  array<string, mixed>  $row */
    private function one(ReconcileContext $ctx, array $row): void
    {
        $id = (string) $row['id'];
        $key = "company_calendar_year {$id}";
        $events = $ctx->rowsOf($id);
        $calendarId = (string) $row['company_calendar_id'];
        $fiscalYear = (int) $row['fiscal_year'];
        $startsOn = (string) Norm::date($row['starts_on']);
        $endsOn = (string) Norm::date($row['ends_on']);
        $status = (string) $row['status'];
        $publishedBy = Norm::str($row['published_by_user_id'] ?? null);
        $publishedAt = Norm::str($row['published_at'] ?? null);

        $hasCreated = false;
        $published = false;
        $deleted = false;
        $state = ['calendar' => $calendarId, 'fiscal_year' => $fiscalYear, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'status' => 'draft'];
        foreach ($events as $event) {
            $payload = $event->payload;
            switch ($event->eventClass) {
                case 'company_calendar_year.created':
                    $hasCreated = true;
                    $state = [
                        'calendar' => (string) $payload['companyCalendarId'], 'fiscal_year' => (int) $payload['fiscalYear'],
                        'starts_on' => (string) Norm::date($payload['startsOn']), 'ends_on' => (string) Norm::date($payload['endsOn']), 'status' => 'draft',
                    ];
                    break;
                case 'company_calendar_year.fiscal_year_corrected':
                    $state['fiscal_year'] = (int) $payload['fiscalYear'];
                    $state['starts_on'] = (string) Norm::date($payload['startsOn']);
                    $state['ends_on'] = (string) Norm::date($payload['endsOn']);
                    break;
                case 'company_calendar_year.published':
                    $state['status'] = 'published';
                    $published = true;
                    break;
                case 'company_calendar_year.unpublished':
                    $state['status'] = 'draft';
                    break;
                case 'company_calendar_year.archived':
                    $state['status'] = 'archived';
                    break;
                case 'company_calendar_year.deleted':
                    $deleted = true;
                    break;
            }
        }
        if ($deleted) {
            $ctx->mismatch(self::TABLE, $key, 'イベントでは削除済みだが行がある');
            throw new UnsupportedEventSequence($key, 'イベントでは削除済みだが行がある(削除を取り消すイベントは導出できない)');
        }

        $cursor = null;
        if (! $hasCreated) {
            $createdAt = Norm::str($row['created_at'] ?? null);
            $dep = $publishedBy !== null ? $ctx->ref('user:'.$publishedBy) : null;
            $slot = $ctx->beforeFirst($id, $createdAt);
            $slot = $slot !== null
                ? $ctx->notBefore($slot, $dep)
                : ($dep !== null ? $ctx->afterRef($dep, $createdAt) : $ctx->head($createdAt));
            $cursor = $ctx->emit(self::TABLE, $slot, 'company_calendar_year.created', $id, [
                'companyCalendarId' => $calendarId,
                'fiscalYear' => $fiscalYear,
                'startsOn' => $startsOn,
                'endsOn' => $endsOn,
                'generatedFrom' => (string) $row['generated_from'],
                'createdByUserId' => null,
            ], $createdAt);
            $ctx->registerRef('calendar_year:'.$id, $cursor);
        } else {
            $want = ['calendar' => $calendarId, 'fiscal_year' => $fiscalYear, 'starts_on' => $startsOn, 'ends_on' => $endsOn];
            foreach ($want as $column => $value) {
                if ($state[$column] !== $value) {
                    $ctx->mismatch(self::TABLE, $key, "{$column} がイベントと違う");
                    throw new UnsupportedEventSequence($key, "{$column} がイベントの最終状態と違い、差を埋めるイベント(fiscal_year_corrected は操作者が必須)を決められない");
                }
            }
        }

        if ($events !== [] && ! $hasCreated) {
            $ctx->mismatch(self::TABLE, $key, 'company_calendar_year.created が無い');
        }
        if ($state['status'] === $status) {
            return;
        }
        if ($events !== []) {
            $ctx->mismatch(self::TABLE, $key, "status がイベントの最終状態({$state['status']})と違い、行は {$status}");
        }
        // 最後のイベントの直後(イベントが無ければ作成の直後)に続けて置く。
        $cursor = $ctx->afterLast($id) ?? $cursor;
        if ($cursor === null) {
            throw new UnsupportedEventSequence($key, 'イベントの置き場所を決められない');
        }
        if ($status === 'draft') {
            $ctx->emit(self::TABLE, $cursor, 'company_calendar_year.unpublished', $id, ['unpublishedByUserId' => null]);

            return;
        }
        if ($status === 'published' || ($status === 'archived' && $publishedAt !== null && ! $published)) {
            $cursor = $ctx->emit(self::TABLE, $cursor, 'company_calendar_year.published', $id, ['publishedByUserId' => $publishedBy], $publishedAt);
        }
        if ($status === 'archived') {
            $ctx->emit(self::TABLE, $cursor, 'company_calendar_year.archived', $id, ['archivedByUserId' => $publishedBy]);
        }
    }
}
