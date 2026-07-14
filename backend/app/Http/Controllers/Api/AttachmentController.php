<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attachment\Commands\UploadAttachment;
use App\Domain\Attachment\Events\AttachmentDownloaded;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\EventStore;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Models\AttendanceDay;
use App\Models\BackOfficeTask;
use App\Models\WorkflowRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use OpenApi\Attributes as OA;

/**
 * UC-F001/UC-F002: 添付ファイルのアップロード・閲覧。
 * owner_type は公開APIとして安全な別名(下記MAP)のみ受け付け、Eloquentクラス名を直接受け取らない。
 * docs/12-usecases-attachment.md「申請・勤怠など任意のエンティティ」に対応するため、
 * 申請(workflow_request)以外に勤怠実績(attendance_day)にも添付できる。
 */
#[OA\Tag(name: '添付ファイル', description: '申請・勤怠に紐づく添付ファイル')]
class AttachmentController extends Controller
{
    /**
     * 申請種別マスタでサイズ/拡張子を設定できない owner_type(勤怠実績など)向けの既定値。
     */
    private const DEFAULT_MAX_SIZE_KB = 10240;

    /**
     * @var array<string, class-string>
     */
    private const OWNER_TYPE_MAP = [
        'workflow_request' => WorkflowRequest::class,
        'attendance_day' => AttendanceDay::class,
    ];

