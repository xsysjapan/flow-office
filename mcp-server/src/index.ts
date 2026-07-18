#!/usr/bin/env node
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { FlowOfficeApiClient } from "./apiClient.js";
import { registerProfileTools } from "./tools/profile.js";
import { registerAttendanceReadTools } from "./tools/attendance.js";
import { registerClockTools } from "./tools/clock.js";
import { registerMonthlyDraftTools } from "./tools/monthlyDraft.js";
import { registerImportSessionTools } from "./tools/importSession.js";

/**
 * flow-office MCPサーバー(docs/25-usecases-integrations-mcp.md「MCPサーバーの責務」)。
 *
 * ユーザーが個人API/MCP連携(UC-I001、`POST /users/me/integrations`)で発行した
 * Sanctumトークンを`FLOW_OFFICE_TOKEN`環境変数として渡す。トークンのスコープに応じて
 * backend側のability検証(EnsureFullAccessOrExplicitAbility)がツール呼び出しの可否を決める。
 * このサーバー自体は認証・認可・勤怠計算ロジックを一切持たず、既存のLaravel APIを
 * 呼び出すだけのクライアントである(docs/03-architecture.md 3.5)。
 */
function requiredEnv(name: string): string {
  const value = process.env[name];
  if (!value) {
    throw new Error(
      `環境変数 ${name} が設定されていません。個人API/MCP連携(docs/25-usecases-integrations-mcp.md UC-I001)で` +
        "発行したトークンとbackendのURLを設定してください。",
    );
  }
  return value;
}

async function main() {
  const client = new FlowOfficeApiClient({
    baseUrl: requiredEnv("FLOW_OFFICE_API_BASE_URL"),
    token: requiredEnv("FLOW_OFFICE_TOKEN"),
  });

  const server = new McpServer({
    name: "flow-office",
    version: "0.1.0",
  });

  registerProfileTools(server, client);
  registerAttendanceReadTools(server, client);
  registerClockTools(server, client);
  registerMonthlyDraftTools(server, client);
  registerImportSessionTools(server, client);

  const transport = new StdioServerTransport();
  await server.connect(transport);
}

main().catch((error) => {
  console.error("flow-office MCPサーバーの起動に失敗しました:", error);
  process.exit(1);
});
