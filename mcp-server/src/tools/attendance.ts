import { z } from "zod";
import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import type { FlowOfficeApiClient } from "../apiClient.js";
import { callAndReport } from "../toolResult.js";

/**
 * 読み取り系ツール(docs/25-usecases-integrations-mcp.md「MCPツール一覧」)。
 * attendance:self:read / leave:self:read / schedule:self:read スコープを要求する。
 */
export function registerAttendanceReadTools(server: McpServer, client: FlowOfficeApiClient) {
  server.registerTool(
    "get_my_attendance_month",
    {
      description: "指定した年月(YYYY-MM)の自分の月次勤怠(日別明細・月次集計)を取得する。",
      inputSchema: { year_month: z.string().regex(/^\d{4}-\d{2}$/) },
    },
    async ({ year_month }) => callAndReport(() => client.get(`/attendance/months/${year_month}`)),
  );

  server.registerTool(
    "get_my_attendance_day",
    {
      description: "指定した日付(YYYY-MM-DD)の自分の日次勤怠を取得する。",
      inputSchema: { date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/) },
    },
    async ({ date }) =>
      callAndReport(async () => {
        const yearMonth = date.slice(0, 7);
        const month = (await client.get<{ days: Array<{ work_date: string }> }>(`/attendance/months/${yearMonth}`)) as {
          days?: Array<{ work_date: string }>;
        };
        const day = month.days?.find((d) => d.work_date === date);
        return day ?? { message: `${date}の日次勤怠は登録されていません。`, work_date: date };
      }),
  );

  server.registerTool(
    "get_my_attendance_events",
    {
      description: "指定した期間の自分の打刻ログ(出勤・休憩開始・休憩終了・退勤の生ログ)を取得する。",
      inputSchema: { from: z.string(), to: z.string() },
    },
    async ({ from, to }) => callAndReport(() => client.get("/attendance-punches", { from, to })),
  );

  server.registerTool(
    "get_my_work_schedule",
    {
      description: "指定した期間の自分の勤務予定(シフト)を取得する。",
      inputSchema: { from: z.string(), to: z.string() },
    },
    async ({ from, to }) =>
      callAndReport(async () => {
        const profile = (await client.get<{ id: number }>("/auth/me")) as { id: number };
        return client.get("/employee-shift-assignments", { user_id: profile.id, from, to });
      }),
  );

  server.registerTool(
    "get_my_calendar",
    {
      description: "対象年の会社カレンダー(所定休日・法定休日等)一覧を取得する。",
      inputSchema: {},
    },
    async () => callAndReport(() => client.get("/work-calendars")),
  );

  server.registerTool(
    "get_my_leave_requests",
    {
      description: "自分の有給申請一覧を取得する。",
      inputSchema: {},
    },
    async () => callAndReport(() => client.get("/paid-leave/requests/mine")),
  );

  server.registerTool(
    "get_my_monthly_summary",
    {
      description: "指定した年月の月次集計(所定内・所定外・法定外残業・深夜・休日労働・有給等)を取得する。",
      inputSchema: { year_month: z.string().regex(/^\d{4}-\d{2}$/) },
    },
    async ({ year_month }) =>
      callAndReport(async () => {
        const month = (await client.get<Record<string, unknown>>(`/attendance/months/${year_month}`)) as Record<string, unknown>;
        return month["monthly_calculation_totals"] ?? month;
      }),
  );

  server.registerTool(
    "get_my_monthly_attendance_status",
    {
      description: "指定した年月の月次勤怠の状態(未提出/提出済み/差戻し/承認済み/締め済み)を取得する。",
      inputSchema: { year_month: z.string().regex(/^\d{4}-\d{2}$/) },
    },
    async ({ year_month }) =>
      callAndReport(async () => {
        const month = (await client.get<{ status?: string }>(`/attendance/months/${year_month}`)) as { status?: string };
        return { year_month, status: month.status ?? "not_submitted" };
      }),
  );
}
