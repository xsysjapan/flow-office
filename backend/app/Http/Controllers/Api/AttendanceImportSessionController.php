<?php

namespace App\Http\Controllers\Api;

use App\Domain\AttendanceImport\Commands\ApplyAttendanceImportSessionToDraft;
use App\Domain\AttendanceImport\Commands\CreateAttendanceImportSession;
use App\Domain\AttendanceImport\Commands\PreviewAttendanceImportSession;
use App\Domain\AttendanceImport\Commands\UploadAttendanceImportData;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceImportSessionResource;
use App\Http\Resources\MonthlyAttendanceDraftResource;
use App\Models\AttendanceImportSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * UC-R001: 作業報告書インポートセッション(docs/26-usecases-monthly-import.md)。
 * ファイル解析自体はClaude側で行い、構造化データの受け入れ・差異検出・下書き反映のみを担当する。
 */
#[OA\Tag(name: '作業報告書インポート', description: '作業報告書からの月次勤怠インポートセッション')]
class AttendanceImportSessionController extends Controller
{
    #[OA\Post(path: '/attendance/import-sessions', operationId: 'attendanceImportSessions.store', summary: 'インポートセッションを作成する', tags: ['作業報告書インポート'], responses: [new OA\Response(response: 201, description: 'Created')])]
    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'target_month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'source_type' => ['nullable', 'string'],
            'source_file_name' => ['nullable', 'string'],
            'source_file_hash' => ['nullable', 'string'],
            'client_type' => ['nullable', 'string'],
            'integration_id' => ['nullable', 'integer', 'exists:application_integrations,id'],
        ]);

        $session = $commandBus->dispatch(new CreateAttendanceImportSession(
            userId: $request->user()->id,
            targetMonth: $data['target_month'],
            sourceType: $data['source_type'] ?? 'work_report',
            sourceFileName: $data['source_file_name'] ?? null,
            sourceFileHash: $data['source_file_hash'] ?? null,
            clientType: $data['client_type'] ?? null,
            integrationId: $data['integration_id'] ?? null,
        ));

        return (new AttendanceImportSessionResource($session))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/attendance/import-sessions/{importSession}', operationId: 'attendanceImportSessions.show', summary: 'インポートセッションを取得する', tags: ['作業報告書インポート'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function show(Request $request, AttendanceImportSession $importSession): AttendanceImportSessionResource
    {
        $this->abortUnlessOwnerOrAdmin($request, $importSession->user_id, '他の社員のインポートセッションを閲覧する権限がありません。');

        return new AttendanceImportSessionResource($importSession->load('items'));
    }

    #[OA\Post(path: '/attendance/import-sessions/{importSession}/data', operationId: 'attendanceImportSessions.uploadData', summary: '構造化データをアップロードする', tags: ['作業報告書インポート'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function uploadData(Request $request, AttendanceImportSession $importSession, CommandBus $commandBus): AttendanceImportSessionResource
    {
        $this->abortUnlessOwnerOrAdmin($request, $importSession->user_id, '他の社員のインポートセッションを操作する権限がありません。');

        // 提案データ(startTime/endTime/breaks/workLocation/projectName/confidence等)は
        // Claudeが送る自由形式のJSONのため、'date'以外のキーを検証で削ぎ落とさないよう
        // validate()の結果ではなく生の入力をそのままコマンドへ渡す。
        $request->validate([
            'days' => ['required', 'array', 'min:1'],
            'days.*.date' => ['required', 'date'],
        ]);

        $session = $commandBus->dispatch(new UploadAttendanceImportData(
            sessionId: $importSession->id,
            days: $request->input('days'),
        ));

        return new AttendanceImportSessionResource($session);
    }

    #[OA\Post(path: '/attendance/import-sessions/{importSession}/preview', operationId: 'attendanceImportSessions.preview', summary: '既存勤怠との差異を検出する', tags: ['作業報告書インポート'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function preview(Request $request, AttendanceImportSession $importSession, CommandBus $commandBus): AttendanceImportSessionResource
    {
        $this->abortUnlessOwnerOrAdmin($request, $importSession->user_id, '他の社員のインポートセッションを操作する権限がありません。');

        $session = $commandBus->dispatch(new PreviewAttendanceImportSession($importSession->id));

        return new AttendanceImportSessionResource($session);
    }

    #[OA\Post(path: '/attendance/import-sessions/{importSession}/apply', operationId: 'attendanceImportSessions.apply', summary: '月次勤怠下書きへ反映する', tags: ['作業報告書インポート'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function apply(Request $request, AttendanceImportSession $importSession, CommandBus $commandBus): JsonResponse
    {
        $this->abortUnlessOwnerOrAdmin($request, $importSession->user_id, '他の社員のインポートセッションを操作する権限がありません。');

        $data = $request->validate(['draft_id' => ['nullable', 'integer', 'exists:monthly_attendance_drafts,id']]);

        $result = $commandBus->dispatch(new ApplyAttendanceImportSessionToDraft(
            sessionId: $importSession->id,
            draftId: $data['draft_id'] ?? null,
            appliedByUserId: $request->user()->id,
        ));

        return response()->json([
            'session' => new AttendanceImportSessionResource($result['session']),
            'draft' => new MonthlyAttendanceDraftResource($result['draft']),
            'results' => $result['results'],
        ]);
    }
}
