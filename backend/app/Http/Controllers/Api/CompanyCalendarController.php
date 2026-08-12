<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance\Commands\ArchiveCompanyCalendarYear;
use App\Domain\Attendance\Commands\CreateCompanyCalendar;
use App\Domain\Attendance\Commands\CreateCompanyCalendarYear;
use App\Domain\Attendance\Commands\DeleteCompanyCalendar;
use App\Domain\Attendance\Commands\DuplicateCompanyCalendarYear;
use App\Domain\Attendance\Commands\PublishCompanyCalendarYear;
use App\Domain\Attendance\Commands\SetDefaultCompanyCalendar;
use App\Domain\Attendance\Commands\SyncHolidayCalendarSource;
use App\Domain\Attendance\Commands\UnpublishCompanyCalendarYear;
use App\Domain\Attendance\Commands\UpdateCompanyCalendar;
use App\Domain\Attendance\Commands\UpdateCompanyCalendarDays;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyCalendarDayResource;
use App\Http\Resources\CompanyCalendarResource;
use App\Http\Resources\CompanyCalendarYearResource;
use App\Http\Resources\HolidayCalendarSourceResource;
use App\Models\CompanyCalendar;
use App\Models\CompanyCalendarYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * UC-C009: 会社カレンダー本体とカレンダー年度を分離して管理する。
 */
#[OA\Tag(name: '勤務カレンダー', description: '会社カレンダーと休日設定')]
class CompanyCalendarController extends Controller
{
    /**
     * pageクエリパラメータを省略した場合は既存挙動のまま全件配列を返す(フロントエンドの
     * 他画面が全件取得に依存しているため)。pageを指定した場合のみページネーションする。
     */
    #[OA\Get(
        path: '/company-calendars',
        operationId: 'companyCalendars.index',
        summary: '会社カレンダー本体一覧を取得する',
        tags: ['勤務カレンダー'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, description: '省略時は全件を配列で返す。指定時はページネーションされたオブジェクト({data, links, meta})を返す。', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: '1ページあたりの件数(1〜100、既定20)。pageを指定した場合のみ有効。', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        if (! $request->has('page')) {
            return CompanyCalendarResource::collection(CompanyCalendar::query()->orderBy('name')->get());
        }

        $perPage = $data['per_page'] ?? 20;

        return CompanyCalendarResource::collection(
            CompanyCalendar::query()->orderBy('name')->paginate($perPage, page: $data['page'])
        );
    }

