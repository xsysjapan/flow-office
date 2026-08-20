<?php

namespace App\Http\Controllers\Api;

use App\Console\AdminCommandRegistry;
use App\Http\Controllers\Controller;
use App\Jobs\RunAdminCommandJob;
use App\Models\AdminCommandRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

final class AdminCommandController extends Controller
{
    #[OA\Get(path: '/admin/commands', operationId: 'adminCommands.index', summary: '管理画面から実行可能なArtisanコマンドとメタデータを取得する', tags: ['運用コマンド'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function index(AdminCommandRegistry $registry): JsonResponse
    {
        return response()->json(['data' => array_values($registry->all())]);
    }

    #[OA\Get(path: '/admin/command-runs', operationId: 'adminCommandRuns.index', summary: '運用コマンドの実行履歴を取得する', tags: ['運用コマンド'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function runs(): JsonResponse
    {
        $runs = AdminCommandRun::query()->with('requestedByUser:id,name')->latest()->paginate(50);

        return response()->json([
            'data' => $runs->items(),
            'meta' => ['current_page' => $runs->currentPage(), 'last_page' => $runs->lastPage(), 'total' => $runs->total(), 'per_page' => $runs->perPage()],
            'links' => ['next' => $runs->nextPageUrl(), 'prev' => $runs->previousPageUrl()],
        ]);
    }

    #[OA\Post(path: '/admin/commands/{command}/runs', operationId: 'adminCommands.run', summary: '許可済み運用コマンドをDBキューへ投入する', tags: ['運用コマンド'], responses: [new OA\Response(response: 202, description: 'Accepted')])]
    public function store(Request $request, string $command, AdminCommandRegistry $registry): JsonResponse
    {
        $metadata = $registry->find($command);
        abort_if($metadata === null, 404);

        $parameters = $request->input('parameters', []);
        if (! is_array($parameters)) {
            throw ValidationException::withMessages(['parameters' => 'パラメーターはオブジェクトで指定してください。']);
        }
        $known = collect($metadata['parameters'])->pluck('name')->all();
        $unknown = array_diff(array_keys($parameters), $known);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['parameters' => '未定義のパラメーターが含まれています: '.implode(', ', $unknown)]);
        }
        $validated = Validator::make($parameters, $metadata['rules'])->validate();

        $run = AdminCommandRun::create([
            'command_name' => $command,
            'parameters' => $validated,
            'status' => 'queued',
            'requested_by_user_id' => $request->user()->id,
        ]);
        RunAdminCommandJob::dispatch($run->id);

        return response()->json(['data' => $run], 202);
    }
}
