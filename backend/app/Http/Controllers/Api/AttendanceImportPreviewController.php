<?php

namespace App\Http\Controllers\Api;

use App\Domain\AttendanceImport\Services\AttendanceDifferenceDetector;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * docs/26-usecases-monthly-import.md「データの保持場所」: 作業報告書インポートの下書き・
 * セッションデータはmcp/自身のDBに保持し、backend/には持たせない。backend/は
 * AttendanceDifferenceDetector(既存の勤怠・打刻・休暇消化・勤務予定との照合ロジック)を
 * 再利用したこのステートレスな検証だけを提供する。何も保存しない(CLAUDE.mdの設計原則9)。
 */
#[OA\Tag(name: '作業報告書インポート', description: '作業報告書からの月次勤怠インポート(ステートレスな差異検出)')]
class AttendanceImportPreviewController extends Controller
{
    #[OA\Post(
        path: '/attendance/import-preview',
        operationId: 'attendanceImportPreview.check',
        summary: '作業報告書由来の候補と既存勤怠との差異を検出する(何も保存しない)',
        tags: ['作業報告書インポート'],
        responses: [new OA\Response(response: 200, description: 'Successful response')],
    )]
    public function check(Request $request, AttendanceDifferenceDetector $detector): JsonResponse
    {
        // 候補データ(startTime/endTime/breaks/workLocation等)はClaudeが送る自由形式のJSONの
        // ため、'date'以外のキーをvalidate()で削ぎ落とさないよう、生の入力をそのまま使う
        // (AttendanceImportSessionController::uploadData()と同じ理由)。
        $request->validate([
            'target_month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'days' => ['required', 'array', 'min:1'],
            'days.*.date' => ['required', 'date'],
        ]);

        $targetMonth = $request->input('target_month');
        $days = $request->input('days');
        $userId = $request->user()->id;

        $items = array_map(
            fn (array $day) => [
                'work_date' => $day['date'],
                ...$detector->detect($userId, $targetMonth, $day),
            ],
            $days,
        );

        $proposedDates = array_column($days, 'date');
        $missingDates = $detector->findDatesMissingFromReport($userId, $targetMonth, $proposedDates);

        return response()->json(['items' => $items, 'missing_dates' => $missingDates]);
    }
}
