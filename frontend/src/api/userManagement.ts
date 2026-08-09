import { apiFetch } from "./client";

export interface GroupType {
  id: number;
  code: string;
  name: string;
  display_order: number;
  status: string;
  is_system: boolean;
  membership_limit_type: "unlimited" | "limited";
  max_memberships_per_user: number | null;
  primary_membership_required: boolean;
  max_primary_memberships: number | null;
}
export interface Membership {
  id: number;
  user_id: string;
  group_id: string;
  membership_kind: string;
  is_primary: boolean;
  user: { id: string; name: string; email: string };
}
export interface GroupFeatureReference {
  id: number;
  code: string;
  name: string;
  status: string;
}
export interface ManagedGroup {
  id: string;
  group_type_id: number;
  name: string;
  code: string;
  status: string;
  parent_group_id: string | null;
  memberships_count: number;
  type: GroupType;
  features: GroupFeatureReference[];
  memberships: Membership[];
  role_assignments: Array<{
    id: string;
    scope_type: string;
    include_descendants: boolean;
    scope_group?: { name: string } | null;
    role?: { name: string } | null;
  }>;
}
export interface ExternalIdentity {
  id: number;
  user_id: string;
  provider: string;
  external_tenant_id: string | null;
  external_subject_id: string;
  external_code: string | null;
  email: string | null;
  status: string;
  user: { id: string; name: string; email: string };
}
export interface FieldAuthority {
  id: number;
  field_key: string;
  authority_type: "LOCAL" | "EXTERNAL_HR";
  provider: string | null;
}
export interface ChangeItem {
  operation: "add" | "remove" | "replace" | "set_primary";
  group_type_id: number;
  from_group_id?: string | null;
  to_group_id?: string | null;
  target_group_id?: string | null;
  is_primary?: boolean;
}
export interface MembershipChangeSet {
  id: string;
  user_id: string;
  effective_at: string;
  source_type: string;
  status: string;
  note: string | null;
  failure_reason?: string | null;
  items: ChangeItem[];
}
export interface ExternalHrPreviewRow {
  user_id: string;
  external_subject_id: string;
  changes: Record<string, string | null>;
  diff: Record<string, { before: unknown; after: unknown }>;
  group_code: string | null;
  effective_at: string;
  is_new: boolean;
}
export interface ExternalHrPreview {
  rows: ExternalHrPreviewRow[];
  summary: { total: number; new: number; changed: number };
}

export const fetchGroupTypes = (): Promise<GroupType[]> =>
  apiFetch("/admin/user-management/group-types");
export const fetchManagedGroups = (): Promise<ManagedGroup[]> =>
  apiFetch("/admin/user-management/groups");
export const fetchExternalIdentities = (): Promise<ExternalIdentity[]> =>
  apiFetch("/admin/user-management/external-identities");
export const fetchFieldAuthorities = (): Promise<FieldAuthority[]> =>
  apiFetch("/admin/user-management/field-authorities");
export const fetchMembershipChangeSets = (): Promise<MembershipChangeSet[]> =>
  apiFetch("/admin/user-management/membership-change-sets");
export const createGroup = (input: {
  group_type_id: number;
  name: string;
  code: string;
  description?: string;
  parent_group_id?: string;
}): Promise<{ id: string }> =>
  apiFetch("/admin/user-management/groups", { method: "POST", body: input });
export const createGroupType = (input: {
  code: string;
  name: string;
  display_order?: number;
  membership_limit_type: "unlimited" | "limited";
  max_memberships_per_user?: number | null;
  primary_membership_required?: boolean;
  max_primary_memberships?: number | null;
}): Promise<void> =>
  apiFetch("/admin/user-management/group-types", {
    method: "POST",
    body: input,
  });
export const updateGroupType = (
  id: number,
  input: Partial<Omit<GroupType, "id" | "code" | "is_system">>,
): Promise<void> =>
  apiFetch(`/admin/user-management/group-types/${id}`, {
    method: "PATCH",
    body: input,
  });
export const updateGroup = (
  id: string,
  input: Partial<
    Pick<ManagedGroup, "name" | "code" | "status" | "parent_group_id">
  >,
): Promise<void> =>
  apiFetch(`/admin/user-management/groups/${id}`, {
    method: "PATCH",
    body: input,
  });
export const addMembership = (input: {
  user_id: string;
  group_id: string;
  membership_kind: string;
  is_primary?: boolean;
}): Promise<void> =>
  apiFetch("/admin/user-management/memberships", {
    method: "POST",
    body: input,
  });
export const removeMembership = (
  userId: string,
  groupId: string,
): Promise<void> =>
  apiFetch(`/admin/user-management/users/${userId}/groups/${groupId}`, {
    method: "DELETE",
  });
export const linkExternalIdentity = (
  userId: string,
  input: {
    provider: string;
    external_tenant_id?: string | null;
    external_subject_id: string;
    external_code?: string | null;
    email?: string | null;
  },
): Promise<void> =>
  apiFetch(`/admin/user-management/users/${userId}/external-identities`, {
    method: "POST",
    body: input,
  });
export const unlinkExternalIdentity = (id: number): Promise<void> =>
  apiFetch(`/admin/user-management/external-identities/${id}`, {
    method: "DELETE",
  });
export const updateFieldAuthority = (
  fieldKey: string,
  authority_type: FieldAuthority["authority_type"],
  provider?: string | null,
): Promise<void> =>
  apiFetch(`/admin/user-management/field-authorities/${fieldKey}`, {
    method: "PUT",
    body: { authority_type, provider },
  });
export const scheduleMembershipChange = (input: {
  user_id: string;
  effective_at: string;
  source_type: string;
  note?: string;
  items: ChangeItem[];
}): Promise<{ id: string }> =>
  apiFetch("/admin/user-management/membership-change-sets", {
    method: "POST",
    body: input,
  });
export const createMembershipChangeDraft = (
  input: Parameters<typeof scheduleMembershipChange>[0],
): Promise<{ id: string }> =>
  apiFetch("/admin/user-management/membership-change-sets/drafts", {
    method: "POST",
    body: input,
  });
export const updateMembershipChange = (
  id: string,
  input: Parameters<typeof scheduleMembershipChange>[0],
): Promise<void> =>
  apiFetch(`/admin/user-management/membership-change-sets/${id}`, {
    method: "PATCH",
    body: input,
  });
export const applyMembershipChange = (id: string): Promise<void> =>
  apiFetch(`/admin/user-management/membership-change-sets/${id}/apply`, {
    method: "POST",
  });
export const scheduleExistingMembershipChange = (id: string): Promise<void> =>
  apiFetch(`/admin/user-management/membership-change-sets/${id}/schedule`, {
    method: "POST",
  });
export const cancelMembershipChange = (id: string): Promise<void> =>
  apiFetch(`/admin/user-management/membership-change-sets/${id}/cancel`, {
    method: "POST",
  });
export const previewExternalHrCsv = (
  file: File,
): Promise<ExternalHrPreview> => {
  const body = new FormData();
  body.append("file", file);
  return apiFetch("/admin/user-management/external-hr/import-preview", {
    method: "POST",
    body,
  });
};
export const applyExternalHrImport = (
  rows: ExternalHrPreviewRow[],
): Promise<{ id: string }> =>
  apiFetch("/admin/user-management/external-hr/import", {
    method: "POST",
    body: { rows },
  });