    /**
     * UC-C009 手順1〜2: 会社カレンダー本体を作成し、続けて最初のカレンダー年度を作成する
     * (「本体作成=年度作成」だった旧UC-C001の挙動を2段階のCommand発行に分離する)。
     */
    #[OA\Post(
        path: '/company-calendars',
        operationId: 'companyCalendars.store',
        summary: '会社カレンダー本体と最初のカレンダー年度を作成する',
        tags: ['勤務カレンダー'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name'], properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'week_starts_on', type: 'integer'), new OA\Property(property: 'fiscal_year_start_month', type: 'integer'), new OA\Property(property: 'fiscal_year_start_day', type: 'integer'), new OA\Property(property: 'fiscal_year', type: 'integer', description: '省略時は本体のみ作成する(最初の年度はPOST /company-calendars/{id}/yearsまたは定期バッチ/UC-C011で後から生成できる)'), new OA\Property(property: 'starts_on', type: 'string', format: 'date'), new OA\Property(property: 'ends_on', type: 'string', format: 'date')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'week_starts_on' => ['integer', 'between:1,7'],
            'fiscal_year_start_month' => ['integer', 'between:1,12'],
            'fiscal_year_start_day' => ['integer', 'between:1,31'],
            // 最初の年度の指定は任意(UC-C009手順1〜2は本体作成→年度作成の2段階の操作であり、
            // 年度は省略して本体のみ作成できる。省略時は定期バッチ/UC-C011「今すぐ生成する」が
            // fiscal_year_start_month/dayから標準の年度を生成する)。
            'fiscal_year' => ['nullable', 'integer'],
            'starts_on' => ['nullable', 'date', 'required_with:fiscal_year,ends_on'],
            'ends_on' => ['nullable', 'date', 'after:starts_on', 'required_with:fiscal_year,starts_on'],
        ]);

        $calendar = $commandBus->dispatch(new CreateCompanyCalendar(
            name: $data['name'],
            weekStartsOn: $data['week_starts_on'] ?? 1,
            fiscalYearStartMonth: $data['fiscal_year_start_month'] ?? 4,
            fiscalYearStartDay: $data['fiscal_year_start_day'] ?? 1,
            createdByUserId: $request->user()->id,
        ));

        if (array_key_exists('fiscal_year', $data) && $data['fiscal_year'] !== null) {
            $commandBus->dispatch(new CreateCompanyCalendarYear(
                companyCalendarId: $calendar->id,
                fiscalYear: $data['fiscal_year'],
                startsOn: $data['starts_on'],
                endsOn: $data['ends_on'],
                generatedFrom: 'manual',
                createdByUserId: $request->user()->id,
            ));
        }

        return (new CompanyCalendarResource($calendar->refresh()))->response()->setStatusCode(201);
    }

    /**
     * 会社カレンダー本体の名称・週起算曜日・年度開始月日・祝日iCalendarソースを編集する。
     * 作成時は名称だけを入力し、これらの設定は後から本APIで入力・変更する運用を想定する。
     */
    #[OA\Put(
        path: '/company-calendars/{companyCalendar}',
        operationId: 'companyCalendars.update',
        summary: '会社カレンダー本体を編集する',
        tags: ['勤務カレンダー'],
        parameters: [new OA\Parameter(name: 'companyCalendar', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name'], properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'week_starts_on', type: 'integer'), new OA\Property(property: 'fiscal_year_start_month', type: 'integer'), new OA\Property(property: 'fiscal_year_start_day', type: 'integer'), new OA\Property(property: 'holiday_calendar_source_id', type: 'string', format: 'uuid', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function update(Request $request, CompanyCalendar $companyCalendar, CommandBus $commandBus): CompanyCalendarResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'week_starts_on' => ['integer', 'between:1,7'],
            'fiscal_year_start_month' => ['integer', 'between:1,12'],
            'fiscal_year_start_day' => ['integer', 'between:1,31'],
            'holiday_calendar_source_id' => ['nullable', 'uuid', 'exists:holiday_calendar_sources,id'],
        ]);

        $calendar = $commandBus->dispatch(new UpdateCompanyCalendar(
            companyCalendarId: $companyCalendar->id,
            name: $data['name'],
            weekStartsOn: $data['week_starts_on'] ?? $companyCalendar->week_starts_on,
            fiscalYearStartMonth: $data['fiscal_year_start_month'] ?? $companyCalendar->fiscal_year_start_month,
            fiscalYearStartDay: $data['fiscal_year_start_day'] ?? $companyCalendar->fiscal_year_start_day,
            holidayCalendarSourceId: $data['holiday_calendar_source_id'] ?? null,
            updatedByUserId: $request->user()->id,
        ));

        return new CompanyCalendarResource($calendar);
    }

    /**
     * docs/16-database-schema.md: 会社カレンダー本体で有効なデフォルトは常に高々1件。
     * 既存のデフォルトを解除しつつ、指定した本体をデフォルトに切り替える。
     */
    #[OA\Post(
        path: '/company-calendars/{companyCalendar}/set-default',
        operationId: 'companyCalendars.setDefault',
        summary: '会社カレンダー本体をデフォルトに設定する',
        tags: ['勤務カレンダー'],
        parameters: [new OA\Parameter(name: 'companyCalendar', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function setDefault(Request $request, CompanyCalendar $companyCalendar, CommandBus $commandBus): CompanyCalendarResource
    {
        $calendar = $commandBus->dispatch(new SetDefaultCompanyCalendar(
            companyCalendarId: $companyCalendar->id,
            changedByUserId: $request->user()->id,
        ));

        return new CompanyCalendarResource($calendar);
    }

    /**
     * デフォルトカレンダー、または勤務形態から参照されているカレンダーは削除できない
     * (DeleteCompanyCalendarHandler参照)。
     */
    #[OA\Delete(
        path: '/company-calendars/{companyCalendar}',
        operationId: 'companyCalendars.destroy',
        summary: '会社カレンダー本体を削除する',
        tags: ['勤務カレンダー'],
        parameters: [new OA\Parameter(name: 'companyCalendar', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 204, description: 'Deleted'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function destroy(Request $request, CompanyCalendar $companyCalendar, CommandBus $commandBus): Response
    {
        $commandBus->dispatch(new DeleteCompanyCalendar(
            companyCalendarId: $companyCalendar->id,
            deletedByUserId: $request->user()->id,
        ));

        return response()->noContent();
    }

    #[OA\Get(
        path: '/company-calendars/{companyCalendar}/years',
        operationId: 'companyCalendars.years.index',
        summary: '会社カレンダー本体配下のカレンダー年度一覧を取得する',
        tags: ['勤務カレンダー'],
        parameters: [new OA\Parameter(name: 'companyCalendar', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function years(CompanyCalendar $companyCalendar): AnonymousResourceCollection
    {
        return CompanyCalendarYearResource::collection($companyCalendar->years()->orderByDesc('fiscal_year')->get());
    }

    /**
     * UC-C009 手順2: 本体配下にカレンダー年度を作成する。
     */
    #[OA\Post(
        path: '/company-calendars/{companyCalendar}/years',
        operationId: 'companyCalendars.years.store',
        summary: 'カレンダー年度を作成する',
        tags: ['勤務カレンダー'],
        parameters: [new OA\Parameter(name: 'companyCalendar', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['fiscal_year', 'starts_on', 'ends_on'], properties: [new OA\Property(property: 'fiscal_year', type: 'integer'), new OA\Property(property: 'starts_on', type: 'string', format: 'date'), new OA\Property(property: 'ends_on', type: 'string', format: 'date')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function storeYear(Request $request, CompanyCalendar $companyCalendar, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'fiscal_year' => ['required', 'integer'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
        ]);

        $year = $commandBus->dispatch(new CreateCompanyCalendarYear(
            companyCalendarId: $companyCalendar->id,
            fiscalYear: $data['fiscal_year'],
            startsOn: $data['starts_on'],
            endsOn: $data['ends_on'],
            generatedFrom: 'manual',
            createdByUserId: $request->user()->id,
        ));

        return (new CompanyCalendarYearResource($year))->response()->setStatusCode(201);
    }

    #[OA\Post(
        path: '/company-calendar-years/{companyCalendarYear}/publish',
        operationId: 'companyCalendarYears.publish',
        summary: 'カレンダー年度を公開する',
        tags: ['勤務カレンダー'],
        parameters: [new OA\Parameter(name: 'companyCalendarYear', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function publish(Request $request, CompanyCalendarYear $companyCalendarYear, CommandBus $commandBus): CompanyCalendarYearResource
    {
        $year = $commandBus->dispatch(new PublishCompanyCalendarYear(
            companyCalendarYearId: $companyCalendarYear->id,
            publishedByUserId: $request->user()->id,
        ));

        return new CompanyCalendarYearResource($year);
    }

    #[OA\Post(
        path: '/company-calendar-years/{companyCalendarYear}/unpublish',
        operationId: 'companyCalendarYears.unpublish',
        summary: 'カレンダー年度を下書きへ差し戻す',
        tags: ['勤務カレンダー'],
        parameters: [new OA\Parameter(name: 'companyCalendarYear', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function unpublish(Request $request, CompanyCalendarYear $companyCalendarYear, CommandBus $commandBus): CompanyCalendarYearResource
    {
        $year = $commandBus->dispatch(new UnpublishCompanyCalendarYear(
            companyCalendarYearId: $companyCalendarYear->id,
            unpublishedByUserId: $request->user()->id,
        ));

        return new CompanyCalendarYearResource($year);
    }

    #[OA\Post(
        path: '/company-calendar-years/{companyCalendarYear}/archive',
        operationId: 'companyCalendarYears.archive',
        summary: 'カレンダー年度を廃止する',
        tags: ['勤務カレンダー'],
        parameters: [new OA\Parameter(name: 'companyCalendarYear', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function archive(Request $request, CompanyCalendarYear $companyCalendarYear, CommandBus $commandBus): CompanyCalendarYearResource
    {
        $year = $commandBus->dispatch(new ArchiveCompanyCalendarYear(
            companyCalendarYearId: $companyCalendarYear->id,
            archivedByUserId: $request->user()->id,
        ));

        return new CompanyCalendarYearResource($year);
    }

    /**
     * UC-C009 手順4: 既存年度を複製して翌年度を作成する。
     */
    #[OA\Post(
        path: '/company-calendar-years/{companyCalendarYear}/duplicate',
        operationId: 'companyCalendarYears.duplicate',
        summary: '既存年度を複製して翌年度を作成する',
        tags: ['勤務カレンダー'],
        parameters: [new OA\Parameter(name: 'companyCalendarYear', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function duplicate(Request $request, CompanyCalendarYear $companyCalendarYear, CommandBus $commandBus): JsonResponse
    {
        $year = $commandBus->dispatch(new DuplicateCompanyCalendarYear(
            sourceCompanyCalendarYearId: $companyCalendarYear->id,
            createdByUserId: $request->user()->id,
        ));

        return (new CompanyCalendarYearResource($year))->response()->setStatusCode(201);
    }

    /**
     * UC-C012: カレンダー年度単位で祝日iCalendarソースを同期する(そのカレンダー本体に
     * 設定済みのソースを、この年度の年度範囲(starts_on〜ends_on)に限定して同期する)。
     * 全年度一括同期の`POST /holiday-calendar-sources/{id}/sync`とは別の入口として、
     * 年度一覧画面の各行から個別に同期できるようにする。
     */
    #[OA\Post(
        path: '/company-calendar-years/{companyCalendarYear}/sync-holiday-calendar',
        operationId: 'companyCalendarYears.syncHolidayCalendar',
        summary: 'カレンダー年度単位で祝日iCalendarソースを同期する',
        tags: ['勤務カレンダー'],
        parameters: [new OA\Parameter(name: 'companyCalendarYear', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function syncHolidayCalendar(Request $request, CompanyCalendarYear $companyCalendarYear, CommandBus $commandBus): HolidayCalendarSourceResource
    {
        $companyCalendar = $companyCalendarYear->companyCalendar;

        if ($companyCalendar->holiday_calendar_source_id === null) {
            throw ValidationException::withMessages([
                'holiday_calendar_source_id' => ['このカレンダーには祝日iCalendarソースが設定されていません。'],
            ]);
        }

        $source = $commandBus->dispatch(new SyncHolidayCalendarSource(
            holidayCalendarSourceId: $companyCalendar->holiday_calendar_source_id,
            syncedByUserId: $request->user()->id,
            companyCalendarYearId: $companyCalendarYear->id,
        ));

        return new HolidayCalendarSourceResource($source);
    }

    /**
     * UC-C010: 会社休日・祝日・法定/所定休日を一括登録する。
     */
    #[OA\Put(
        path: '/company-calendar-years/{companyCalendarYear}/days',
        operationId: 'companyCalendarYears.putDays',
        summary: 'カレンダー年度の日別設定を更新する',
        tags: ['勤務カレンダー'],
        parameters: [new OA\Parameter(name: 'companyCalendarYear', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['days'], properties: [new OA\Property(property: 'days', type: 'array', items: new OA\Items(type: 'object'))])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function putDays(Request $request, CompanyCalendarYear $companyCalendarYear, CommandBus $commandBus): AnonymousResourceCollection
    {
        $data = $request->validate([
            'days' => ['required', 'array'],
            'days.*.date' => ['required', 'date'],
            'days.*.day_type' => ['required', 'string'],
            'days.*.is_working_day' => ['boolean'],
            'days.*.is_legal_holiday' => ['boolean'],
            'days.*.is_company_holiday' => ['boolean'],
            'days.*.is_public_holiday' => ['boolean'],
            'days.*.public_holiday_name' => ['nullable', 'string'],
            'days.*.schedule_state' => ['string', 'in:WORK,OFF'],
            'days.*.note' => ['nullable', 'string'],
        ]);

        $commandBus->dispatch(new UpdateCompanyCalendarDays(
            companyCalendarYearId: $companyCalendarYear->id,
            days: $data['days'],
            updatedByUserId: $request->user()->id,
        ));

        return CompanyCalendarDayResource::collection($companyCalendarYear->days()->orderBy('date')->get());
    }
}
