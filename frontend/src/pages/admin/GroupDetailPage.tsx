import { useEffect, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { BackLink } from "../../components/BackLink/BackLink";
import { Button } from "../../components/Button/Button";
import { Card } from "../../components/Card/Card";
import { ConfirmActionDialog } from "../../components/ConfirmActionDialog/ConfirmActionDialog";
import { DateTimePicker } from "../../components/DateTimePicker/DateTimePicker";
import { EmptyState } from "../../components/EmptyState/EmptyState";
import { ErrorMessage } from "../../components/ErrorMessage/ErrorMessage";
import { FormActions } from "../../components/FormActions/FormActions";
import { FormField } from "../../components/FormField/FormField";
import { FormLayout } from "../../components/FormLayout/FormLayout";
import { notify } from "../../lib/notify";
import { PageHeader } from "../../components/PageHeader/PageHeader";
import { LoadingState } from "../../components/LoadingState/LoadingState";
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
  useApplyMembershipChangeNow,
  useCancelMembershipChange,
  useCreateGroup,
  useGroupTypes,
  useManagedGroups,
  useMembershipChangeSets,
  useScheduleMembershipChange,
  useUpdateGroup,
} from "../../hooks/useUserManagement";
import { useForwardState, useKeepBackState } from "../../navigation/backState";
import { useUsers } from "../../hooks/useUsers";
import type { ManagedGroup } from "../../api/userManagement";
import {
  membershipChangeDescription,
  membershipChangeStatusLabel,
} from "../../utils/membershipChangeLabels";
import { RoleAssignmentSection } from "../../components/RoleAssignmentSection/RoleAssignmentSection";
import * as access from "../../hooks/useAccessControl";
import { membershipKindLabel } from '../../utils/statusLabels'
import { useUnsavedChangesGuard } from '../../hooks/useUnsavedChangesGuard'
import { isGroupFormDirty, type GroupFormValues } from './groupDetailDirty'
import { fieldErrors } from '../../utils/fieldErrors'

type GroupErrorKey =
  | "group_type_id"
  | "name"
  | "parent_group_id"
  | "status"
  | "initial_role_id";
type MembershipErrorKey = "user_id" | "effective_at" | "note" | "items.*.is_primary";

const emptyGroupForm: GroupFormValues = {
  group_type_id: "",
  name: "",
  parent_group_id: "",
  status: "active",
  initial_role_id: "",
};

export function GroupDetailPage() {
  const { id = "new" } = useParams<{ id: string }>();
  // new と :id で同じ部品が使い回されないよう、id をキーにしてフォームを作り直す。
  return <GroupDetailForm key={id} />;
}

