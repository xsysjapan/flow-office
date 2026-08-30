import { useMemo, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { Badge } from "../../components/Badge/Badge";
import { Button } from "../../components/Button/Button";
import { Card } from "../../components/Card/Card";
import { ClickableTableRow } from "../../components/ClickableTableRow/ClickableTableRow";
import { EmptyState } from "../../components/EmptyState/EmptyState";
import { ErrorMessage } from "../../components/ErrorMessage/ErrorMessage";
import { FormField } from "../../components/FormField/FormField";
import { LoadingState } from "../../components/LoadingState/LoadingState";
import { Checkbox } from "../../components/ui/checkbox";
import { Input } from "../../components/ui/input";
import { NativeSelect } from "../../components/ui/native-select";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "../../components/ui/dialog";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "../../components/ui/table";
import * as access from "../../hooks/useAccessControl";
import type { AccessRole, Feature } from "../../api/accessControl";

/**
 * ロール定義ページ(OOUI: Role起点)。
 *
 * ロール一覧から選択したロールの基本情報・Permission構成・Feature構成をまとめて編集する。
 * `UserManagementAccessPage`の`section="access"`にあった機能タブ起点のRole操作
 * (作成・複製・Permission編集)をロール起点に組み替えたもの。
 */
export function RoleDefinitionPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const selectedRoleId = searchParams.get("roleId") ?? "";

  const roles = access.useAccessRoles();
  const permissions = access.usePermissions();
  const features = access.useFeatures();
  const assignments = access.useRoleAssignments();

  const createRole = access.useCreateRole();
  const cloneRole = access.useCloneRole();
  const updateRole = access.useUpdateRole();
  const updateRolePermissions = access.useUpdateRolePermissions();
  const updateRoleFeatures = access.useUpdateRoleFeatures();

  const [createDialogOpen, setCreateDialogOpen] = useState(false);
  const [createMode, setCreateMode] = useState<"create" | "clone">("create");
  const [roleForm, setRoleForm] = useState({
    code: "",
    name: "",
    description: "",
  });

  const selectedRole = useMemo(
    () => roles.data?.find((role) => String(role.id) === selectedRoleId),
    [roles.data, selectedRoleId],
  );

  const selectRole = (role: AccessRole) => {
    setSearchParams((params) => {
      const next = new URLSearchParams(params);
      next.set("roleId", String(role.id));
      return next;
    });
  };

  const openCreateDialog = () => {
    setCreateMode("create");
    setRoleForm({ code: "", name: "", description: "" });
    setCreateDialogOpen(true);
  };
  const openCloneDialog = () => {
    if (!selectedRole) return;
    setCreateMode("clone");
    setRoleForm({ code: "", name: "", description: "" });
    setCreateDialogOpen(true);
  };

  const mutationError =
    createRole.error ??
    cloneRole.error ??
    updateRole.error ??
    updateRolePermissions.error ??
    updateRoleFeatures.error;

  const queries = [roles, permissions, features, assignments];
  if (queries.some((query) => query.isLoading)) return <LoadingState />;
  const loadError = queries.find((query) => query.error)?.error;
  if (loadError)
    return (
      <ErrorMessage error={loadError} fallback="ロール定義の取得に失敗しました。" />
    );

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-lg font-semibold">ロール定義</h1>
        <p className="text-sm text-muted-foreground">
          ロールを選択して、名称・状態・Permission・Feature構成を編集します。
        </p>
      </div>
      {mutationError && <ErrorMessage error={mutationError} />}

      <Card
        title="ロール一覧"
        actions={<Button onClick={openCreateDialog}>新規作成</Button>}
      >
        {(roles.data ?? []).length === 0 ? (
          <EmptyState
            title="まだロールがありません。"
            description="「新規作成」からロールを作成できます。"
            action={<Button onClick={openCreateDialog}>新規作成</Button>}
          />
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>名称</TableHead>
                <TableHead>コード</TableHead>
                <TableHead>状態</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {roles.data?.map((role) => (
                <ClickableTableRow
                  key={role.id}
                  onRowClick={() => selectRole(role)}
                  rowLabel={`${role.name}の詳細を開く`}
                >
                  <TableCell className="font-medium text-foreground">
                    {role.name}
                  </TableCell>
                  <TableCell>{role.code}</TableCell>
                  <TableCell>
                    <Badge tone={role.status === "active" ? "success" : "neutral"}>
                      {role.status === "active" ? "有効" : "廃止"}
                    </Badge>
                  </TableCell>
                </ClickableTableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </Card>

      {/*
        Pattern exception:
        ロールの新規作成・複製をDialogで実装する。

        Reason:
        入力項目がコード・名称・説明の3項目のみで、作成/複製後も現在の一覧コンテキストを
        維持する価値が高いため。
      */}
      <Dialog open={createDialogOpen} onOpenChange={setCreateDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {createMode === "clone" ? "ロールを複製" : "ロールを作成"}
            </DialogTitle>
            <DialogDescription>
              {createMode === "clone"
                ? `${selectedRole?.name ?? ""}を複製元として、新しいロールを作成します。`
                : "新しいロールのコード・名称・説明を入力してください。"}
            </DialogDescription>
          </DialogHeader>
          <div className="grid gap-3">
            <FormField label="ロールコード" htmlFor="role-form-code">
              <Input
                id="role-form-code"
                value={roleForm.code}
                onChange={(e) =>
                  setRoleForm({ ...roleForm, code: e.target.value })
                }
              />
            </FormField>
            <FormField label="ロール名" htmlFor="role-form-name">
              <Input
                id="role-form-name"
                value={roleForm.name}
                onChange={(e) =>
                  setRoleForm({ ...roleForm, name: e.target.value })
                }
              />
            </FormField>
            <FormField label="説明(任意)" htmlFor="role-form-description">
              <Input
                id="role-form-description"
                value={roleForm.description}
                onChange={(e) =>
                  setRoleForm({ ...roleForm, description: e.target.value })
                }
              />
            </FormField>
          </div>
          <DialogFooter>
            <Button
              variant="secondary"
              onClick={() => setCreateDialogOpen(false)}
            >
              キャンセル
            </Button>
            <div>
              <Button
                disabled={!roleForm.code || !roleForm.name}
                isLoading={
                  createMode === "clone"
                    ? cloneRole.isPending
                    : createRole.isPending
                }
                onClick={() => {
                  const input = {
                    code: roleForm.code,
                    name: roleForm.name,
                    description: roleForm.description || undefined,
                  };
                  if (createMode === "clone" && selectedRole) {
                    cloneRole.mutate(
                      { id: selectedRole.id, input },
                      { onSuccess: () => setCreateDialogOpen(false) },
                    );
                  } else {
                    createRole.mutate(input, {
                      onSuccess: () => setCreateDialogOpen(false),
                    });
                  }
                }}
              >
                {createMode === "clone" ? "複製する" : "作成"}
              </Button>
              {(!roleForm.code || !roleForm.name) && (
                <p className="mt-1.5 text-xs text-muted-foreground">
                  ロールコードと名称を入力してください。
                </p>
              )}
            </div>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {selectedRole && (
        <RoleDetail
          role={selectedRole}
          permissions={permissions.data ?? []}
          allFeatures={features.data ?? []}
          groupsHoldingRoleCount={
            assignments.data?.filter(
              (assignment) =>
                assignment.subject_type === "group" &&
                assignment.role_id === selectedRole.id &&
                assignment.status === "active",
            ).length ?? 0
          }
          updateRole={updateRole}
          updateRolePermissions={updateRolePermissions}
          updateRoleFeatures={updateRoleFeatures}
          onClone={openCloneDialog}
        />
      )}
    </div>
  );
}

function RoleDetail({
  role,
  permissions,
  allFeatures,
  groupsHoldingRoleCount,
  updateRole,
  updateRolePermissions,
  updateRoleFeatures,
  onClone,
}: {
  role: AccessRole;
  permissions: Array<{
    id: number;
    resource: string;
    action: string;
    description: string | null;
  }>;
  allFeatures: Feature[];
  groupsHoldingRoleCount: number;
  updateRole: ReturnType<typeof access.useUpdateRole>;
  updateRolePermissions: ReturnType<typeof access.useUpdateRolePermissions>;
  updateRoleFeatures: ReturnType<typeof access.useUpdateRoleFeatures>;
  onClone: () => void;
}) {
  const [basicForm, setBasicForm] = useState({
    id: role.id,
    name: role.name,
    description: role.description ?? "",
    status: role.status,
  });
  const [selectedPermissions, setSelectedPermissions] = useState<number[]>(
    role.permissions.map((permission) => permission.id),
  );
  const [selectedFeatureIds, setSelectedFeatureIds] = useState<number[]>(
    role.features.map((feature) => feature.id),
  );

  if (basicForm.id !== role.id) {
    setBasicForm({
      id: role.id,
      name: role.name,
      description: role.description ?? "",
      status: role.status,
    });
    setSelectedPermissions(role.permissions.map((permission) => permission.id));
    setSelectedFeatureIds(role.features.map((feature) => feature.id));
  }

  const permissionGroups = useMemo(
    () =>
      Object.entries(
        permissions.reduce<Record<string, typeof permissions>>(
          (result, permission) => {
            (result[permission.resource] ??= []).push(permission);
            return result;
          },
          {},
        ),
      ),
    [permissions],
  );

  const toggleFeature = (featureId: number, checked: boolean) => {
    setSelectedFeatureIds((current) =>
      checked
        ? [...current, featureId]
        : current.filter((id) => id !== featureId),
    );
  };

  return (
    <Card
      title={role.name}
      actions={
        <Button variant="secondary" onClick={onClone}>
          このロールを複製
        </Button>
      }
    >
      <div className="mb-6 rounded border p-3">
        <h2 className="mb-3 font-medium">基本情報</h2>
        <div className="grid gap-3 md:grid-cols-2">
          <FormField label="ロール名" htmlFor="role-basic-name">
            <Input
              id="role-basic-name"
              value={basicForm.name}
              onChange={(e) =>
                setBasicForm({ ...basicForm, name: e.target.value })
              }
            />
          </FormField>
          <FormField label="説明" htmlFor="role-basic-description">
            <Input
              id="role-basic-description"
              value={basicForm.description}
              onChange={(e) =>
                setBasicForm({ ...basicForm, description: e.target.value })
              }
            />
          </FormField>
          <FormField label="状態" htmlFor="role-basic-status">
            <NativeSelect
              id="role-basic-status"
              disabled={role.is_system}
              value={basicForm.status}
              onChange={(e) =>
                setBasicForm({ ...basicForm, status: e.target.value })
              }
            >
              <option value="active">有効</option>
              <option value="inactive">廃止</option>
            </NativeSelect>
            {role.is_system && (
              <p className="mt-1.5 text-xs text-muted-foreground">
                システム標準ロールのため状態は変更できません。
              </p>
            )}
          </FormField>
        </div>
        <Button
          variant="secondary"
          isLoading={updateRole.isPending}
          disabled={!basicForm.name}
          onClick={() =>
            updateRole.mutate({
              id: role.id,
              input: {
                name: basicForm.name,
                description: basicForm.description || null,
                status: basicForm.status,
              },
            })
          }
        >
          基本情報を保存
        </Button>
        {!basicForm.name && (
          <p className="mt-1.5 text-xs text-muted-foreground">
            ロール名を入力してください。
          </p>
        )}
      </div>

      <div className="mb-6 rounded border p-3">
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <h2 className="font-medium">Permission</h2>
          <Button
            isLoading={updateRolePermissions.isPending}
            onClick={() =>
              updateRolePermissions.mutate({
                roleId: role.id,
                permissionIds: selectedPermissions,
              })
            }
          >
            Permissionを保存
          </Button>
        </div>
        <div className="grid gap-3 md:grid-cols-3">
          {permissionGroups.map(([resource, items]) => (
            <fieldset className="rounded border p-3" key={resource}>
              <legend className="px-1 text-sm font-medium">{resource}</legend>
              {items.map((permission) => (
                <label className="flex gap-2 text-sm" key={permission.id}>
                  <Checkbox
                    checked={selectedPermissions.includes(permission.id)}
                    onCheckedChange={(checked) =>
                      setSelectedPermissions((current) =>
                        checked === true
                          ? [...current, permission.id]
                          : current.filter((id) => id !== permission.id),
                      )
                    }
                  />
                  <span>
                    {permission.action}
                    {permission.description && (
                      <span className="block text-xs text-muted-foreground">
                        {permission.description}
                      </span>
                    )}
                  </span>
                </label>
              ))}
            </fieldset>
          ))}
          {permissionGroups.length === 0 && (
            <EmptyState title="Permissionはまだありません。" />
          )}
        </div>
      </div>

      <div className="rounded border p-3">
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <h2 className="font-medium">Feature構成</h2>
          <Button
            isLoading={updateRoleFeatures.isPending}
            onClick={() =>
              updateRoleFeatures.mutate({
                roleId: role.id,
                featureIds: selectedFeatureIds,
              })
            }
          >
            Feature構成を保存
          </Button>
        </div>
        {allFeatures.length === 0 ? (
          <EmptyState title="Featureはまだありません。" />
        ) : (
          <div className="space-y-2">
            {allFeatures.map((feature) => (
              <div key={feature.id}>
                <label className="flex items-center gap-2 text-sm">
                  <Checkbox
                    checked={selectedFeatureIds.includes(feature.id)}
                    onCheckedChange={(checked) =>
                      toggleFeature(feature.id, checked === true)
                    }
                  />
                  <span className="font-medium">{feature.name}</span>
                </label>
                {(feature.children ?? []).length > 0 && (
                  <div className="ml-6 mt-1 space-y-1">
                    {feature.children?.map((child) => (
                      <label
                        className="flex items-center gap-2 text-sm"
                        key={child.id}
                      >
                        <Checkbox
                          checked={selectedFeatureIds.includes(child.id)}
                          onCheckedChange={(checked) =>
                            toggleFeature(child.id, checked === true)
                          }
                        />
                        <span>{child.name}</span>
                      </label>
                    ))}
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
        {updateRoleFeatures.isSuccess && (
          <p className="mt-3 text-sm text-muted-foreground">
            このロールを保持しているグループ: {groupsHoldingRoleCount}件
          </p>
        )}
      </div>
    </Card>
  );
}
