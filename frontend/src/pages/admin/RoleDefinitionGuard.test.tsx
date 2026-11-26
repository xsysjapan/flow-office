import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import * as accessControlApi from "../../api/accessControl";
import type { AccessRole } from "../../api/accessControl";
import * as userManagementApi from "../../api/userManagement";
import { renderWithGuard } from "../../test/renderWithGuard";
import { RoleDefinitionPage } from "./RoleDefinitionPage";

vi.mock("../../lib/notify", () => ({ notify: { success: vi.fn() } }));

const roles: AccessRole[] = [
  { id: 1, code: "employee", name: "一般社員", description: null, status: "active", is_system: false, permissions: [], features: [] },
  { id: 2, code: "staff", name: "担当者", description: null, status: "active", is_system: false, permissions: [], features: [] },
];

describe("RoleDefinitionPage の離脱警告", () => {
  it("変更ありでロールを切り替えようとすると確認のDialogが出て、編集を続けると留まる", async () => {
    vi.spyOn(accessControlApi, "fetchAccessRoles").mockResolvedValue(roles);
    vi.spyOn(accessControlApi, "fetchPermissions").mockResolvedValue([]);
    vi.spyOn(accessControlApi, "fetchFeatures").mockResolvedValue([]);
    vi.spyOn(accessControlApi, "fetchRoleAssignments").mockResolvedValue([]);
    vi.spyOn(userManagementApi, "fetchManagedGroups").mockResolvedValue([]);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    const { router } = renderWithGuard(
      <QueryClientProvider client={queryClient}>
        <RoleDefinitionPage />
      </QueryClientProvider>,
      { initialEntries: ["/admin/access/roles?roleId=1"] },
    );
    await screen.findByDisplayValue("一般社員");

    // 変更なしなら切り替えられる
    await userEvent.click(screen.getByRole("row", { name: /担当者の詳細を開く/ }));
    await screen.findByDisplayValue("担当者");
    expect(router.state.location.search).toBe("?roleId=2");

    await userEvent.type(screen.getByLabelText("説明"), "備考");
    await userEvent.click(screen.getByRole("row", { name: /一般社員の詳細を開く/ }));

    expect(await screen.findByText("保存していない変更があります")).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "編集を続ける" }));
    expect(router.state.location.search).toBe("?roleId=2");
    expect(screen.getByLabelText("説明")).toHaveValue("備考");
  });
});
