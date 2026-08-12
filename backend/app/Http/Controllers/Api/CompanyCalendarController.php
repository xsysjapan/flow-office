<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance\Commands\ArchiveCompanyCalendarYear;
use App\Domain\Attendance\Commands\CreateCompanyCalendar;
use App\Domain\Attendance\Commands\CreateCompanyCalendarYear;
use App\Domain\Attendance\Commands\DuplicateCompanyCalendarYear;
use App\Domain\Attendance\Commands\PublishCompanyCalendarYear;
use App\Domain\Attendance\Commands\SetDefaultCompanyCalendar;
use App\Domain\Attendance\Commands\UnpublishCompanyCalendarYear;
use App\Domain\Attendance\Commands\UpdateCompanyCalendarDays;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyCalendarDayResource;
use App\Http\Resources\CompanyCalendarResource;
use App\Http\Resources\CompanyCalendarYearResource;
use App\Models\CompanyCalendar;
use App\Models\CompanyCalendarYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * UC-C009: 会社カレンダー本体とカレンダー年度を分離して管理する。
 */
#[OA\Tag(name: '勤務カレンダー', description: '会社カレンダーと休日設定')]
class CompanyCalendarController extends Controller
{
    #[OA\Get(
        path: '/company-calendars',
        operationId: 'companyCalendars.index',
        summary: '会社カレンダー本体一覧を取得する',
        tags: ['勤務カレンダー'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function index(): AnonymousResourceCollection
    {
        return CompanyCalendarResource::collection(CompanyCalendar::query()->orderBy('name')->get());
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
