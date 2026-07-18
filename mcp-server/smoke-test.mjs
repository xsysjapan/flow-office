import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { StdioClientTransport } from "@modelcontextprotocol/sdk/client/stdio.js";

const token = process.env.FLOW_OFFICE_TOKEN;
if (!token) {
  console.error("Set FLOW_OFFICE_TOKEN before running this script.");
  process.exit(1);
}

const transport = new StdioClientTransport({
  command: "node",
  args: ["dist/index.js"],
  env: {
    FLOW_OFFICE_API_BASE_URL: "http://127.0.0.1:8123/api/",
    FLOW_OFFICE_TOKEN: token,
  },
});

const client = new Client({ name: "smoke-test-client", version: "0.0.1" });
await client.connect(transport);

const tools = await client.listTools();
console.log(
  "TOOLS:",
  tools.tools.map((t) => t.name),
);

const profile = await client.callTool({ name: "get_my_profile", arguments: {} });
console.log("PROFILE RESULT:", JSON.stringify(profile, null, 2));

const clockIn = await client.callTool({ name: "clock_in", arguments: {} });
console.log("CLOCK_IN RESULT:", JSON.stringify(clockIn, null, 2));

const forbidden = await client.callTool({ name: "get_my_leave_requests", arguments: {} });
console.log("FORBIDDEN (no leave scope) RESULT:", JSON.stringify(forbidden, null, 2));

await client.close();
