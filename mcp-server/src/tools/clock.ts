import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import type { FlowOfficeApiClient } from "../apiClient.js";
import { callAndReport } from "../toolResult.js";

/**
 * 打刻系ツール(docs/25-usecases-integrations-mcp.md「MCPツール一覧」)。attendance:self:clock
 * スコープを要求する。UC-A001〜A004の画面打刻と同じCommand(ClockIn等)を呼び出す既存の
 * `/attendance/*`エンドポイントをそのまま利用し、打刻ロジックを複製しない
 * (docs/03-architecture.md 3.5)。
 */
export function registerClockTools(server: McpServer, client: FlowOfficeApiClient) {
  server.registerTool(
    "clock_in",
    { description: "出勤の打刻を行う(UC-A001)。", inputSchema: {} },
    async () => callAndReport(() => client.post("/attendance/clock-in")),
  );

  server.registerTool(
    "start_break",
    { description: "休憩開始の打刻を行う(UC-A002)。", inputSchema: {} },
    async () => callAndReport(() => client.post("/attendance/break/start")),
  );

  server.registerTool(
    "end_break",
    { description: "休憩終了の打刻を行う(UC-A003)。", inputSchema: {} },
    async () => callAndReport(() => client.post("/attendance/break/end")),
  );

  server.registerTool(
    "clock_out",
    { description: "退勤の打刻を行う(UC-A004)。", inputSchema: {} },
    async () => callAndReport(() => client.post("/attendance/clock-out")),
  );
}