function GroupDetailForm() {
  const { id = "new" } = useParams<{ id: string }>();
  const isNew = id === "new";
  const navigate = useNavigate();
  const keepBackState = useKeepBackState();
  const groups = useManagedGroups();
  const types = useGroupTypes();
  const roles = access.useAccessRoles();
  const createGroup = useCreateGroup();
  const updateGroup = useUpdateGroup();
  const createAssignment = access.useCreateRoleAssignment();
  const updateAssignment = access.useUpdateRoleAssignment();
  const removeAssignment = access.useRemoveRoleAssignment();
  const assignments = access.useRoleAssignments(!isNew);
  const [initializedId, setInitializedId] = useState("");
  const [form, setForm] = useState<GroupFormValues>(emptyGroupForm);
  const [base, setBase] = useState<GroupFormValues>(emptyGroupForm);
  const { navigateAfterSave } = useUnsavedChangesGuard(isGroupFormDirty(base, form));

  const group = groups.data?.find((item) => item.id === id);
  useEffect(() => {
    if (isNew || !group || initializedId === group.id) return;
    const loaded = {
      group_type_id: String(group.group_type_id),
      name: group.name,
      parent_group_id: group.parent_group_id ?? "",
      status: group.status,
      initial_role_id: "",
    };
    setForm(loaded);
    setBase(loaded);
    setInitializedId(group.id);
  }, [group, initializedId, isNew]);

  const top = (
    <>
      <BackLink className="mb-0" />
      <PageHeader description="グループの基本情報、割り当てるロール、所属メンバーを確認・編集できます。" />
    </>
  );
  if (
    groups.isLoading ||
    types.isLoading ||
    roles.isLoading ||
    (!isNew && assignments.isLoading)
  )
    return (
      <div className="space-y-4">
        {top}
        <LoadingState />
      </div>
    );
  if (groups.error || types.error || roles.error || assignments.error) {
    return (
      <div className="space-y-4">
        {top}
        <ErrorMessage
          error={groups.error ?? types.error ?? roles.error ?? assignments.error}
          fallback="グループ詳細の取得に失敗しました。"
        />
      </div>
    );
  }
  if (!isNew && !group) {
    return (
      <div className="space-y-4">
        {top}
        <p className="text-sm text-muted-foreground">
          グループが見つかりません。
        </p>
      </div>
    );
  }

  const mutation = isNew ? createGroup : updateGroup;
  const fields: GroupErrorKey[] = isNew
    ? ["group_type_id", "name", "parent_group_id", "initial_role_id"]
    : ["name", "parent_group_id", "status"];
  const { of, rest } = fieldErrors(mutation.error, fields);
  const parentCandidates = groups.data?.filter(
    (candidate) =>
      candidate.id !== id &&
      candidate.status === "active" &&
      String(candidate.group_type_id) === form.group_type_id,
  );

  return (
    <div className="space-y-4">
      {top}
      <Card title={isNew ? "基本情報" : group!.name}>
        <FormLayout>
          <FormField label="グループ種別" htmlFor="group-detail-type" error={of("group_type_id")}>
            <NativeSelect
              id="group-detail-type"
              disabled={!isNew}
              value={form.group_type_id}
              onChange={(event) =>
                setForm({
                  ...form,
                  group_type_id: event.target.value,
                  parent_group_id: "",
                })
              }
            >
              <option value="">グループ種別を選択</option>
              {types.data?.map((type) => (
                <option key={type.id} value={type.id}>
                  {type.name}
                </option>
              ))}
            </NativeSelect>
          </FormField>
          <FormField label="名称" htmlFor="group-detail-name" error={of("name")}>
            <Input
              id="group-detail-name"
              value={form.name}
              onChange={(event) =>
                setForm({ ...form, name: event.target.value })
              }
            />
          </FormField>
          <FormField label="親グループ" htmlFor="group-detail-parent" error={of("parent_group_id")}>
            <NativeSelect
              id="group-detail-parent"
              value={form.parent_group_id}
              onChange={(event) =>
                setForm({ ...form, parent_group_id: event.target.value })
              }
            >
              <option value="">親なし</option>
              {parentCandidates?.map((candidate) => (
                <option key={candidate.id} value={candidate.id}>
                  {candidate.name}
                </option>
              ))}
            </NativeSelect>
          </FormField>
          {!isNew && (
            <FormField label="状態" htmlFor="group-detail-status" error={of("status")}>
              <NativeSelect
                id="group-detail-status"
                value={form.status}
                onChange={(event) =>
                  setForm({ ...form, status: event.target.value })
                }
              >
                <option value="active">有効</option>
                <option value="inactive">無効</option>
              </NativeSelect>
            </FormField>
          )}
          {isNew && (
            <FormField
              label="作成時に割り当てるRole(任意)"
              htmlFor="group-detail-initial-role"
              error={of("initial_role_id")}
            >
              <NativeSelect
                id="group-detail-initial-role"
                value={form.initial_role_id}
                onChange={(event) =>
                  setForm({ ...form, initial_role_id: event.target.value })
                }
              >
                <option value="">割り当てない</option>
                {roles.data?.map((role) => (
                  <option key={role.id} value={role.id}>
                    {role.name}
                  </option>
                ))}
              </NativeSelect>
            </FormField>
          )}
          {rest != null && <ErrorMessage error={rest} />}
          <FormActions>
            <Button variant="secondary" onClick={() => navigate("/admin/groups")}>
              キャンセル
            </Button>
            <Button
              disabled={!form.group_type_id || !form.name}
              isLoading={mutation.isPending}
              onClick={() => {
                if (isNew) {
                  createGroup.mutate(
                    {
                      group_type_id: Number(form.group_type_id),
                      name: form.name,
                      parent_group_id: form.parent_group_id || undefined,
                      initial_role_id: form.initial_role_id
                        ? Number(form.initial_role_id)
                        : undefined,
                    },
                    {
                      // 作成後は更新時と同じ「詳細画面に留まる」挙動に揃えるため、一覧ではなく
                      // 作成されたグループ自身の詳細画面(このページ)へ遷移する(SKILL.md §2.6)。
                      onSuccess: (data) => {
                        const newGroupId = (data as { id: string }).id;
                        notify.success("グループを作成しました");
                        navigateAfterSave(`/admin/groups/${newGroupId}`, {
                          replace: true,
                          state: keepBackState,
                        });
                      },
                    },
                  );
                  return;
                }
                updateGroup.mutate(
                  {
                    id,
                    input: {
                      name: form.name,
                      parent_group_id: form.parent_group_id || null,
                      status: form.status,
                    },
                  },
                  {
                    onSuccess: () => {
                      setBase(form);
                      notify.success("グループを保存しました");
                    },
                  },
                );
              }}
            >
              {isNew ? "作成" : "保存"}
            </Button>
          </FormActions>
        </FormLayout>
      </Card>
      {!isNew && group && (
        <RoleAssignmentSection
          mode="pick-role"
          title="Role割当"
          roles={roles.data ?? []}
          groups={groups.data ?? []}
          assignments={(assignments.data ?? []).filter(
            (assignment) =>
              assignment.subject_type === "group" &&
              assignment.subject_id === group.id &&
              assignment.status === "active",
          )}
          fixedGroupId={group.id}
          createAssignment={createAssignment}
          updateAssignment={updateAssignment}
          removeAssignment={removeAssignment}
        />
      )}
      {!isNew && group && (
        <GroupMembersCard group={group} groupOptions={groups.data ?? []} />
      )}
    </div>
  );
}

