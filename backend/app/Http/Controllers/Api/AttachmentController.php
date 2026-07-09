<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Models\WorkflowRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * UC-F001/UC-F002: 添付ファイルのアップロード・閲覧。
 * owner_type は公開APIとして安全な別名(下記MAP)のみ受け付け、Eloquentクラス名を直接受け取らない。
 */
class AttachmentController extends Controller
{
    /**
     * @var array<string, class-string>
     */
    private const OWNER_TYPE_MAP = [
        'workflow_request' => WorkflowRequest::class,
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        [$ownerClass, $ownerId] = $this->resolveOwner($request);
        $owner = $this->authorizeOwner($request, $ownerClass, $ownerId);

        return AttachmentResource::collection($owner->attachments()->get());
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'owner_type' => ['required', 'string', 'in:'.implode(',', array_keys(self::OWNER_TYPE_MAP))],
            'owner_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        [$ownerClass, $ownerId] = $this->resolveOwner($request);
        $owner = $this->authorizeOwner($request, $ownerClass, $ownerId);

        $file = $request->file('file');
        $storedPath = $file->store('attachments/'.Str::plural(class_basename($ownerClass)).'/'.$ownerId, 'local');

        $attachment = Attachment::query()->create([
            'owner_type' => $request->input('owner_type'),
            'owner_id' => $owner->id,
            'uploaded_by' => $request->user()->id,
            'file_name' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
        ]);

        return (new AttachmentResource($attachment))->response()->setStatusCode(201);
    }

    public function download(Request $request, Attachment $attachment): StreamedResponse
    {
        $owner = $attachment->owner;
        $this->authorizeAttachmentAccess($request, $attachment, $owner);

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
    private function authorizeOwner(Request $request, string $ownerClass, int $ownerId): WorkflowRequest
    {
        /** @var WorkflowRequest $owner */
        $owner = $ownerClass::query()->findOrFail($ownerId);
        $user = $request->user();

        abort_unless(
            $owner->applicant_user_id === $user->id || $owner->approver_user_id === $user->id,
            Response::HTTP_FORBIDDEN,
            'この添付ファイルにアクセスする権限がありません。'
        );

        return $owner;
    }

    private function authorizeAttachmentAccess(Request $request, Attachment $attachment, mixed $owner): void
    {
        $user = $request->user();

        $isRelatedToWorkflowRequest = $owner instanceof WorkflowRequest
            && in_array($user->id, [$owner->applicant_user_id, $owner->approver_user_id], true);

        abort_unless(
            $attachment->uploaded_by === $user->id || $isRelatedToWorkflowRequest,
            Response::HTTP_FORBIDDEN,
            'この添付ファイルにアクセスする権限がありません。'
        );
    }
}
