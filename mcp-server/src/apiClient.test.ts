import { describe, expect, it, vi, beforeEach, afterEach } from "vitest";
import { FlowOfficeApiClient, FlowOfficeApiError } from "./apiClient.js";

describe("FlowOfficeApiClient", () => {
  const originalFetch = global.fetch;

  afterEach(() => {
    global.fetch = originalFetch;
  });

  it("sends a bearer token and parses a successful JSON response", async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ id: 1, name: "Taro" }), { status: 200 }),
    );
    global.fetch = fetchMock as unknown as typeof fetch;

    const client = new FlowOfficeApiClient({ baseUrl: "http://localhost:8000/api/", token: "abc123" });
    const result = await client.get("/auth/me");

    expect(result).toEqual({ id: 1, name: "Taro" });
    const [, init] = fetchMock.mock.calls[0];
    expect(init.headers.Authorization).toBe("Bearer abc123");
  });

  it("throws FlowOfficeApiError with the backend message on a non-2xx response", async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ message: "本日は既に出勤処理済みです。" }), { status: 422 }),
    );
    global.fetch = fetchMock as unknown as typeof fetch;

    const client = new FlowOfficeApiClient({ baseUrl: "http://localhost:8000/api/", token: "abc123" });

    await expect(client.post("/attendance/clock-in")).rejects.toMatchObject({
      message: "本日は既に出勤処理済みです。",
      status: 422,
    } satisfies Partial<FlowOfficeApiError>);
  });

  it("builds URLs correctly with query parameters", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify([]), { status: 200 }));
    global.fetch = fetchMock as unknown as typeof fetch;

    const client = new FlowOfficeApiClient({ baseUrl: "http://localhost:8000/api/", token: "t" });
    await client.get("/attendance-punches", { from: "2026-07-01", to: "2026-07-31" });

    const [url] = fetchMock.mock.calls[0];
    expect(String(url)).toBe("http://localhost:8000/api/attendance-punches?from=2026-07-01&to=2026-07-31");
  });
});
