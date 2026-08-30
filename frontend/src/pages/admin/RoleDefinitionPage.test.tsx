import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { describe, expect, it, vi } from "vitest";
import * as accessControlApi from "../../api/accessControl";
import type {
  AccessRole,
  Feature,
  Permission,
  RoleAssignment,
} from "../../api/accessControl";
import { RoleDefinitionPage } from "./RoleDefinitionPage";

const permissions: Permission[] = [
  {
    id: 1,
    code: "attendance.view",
    resource: "attendance",
    action: "view",
    description: "勤怠を閲覧する",
    allowed_scope_types: ["global"],
  },
];

const features: Feature[] = [
  {
    id: 1,
    code: "attendance",
    name: "勤怠",
    status: "active",
    display_order: 1,
    is_selectable: true,
    children: [
      {
        id: 11,
        code: "attendance.approve",
        name: "勤怠承認",
        status: "active",
        display_order: 1,
        is_selectable: true,
      },
    ],
  },
];

function makeRoles(): AccessRole[] {
  return [
    {
      id: 1,
      code: "employee",
      name: "一般社員",
      description: null,
      status: "active",
      is_system: true,
      permissions: [],
      features: [],
    },
    {
      id: 2,
      code: "backoffice_staff",
      name: "バックオフィス担当者",
      description: null,
      status: "active",
      is_system: false,
      permissions,
      features: [features[0]],
    },
  ];
}

function renderPage({
  roles = makeRoles(),
  roleAssignments = [] as RoleAssignment[],
  initialPath = "/admin/access/roles",
}: {
  roles?: AccessRole[];
  roleAssignments?: RoleAssignment[];
  initialPath?: string;
} = {}) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  vi.spyOn(accessControlApi, "fetchAccessRoles").mockResolvedValue(roles);
  vi.spyOn(accessControlApi, "fetchPermissions").mockResolvedValue(permissions);
  vi.spyOn(accessControlApi, "fetchFeatures").mockResolvedValue(features);
  vi.spyOn(accessControlApi, "fetchRoleAssignments").mockResolvedValue(
    roleAssignments,
  );

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialPath]}>
        <RoleDefinitionPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe("RoleDefinitionPage", () => {
  it("shows the role list", async () => {
    renderPage();

    expect(await screen.findByText("一般社員")).toBeInTheDocument();
    expect(screen.getByText("バックオフィス担当者")).toBeInTheDocument();
  });

  it("shows the detail (permission/feature editors) when a role row is clicked", async () => {
    renderPage();

    await userEvent.click(
      await screen.findByRole("row", {
        name: /バックオフィス担当者の詳細を開く/,
      }),
    );

    expect(
      await screen.findByRole("heading", { name: "バックオフィス担当者" }),
    ).toBeInTheDocument();
    expect(screen.getByText("Permission")).toBeInTheDocument();
    expect(screen.getByText("Feature構成")).toBeInTheDocument();
  });

  it("saves the feature configuration with the correct feature ids", async () => {
    vi.spyOn(accessControlApi, "updateRoleFeatures").mockResolvedValue(
      undefined,
    );
    renderPage({ initialPath: "/admin/access/roles?roleId=2" });

    await userEvent.click(
      await screen.findByRole("checkbox", { name: "勤怠承認" }),
    );
    await userEvent.click(
      screen.getByRole("button", { name: "Feature構成を保存" }),
    );

    await waitFor(() =>
      expect(accessControlApi.updateRoleFeatures).toHaveBeenCalledWith(
        2,
        expect.arrayContaining([1, 11]),
      ),
    );
  });

  it("shows the reference group count after saving the feature configuration", async () => {
    vi.spyOn(accessControlApi, "updateRoleFeatures").mockResolvedValue(
      undefined,
    );
    renderPage({
      initialPath: "/admin/access/roles?roleId=2",
      roleAssignments: [
        {
          id: "assignment-1",
          subject_type: "group",
          subject_id: "group-1",
          role_id: 2,
          scope_type: "group",
          scope_group_id: "group-1",
          include_descendants: false,
          starts_at: null,
          ends_at: null,
          status: "active",
        },
        {
          id: "assignment-2",
          subject_type: "user",
          subject_id: "user-1",
          role_id: 2,
          scope_type: "self",
          scope_group_id: null,
          include_descendants: false,
          starts_at: null,
          ends_at: null,
          status: "active",
        },
      ],
    });

    await userEvent.click(
      await screen.findByRole("button", { name: "Feature構成を保存" }),
    );

    expect(
      await screen.findByText("このロールを保持しているグループ: 1件"),
    ).toBeInTheDocument();
  });
});
