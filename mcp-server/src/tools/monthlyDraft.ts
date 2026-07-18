import { z } from "zod";
import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import type { FlowOfficeApiClient } from "../apiClient.js";
import { callAndReport } from "../toolResult.js";

const dayShape = z
  .object({
    date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
    startTime: z.string().regex(/^\d{2}:\d{2}$/).optional(),
    endTime: z.string().regex(/^\d{2}:\d{2}$/).optional(),
    breaks: z
      .array(z.object({ startTime: z.string(), endTime: z.string().optional() }))
      .optional(),
    workLocationType: z
      .enum(["office", "remote", "client_site", "business_trip", "direct_to_site", "direct_from_site", "other"])
      .optional(),
    workDescription: z.string().optional(),
    source: z
      .enum([
        "source_document",
        "existing_clock_event",
        "existing_attendance",
        "work_schedule",
        "employment_rule",
        "ai_inferred",
        "user_confirmed",
        "user_manual_input",
        "admin_correction",
      ])
      .optional(),
    confidence: z.enum(["high", "medium", "low"]).optional(),
  })
  .passthrough();

/**
 * 日次勤怠編集系・月次勤怠系ツール(docs/25-usecases-integrations-mcp.md「MCPツール一覧」)。
 * すべてdocs/26-usecases-monthly-import.mdのmonthly_attendance_drafts APIを呼び出す。
 * `create_attendance_day_draft`/`update_attendance_day_draft`は、backend側では単一の
 * 一括更新API(days[]が1件)として扱う(専用の単日エンドポイントを持たない)。
 */
