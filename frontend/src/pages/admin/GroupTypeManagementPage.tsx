import { useState } from "react";
import { Button } from "../../components/Button/Button";
import { Card } from "../../components/Card/Card";
import { ErrorMessage } from "../../components/ErrorMessage/ErrorMessage";
import { LoadingState } from "../../components/LoadingState/LoadingState";
import { Input } from "../../components/ui/input";
import { NativeSelect } from "../../components/ui/native-select";
import * as userManagement from "../../hooks/useUserManagement";

export function GroupTypeManagementPage() {
  const types = userManagement.useGroupTypes();
  const createGroupType = userManagement.useCreateGroupType();
  const updateGroupType = userManagement.useUpdateGroupType();
  const [createForm, setCreateForm] = useState({
    code: "",
    name: "",
    display_order: "0",
    membership_limit_type: "unlimited" as "unlimited" | "limited",
    max_memberships_per_user: "",
    primary_membership_required: false,
    max_primary_memberships: "",
  });
  const [editForm, setEditForm] = useState({
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

  if (types.isLoading) return <LoadingState />;
  if (types.error)
    return (
      <ErrorMessage
        error={types.error}
        fallback="GroupType設定の取得に失敗しました。"
      />
    );

  const mutationError =
    createGroupType.error ?? updateGroupType.error ?? undefined;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">GroupType管理</h1>
        <p className="text-sm text-muted-foreground">
          グループの分類と所属数・主所属の制約を管理します。
        </p>
      </div>
      {mutationError && <ErrorMessage error={mutationError} />}
      <Card title="GroupType管理">
        <div className="mb-4 grid gap-2 rounded border p-3 md:grid-cols-2 xl:grid-cols-4">
          <Input
            placeholder="新規GroupTypeコード"
            value={createForm.code}
            onChange={(event) =>
              setCreateForm({ ...createForm, code: event.target.value })
            }
          />
          <Input
            placeholder="GroupType名"
            value={createForm.name}
            onChange={(event) =>
              setCreateForm({ ...createForm, name: event.target.value })
            }
          />
          <Input
            aria-label="新規GroupType表示順"
            type="number"
            min="0"
            placeholder="表示順"
            value={createForm.display_order}
            onChange={(event) =>
              setCreateForm({
                ...createForm,
                display_order: event.target.value,
              })
            }
          />
          <NativeSelect
            aria-label="新規GroupType所属数制約"
            value={createForm.membership_limit_type}
            onChange={(event) =>
              setCreateForm({
                ...createForm,
                membership_limit_type: event.target.value as
                  "unlimited" | "limited",
              })
            }
          >
            <option value="unlimited">複数可</option>
            <option value="limited">上限あり</option>
          </NativeSelect>
          <Input
            aria-label="新規GroupType所属上限"
            type="number"
            placeholder="所属上限"
            value={createForm.max_memberships_per_user}
            onChange={(event) =>
              setCreateForm({
                ...createForm,
                max_memberships_per_user: event.target.value,
              })
            }
          />
          <label className="flex items-center gap-2">
            <input
              type="checkbox"
              checked={createForm.primary_membership_required}
              onChange={(event) =>
                setCreateForm({
                  ...createForm,
                  primary_membership_required: event.target.checked,
                })
              }
            />
            主所属必須
          </label>
          <Input
            aria-label="新規GroupType主所属上限"
            type="number"
            placeholder="主所属上限"
            value={createForm.max_primary_memberships}
            onChange={(event) =>
              setCreateForm({
                ...createForm,
                max_primary_memberships: event.target.value,
              })
            }
          />
          <Button
            disabled={!createForm.code || !createForm.name}
            isLoading={createGroupType.isPending}
            onClick={() =>
              createGroupType.mutate({
                code: createForm.code,
                name: createForm.name,
                display_order: Number(createForm.display_order) || 0,
                membership_limit_type: createForm.membership_limit_type,
                max_memberships_per_user: createForm.max_memberships_per_user
                  ? Number(createForm.max_memberships_per_user)
                  : null,
                primary_membership_required:
                  createForm.primary_membership_required,
                max_primary_memberships: createForm.max_primary_memberships
                  ? Number(createForm.max_primary_memberships)
                  : null,
              })
            }
          >
            GroupType追加
          </Button>
        </div>

        <div className="mb-4 flex flex-wrap gap-2">
          {types.data?.map((type) => (
            <span
              key={type.id}
              className="inline-flex items-center gap-2 rounded border p-2 text-sm"
            >
              {type.name} ({type.code}) / 表示順 {type.display_order} / 上限{" "}
              {type.max_memberships_per_user ?? "なし"} / 主所属{" "}
              {type.primary_membership_required ? "必須" : "任意"}
            </span>
          ))}
        </div>

        <div className="grid gap-2 rounded border p-3 md:grid-cols-2 xl:grid-cols-4">
          <NativeSelect
            aria-label="編集するGroupType"
            value={editForm.id}
            onChange={(event) => {
              const type = types.data?.find(
                (item) => item.id === Number(event.target.value),
              );
              if (!type) return;
              setEditForm({
                id: String(type.id),
                name: type.name,
                display_order: String(type.display_order),
                status: type.status,
                membership_limit_type: type.membership_limit_type,
                max_memberships_per_user:
                  type.max_memberships_per_user === null
                    ? ""
                    : String(type.max_memberships_per_user),
                primary_membership_required: type.primary_membership_required,
                max_primary_memberships:
                  type.max_primary_memberships === null
                    ? ""
                    : String(type.max_primary_memberships),
                is_system: type.is_system,
              });
            }}
          >
            <option value="">編集するGroupType</option>
            {types.data?.map((type) => (
              <option key={type.id} value={type.id}>
                {type.name}
              </option>
            ))}
          </NativeSelect>
          <Input
            aria-label="GroupType名を編集"
            placeholder="名称"
            value={editForm.name}
            onChange={(event) =>
              setEditForm({ ...editForm, name: event.target.value })
            }
          />
          <Input
            aria-label="GroupType表示順を編集"
            type="number"
            min="0"
            placeholder="表示順"
            value={editForm.display_order}
            onChange={(event) =>
              setEditForm({ ...editForm, display_order: event.target.value })
            }
          />
          <NativeSelect
            aria-label="GroupType状態"
            disabled={editForm.is_system}
            value={editForm.status}
            onChange={(event) =>
              setEditForm({ ...editForm, status: event.target.value })
            }
          >
            <option value="active">有効</option>
            <option value="inactive">廃止</option>
          </NativeSelect>
          <NativeSelect
            aria-label="所属数制約"
            disabled={editForm.is_system}
            value={editForm.membership_limit_type}
            onChange={(event) =>
              setEditForm({
                ...editForm,
                membership_limit_type: event.target.value as
                  "unlimited" | "limited",
              })
            }
          >
            <option value="unlimited">複数可</option>
            <option value="limited">上限あり</option>
          </NativeSelect>
          <Input
            aria-label="所属上限を編集"
            disabled={editForm.is_system}
            type="number"
            placeholder="所属上限"
            value={editForm.max_memberships_per_user}
            onChange={(event) =>
              setEditForm({
                ...editForm,
                max_memberships_per_user: event.target.value,
              })
            }
          />
          <label className="flex items-center gap-2">
            <input
              type="checkbox"
              disabled={editForm.is_system}
              checked={editForm.primary_membership_required}
              onChange={(event) =>
                setEditForm({
                  ...editForm,
                  primary_membership_required: event.target.checked,
                })
              }
            />
            主所属必須
          </label>
          <Input
            aria-label="主所属上限を編集"
            disabled={editForm.is_system}
            type="number"
            placeholder="主所属上限"
            value={editForm.max_primary_memberships}
            onChange={(event) =>
              setEditForm({
                ...editForm,
                max_primary_memberships: event.target.value,
              })
            }
          />
          <Button
            disabled={!editForm.id || !editForm.name}
            isLoading={updateGroupType.isPending}
            onClick={() =>
              updateGroupType.mutate({
                id: Number(editForm.id),
                input: {
                  name: editForm.name,
                  display_order: Number(editForm.display_order) || 0,
                  status: editForm.status,
                  membership_limit_type: editForm.membership_limit_type,
                  max_memberships_per_user: editForm.max_memberships_per_user
                    ? Number(editForm.max_memberships_per_user)
                    : null,
                  primary_membership_required:
                    editForm.primary_membership_required,
                  max_primary_memberships: editForm.max_primary_memberships
                    ? Number(editForm.max_primary_memberships)
                    : null,
                },
              })
            }
          >
            変更を保存
          </Button>
        </div>
      </Card>
    </div>
  );
}
