<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance\Commands\DeleteHolidayCalendarSource;
use App\Domain\Attendance\Commands\DisableHolidayCalendarSource;
use App\Domain\Attendance\Commands\RegisterHolidayCalendarSource;
use App\Domain\Attendance\Commands\RevertLastHolidayCalendarSync;
use App\Domain\Attendance\Commands\SyncHolidayCalendarSource;
use App\Domain\Attendance\Commands\UpdateHolidayCalendarSource;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\HolidayCalendarSourceResource;
use App\Models\HolidayCalendarSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * UC-C012: 祝日iCalendarソースを同期する。
 */
#[OA\Tag(name: '祝日iCalendar同期', description: '祝日iCalendarソースの登録・同期')]
class HolidayCalendarSourceController extends Controller
{
    #[OA\Get(
        path: '/holiday-calendar-sources',
        operationId: 'holidayCalendarSources.index',
        summary: '祝日iCalendarソース一覧を取得する',
        tags: ['祝日iCalendar同期'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function index(): AnonymousResourceCollection
    {
        return HolidayCalendarSourceResource::collection(HolidayCalendarSource::query()->orderBy('name')->get());
    }

    /**
     * アップロードiCalendarファイルとして許可する拡張子。
     */
    private const ALLOWED_ICS_EXTENSIONS = ['ics', 'ical', 'ifb'];

    #[OA\Post(
        path: '/holiday-calendar-sources',
        operationId: 'holidayCalendarSources.store',
        summary: '祝日iCalendarソースを登録する',
        tags: ['祝日iCalendar同期'],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(type: 'object', required: ['name'], properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'ics_url', type: 'string'), new OA\Property(property: 'ics_file', type: 'string', format: 'binary')]))),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $this->validateSourcePayload($request);

        $source = $commandBus->dispatch(new RegisterHolidayCalendarSource(
            name: $data['name'],
            sourceKind: $data['source_kind'],
            icsUrl: $data['ics_url'],
            uploadedIcsPath: $data['uploaded_ics_path'],
            uploadedIcsFilename: $data['uploaded_ics_filename'],
            registeredByUserId: $request->user()->id,
        ));

        return (new HolidayCalendarSourceResource($source))->response()->setStatusCode(201);
    }

    #[OA\Post(
        path: '/holiday-calendar-sources/{holidayCalendarSource}',
        operationId: 'holidayCalendarSources.update',
        summary: '祝日iCalendarソースのURL/アップロードファイルを編集する',
        tags: ['祝日iCalendar同期'],
        parameters: [new OA\Parameter(name: 'holidayCalendarSource', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(type: 'object', required: ['name'], properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'ics_url', type: 'string'), new OA\Property(property: 'ics_file', type: 'string', format: 'binary')]))),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function update(Request $request, HolidayCalendarSource $holidayCalendarSource, CommandBus $commandBus): HolidayCalendarSourceResource
    {
        $data = $this->validateSourcePayload($request);

        $source = $commandBus->dispatch(new UpdateHolidayCalendarSource(
            holidayCalendarSourceId: $holidayCalendarSource->id,
            name: $data['name'],
            sourceKind: $data['source_kind'],
            icsUrl: $data['ics_url'],
            uploadedIcsPath: $data['uploaded_ics_path'],
            uploadedIcsFilename: $data['uploaded_ics_filename'],
            updatedByUserId: $request->user()->id,
        ));

        return new HolidayCalendarSourceResource($source);
    }

    /**
     * store/updateで共通の入力検証・ファイル保存処理。
     *
     * @return array{name: string, source_kind: string, ics_url: ?string, uploaded_ics_path: ?string, uploaded_ics_filename: ?string}
     */
    private function validateSourcePayload(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'ics_url' => ['nullable', 'string', 'url', 'max:500', 'required_without:ics_file'],
            'ics_file' => ['nullable', 'file', 'max:2048', 'required_without:ics_url'],
        ]);

        if ($request->filled('ics_url') && $request->hasFile('ics_file')) {
            throw ValidationException::withMessages([
                'ics_url' => ['ics_urlとics_fileはどちらか一方のみ指定してください。'],
            ]);
        }

        if ($request->hasFile('ics_file')) {
            /** @var UploadedFile $file */
            $file = $request->file('ics_file');
            $extension = strtolower($file->getClientOriginalExtension());

            if (! in_array($extension, self::ALLOWED_ICS_EXTENSIONS, true)) {
                throw ValidationException::withMessages([
                    'ics_file' => ['許可されていないファイル形式です(許可: '.implode(', ', self::ALLOWED_ICS_EXTENSIONS).')。'],
                ]);
            }

            $storedPath = $file->store('holiday-ics-uploads', 'local');

            return [
                'name' => $data['name'],
                'source_kind' => HolidayCalendarSource::SOURCE_KIND_UPLOAD,
                'ics_url' => null,
                'uploaded_ics_path' => $storedPath,
                'uploaded_ics_filename' => $file->getClientOriginalName(),
            ];
        }

        return [
            'name' => $data['name'],
            'source_kind' => HolidayCalendarSource::SOURCE_KIND_URL,
            'ics_url' => $data['ics_url'],
            'uploaded_ics_path' => null,
            'uploaded_ics_filename' => null,
        ];
    }

    #[OA\Post(
        path: '/holiday-calendar-sources/{holidayCalendarSource}/sync',
        operationId: 'holidayCalendarSources.sync',
        summary: '祝日iCalendarソースを手動同期する',
        tags: ['祝日iCalendar同期'],
        parameters: [new OA\Parameter(name: 'holidayCalendarSource', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function sync(Request $request, HolidayCalendarSource $holidayCalendarSource, CommandBus $commandBus): HolidayCalendarSourceResource
    {
        $source = $commandBus->dispatch(new SyncHolidayCalendarSource(
            holidayCalendarSourceId: $holidayCalendarSource->id,
            syncedByUserId: $request->user()->id,
        ));

        return new HolidayCalendarSourceResource($source);
    }

    #[OA\Post(
        path: '/holiday-calendar-sources/{holidayCalendarSource}/disable',
        operationId: 'holidayCalendarSources.disable',
        summary: '祝日iCalendarソースを無効化する',
        tags: ['祝日iCalendar同期'],
        parameters: [new OA\Parameter(name: 'holidayCalendarSource', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function disable(Request $request, HolidayCalendarSource $holidayCalendarSource, CommandBus $commandBus): HolidayCalendarSourceResource
    {
        $source = $commandBus->dispatch(new DisableHolidayCalendarSource(
            holidayCalendarSourceId: $holidayCalendarSource->id,
            disabledByUserId: $request->user()->id,
        ));

        return new HolidayCalendarSourceResource($source);
    }

    /**
     * UC-C012 手順4後半: 直近1回分の祝日同期を取消す。
     */
    #[OA\Post(
        path: '/holiday-calendar-sources/{holidayCalendarSource}/revert-last-sync',
        operationId: 'holidayCalendarSources.revertLastSync',
        summary: '直近の祝日同期1回分を取消す',
        tags: ['祝日iCalendar同期'],
        parameters: [new OA\Parameter(name: 'holidayCalendarSource', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function revertLastSync(Request $request, HolidayCalendarSource $holidayCalendarSource, CommandBus $commandBus): HolidayCalendarSourceResource
    {
        $source = $commandBus->dispatch(new RevertLastHolidayCalendarSync(
            holidayCalendarSourceId: $holidayCalendarSource->id,
            revertedByUserId: $request->user()->id,
        ));

        return new HolidayCalendarSourceResource($source);
    }

    /**
     * UC-C012: 祝日iCalendarソースを削除する。無効化(disable)したソースを再び有効化する
     * 手段が無かったため、不要なソースを削除できるようにする。
     */
    #[OA\Delete(
        path: '/holiday-calendar-sources/{holidayCalendarSource}',
        operationId: 'holidayCalendarSources.destroy',
        summary: '祝日iCalendarソースを削除する',
        tags: ['祝日iCalendar同期'],
        parameters: [new OA\Parameter(name: 'holidayCalendarSource', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 204, description: 'No Content'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function destroy(Request $request, HolidayCalendarSource $holidayCalendarSource, CommandBus $commandBus): JsonResponse
    {
        $commandBus->dispatch(new DeleteHolidayCalendarSource(
            holidayCalendarSourceId: $holidayCalendarSource->id,
            deletedByUserId: $request->user()->id,
        ));

        return response()->json(null, 204);
    }
}