    #[OA\Get(
        path: '/attachments',
        operationId: 'attachments.index',
        summary: '添付ファイル一覧を取得する',
        tags: ['添付ファイル'],
        parameters: [new OA\Parameter(name: 'owner_type', in: 'query', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'owner_id', in: 'query', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        [$ownerClass, $ownerId] = $this->resolveOwner($request);
        $owner = $this->authorizeOwner($request, $ownerClass, $ownerId);

        return AttachmentResource::collection($owner->attachments()->get());
    }

    #[OA\Post(
        path: '/attachments',
        operationId: 'attachments.store',
        summary: '添付ファイルをアップロードする',
        tags: ['添付ファイル'],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(type: 'object', required: ['owner_type', 'owner_id', 'file'], properties: [new OA\Property(property: 'owner_type', type: 'string'), new OA\Property(property: 'owner_id', type: 'integer'), new OA\Property(property: 'file', type: 'string', format: 'binary')]))),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $request->validate([
            'owner_type' => ['required', 'string', 'in:'.implode(',', array_keys(self::OWNER_TYPE_MAP))],
            'owner_id' => ['required', 'integer'],
            'file' => ['required', 'file'],
        ]);

        [$ownerClass, $ownerId] = $this->resolveOwner($request);
        $owner = $this->authorizeOwner($request, $ownerClass, $ownerId);

        $file = $request->file('file');
        $this->assertWithinAttachmentLimits($owner, $file->getSize(), $file->getClientOriginalExtension());

        $storedPath = $file->store('attachments/'.Str::plural(class_basename($ownerClass)).'/'.$ownerId, 'local');

        $attachment = $commandBus->dispatch(new UploadAttachment(
            ownerTypeAlias: $request->input('owner_type'),
            ownerId: $owner->id,
            uploadedByUserId: $request->user()->id,
            fileName: $file->getClientOriginalName(),
            storedPath: $storedPath,
            mimeType: $file->getClientMimeType(),
            fileSize: $file->getSize(),
        ));

        return (new AttachmentResource($attachment))->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/attachments/{attachment}/download',
        operationId: 'attachments.download',
        summary: '添付ファイルをダウンロードする',
        tags: ['添付ファイル'],
        parameters: [new OA\Parameter(name: 'attachment', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function download(Request $request, Attachment $attachment, EventStore $eventStore): StreamedResponse
    {
        $owner = $attachment->owner;
        $this->authorizeAttachmentAccess($request, $attachment, $owner);

        $eventStore->append(
            aggregateType: 'attachment',
            aggregateId: (string) $attachment->id,
            event: new AttachmentDownloaded(
                attachmentId: $attachment->id,
                downloadedByUserId: $request->user()->id,
            ),
        );

        return Storage::disk('local')->download($attachment->stored_path, $attachment->file_name);
    }

    /**
     * @return array{0: class-string, 1: int}
     */
    private function resolveOwner(Request $request): array
    {
        $alias = $request->input('owner_type');
        $ownerClass = self::OWNER_TYPE_MAP[$alias] ?? null;
        abort_if($ownerClass === null, 422, '不正な owner_type です。');

        return [$ownerClass, (int) $request->input('owner_id')];
    }

    /**
     * @param  class-string  $ownerClass
     */
    private function authorizeOwner(Request $request, string $ownerClass, int $ownerId): WorkflowRequest|AttendanceDay
    {
        $owner = $ownerClass::query()->findOrFail($ownerId);
        $user = $request->user();

        if ($owner instanceof WorkflowRequest) {
            abort_unless(
                $owner->applicant_user_id === $user->id || $owner->approver_user_id === $user->id,
                Response::HTTP_FORBIDDEN,
                'この添付ファイルにアクセスする権限がありません。'
            );

            return $owner;
        }

        $this->abortUnlessOwnerOrAdmin($request, $owner->user_id, 'この添付ファイルにアクセスする権限がありません。');

        return $owner;
    }

    private function authorizeAttachmentAccess(Request $request, Attachment $attachment, mixed $owner): void
    {
        $user = $request->user();

        if ($attachment->uploaded_by === $user->id) {
            return;
        }

        if ($owner instanceof WorkflowRequest) {
            $isApplicantOrApprover = in_array($user->id, [$owner->applicant_user_id, $owner->approver_user_id], true);
            $isAssignedBackOfficeStaff = BackOfficeTask::query()
                ->where('source_type', 'workflow_request')
                ->where('source_id', $owner->id)
                ->where('assigned_user_id', $user->id)
                ->exists();

            abort_unless($isApplicantOrApprover || $isAssignedBackOfficeStaff, Response::HTTP_FORBIDDEN,
                'この添付ファイルにアクセスする権限がありません。');

            return;
        }

        if ($owner instanceof AttendanceDay) {
            $this->abortUnlessOwnerOrAdmin($request, $owner->user_id, 'この添付ファイルにアクセスする権限がありません。');

            return;
        }

        abort(Response::HTTP_FORBIDDEN, 'この添付ファイルにアクセスする権限がありません。');
    }

    /**
     * 添付ファイルのサイズ/拡張子の許可リストをマスタから解決して検証する。
     * workflow_requestは申請種別マスタ(request_types)の設定を使う(未設定ならサイズのみ既定値で制限し、
     * 拡張子は制限しない)。それ以外のowner(勤怠実績等)は申請種別を持たないため、既定値のみを適用する。
     */
    private function assertWithinAttachmentLimits(WorkflowRequest|AttendanceDay $owner, int $fileSize, string $extension): void
    {
        $maxSizeKb = self::DEFAULT_MAX_SIZE_KB;
        $allowedExtensions = null;

        if ($owner instanceof WorkflowRequest) {
            $requestType = $owner->requestType;
            $maxSizeKb = $requestType?->attachment_max_size_kb ?? self::DEFAULT_MAX_SIZE_KB;
            $allowedExtensions = $requestType?->attachment_allowed_extensions;
        }

        if ($fileSize > $maxSizeKb * 1024) {
            throw ValidationException::withMessages([
                'file' => ["ファイルサイズは{$maxSizeKb}KB以内にしてください。"],
            ]);
        }

        if ($allowedExtensions !== null && ! in_array(strtolower($extension), array_map('strtolower', $allowedExtensions), true)) {
            throw ValidationException::withMessages([
                'file' => ['許可されていないファイル形式です(許可: '.implode(', ', $allowedExtensions).')。'],
            ]);
        }
    }
}
