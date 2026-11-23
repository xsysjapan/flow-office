import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "../../api/client";
import * as userManagementApi from "../../api/userManagement";
import type { GroupType } from "../../api/userManagement";
import { notify } from "../../lib/notify";
import { GroupTypeManagementPage } from "./GroupTypeManagementPage";

vi.mock("../../lib/notify", () => ({ notify: { success: vi.fn() } }));

const organizationType: GroupType = {
  id: 1,
  code: "ORGANIZATION",
  name: "組織",
  display_order: 1,
  status: "active",
  is_system: true,
  membership_limit_type: "unlimited",
  max_memberships_per_user: null,
  requires_primary_membership: false,
  max_primary_memberships: null,
};

const projectType: GroupType = {
  id: 2,
  code: "PROJECT",
  name: "プロジェクト",
  display_order: 2,
  status: "active",
  is_system: false,
  membership_limit_type: "limited",
  max_memberships_per_user: 3,
  requires_primary_membership: true,
  max_primary_memberships: 1,
};

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <GroupTypeManagementPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

function mockGroupTypes(types: GroupType[] = [organizationType, projectType]) {
  vi.spyOn(userManagementApi, "fetchGroupTypes").mockResolvedValue(types);
}

describe("GroupTypeManagementPage", () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    vi.mocked(notify.success).mockClear();
  });

  it("shows the loading state while the group types are fetched", async () => {
    vi.spyOn(userManagementApi, "fetchGroupTypes").mockReturnValue(
      new Promise(() => {}),
    );
    renderPage();

    expect(await screen.findByRole("status")).toHaveTextContent("読み込み中...");
    expect(screen.queryByRole("button", { name: "グループ種別を作成" })).not.toBeInTheDocument();
  });

  it("lists the registered group types with their order, limit and primary setting", async () => {
    mockGroupTypes();
    renderPage();

    expect(
      await screen.findByText(/組織 \(ORGANIZATION\)/),
    ).toHaveTextContent("組織 (ORGANIZATION) / 表示順 1 / 上限 なし / 主所属 任意");
    expect(
      screen.getByText(/プロジェクト \(PROJECT\)/),
    ).toHaveTextContent("プロジェクト (PROJECT) / 表示順 2 / 上限 3 / 主所属 必須");
  });

  it("shows the empty state when no group type is registered", async () => {
    mockGroupTypes([]);
    renderPage();

    expect(
      await screen.findByText("グループ種別はまだありません。"),
    ).toBeInTheDocument();
  });

  it("creates a group type and shows the success toast with the page wording", async () => {
    mockGroupTypes();
    vi.spyOn(userManagementApi, "createGroupType").mockResolvedValue(undefined);
    renderPage();

    await screen.findByText(/組織 \(ORGANIZATION\)/);
    await userEvent.type(screen.getByPlaceholderText("新規グループ種別コード"), "DEPARTMENT");
    await userEvent.type(screen.getByPlaceholderText("グループ種別名"), "部門");
    await userEvent.click(screen.getByRole("button", { name: "グループ種別を作成" }));

    // mutationFnはvariablesに加えてreact-queryのcontextも第2引数で受け取るため、第2引数は問わない
    await waitFor(() =>
      expect(userManagementApi.createGroupType).toHaveBeenCalledWith(
        {
          code: "DEPARTMENT",
          name: "部門",
          display_order: 0,
          membership_limit_type: "unlimited",
          max_memberships_per_user: null,
          requires_primary_membership: false,
          max_primary_memberships: null,
        },
        expect.anything(),
      ),
    );
    await waitFor(() => expect(notify.success).toHaveBeenCalledTimes(1));
    expect(notify.success).toHaveBeenCalledWith("グループ種別を作成しました");
  });

  it("saves the selected group type and shows the success toast with the page wording", async () => {
    mockGroupTypes();
    vi.spyOn(userManagementApi, "updateGroupType").mockResolvedValue(undefined);
    renderPage();

    await screen.findByText(/プロジェクト \(PROJECT\)/);
    await userEvent.selectOptions(screen.getByLabelText("編集対象"), "2");
    // 名称の入力欄は新規作成と編集で2つあるため、編集側(後ろ)を選ぶ
    const editName = screen.getAllByLabelText("名称")[1];
    await userEvent.clear(editName);
    await userEvent.type(editName, "案件");
    await userEvent.click(screen.getByRole("button", { name: "変更を保存" }));

    await waitFor(() =>
      expect(userManagementApi.updateGroupType).toHaveBeenCalledWith(2, {
        name: "案件",
        display_order: 2,
        status: "active",
        membership_limit_type: "limited",
        max_memberships_per_user: 3,
        requires_primary_membership: true,
        max_primary_memberships: 1,
      }),
    );
    await waitFor(() => expect(notify.success).toHaveBeenCalledTimes(1));
    expect(notify.success).toHaveBeenCalledWith("グループ種別を保存しました");
  });

  it("does not show the success toast when creating fails, and shows the error next to the button", async () => {
    mockGroupTypes();
    vi.spyOn(userManagementApi, "createGroupType").mockRejectedValue(
      new ApiError(422, "このコードは既に使われています。"),
    );
    renderPage();

    await screen.findByText(/組織 \(ORGANIZATION\)/);
    await userEvent.type(screen.getByPlaceholderText("新規グループ種別コード"), "ORGANIZATION");
    await userEvent.type(screen.getByPlaceholderText("グループ種別名"), "重複");
    const createButton = screen.getByRole("button", { name: "グループ種別を作成" });
    await userEvent.click(createButton);

    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent("このコードは既に使われています。");
    expect(createButton.parentElement).toContainElement(alert);
    expect(notify.success).not.toHaveBeenCalled();
  });

  it("does not show the success toast when saving fails, and shows the error next to the button", async () => {
    mockGroupTypes();
    vi.spyOn(userManagementApi, "updateGroupType").mockRejectedValue(
      new ApiError(500, "保存に失敗しました。"),
    );
    renderPage();

    await screen.findByText(/プロジェクト \(PROJECT\)/);
    await userEvent.selectOptions(screen.getByLabelText("編集対象"), "2");
    const saveButton = screen.getByRole("button", { name: "変更を保存" });
    await userEvent.click(saveButton);

    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent("保存に失敗しました。");
    expect(saveButton.parentElement).toContainElement(alert);
    expect(notify.success).not.toHaveBeenCalled();
  });

  it("shows the permission error from the API when the user lacks permission (403)", async () => {
    vi.spyOn(userManagementApi, "fetchGroupTypes").mockRejectedValue(
      new ApiError(403, "グループ種別を管理する権限がありません。"),
    );
    renderPage();

    expect(
      await screen.findByRole("alert"),
    ).toHaveTextContent("グループ種別を管理する権限がありません。");
    expect(screen.queryByRole("button", { name: "グループ種別を作成" })).not.toBeInTheDocument();
    expect(notify.success).not.toHaveBeenCalled();
  });
});