export function registerMonthlyDraftTools(server: McpServer, client: FlowOfficeApiClient) {
  server.registerTool(
    "create_monthly_attendance_draft",
    {
      description: "対象年月の月次勤怠下書きを新規作成する。",
      inputSchema: {
        target_month: z.string().regex(/^\d{4}-\d{2}$/),
        source_type: z.string().optional(),
        source_reference: z.string().optional(),
      },
    },
    async ({ target_month, source_type, source_reference }) =>
      callAndReport(() =>
        client.post("/attendance/monthly-drafts", {
          target_month,
          source_type,
          source_reference,
        }),
      ),
  );

  server.registerTool(
    "list_my_monthly_attendance_drafts",
    {
      description: "自分の月次勤怠下書きを一覧する(新しい順)。",
      inputSchema: {},
    },
    async () => callAndReport(() => client.get("/attendance/monthly-drafts/mine")),
  );

  server.registerTool(
    "get_monthly_attendance_draft",
    {
      description: "月次勤怠下書きを取得する。",
      inputSchema: { draft_id: z.number() },
    },
    async ({ draft_id }) => callAndReport(() => client.get(`/attendance/monthly-drafts/${draft_id}`)),
  );

  server.registerTool(
    "create_attendance_day_draft",
    {
      description:
        "月次勤怠下書きに1日分の勤務候補を追加する(内部的にはbulk_update_attendance_daysの1日版)。",
      inputSchema: { draft_id: z.number(), expected_version: z.number(), day: dayShape },
    },
    async ({ draft_id, expected_version, day }) =>
      callAndReport(() =>
        client.put(`/attendance/monthly-drafts/${draft_id}/days`, {
          expected_version,
          days: [day],
        }),
      ),
  );

  server.registerTool(
    "update_attendance_day_draft",
    {
      description: "月次勤怠下書きの1日分の勤務候補を更新する。",
      inputSchema: { draft_id: z.number(), expected_version: z.number(), day: dayShape },
    },
    async ({ draft_id, expected_version, day }) =>
      callAndReport(() =>
        client.put(`/attendance/monthly-drafts/${draft_id}/days`, {
          expected_version,
          days: [day],
        }),
      ),
  );

  server.registerTool(
    "bulk_update_attendance_days",
    {
      description:
        "月次勤怠下書きへ複数日分の勤務候補をまとめて反映する(docs/26「一括更新API」)。楽観ロック" +
        "(expected_version)・冪等性(idempotency_key)に対応する。",
      inputSchema: {
        draft_id: z.number(),
        expected_version: z.number(),
        days: z.array(dayShape),
        idempotency_key: z.string().optional(),
      },
    },
    async ({ draft_id, expected_version, days, idempotency_key }) =>
      callAndReport(() =>
        client.put(
          `/attendance/monthly-drafts/${draft_id}/days`,
          { expected_version, days },
          idempotency_key ? { "Idempotency-Key": idempotency_key } : undefined,
        ),
      ),
  );

  server.registerTool(
    "delete_attendance_day_draft",
    {
      description:
        "確定済みの日次勤怠(attendance_days)を削除する(UC-A015)。月次下書き固有の削除APIは" +
        "backendに未実装のため、既存の日次勤怠削除エンドポイントを呼び出す。",
      inputSchema: { attendance_day_id: z.number(), reason: z.string() },
    },
    async ({ attendance_day_id, reason }) =>
      callAndReport(() => client.delete(`/attendance/days/${attendance_day_id}`, { reason })),
  );

  server.registerTool(
    "validate_monthly_attendance",
    {
      description: "月次勤怠下書きを検証する。未確認のAI推定値が残っている場合はその一覧を返す。",
      inputSchema: { draft_id: z.number() },
    },
    async ({ draft_id }) => callAndReport(() => client.post(`/attendance/monthly-drafts/${draft_id}/validate`)),
  );

  server.registerTool(
    "list_attendance_draft_fields",
    {
      description:
        "月次勤怠下書きに紐づく各項目(日付・項目名・値の出所・確認状況)を一覧する。" +
        "validate_monthly_attendanceは未確認項目の名前(例: '2026-07-01:start_time')しか返さないため、" +
        "confirm_attendance_draft_fieldに渡すfield_provenance_idはこのツールで取得すること。",
      inputSchema: { draft_id: z.number() },
    },
    async ({ draft_id }) => callAndReport(() => client.get(`/attendance/monthly-drafts/${draft_id}/fields`)),
  );

  server.registerTool(
    "confirm_attendance_draft_field",
    {
      description:
        "月次勤怠下書きのAI推定値をユーザーが確認したことを記録する。field_provenance_idは" +
        "list_attendance_draft_fieldsで取得したidを使うこと。ユーザー自身がその値の内容を確認・" +
        "了承した場合にのみ呼び出すこと(AIが自己判断で確定させない、docs/03-architecture.md 3.7)。",
      inputSchema: { draft_id: z.number(), field_provenance_id: z.number() },
    },
    async ({ draft_id, field_provenance_id }) =>
      callAndReport(() => client.post(`/attendance/monthly-drafts/${draft_id}/fields/${field_provenance_id}/confirm`)),
  );

  server.registerTool(
    "submit_monthly_attendance",
    {
      description:
        "月次勤怠を申請する。ユーザーの明示的な指示があった場合にのみ呼び出すこと" +
        "(docs/26「月次申請」。ユーザーの指示なしに呼ばない)。",
      inputSchema: { draft_id: z.number(), approver_user_id: z.number() },
    },
    async ({ draft_id, approver_user_id }) =>
      callAndReport(() => client.post(`/attendance/monthly-drafts/${draft_id}/submit`, { approver_user_id })),
  );

  server.registerTool(
    "cancel_monthly_attendance_submission",
    {
      description:
        "月次勤怠の申請を取り消す。backendは現時点でこの取り消しAPIを実装していないため、" +
        "常にエラーを返す(将来のPhase6以降で対応予定)。",
      inputSchema: { draft_id: z.number() },
    },
    async () => ({
      isError: true,
      content: [
        {
          type: "text" as const,
          text:
            "月次申請の取り消しはbackendに未実装です。既存の差戻し(UC-A010、承認者操作)を" +
            "利用するか、管理者に問い合わせてください。",
        },
      ],
    }),
  );
}
