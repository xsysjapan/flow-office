import { apiFetch } from "./client";

export interface EffectiveAccess {
  features: string[];
  permissions: string[];
  global_permissions: string[];
}
export interface Feature {
  id: number;
  code: string;
  name: string;
  status: string;
  display_order: number;
  is_selectable: boolean;
  children?: Feature[];
}
export interface Permission {
  id: number;
  code: string;
  resource: string;
  action: string;
  description: string | null;
  allowed_scope_types: RoleAssignment["scope_type"][];
}
export interface AccessRole {
  id: number;
  code: string;
  name: string;
  description: string | null;
  status: string;
  is_system: boolean;
  permissions: Permission[];
  features: Feature[];
}
export interface RoleAssignment {
  id: string;
  subject_type: "user" | "group";
  subject_id: string;
  role_id: number;
  scope_type: "global" | "group" | "self" | "approval_task";
  scope_group_id: string | null;
  include_descendants: boolean;
  starts_at: string | null;
  ends_at: string | null;
  status: string;
  role?: AccessRole;
}
export interface FeatureSuspension {
  id: string;
  user_id: string;
  feature_id: number;
  reason: string;
  starts_at: string | null;
  ends_at: string | null;
  user: { id: string; name: string };
  feature: Feature;
}

export const fetchEffectiveAccess = (): Promise<EffectiveAccess> =>
  apiFetch("/access/me");
export const fetchFeatures = (): Promise<Feature[]> =>
  apiFetch("/admin/access-control/features");
export const fetchPermissions = (): Promise<Permission[]> =>
  apiFetch("/admin/access-control/permissions");
export const fetchAccessRoles = (): Promise<AccessRole[]> =>
  apiFetch("/admin/access-control/roles");
export const fetchRoleAssignments = (): Promise<RoleAssignment[]> =>
  apiFetch("/admin/access-control/role-assignments");
export const fetchFeatureSuspensions = (): Promise<FeatureSuspension[]> =>
  apiFetch("/admin/access-control/feature-suspensions");
export const createRole = (input: {
  code: string;
  name: string;
  description?: string;
}): Promise<void> =>
  apiFetch("/admin/access-control/roles", { method: "POST", body: input });
export const cloneRole = (
  id: number,
  input: { code: string; name: string; description?: string },
): Promise<void> =>
  apiFetch(`/admin/access-control/roles/${id}/clone`, {
    method: "POST",
    body: input,
  });
export const updateRole = (
  id: number,
  input: Partial<Pick<AccessRole, "name" | "description" | "status">>,
): Promise<void> =>
  apiFetch(`/admin/access-control/roles/${id}`, {
    method: "PATCH",
    body: input,
  });
export const createRoleAssignment = (
  input: Omit<RoleAssignment, "id" | "status" | "role">,
): Promise<{ id: string }> =>
  apiFetch("/admin/access-control/role-assignments", {
    method: "POST",
    body: input,
  });
export const removeRoleAssignment = (id: string): Promise<void> =>
  apiFetch(`/admin/access-control/role-assignments/${id}`, {
    method: "DELETE",
  });
export const updateRoleAssignment = (
  id: string,
  input: Partial<
    Pick<
      RoleAssignment,
      | "scope_type"
      | "scope_group_id"
      | "include_descendants"
      | "starts_at"
      | "ends_at"
    >
  >,
): Promise<void> =>
  apiFetch(`/admin/access-control/role-assignments/${id}`, {
    method: "PATCH",
    body: input,
  });
export const suspendUserFeature = (input: {
  user_id: string;
  feature_id: number;
  reason: string;
  starts_at?: string | null;
  ends_at?: string | null;
}): Promise<void> =>
  apiFetch("/admin/access-control/feature-suspensions", {
    method: "POST",
    body: input,
  });
export const removeFeatureSuspension = (id: string): Promise<void> =>
  apiFetch(`/admin/access-control/feature-suspensions/${id}`, {
    method: "DELETE",
  });
export const updateRolePermissions = (
  roleId: number,
  permissionIds: number[],
): Promise<void> =>
  apiFetch(`/admin/access-control/roles/${roleId}/permissions`, {
    method: "PUT",
    body: { permission_ids: permissionIds },
  });
export const updateRoleFeatures = (
  roleId: number,
  featureIds: number[],
): Promise<void> =>
  apiFetch(`/admin/access-control/roles/${roleId}/features`, {
    method: "PUT",
    body: { feature_ids: featureIds },
  });
