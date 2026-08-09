import { useMemo, useState } from "react";
import { Badge } from "../../components/Badge/Badge";
import { Button } from "../../components/Button/Button";
import { Card } from "../../components/Card/Card";
import { ErrorMessage } from "../../components/ErrorMessage/ErrorMessage";
import { LoadingState } from "../../components/LoadingState/LoadingState";
import { Input } from "../../components/ui/input";
import { NativeSelect } from "../../components/ui/native-select";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "../../components/ui/table";
import * as access from "../../hooks/useAccessControl";
import * as userManagement from "../../hooks/useUserManagement";
import { useUsers } from "../../hooks/useUsers";
import type { ChangeItem } from "../../api/userManagement";

export function UserManagementAccessPage() {
  const groups = userManagement.useManagedGroups(),
    types = userManagement.useGroupTypes(),
    features = access.useFeatures(),
    permissions = access.usePermissions(),
    roles = access.useAccessRoles(),
    assignments = access.useRoleAssignments(),
    suspensions = access.useFeatureSuspensions(),
    identities = userManagement.useExternalIdentities(),
    authorities = userManagement.useFieldAuthorities(),
    changeSets = userManagement.useMembershipChangeSets(),
    users = useUsers(undefined, 100);
  const createGroup = userManagement.useCreateGroup(),
    createGroupType = userManagement.useCreateGroupType(),
    updateGroupType = userManagement.useUpdateGroupType(),
    createRole = access.useCreateRole(),
    cloneRole = access.useCloneRole(),
    updateRole = access.useUpdateRole(),
    updateGroup = userManagement.useUpdateGroup(),
    addMembership = userManagement.useAddMembership(),
    removeMembership = userManagement.useRemoveMembership(),
    assignFeature = access.useAssignFeatureToGroup(),
    removeFeature = access.useRemoveFeatureFromGroup(),
    createAssignment = access.useCreateRoleAssignment(),
    updateAssignment = access.useUpdateRoleAssignment(),
    removeAssignment = access.useRemoveRoleAssignment(),
    suspendFeature = access.useSuspendUserFeature(),
    removeSuspension = access.useRemoveFeatureSuspension(),
    linkIdentity = userManagement.useLinkExternalIdentity(),
    unlinkIdentity = userManagement.useUnlinkExternalIdentity(),
    updateAuthority = userManagement.useUpdateFieldAuthority(),
    updateRolePermissions = access.useUpdateRolePermissions(),
    scheduleChange = userManagement.useScheduleMembershipChange(),
    createDraft = userManagement.useCreateMembershipChangeDraft(),
    updateChange = userManagement.useUpdateMembershipChange(),
    scheduleExisting = userManagement.useScheduleExistingMembershipChange(),
    applyChange = userManagement.useApplyMembershipChange(),
    cancelChange = userManagement.useCancelMembershipChange(),
    previewCsv = userManagement.usePreviewExternalHrCsv(),
    applyCsv = userManagement.useApplyExternalHrImport();
  const [groupForm, setGroupForm] = useState({
    group_type_id: "",
    name: "",
    code: "",
    parent_group_id: "",
  });
  const [typeForm, setTypeForm] = useState({
    code: "",
    name: "",
    display_order: "0",
    membership_limit_type: "unlimited" as "unlimited" | "limited",
    max_memberships_per_user: "",
    primary_membership_required: false,
    max_primary_memberships: "",
  });
  const [typeEditForm, setTypeEditForm] = useState({
    id: "",
    name: "",
    display_order: "0",
    status: "active",
    membership_limit_type: "unlimited" as "unlimited" | "limited",
    max_memberships_per_user: "",
    primary_membership_required: false,
    max_primary_memberships: "",
    is_system: false,
  });
  const [newRole, setNewRole] = useState({
    code: "",
    name: "",
    description: "",
  });
  const [roleEditForm, setRoleEditForm] = useState({
    id: "",
    name: "",
    description: "",
    status: "active",
    is_system: false,
  });
  const [membership, setMembership] = useState({
    user_id: "",
    group_id: "",
    membership_kind: "member",
  });
  const [featureForm, setFeatureForm] = useState({
    groupId: "",
    featureId: "",
  });
  const [roleForm, setRoleForm] = useState({
    subject_type: "user" as "user" | "group",
    subject_id: "",
    role_id: "",
    scope_type: "" as "" | "global" | "group" | "self" | "approval_task",
    scope_group_id: "",
    include_descendants: false,
    starts_at: "",
    ends_at: "",
  });
  const [editingAssignmentId, setEditingAssignmentId] = useState("");
  const [suspensionForm, setSuspensionForm] = useState({
    user_id: "",
    feature_id: "",
    reason: "",
    starts_at: "",
    ends_at: "",
  });
  const [identityForm, setIdentityForm] = useState({
    user_id: "",
    provider: "MICROSOFT_ENTRA",
    external_subject_id: "",
    external_tenant_id: "",
    external_code: "",
    email: "",
  });
  const [selectedRole, setSelectedRole] = useState("");
  const [selectedPermissions, setSelectedPermissions] = useState<number[]>([]);
  const [changeForm, setChangeForm] = useState({
    user_id: "",
    effective_at: "",
    operation: "add" as "add" | "remove" | "replace" | "set_primary",
    from_group_id: "",
    to_group_id: "",
    note: "",
  });
  const [changeItems, setChangeItems] = useState<ChangeItem[]>([]);
  const [editingChangeSet, setEditingChangeSet] = useState("");
  const [csvFile, setCsvFile] = useState<File | null>(null);
  const [groupTypeFilter, setGroupTypeFilter] = useState("");
  const queries = [
    groups,
    types,
    features,
    permissions,
    roles,
    assignments,
    suspensions,
    identities,
    authorities,
    changeSets,
    users,
  ];
  const mutationError = [
    createGroup,
    createGroupType,
    updateGroupType,
    createRole,
    cloneRole,
    updateRole,
    updateGroup,
    addMembership,
    removeMembership,
    assignFeature,
    removeFeature,
    createAssignment,
    updateAssignment,
    removeAssignment,
    suspendFeature,
    removeSuspension,
    linkIdentity,
    unlinkIdentity,
    updateAuthority,
    updateRolePermissions,
    scheduleChange,
    createDraft,
    updateChange,
    scheduleExisting,
    applyChange,
    cancelChange,
    previewCsv,
    applyCsv,
  ].find((mutation) => mutation.error)?.error;
  const selectedFrom = useMemo(
    () => groups.data?.find((g) => g.id === changeForm.from_group_id),
    [groups.data, changeForm.from_group_id],
  );
  const selectedTo = useMemo(
    () => groups.data?.find((g) => g.id === changeForm.to_group_id),
    [groups.data, changeForm.to_group_id],
  );
  const permissionGroups = useMemo(
    () =>
      Object.entries(
        (permissions.data ?? []).reduce<
          Record<string, typeof permissions.data>
        >((result, permission) => {
          (result[permission.resource] ??= []).push(permission);
          return result;
        }, {}),
      ),
    [permissions.data],
  );
  const allowedRoleScopes = useMemo(
    () =>
      Array.from(
        new Set(
          roles.data
            ?.find((role) => role.id === Number(roleForm.role_id))
            ?.permissions.flatMap(
              (permission) => permission.allowed_scope_types,
            ) ?? [],
        ),
      ),
    [roles.data, roleForm.role_id],
  );
  const visibleGroups = useMemo(
    () =>
      groups.data?.filter(
        (group) =>
          !groupTypeFilter || String(group.group_type_id) === groupTypeFilter,
      ),
    [groups.data, groupTypeFilter],
  );
  const appendChangeItem = () => {
    const base = selectedTo ?? selectedFrom;
    if (!base) return;
    setChangeItems((items) => [
      ...items,
      {
        operation: changeForm.operation,
        group_type_id: base.group_type_id,
        from_group_id: selectedFrom?.id ?? null,
        to_group_id: selectedTo?.id ?? null,
        target_group_id:
          changeForm.operation === "remove" ||
          changeForm.operation === "set_primary"
            ? selectedFrom?.id
            : selectedTo?.id,
        is_primary: changeForm.operation === "set_primary",
      },
    ]);
    setChangeForm({ ...changeForm, from_group_id: "", to_group_id: "" });
  };
  if (queries.some((x) => x.isLoading)) return <LoadingState />;
  const error = queries.find((x) => x.error)?.error;
  if (error)
    return (
      <ErrorMessage
        error={error}
        fallback="ユーザー・グループ・アクセス管理設定の取得に失敗しました。"
      />
    );

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">ユーザー・グループ・アクセス管理</h1>
        <p className="text-sm text-muted-foreground">
          ユーザー管理を中心に、所属グループと副次的な利用機能・権限を一元管理します。
        </p>
      </div>
      {mutationError && <ErrorMessage error={mutationError} />}
      <Card title="グループ管理">
        <div className="mb-4 grid gap-2 rounded border p-3 md:grid-cols-4">
          <Input
            placeholder="新規GroupTypeコード"
            value={typeForm.code}
            onChange={(e) => setTypeForm({ ...typeForm, code: e.target.value })}
          />
          <Input
            placeholder="GroupType名"
            value={typeForm.name}
            onChange={(e) => setTypeForm({ ...typeForm, name: e.target.value })}
          />
          <Input
            aria-label="新規GroupType表示順"
            type="number"
            min="0"
            placeholder="表示順"
            value={typeForm.display_order}
            onChange={(e) =>
              setTypeForm({ ...typeForm, display_order: e.target.value })
            }
          />
          <NativeSelect
            value={typeForm.membership_limit_type}
            onChange={(e) =>
              setTypeForm({
                ...typeForm,
                membership_limit_type: e.target.value as
                  "unlimited" | "limited",
              })
            }
          >
            <option value="unlimited">複数可</option>
            <option value="limited">上限あり</option>
          </NativeSelect>
          <Input
            type="number"
            placeholder="所属上限"
            value={typeForm.max_memberships_per_user}
            onChange={(e) =>
              setTypeForm({
                ...typeForm,
                max_memberships_per_user: e.target.value,
              })
            }
          />
          <label className="flex items-center gap-2">
            <input
              type="checkbox"
              checked={typeForm.primary_membership_required}
              onChange={(e) =>
                setTypeForm({
                  ...typeForm,
                  primary_membership_required: e.target.checked,
                })
              }
            />
            主所属必須
          </label>
          <Input
            type="number"
            placeholder="主所属上限"
            value={typeForm.max_primary_memberships}
            onChange={(e) =>
              setTypeForm({
                ...typeForm,
                max_primary_memberships: e.target.value,
              })
            }
          />
          <Button
            disabled={!typeForm.code || !typeForm.name}
            isLoading={createGroupType.isPending}
            onClick={() =>
              createGroupType.mutate({
                code: typeForm.code,
                name: typeForm.name,
                display_order: Number(typeForm.display_order) || 0,
                membership_limit_type: typeForm.membership_limit_type,
                max_memberships_per_user: typeForm.max_memberships_per_user
                  ? Number(typeForm.max_memberships_per_user)
                  : null,
                primary_membership_required:
                  typeForm.primary_membership_required,
                max_primary_memberships: typeForm.max_primary_memberships
                  ? Number(typeForm.max_primary_memberships)
                  : null,
              })
            }
          >
            GroupType追加
          </Button>
        </div>
        <div className="mb-4 flex flex-wrap gap-2">
          {types.data?.map((t) => (
            <span
              key={t.id}
              className="inline-flex items-center gap-2 rounded border p-2 text-sm"
            >
              {t.name} ({t.code}) / 表示順 {t.display_order} / 上限{" "}
              {t.max_memberships_per_user ?? "なし"} / 主所属{" "}
              {t.primary_membership_required ? "必須" : "任意"}
            </span>
          ))}
        </div>
        <div className="mb-4 grid gap-2 rounded border p-3 md:grid-cols-4">
          <NativeSelect
            aria-label="編集するGroupType"
            value={typeEditForm.id}
            onChange={(e) => {
              const t = types.data?.find(
                (item) => item.id === Number(e.target.value),
              );
              if (t)
                setTypeEditForm({
                  id: String(t.id),
                  name: t.name,
                  display_order: String(t.display_order),
                  status: t.status,
                  membership_limit_type: t.membership_limit_type,
                  max_memberships_per_user:
                    t.max_memberships_per_user === null
                      ? ""
                      : String(t.max_memberships_per_user),
                  primary_membership_required: t.primary_membership_required,
                  max_primary_memberships:
                    t.max_primary_memberships === null
                      ? ""
                      : String(t.max_primary_memberships),
                  is_system: t.is_system,
                });
            }}
          >
            <option value="">編集するGroupType</option>
            {types.data?.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name}
              </option>
            ))}
          </NativeSelect>
          <Input
            aria-label="GroupType名を編集"
            placeholder="名称"
            value={typeEditForm.name}
            onChange={(e) =>
              setTypeEditForm({ ...typeEditForm, name: e.target.value })
            }
          />
          <Input
            aria-label="GroupType表示順を編集"
            type="number"
            min="0"
            placeholder="表示順"
            value={typeEditForm.display_order}
            onChange={(e) =>
              setTypeEditForm({
                ...typeEditForm,
                display_order: e.target.value,
              })
            }
          />
          <NativeSelect
            aria-label="GroupType状態"
            disabled={typeEditForm.is_system}
            value={typeEditForm.status}
            onChange={(e) =>
              setTypeEditForm({ ...typeEditForm, status: e.target.value })
            }
          >
            <option value="active">有効</option>
            <option value="inactive">廃止</option>
          </NativeSelect>
          <NativeSelect
            aria-label="所属数制約"
            disabled={typeEditForm.is_system}
            value={typeEditForm.membership_limit_type}
            onChange={(e) =>
              setTypeEditForm({
                ...typeEditForm,
                membership_limit_type: e.target.value as
                  "unlimited" | "limited",
              })
            }
          >
            <option value="unlimited">複数可</option>
            <option value="limited">上限あり</option>
          </NativeSelect>
          <Input
            aria-label="所属上限を編集"
            disabled={typeEditForm.is_system}
            type="number"
            placeholder="所属上限"
            value={typeEditForm.max_memberships_per_user}
            onChange={(e) =>
              setTypeEditForm({
                ...typeEditForm,
                max_memberships_per_user: e.target.value,
              })
            }
          />
          <label className="flex items-center gap-2">
            <input
              type="checkbox"
              disabled={typeEditForm.is_system}
              checked={typeEditForm.primary_membership_required}
              onChange={(e) =>
                setTypeEditForm({
                  ...typeEditForm,
                  primary_membership_required: e.target.checked,
                })
              }
            />
            主所属必須
          </label>
          <Input
            aria-label="主所属上限を編集"
            disabled={typeEditForm.is_system}
            type="number"
            placeholder="主所属上限"
            value={typeEditForm.max_primary_memberships}
            onChange={(e) =>
              setTypeEditForm({
                ...typeEditForm,
                max_primary_memberships: e.target.value,
              })
            }
          />
          <Button
            disabled={!typeEditForm.id || !typeEditForm.name}
            isLoading={updateGroupType.isPending}
            onClick={() =>
              updateGroupType.mutate({
                id: Number(typeEditForm.id),
                input: {
                  name: typeEditForm.name,
                  display_order: Number(typeEditForm.display_order) || 0,
                  status: typeEditForm.status,
                  membership_limit_type: typeEditForm.membership_limit_type,
                  max_memberships_per_user:
                    typeEditForm.max_memberships_per_user
                      ? Number(typeEditForm.max_memberships_per_user)
                      : null,
                  primary_membership_required:
                    typeEditForm.primary_membership_required,
                  max_primary_memberships: typeEditForm.max_primary_memberships
                    ? Number(typeEditForm.max_primary_memberships)
                    : null,
                },
              })
            }
          >
            変更を保存
          </Button>
        </div>
        <div className="mb-4 grid gap-2 md:grid-cols-4">
          <NativeSelect
            aria-label="グループ種別"
            value={groupForm.group_type_id}
            onChange={(e) =>
              setGroupForm({ ...groupForm, group_type_id: e.target.value })
            }
          >
            <option value="">種別</option>
            {types.data?.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name}
              </option>
            ))}
          </NativeSelect>
          <Input
            placeholder="グループ名"
            value={groupForm.name}
            onChange={(e) =>
              setGroupForm({ ...groupForm, name: e.target.value })
            }
          />
          <Input
            placeholder="コード"
            value={groupForm.code}
            onChange={(e) =>
              setGroupForm({ ...groupForm, code: e.target.value })
            }
          />
          <NativeSelect
            aria-label="親グループ"
            value={groupForm.parent_group_id}
            onChange={(e) =>
              setGroupForm({ ...groupForm, parent_group_id: e.target.value })
            }
          >
            <option value="">親なし</option>
            {groups.data
              ?.filter(
                (g) =>
                  String(g.group_type_id) === groupForm.group_type_id &&
                  g.status === "active",
              )
              .map((g) => (
                <option key={g.id} value={g.id}>
                  {g.name}
                </option>
              ))}
          </NativeSelect>
          <Button
            disabled={
              !groupForm.group_type_id || !groupForm.name || !groupForm.code
            }
            isLoading={createGroup.isPending}
            onClick={() =>
              createGroup.mutate(
                {
                  group_type_id: Number(groupForm.group_type_id),
                  name: groupForm.name,
                  code: groupForm.code,
                  parent_group_id: groupForm.parent_group_id || undefined,
                },
                {
                  onSuccess: () =>
                    setGroupForm({
                      group_type_id: "",
                      name: "",
                      code: "",
                      parent_group_id: "",
                    }),
                },
              )
            }
          >
            追加
          </Button>
        </div>
        <NativeSelect
          aria-label="表示するGroupType"
          className="mb-3"
          value={groupTypeFilter}
          onChange={(event) => setGroupTypeFilter(event.target.value)}
        >
          <option value="">全GroupType</option>
          {types.data?.map((type) => (
            <option key={type.id} value={type.id}>
              {type.name}
            </option>
          ))}
        </NativeSelect>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>種別</TableHead>
              <TableHead>名称・親</TableHead>
              <TableHead>メンバー</TableHead>
              <TableHead>Feature</TableHead>
              <TableHead>Role・管理スコープ</TableHead>
              <TableHead>状態</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {visibleGroups?.map((g) => (
              <TableRow key={g.id}>
                <TableCell>{g.type.name}</TableCell>
                <TableCell>
                  {g.name}
                  <div className="text-xs text-muted-foreground">{g.code}</div>
                  <NativeSelect
                    aria-label={`${g.name}の親グループ`}
                    value={g.parent_group_id ?? ""}
                    onChange={(e) =>
                      updateGroup.mutate({
                        id: g.id,
                        input: { parent_group_id: e.target.value || null },
                      })
                    }
                  >
                    <option value="">親なし</option>
                    {groups.data
                      ?.filter(
                        (parent) =>
                          parent.id !== g.id &&
                          parent.group_type_id === g.group_type_id &&
                          parent.status === "active",
                      )
                      .map((parent) => (
                        <option key={parent.id} value={parent.id}>
                          {parent.name}
                        </option>
                      ))}
                  </NativeSelect>
                </TableCell>
                <TableCell>
                  {g.memberships.map((m) => (
                    <span
                      className="mr-2 inline-flex items-center gap-1"
                      key={m.id}
                    >
                      {m.user.name}
                      <Button
                        size="sm"
                        variant="secondary"
                        onClick={() =>
                          removeMembership.mutate({
                            userId: m.user_id,
                            groupId: g.id,
                          })
                        }
                      >
                        解除
                      </Button>
                    </span>
                  ))}
                </TableCell>
                <TableCell>
                  <span className="flex flex-wrap gap-1">
                    {g.features.map((f) => (
                      <span
                        key={f.id}
                        className="inline-flex items-center gap-1"
                      >
                        <Badge tone="info">{f.name}</Badge>
                        <Button
                          size="sm"
                          variant="secondary"
                          onClick={() =>
                            removeFeature.mutate({
                              groupId: g.id,
                              featureId: f.id,
                            })
                          }
                        >
                          ×
                        </Button>
                      </span>
                    ))}
                  </span>
                </TableCell>
                <TableCell>
                  {g.role_assignments.map((assignment) => (
                    <div className="text-xs" key={assignment.id}>
                      {assignment.role?.name ?? "-"} / {assignment.scope_type}
                      {assignment.scope_group?.name
                        ? ` (${assignment.scope_group.name})`
                        : ""}
                      {assignment.include_descendants ? "・配下含む" : ""}
                    </div>
                  ))}
                  <a
                    className="text-xs text-primary hover:underline"
                    href={`/admin/audit-log?aggregate_uuid=${g.id}`}
                  >
                    変更履歴
                  </a>
                </TableCell>
                <TableCell>
                  <span className="flex items-center gap-2">
                    <Badge tone={g.status === "active" ? "success" : "neutral"}>
                      {g.status}
                    </Badge>
                    {g.status === "active" && (
                      <Button
                        size="sm"
                        variant="danger"
                        onClick={() =>
                          updateGroup.mutate({
                            id: g.id,
                            input: { status: "inactive" },
                          })
                        }
                      >
                        無効化
                      </Button>
                    )}
                  </span>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Card>

      <Card title="所属・Feature割当">
        <div className="grid gap-2 md:grid-cols-4">
          <UserSelect
            value={membership.user_id}
            onChange={(v) => setMembership({ ...membership, user_id: v })}
            users={users.data?.data}
          />
          <GroupSelect
            value={membership.group_id}
            onChange={(v) => setMembership({ ...membership, group_id: v })}
            groups={groups.data}
          />
          <NativeSelect
            value={membership.membership_kind}
            onChange={(e) =>
              setMembership({ ...membership, membership_kind: e.target.value })
            }
          >
            {["primary", "secondary", "member", "temporary", "observer"].map(
              (v) => (
                <option key={v}>{v}</option>
              ),
            )}
          </NativeSelect>
          <Button
            disabled={!membership.user_id || !membership.group_id}
            isLoading={addMembership.isPending}
            onClick={() =>
              addMembership.mutate({
                ...membership,
                is_primary: membership.membership_kind === "primary",
              })
            }
          >
            所属を追加
          </Button>
        </div>
        <div className="mt-3 grid gap-2 md:grid-cols-3">
          <GroupSelect
            value={featureForm.groupId}
            onChange={(v) => setFeatureForm({ ...featureForm, groupId: v })}
            groups={groups.data}
          />
          <FeatureSelect
            value={featureForm.featureId}
            onChange={(v) => setFeatureForm({ ...featureForm, featureId: v })}
            features={features.data}
          />
          <Button
            disabled={!featureForm.groupId || !featureForm.featureId}
            isLoading={assignFeature.isPending}
            onClick={async () => {
              const selected = flattenFeatures(features.data).find(
                (feature) => feature.id === Number(featureForm.featureId),
              );
              const assigned = new Set(
                groups.data
                  ?.find((group) => group.id === featureForm.groupId)
                  ?.features.map((feature) => feature.id) ?? [],
              );
              const featureIds = [
                selected?.id,
                ...(selected?.children?.map((child) => child.id) ?? []),
              ].filter(
                (id): id is number => id !== undefined && !assigned.has(id),
              );
              for (const featureId of featureIds)
                await assignFeature.mutateAsync({
                  groupId: featureForm.groupId,
                  featureId,
                });
            }}
          >
            Featureを割当
          </Button>
        </div>
      </Card>

      <Card title="Role・Permission">
        <div className="mb-4 grid gap-2 rounded border p-3 md:grid-cols-5">
          <Input
            placeholder="新規Roleコード"
            value={newRole.code}
            onChange={(e) => setNewRole({ ...newRole, code: e.target.value })}
          />
          <Input
            placeholder="Role名"
            value={newRole.name}
            onChange={(e) => setNewRole({ ...newRole, name: e.target.value })}
          />
          <Input
            placeholder="Role説明（任意）"
            value={newRole.description}
            onChange={(e) =>
              setNewRole({ ...newRole, description: e.target.value })
            }
          />
          <Button
            disabled={!newRole.code || !newRole.name}
            isLoading={createRole.isPending}
            onClick={() =>
              createRole.mutate({
                code: newRole.code,
                name: newRole.name,
                description: newRole.description || undefined,
              })
            }
          >
            Role追加
          </Button>
          <Button
            variant="secondary"
            disabled={!roleEditForm.id || !newRole.code || !newRole.name}
            isLoading={cloneRole.isPending}
            onClick={() =>
              cloneRole.mutate({
                id: Number(roleEditForm.id),
                input: {
                  code: newRole.code,
                  name: newRole.name,
                  description: newRole.description || undefined,
                },
              })
            }
          >
            選択Roleを複製
          </Button>
        </div>
        <div className="mb-4 flex flex-wrap gap-2">
          {roles.data?.map((role) => (
            <span
              key={role.id}
              className="inline-flex items-center gap-2 rounded border p-2 text-sm"
            >
              {role.name} ({role.code}) / {role.status}
            </span>
          ))}
        </div>
        <div className="mb-4 grid gap-2 rounded border p-3 md:grid-cols-4">
          <NativeSelect
            aria-label="編集するRole"
            value={roleEditForm.id}
            onChange={(e) => {
              const role = roles.data?.find(
                (item) => item.id === Number(e.target.value),
              );
              if (role)
                setRoleEditForm({
                  id: String(role.id),
                  name: role.name,
                  description: role.description ?? "",
                  status: role.status,
                  is_system: role.is_system,
                });
            }}
          >
            <option value="">編集するRole</option>
            {roles.data?.map((role) => (
              <option key={role.id} value={role.id}>
                {role.name}
              </option>
            ))}
          </NativeSelect>
          <Input
            aria-label="Role名を編集"
            placeholder="Role名"
            value={roleEditForm.name}
            onChange={(e) =>
              setRoleEditForm({ ...roleEditForm, name: e.target.value })
            }
          />
          <Input
            aria-label="Role説明を編集"
            placeholder="説明"
            value={roleEditForm.description}
            onChange={(e) =>
              setRoleEditForm({ ...roleEditForm, description: e.target.value })
            }
          />
          <NativeSelect
            aria-label="Role状態"
            disabled={roleEditForm.is_system}
            value={roleEditForm.status}
            onChange={(e) =>
              setRoleEditForm({ ...roleEditForm, status: e.target.value })
            }
          >
            <option value="active">有効</option>
            <option value="inactive">廃止</option>
          </NativeSelect>
          <Button
            disabled={!roleEditForm.id || !roleEditForm.name}
            isLoading={updateRole.isPending}
            onClick={() =>
              updateRole.mutate({
                id: Number(roleEditForm.id),
                input: {
                  name: roleEditForm.name,
                  description: roleEditForm.description || null,
                  status: roleEditForm.status,
                },
              })
            }
          >
            Role変更を保存
          </Button>
        </div>
        <div className="grid gap-2 md:grid-cols-4">
          <NativeSelect
            disabled={Boolean(editingAssignmentId)}
            value={roleForm.subject_type}
            onChange={(e) =>
              setRoleForm({
                ...roleForm,
                subject_type: e.target.value as "user" | "group",
                subject_id: "",
              })
            }
          >
            <option value="user">ユーザー</option>
            <option value="group">グループ</option>
          </NativeSelect>
          {roleForm.subject_type === "user" ? (
            <UserSelect
              value={roleForm.subject_id}
              onChange={(v) => setRoleForm({ ...roleForm, subject_id: v })}
              users={users.data?.data}
              disabled={Boolean(editingAssignmentId)}
            />
          ) : (
            <GroupSelect
              value={roleForm.subject_id}
              onChange={(v) => setRoleForm({ ...roleForm, subject_id: v })}
              groups={groups.data}
              disabled={Boolean(editingAssignmentId)}
            />
          )}
          <NativeSelect
            disabled={Boolean(editingAssignmentId)}
            value={roleForm.role_id}
            onChange={(e) =>
              setRoleForm({
                ...roleForm,
                role_id: e.target.value,
                scope_type: "",
                scope_group_id: "",
              })
            }
          >
            <option value="">Role</option>
            {roles.data?.map((r) => (
              <option key={r.id} value={r.id}>
                {r.name}
              </option>
            ))}
          </NativeSelect>
          <NativeSelect
            value={roleForm.scope_type}
            onChange={(e) =>
              setRoleForm({
                ...roleForm,
                scope_type: e.target.value as typeof roleForm.scope_type,
                scope_group_id: "",
              })
            }
          >
            <option value="">対象範囲を明示選択</option>
            {allowedRoleScopes.map((scope) => (
              <option key={scope} value={scope}>
                {scope === "global"
                  ? "全社"
                  : scope === "group"
                    ? "グループ"
                    : scope === "self"
                      ? "本人"
                      : "担当承認タスク"}
              </option>
            ))}
          </NativeSelect>
          {roleForm.scope_type === "group" && (
            <GroupSelect
              value={roleForm.scope_group_id}
              onChange={(v) => setRoleForm({ ...roleForm, scope_group_id: v })}
              groups={groups.data}
            />
          )}
          <label className="flex items-center gap-2">
            <input
              type="checkbox"
              disabled={roleForm.scope_type !== "group"}
              checked={roleForm.include_descendants}
              onChange={(e) =>
                setRoleForm({
                  ...roleForm,
                  include_descendants: e.target.checked,
                })
              }
            />
            配下を含む
          </label>
          <Input
            aria-label="Role有効開始日時"
            type="datetime-local"
            value={roleForm.starts_at}
            onChange={(e) =>
              setRoleForm({ ...roleForm, starts_at: e.target.value })
            }
          />
          <Input
            aria-label="Role有効終了日時"
            type="datetime-local"
            value={roleForm.ends_at}
            onChange={(e) =>
              setRoleForm({ ...roleForm, ends_at: e.target.value })
            }
          />
          <div className="rounded bg-muted p-2 text-sm">
            {roleForm.scope_type
              ? `${roleForm.subject_type === "user" ? "選択ユーザー" : "選択グループ"}に、${roles.data?.find((r) => r.id === Number(roleForm.role_id))?.name ?? "Role"}を${roleForm.scope_type === "global" ? "全社" : roleForm.scope_type === "group" ? "選択グループ" + (roleForm.include_descendants ? "と配下" : "のみ") : roleForm.scope_type === "self" ? "本人" : "担当承認タスク"}の範囲で付与します。`
              : "対象範囲を選択してください。"}
          </div>
          <Button
            disabled={
              !roleForm.subject_id ||
              !roleForm.role_id ||
              !roleForm.scope_type ||
              (roleForm.scope_type === "group" && !roleForm.scope_group_id)
            }
            isLoading={
              editingAssignmentId
                ? updateAssignment.isPending
                : createAssignment.isPending
            }
            onClick={() => {
              const scope = {
                scope_type: roleForm.scope_type as Exclude<
                  typeof roleForm.scope_type,
                  ""
                >,
                scope_group_id:
                  roleForm.scope_type === "group"
                    ? roleForm.scope_group_id
                    : null,
                include_descendants: roleForm.include_descendants,
                starts_at: roleForm.starts_at
                  ? new Date(roleForm.starts_at).toISOString()
                  : null,
                ends_at: roleForm.ends_at
                  ? new Date(roleForm.ends_at).toISOString()
                  : null,
              };
              if (editingAssignmentId)
                updateAssignment.mutate(
                  { id: editingAssignmentId, input: scope },
                  { onSuccess: () => setEditingAssignmentId("") },
                );
              else
                createAssignment.mutate({
                  subject_type: roleForm.subject_type,
                  subject_id: roleForm.subject_id,
                  role_id: Number(roleForm.role_id),
                  ...scope,
                });
            }}
          >
            {editingAssignmentId ? "Role割当を更新" : "Roleを割当"}
          </Button>
          {editingAssignmentId && (
            <Button
              variant="secondary"
              onClick={() => setEditingAssignmentId("")}
            >
              編集を取消
            </Button>
          )}
        </div>
        <div className="mt-3 flex flex-wrap gap-2">
          {assignments.data
            ?.filter((a) => a.status === "active")
            .map((a) => (
              <span
                className="inline-flex items-center gap-2 rounded border p-2 text-sm"
                key={a.id}
              >
                {a.role?.name} / {a.subject_type} / {a.scope_type}
                <Button
                  size="sm"
                  variant="secondary"
                  onClick={() => {
                    setEditingAssignmentId(a.id);
                    setRoleForm({
                      subject_type: a.subject_type,
                      subject_id: a.subject_id,
                      role_id: String(a.role_id),
                      scope_type: a.scope_type,
                      scope_group_id: a.scope_group_id ?? "",
                      include_descendants: a.include_descendants,
                      starts_at: toDateTimeLocal(a.starts_at),
                      ends_at: toDateTimeLocal(a.ends_at),
                    });
                  }}
                >
                  編集
                </Button>
                <Button
                  size="sm"
                  variant="secondary"
                  onClick={() =>
                    updateAssignment.mutate({
                      id: a.id,
                      input: { ends_at: new Date().toISOString() },
                    })
                  }
                >
                  今すぐ終了
                </Button>
                <Button
                  size="sm"
                  variant="danger"
                  onClick={() => removeAssignment.mutate(a.id)}
                >
                  解除
                </Button>
              </span>
            ))}
        </div>
        <div className="mt-5 border-t pt-4">
          <div className="mb-2 flex gap-2">
            <NativeSelect
              value={selectedRole}
              onChange={(e) => {
                const id = e.target.value;
                setSelectedRole(id);
                setSelectedPermissions(
                  roles.data
                    ?.find((r) => r.id === Number(id))
                    ?.permissions.map((p) => p.id) ?? [],
                );
              }}
            >
              <option value="">Permissionを編集するRole</option>
              {roles.data?.map((r) => (
                <option key={r.id} value={r.id}>
                  {r.name}
                </option>
              ))}
            </NativeSelect>
            <Button
              disabled={!selectedRole}
              onClick={() =>
                updateRolePermissions.mutate({
                  roleId: Number(selectedRole),
                  permissionIds: selectedPermissions,
                })
              }
            >
              保存
            </Button>
          </div>
          <div className="grid gap-3 md:grid-cols-3">
            {permissionGroups.map(([resource, items]) => (
              <fieldset className="rounded border p-3" key={resource}>
                <legend className="px-1 text-sm font-medium">{resource}</legend>
                {items?.map((p) => (
                  <label className="flex gap-2 text-sm" key={p.id}>
                    <input
                      type="checkbox"
                      checked={selectedPermissions.includes(p.id)}
                      onChange={(e) =>
                        setSelectedPermissions(
                          e.target.checked
                            ? [...selectedPermissions, p.id]
                            : selectedPermissions.filter((id) => id !== p.id),
                        )
                      }
                    />
                    <span>
                      {p.action}
                      {p.description && (
                        <span className="block text-xs text-muted-foreground">
                          {p.description}
                        </span>
                      )}
                    </span>
                  </label>
                ))}
              </fieldset>
            ))}
          </div>
        </div>
      </Card>

      <Card title="個別Feature停止">
        <div className="grid gap-2 md:grid-cols-6">
          <UserSelect
            value={suspensionForm.user_id}
            onChange={(v) =>
              setSuspensionForm({ ...suspensionForm, user_id: v })
            }
            users={users.data?.data}
          />
          <FeatureSelect
            value={suspensionForm.feature_id}
            onChange={(v) =>
              setSuspensionForm({ ...suspensionForm, feature_id: v })
            }
            features={features.data}
          />
          <Input
            placeholder="停止理由"
            value={suspensionForm.reason}
            onChange={(e) =>
              setSuspensionForm({ ...suspensionForm, reason: e.target.value })
            }
          />
          <Input
            aria-label="停止開始日時"
            type="datetime-local"
            value={suspensionForm.starts_at}
            onChange={(e) =>
              setSuspensionForm({
                ...suspensionForm,
                starts_at: e.target.value,
              })
            }
          />
          <Input
            aria-label="停止終了日時"
            type="datetime-local"
            value={suspensionForm.ends_at}
            onChange={(e) =>
              setSuspensionForm({ ...suspensionForm, ends_at: e.target.value })
            }
          />
          <Button
            disabled={
              !suspensionForm.user_id ||
              !suspensionForm.feature_id ||
              !suspensionForm.reason
            }
            onClick={() =>
              suspendFeature.mutate({
                user_id: suspensionForm.user_id,
                feature_id: Number(suspensionForm.feature_id),
                reason: suspensionForm.reason,
                starts_at: suspensionForm.starts_at
                  ? new Date(suspensionForm.starts_at).toISOString()
                  : null,
                ends_at: suspensionForm.ends_at
                  ? new Date(suspensionForm.ends_at).toISOString()
                  : null,
              })
            }
          >
            停止
          </Button>
        </div>
        <div className="mt-3 flex flex-wrap gap-2">
          {suspensions.data?.map((s) => (
            <span
              className="inline-flex items-center gap-2 rounded border p-2 text-sm"
              key={s.id}
            >
              {s.user.name}: {s.feature.name} ({s.reason})
              <Button
                size="sm"
                variant="secondary"
                onClick={() => removeSuspension.mutate(s.id)}
              >
                解除
              </Button>
            </span>
          ))}
        </div>
      </Card>

      <Card title="外部ID・項目管理責任">
        <div className="grid gap-2 md:grid-cols-7">
          <UserSelect
            value={identityForm.user_id}
            onChange={(v) => setIdentityForm({ ...identityForm, user_id: v })}
            users={users.data?.data}
          />
          <Input
            placeholder="Provider"
            value={identityForm.provider}
            onChange={(e) =>
              setIdentityForm({ ...identityForm, provider: e.target.value })
            }
          />
          <Input
            placeholder="Tenant ID"
            value={identityForm.external_tenant_id}
            onChange={(e) =>
              setIdentityForm({
                ...identityForm,
                external_tenant_id: e.target.value,
              })
            }
          />
          <Input
            placeholder="Subject ID"
            value={identityForm.external_subject_id}
            onChange={(e) =>
              setIdentityForm({
                ...identityForm,
                external_subject_id: e.target.value,
              })
            }
          />
          <Input
            placeholder="外部コード"
            value={identityForm.external_code}
            onChange={(e) =>
              setIdentityForm({
                ...identityForm,
                external_code: e.target.value,
              })
            }
          />
          <Input
            type="email"
            placeholder="外部メール"
            value={identityForm.email}
            onChange={(e) =>
              setIdentityForm({ ...identityForm, email: e.target.value })
            }
          />
          <Button
            disabled={
              !identityForm.user_id ||
              !identityForm.provider ||
              !identityForm.external_subject_id
            }
            onClick={() =>
              linkIdentity.mutate({
                userId: identityForm.user_id,
                input: {
                  ...identityForm,
                  external_tenant_id: identityForm.external_tenant_id || null,
                  external_code: identityForm.external_code || null,
                  email: identityForm.email || null,
                },
              })
            }
          >
            リンク
          </Button>
        </div>
        <div className="mt-3 flex flex-wrap gap-2">
          {identities.data
            ?.filter((i) => i.status === "active")
            .map((i) => (
              <span
                className="inline-flex items-center gap-2 rounded border p-2 text-sm"
                key={i.id}
              >
                {i.user.name}: {i.provider} / {i.external_subject_id}
                <Button
                  size="sm"
                  variant="danger"
                  onClick={() => unlinkIdentity.mutate(i.id)}
                >
                  解除
                </Button>
              </span>
            ))}
        </div>
        <div className="mt-5 grid gap-2 border-t pt-4 md:grid-cols-2">
          {authorities.data?.map((a) => (
            <div
              className="flex items-center justify-between gap-2 rounded border p-2"
              key={a.id}
            >
              <span className="text-sm">{a.field_key}</span>
              <NativeSelect
                value={a.authority_type}
                onChange={(e) =>
                  updateAuthority.mutate({
                    fieldKey: a.field_key,
                    authorityType: e.target.value as "LOCAL" | "EXTERNAL_HR",
                    provider:
                      e.target.value === "EXTERNAL_HR" ? "EXTERNAL_HR" : null,
                  })
                }
              >
                <option value="LOCAL">LOCAL</option>
                <option value="EXTERNAL_HR">EXTERNAL_HR</option>
              </NativeSelect>
            </div>
          ))}
        </div>
      </Card>

      <Card title="将来日付の所属変更">
        <div className="grid gap-2 md:grid-cols-5">
          <UserSelect
            value={changeForm.user_id}
            onChange={(v) => setChangeForm({ ...changeForm, user_id: v })}
            users={users.data?.data}
          />
          <Input
            type="datetime-local"
            value={changeForm.effective_at}
            onChange={(e) =>
              setChangeForm({ ...changeForm, effective_at: e.target.value })
            }
          />
          <NativeSelect
            value={changeForm.operation}
            onChange={(e) =>
              setChangeForm({
                ...changeForm,
                operation: e.target.value as typeof changeForm.operation,
              })
            }
          >
            <option value="add">追加</option>
            <option value="remove">削除</option>
            <option value="replace">置換</option>
            <option value="set_primary">主所属設定</option>
          </NativeSelect>
          {changeForm.operation !== "add" && (
            <GroupSelect
              value={changeForm.from_group_id}
              onChange={(v) =>
                setChangeForm({ ...changeForm, from_group_id: v })
              }
              groups={groups.data}
            />
          )}{" "}
          {["add", "replace"].includes(changeForm.operation) && (
            <GroupSelect
              value={changeForm.to_group_id}
              onChange={(v) => setChangeForm({ ...changeForm, to_group_id: v })}
              groups={groups.data}
            />
          )}
          <Input
            placeholder="メモ"
            value={changeForm.note}
            onChange={(e) =>
              setChangeForm({ ...changeForm, note: e.target.value })
            }
          />
          <Button
            variant="secondary"
            disabled={!selectedFrom && !selectedTo}
            onClick={appendChangeItem}
          >
            明細に追加
          </Button>
        </div>
        <div className="my-3 rounded border p-3">
          <div className="mb-2 text-sm font-medium">
            変更明細（{changeItems.length}件）
          </div>
          {changeItems.map((item, index) => (
            <div
              className="flex items-center justify-between text-sm"
              key={`${item.operation}-${index}`}
            >
              <span>
                {item.operation}:{" "}
                {groups.data?.find(
                  (g) => g.id === (item.from_group_id ?? item.target_group_id),
                )?.name ?? "-"}{" "}
                →{" "}
                {groups.data?.find(
                  (g) =>
                    g.id ===
                    (item.to_group_id ??
                      (item.operation === "add" ? item.target_group_id : null)),
                )?.name ?? "-"}
              </span>
              <Button
                size="sm"
                variant="secondary"
                onClick={() =>
                  setChangeItems((items) =>
                    items.filter((_, itemIndex) => itemIndex !== index),
                  )
                }
              >
                削除
              </Button>
            </div>
          ))}
        </div>
        <div className="mb-3 flex flex-wrap gap-2">
          <Button
            disabled={
              !changeForm.user_id ||
              !changeForm.effective_at ||
              changeItems.length === 0
            }
            isLoading={scheduleChange.isPending}
            onClick={() =>
              scheduleChange.mutate(
                {
                  user_id: changeForm.user_id,
                  effective_at: new Date(changeForm.effective_at).toISOString(),
                  source_type: "manual",
                  note: changeForm.note,
                  items: changeItems,
                },
                { onSuccess: () => setChangeItems([]) },
              )
            }
          >
            予約
          </Button>
          <Button
            disabled={
              !changeForm.user_id ||
              !changeForm.effective_at ||
              changeItems.length === 0
            }
            variant="secondary"
            onClick={() =>
              createDraft.mutate(
                {
                  user_id: changeForm.user_id,
                  effective_at: new Date(changeForm.effective_at).toISOString(),
                  source_type: "manual",
                  note: changeForm.note,
                  items: changeItems,
                },
                { onSuccess: () => setChangeItems([]) },
              )
            }
          >
            下書き保存
          </Button>
          {editingChangeSet && (
            <Button
              disabled={changeItems.length === 0}
              variant="secondary"
              onClick={() =>
                updateChange.mutate(
                  {
                    id: editingChangeSet,
                    input: {
                      user_id: changeForm.user_id,
                      effective_at: new Date(
                        changeForm.effective_at,
                      ).toISOString(),
                      source_type: "manual",
                      note: changeForm.note,
                      items: changeItems,
                    },
                  },
                  {
                    onSuccess: () => {
                      setEditingChangeSet("");
                      setChangeItems([]);
                    },
                  },
                )
              }
            >
              変更を保存
            </Button>
          )}
        </div>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>適用日時</TableHead>
              <TableHead>対象</TableHead>
              <TableHead>状態</TableHead>
              <TableHead>操作</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {changeSets.data?.map((s) => (
              <TableRow key={s.id}>
                <TableCell>
                  {new Date(s.effective_at).toLocaleString()}
                </TableCell>
                <TableCell>
                  {users.data?.data.find((u) => u.id === s.user_id)?.name ??
                    s.user_id}
                  <div className="text-xs text-muted-foreground">
                    明細{s.items.length}件
                  </div>
                </TableCell>
                <TableCell>
                  <Badge tone={s.status === "applied" ? "success" : "info"}>
                    {s.status}
                  </Badge>
                  {s.failure_reason && (
                    <div className="text-xs text-destructive">
                      {s.failure_reason}
                    </div>
                  )}
                </TableCell>
                <TableCell>
                  {["draft", "scheduled"].includes(s.status) && (
                    <Button
                      size="sm"
                      variant="secondary"
                      onClick={() => {
                        setEditingChangeSet(s.id);
                        setChangeItems(s.items);
                        setChangeForm({
                          ...changeForm,
                          user_id: s.user_id,
                          effective_at: new Date(s.effective_at)
                            .toISOString()
                            .slice(0, 16),
                          note: s.note ?? "",
                        });
                      }}
                    >
                      編集
                    </Button>
                  )}
                  {s.status === "draft" && (
                    <span className="flex gap-2">
                      <Button
                        size="sm"
                        onClick={() => scheduleExisting.mutate(s.id)}
                      >
                        予約確定
                      </Button>
                      <Button
                        size="sm"
                        variant="danger"
                        onClick={() => cancelChange.mutate(s.id)}
                      >
                        取消
                      </Button>
                    </span>
                  )}
                  {s.status === "scheduled" && (
                    <span className="flex gap-2">
                      <Button
                        size="sm"
                        onClick={() => applyChange.mutate(s.id)}
                      >
                        適用
                      </Button>
                      <Button
                        size="sm"
                        variant="danger"
                        onClick={() => cancelChange.mutate(s.id)}
                      >
                        取消
                      </Button>
                    </span>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Card>
      <Card title="外部HR CSV差分取込">
        <div className="flex flex-wrap items-center gap-2">
          <Input
            type="file"
            accept=".csv,text/csv"
            onChange={(e) => setCsvFile(e.target.files?.[0] ?? null)}
          />
          <Button
            disabled={!csvFile}
            isLoading={previewCsv.isPending}
            onClick={() => csvFile && previewCsv.mutate(csvFile)}
          >
            差分確認
          </Button>
          {previewCsv.data && (
            <Button
              disabled={previewCsv.data.summary.changed === 0}
              isLoading={applyCsv.isPending}
              onClick={() => applyCsv.mutate(previewCsv.data.rows)}
            >
              確認した差分を適用
            </Button>
          )}
        </div>
        {previewCsv.data && (
          <div className="mt-3">
            <p className="text-sm">
              全{previewCsv.data.summary.total}件 / 新規
              {previewCsv.data.summary.new}件 / 変更
              {previewCsv.data.summary.changed}件
            </p>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>外部ID</TableHead>
                  <TableHead>区分</TableHead>
                  <TableHead>差分</TableHead>
                  <TableHead>Group</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {previewCsv.data.rows.map((row) => (
                  <TableRow key={row.external_subject_id}>
                    <TableCell>{row.external_subject_id}</TableCell>
                    <TableCell>{row.is_new ? "新規" : "更新"}</TableCell>
                    <TableCell>
                      {Object.entries(row.diff).map(([key, value]) => (
                        <div key={key}>
                          {key}: {String(value.before ?? "")} →{" "}
                          {String(value.after ?? "")}
                        </div>
                      ))}
                    </TableCell>
                    <TableCell>{row.group_code ?? "-"}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </Card>
    </div>
  );
}

type UserOption = { id: string; name: string };
function UserSelect({
  value,
  onChange,
  users,
  disabled = false,
}: {
  value: string;
  onChange: (v: string) => void;
  users?: UserOption[];
  disabled?: boolean;
}) {
  return (
    <NativeSelect
      disabled={disabled}
      aria-label="ユーザー"
      value={value}
      onChange={(e) => onChange(e.target.value)}
    >
      <option value="">ユーザー</option>
      {users?.map((u) => (
        <option key={u.id} value={u.id}>
          {u.name}
        </option>
      ))}
    </NativeSelect>
  );
}
function GroupSelect({
  value,
  onChange,
  groups,
  disabled = false,
}: {
  value: string;
  onChange: (v: string) => void;
  groups?: { id: string; name: string }[];
  disabled?: boolean;
}) {
  return (
    <NativeSelect
      disabled={disabled}
      aria-label="グループ"
      value={value}
      onChange={(e) => onChange(e.target.value)}
    >
      <option value="">グループ</option>
      {groups?.map((g) => (
        <option key={g.id} value={g.id}>
          {g.name}
        </option>
      ))}
    </NativeSelect>
  );
}
function FeatureSelect({
  value,
  onChange,
  features,
}: {
  value: string;
  onChange: (v: string) => void;
  features?: Array<{
    id: number;
    name: string;
    children?: Array<{ id: number; name: string }>;
  }>;
}) {
  return (
    <NativeSelect
      aria-label="Feature"
      value={value}
      onChange={(e) => onChange(e.target.value)}
    >
      <option value="">Feature</option>
      {features?.flatMap((feature) => [
        <option key={feature.id} value={feature.id}>
          {feature.name}
        </option>,
        ...(feature.children?.map((child) => (
          <option key={child.id} value={child.id}>
            　└ {child.name}
          </option>
        )) ?? []),
      ])}
    </NativeSelect>
  );
}

function flattenFeatures<T extends { children?: T[] }>(features?: T[]): T[] {
  return (
    features?.flatMap((feature) => [
      feature,
      ...flattenFeatures(feature.children),
    ]) ?? []
  );
}
function toDateTimeLocal(value: string | null): string {
  if (!value) return "";
  const date = new Date(value);
  const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);
  return local.toISOString().slice(0, 16);
}
