import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import type { FlowOfficeApiClient } from "../apiClient.js";
import { callAndReport } from "../toolResult.js";

export function registerProfileTools(server: McpServer, client: FlowOfficeApiClient) {
  server.registerTool(
    "get_my_profile",
    {
      description: "自分(このトークンを発行した本人)のプロフィール情報を取得する。",
      inputSchema: {},
    },
    async () => callAndReport(() => client.get("/auth/me")),
  );
}