function GroupMembersCard({
  group,
  groupOptions,
}: {
  group: ManagedGroup;
  groupOptions: ManagedGroup[];
}) {
  const forwardState = useForwardState();
  const users = useUsers("", 100);
  const changes = useMembershipChangeSets(true, {
    group_id: group.id,
    limit: 20,
  });
  const applyNow = useApplyMembershipChangeNow();
  const scheduleChange = useScheduleMembershipChange();
  const cancelChange = useCancelMembershipChange();
  const [dialogOpen, setDialogOpen] = useState(false);
  const [operation, setOperation] = useState<"add" | "remove">("add");
  const [userId, setUserId] = useState("");
  const [isPrimary, setIsPrimary] = useState(false);
  const [timing, setTiming] = useState<"now" | "scheduled">("now");
  const [effectiveAt, setEffectiveAt] = useState("");
  const [note, setNote] = useState("");

  if (users.isLoading || changes.isLoading) return <LoadingState />;
  if (users.error || changes.error) {
    return (
      <ErrorMessage
        error={users.error ?? changes.error}
        fallback="所属メンバー情報の取得に失敗しました。"
      />
    );
  }

  const availableUsers = (users.data?.data ?? []).filter(
    (user) =>
      !group.memberships.some((membership) => membership.user_id === user.id),
  );
  const selectedUser = (users.data?.data ?? []).find(
    (user) => user.id === userId,
  );
  const mutation = timing === "scheduled" ? scheduleChange : applyNow;
  // 描かれる項目と同じ条件でキーを組み立てる(追加のときだけ対象ユーザー・所属区分、予約のときだけ適用日時)。
  const membershipFields: MembershipErrorKey[] = ["note"];
  if (operation === "add") membershipFields.push("user_id", "items.*.is_primary");
  if (timing === "scheduled") membershipFields.push("effective_at");
  const { of: membershipOf, ofRow: membershipOfRow, rest: membershipRest } = fieldErrors(
    mutation.error,
    membershipFields,
  );

  const openChange = (nextOperation: "add" | "remove", nextUserId = "") => {
    setOperation(nextOperation);
    setUserId(nextUserId);
    setIsPrimary(false);
    setTiming("now");
    setEffectiveAt("");
    setNote("");
    setDialogOpen(true);
  };

  const submitChange = () => {
    if (!userId) return;
    mutation.mutate(
      {
        user_id: userId,
        effective_at:
          timing === "scheduled"
            ? new Date(effectiveAt).toISOString()
            : new Date().toISOString(),
        source_type: "manual",
        note,
        items: [
          {
            operation,
            group_type_id: group.group_type_id,
            from_group_id: operation === "remove" ? group.id : null,
            to_group_id: operation === "add" ? group.id : null,
            target_group_id: group.id,
            is_primary: operation === "add" && isPrimary,
          },
        ],
      },
      {
        onSuccess: () => {
          setDialogOpen(false);
          notify.success(
            timing === "scheduled"
              ? "所属変更を予約しました"
              : operation === "add"
                ? "所属メンバーを追加しました"
                : "所属を解除しました",
          );
        },
      },
    );
  };

  return (
    <Card title="所属メンバー">
      <div className="mb-4 flex justify-end">
        <Button variant="secondary" onClick={() => openChange("add")}>
          所属を追加
        </Button>
      </div>

      {group.memberships.length === 0 ? (
        <EmptyState
          title="所属メンバーはいません。"
          description="「所属を追加」からユーザーをこのグループに追加できます。"
        />
      ) : (
        <div className="divide-y divide-border rounded border">
          {group.memberships.map((membership) => (
            <div
              className="flex flex-wrap items-center justify-between gap-3 p-3"
              key={membership.id}
            >
              <div>
                <Link
                  className="font-medium text-foreground hover:text-primary hover:underline"
                  to={`/admin/users/${membership.user_id}`}
                  state={forwardState}
                >
                  {membership.user.name}
                </Link>
                <p className="text-xs text-muted-foreground">
                  {membership.user.email} /{" "}
                  {membershipKindLabel(membership.membership_kind, membership.is_primary)}
                </p>
              </div>
              <Button
                size="sm"
                variant="secondary"
                onClick={() => openChange("remove", membership.user_id)}
              >
                解除
              </Button>
            </div>
          ))}
        </div>
      )}

      <div className="mt-5 border-t border-border pt-4">
        <h3 className="mb-1 text-sm font-semibold">変更履歴</h3>
        <p className="mb-3 text-xs text-muted-foreground">
          このグループに関する直近20件の即時変更と予約を表示します。
        </p>
        {(changes.data ?? []).length === 0 ? (
          <EmptyState title="変更履歴はありません。" />
        ) : (
          <div className="divide-y divide-border">
            {changes.data?.map((change) => (
              <div
                className="flex flex-wrap items-start justify-between gap-3 py-2 text-sm"
                key={change.id}
              >
                <div>
                  <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                    <span className="font-medium text-foreground">
                      {change.user_name ??
                        users.data?.data.find(
                          (user) => user.id === change.user_id,
                        )?.name ??
                        change.user_id}
                    </span>
                    <span>
                      {new Date(change.effective_at).toLocaleString()}
                    </span>
                    <span>{membershipChangeStatusLabel(change.status)}</span>
                  </div>
                  <div className="mt-1 text-sm font-medium text-foreground">
                    {change.items.map((item, index) => (
                      <div key={`${item.operation}-${index}`}>
                        {membershipChangeDescription(item, groupOptions)}
                      </div>
                    ))}
                  </div>
                </div>
                {["draft", "scheduled"].includes(change.status) && (
                  <ConfirmActionDialog
                    triggerLabel="予約を取り消す"
                    triggerVariant="secondary"
                    title="所属変更予約を取り消す"
                    description="この所属変更予約を取り消します。"
                    confirmLabel="取り消す"
                    isPending={cancelChange.isPending}
                    error={cancelChange.error}
                    onConfirm={() =>
                      cancelChange
                        .mutateAsync(change.id)
                        .then(() => notify.success("所属変更の予約を取り消しました"))
                    }
                  />
                )}
              </div>
            ))}
          </div>
        )}
      </div>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {operation === "add" ? "所属メンバーを追加" : "所属を解除"}
            </DialogTitle>
            <DialogDescription>
              即時に反映するか、指定日時に反映するかを選択してください。
            </DialogDescription>
          </DialogHeader>
          <div>
            {operation === "add" ? (
              <FormField
                label="対象ユーザー"
                htmlFor="group-membership-user"
                error={membershipOf("user_id")}
              >
                <NativeSelect
                  id="group-membership-user"
                  value={userId}
                  onChange={(event) => setUserId(event.target.value)}
                >
                  <option value="">ユーザーを選択</option>
                  {availableUsers.map((user) => (
                    <option key={user.id} value={user.id}>
                      {user.name}
                    </option>
                  ))}
                </NativeSelect>
              </FormField>
            ) : (
              <p className="mb-4 rounded bg-muted p-3 text-sm">
                対象: {selectedUser?.name ?? userId}
              </p>
            )}
            {operation === "add" && (
              <FormField
                label="所属区分"
                htmlFor="group-membership-primary"
                error={membershipOfRow("items", 0, "is_primary")}
              >
                <NativeSelect
                  id="group-membership-primary"
                  value={isPrimary ? "primary" : "member"}
                  onChange={(event) =>
                    setIsPrimary(event.target.value === "primary")
                  }
                >
                  <option value="member">通常所属</option>
                  <option value="primary">主所属</option>
                </NativeSelect>
              </FormField>
            )}
            <FormField label="変更タイミング" htmlFor="group-membership-timing">
              <NativeSelect
                id="group-membership-timing"
                value={timing}
                onChange={(event) => {
                  setTiming(event.target.value as "now" | "scheduled");
                  setEffectiveAt("");
                }}
              >
                <option value="now">即時に反映</option>
                <option value="scheduled">日時を指定して予約</option>
              </NativeSelect>
            </FormField>
            {timing === "scheduled" && (
              <FormField
                label="適用日時"
                htmlFor="group-membership-effective-at"
                error={membershipOf("effective_at")}
              >
                <DateTimePicker
                  id="group-membership-effective-at"
                  value={effectiveAt}
                  onChange={(value) => setEffectiveAt(value ?? "")}
                />
              </FormField>
            )}
            <FormField label="メモ" htmlFor="group-membership-note" error={membershipOf("note")}>
              <Input
                id="group-membership-note"
                value={note}
                onChange={(event) => setNote(event.target.value)}
              />
            </FormField>
          </div>
          {membershipRest != null && <ErrorMessage error={membershipRest} />}
          <DialogFooter>
            <Button variant="secondary" onClick={() => setDialogOpen(false)}>
              キャンセル
            </Button>
            <Button
              disabled={!userId || (timing === "scheduled" && !effectiveAt)}
              isLoading={mutation.isPending}
              onClick={submitChange}
            >
              {timing === "scheduled" ? "変更を予約" : "変更を実行"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Card>
  );
}
