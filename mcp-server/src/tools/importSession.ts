import { z } from "zod";
import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import type { FlowOfficeApiClient } from "../apiClient.js";
import { callAndReport } from "../toolResult.js";

/**
 * インポート・照合系ツール(docs/25-usecases-integrations-mcp.md「MCPツール一覧」、
 * docs/26-usecases-monthly-import.md)。ファイル解析自体はClaude側で行い、ここでは
 * Claudeが解析済みの構造化データを受け渡すのみで、汎用的なPDF・Excel解析は行わない。
 */
export function registerImportSessionTools(server: McpServer, client: FlowOfficeApiClient) {
  server.registerTool(
    "create_attendance_import_session",
    {
      description: "作業報告書インポートセッションを作成する(docs/26 UC-R001手順3)。",
      inputSchema: {
        target_month: z.string().regex(/^\d{4}-\d{2}$/),
        source_file_name: z.string().optional(),
        source_file_hash: z.string().optional(),
      },
    },
    async ({ target_month, source_file_name, source_file_hash }) =>
      callAndReport(() =>
        client.post("/attendance/import-sessions", {
          target_month,
          source_type: "work_report",
          source_file_name,
          source_file_hash,
        }),
      ),
  );

  server.registerTool(
    "upload_attendance_import_data",
    {
      description:
        "Claudeが作業報告書から抽出した日別勤務候補(構造化データ)をインポートセッションへ送信する" +
        "(docs/26 UC-R001手順4)。ファイルそのものは送らない。",
      inputSchema: {
        session_id: z.number(),
        days: z.array(z.record(z.string(), z.unknown())),
      },
    },
    async ({ session_id, days }) =>
      callAndReport(() => client.post(`/attendance/import-sessions/${session_id}/data`, { days })),
  );

  server.registerTool(
    "preview_attendance_import",
    {
      description:
        "既存の勤怠・打刻・休暇消化・勤務予定と比較し、日別の差異を検出する(docs/26「差異検出」)。",
      inputSchema: { session_id: z.number() },
    },
    async ({ session_id }) => callAndReport(() => client.post(`/attendance/import-sessions/${session_id}/preview`)),
  );

  server.registerTool(
    "compare_import_with_existing_attendance",
    {
      description:
        "インポートセッションの現在の差異検出結果(既存勤怠との比較)を取得する。" +
        "preview_attendance_importを実行済みであることが前提。",
      inputSchema: { session_id: z.number() },
    },
    async ({ session_id }) => callAndReport(() => client.get(`/attendance/import-sessions/${session_id}`)),
  );

  server.registerTool(
    "apply_import_to_monthly_draft",
    {
      description:
        "差異のない日を一括で月次勤怠下書きへ反映する。差異のある日も反映されるが、" +
        "ユーザー未確認のAI推定値として残る(docs/26「不明点の確認」)。",
      inputSchema: { session_id: z.number(), draft_id: z.number().optional() },
    },
    async ({ session_id, draft_id }) =>
      callAndReport(() => client.post(`/attendance/import-sessions/${session_id}/apply`, { draft_id })),
  );

  server.registerTool(
    "get_attendance_import_status",
    {
      description: "インポートセッションの状態・明細を取得する。",
      inputSchema: { session_id: z.number() },
    },
    async ({ session_id }) => callAndReport(() => client.get(`/attendance/import-sessions/${session_id}`)),
  );
}
