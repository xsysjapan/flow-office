/**
 * flow-office バックエンドAPIへの薄いHTTPクライアント。
 *
 * docs/25-usecases-integrations-mcp.md「MCPサーバーの責務」: MCPサーバーは勤怠計算ロジックを
 * 一切持たず、ユーザーが個人API/MCP連携(UC-I001)で発行したSanctumトークンを使って
 * 既存のLaravel APIを呼び出すだけのクライアントである。
 */

export class FlowOfficeApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly body: unknown,
  ) {
    super(message);
    this.name = "FlowOfficeApiError";
  }
}

export interface FlowOfficeApiClientOptions {
  baseUrl: string;
  token: string;
}

/**
 * ユーザーの個人連携トークン(docs/25 UC-I001で発行)を使ってbackend APIを呼び出す。
 * トークンのスコープ(attendance:self:read等)による403は、MCPサーバー側では判定せず
 * backend側のability検証(EnsureFullAccessOrExplicitAbility、docs/23 UC-D002)にそのまま従う。
 */
export class FlowOfficeApiClient {
  constructor(private readonly options: FlowOfficeApiClientOptions) {}

  async get<T = unknown>(path: string, query?: Record<string, string | number | undefined>): Promise<T> {
    const url = new URL(path.replace(/^\//, ""), this.options.baseUrl.replace(/\/?$/, "/"));
    for (const [key, value] of Object.entries(query ?? {})) {
      if (value !== undefined) {
        url.searchParams.set(key, String(value));
      }
    }
    return this.request<T>(url, { method: "GET" });
  }

  async post<T = unknown>(path: string, body?: unknown, headers?: Record<string, string>): Promise<T> {
    const url = new URL(path.replace(/^\//, ""), this.options.baseUrl.replace(/\/?$/, "/"));
    return this.request<T>(url, {
      method: "POST",
      body: body === undefined ? undefined : JSON.stringify(body),
      headers,
    });
  }

  async delete<T = unknown>(path: string, body?: unknown, headers?: Record<string, string>): Promise<T> {
    const url = new URL(path.replace(/^\//, ""), this.options.baseUrl.replace(/\/?$/, "/"));
    return this.request<T>(url, {
      method: "DELETE",
      body: body === undefined ? undefined : JSON.stringify(body),
      headers,
    });
  }

  async put<T = unknown>(path: string, body?: unknown, headers?: Record<string, string>): Promise<T> {
    const url = new URL(path.replace(/^\//, ""), this.options.baseUrl.replace(/\/?$/, "/"));
    return this.request<T>(url, {
      method: "PUT",
      body: body === undefined ? undefined : JSON.stringify(body),
      headers,
    });
  }

  private async request<T>(
    url: URL,
    init: { method: string; body?: string; headers?: Record<string, string> },
  ): Promise<T> {
    const response = await fetch(url, {
      method: init.method,
      body: init.body,
      headers: {
        Authorization: `Bearer ${this.options.token}`,
        Accept: "application/json",
        "Content-Type": "application/json",
        ...init.headers,
      },
    });

    const text = await response.text();
    const parsed = text.length > 0 ? safeJsonParse(text) : null;

    if (!response.ok) {
      let message = `flow-office API がエラーを返しました(HTTP ${response.status})`;
      if (parsed !== null && typeof parsed === "object" && "message" in parsed) {
        message = String((parsed as { message: unknown }).message);
      }
      throw new FlowOfficeApiError(message, response.status, parsed);
    }

    return parsed as T;
  }
}

function safeJsonParse(text: string): unknown {
  try {
    return JSON.parse(text);
  } catch {
    return text;
  }
}
