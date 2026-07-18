import { FlowOfficeApiError } from "./apiClient.js";

/**
 * MCPツールの戻り値を統一する。docs/25-usecases-integrations-mcp.md「MCPサーバーが担当する処理」
 * の「エラーの説明可能な形式への変換」に対応し、backend APIのエラー(422/403/409等)を
 * Claudeが読める説明文に変換する。勤怠ルールの妥当性判定そのものはbackend側の責務であり、
 * ここでは変換のみを行う。
 */
export function jsonResult(data: unknown) {
  return {
    content: [{ type: "text" as const, text: JSON.stringify(data, null, 2) }],
  };
}

export async function callAndReport(fn: () => Promise<unknown>) {
  try {
    const result = await fn();
    return jsonResult(result);
  } catch (error) {
    if (error instanceof FlowOfficeApiError) {
      return {
        isError: true,
        content: [
          {
            type: "text" as const,
            text: `flow-office APIエラー(HTTP ${error.status}): ${error.message}`,
          },
        ],
      };
    }

    return {
      isError: true,
      content: [{ type: "text" as const, text: `予期しないエラーが発生しました: ${(error as Error).message}` }],
    };
  }
}
