import { useEffect, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { Button } from "../../components/Button/Button";
import { Card } from "../../components/Card/Card";
import { ConfirmActionDialog } from "../../components/ConfirmActionDialog/ConfirmActionDialog";
import { DateTimePicker } from "../../components/DateTimePicker/DateTimePicker";
import { ErrorMessage } from "../../components/ErrorMessage/ErrorMessage";
import { FormField } from "../../components/FormField/FormField";
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
import { useUsers } from "../../hooks/useUsers";
import type { ManagedGroup } from "../../api/userManagement";
import {
  membershipChangeDescription,
  membershipChangeStatusLabel,
} from "../../utils/membershipChangeLabels";

export function GroupDetailPage() {
  const { id = "new" } = useParams<{ id: string }>();
  const isNew = id === "new";
  const navigate = useNavigate();
  const groups = useManagedGroups();
  const types = useGroupTypes();
  const createGroup = useCreateGroup();
  const updateGroup = useUpdateGroup();
  const [initializedId, setInitializedId] = useState("");
  const [form, setForm] = useState({
    group_type_id: "",
    name: "",
    parent_group_id: "",
    status: "active",
  });

  const group = groups.data?.find((item) => item.id === id);
  useEffect(() => {
    if (isNew || !group || initializedId === group.id) return;
    setForm({
      group_type_id: String(group.group_type_id),
      name: group.name,
      parent_group_id: group.parent_group_id ?? "",
      status: group.status,
    });
    setInitializedId(group.id);
  }, [group, initializedId, isNew]);

  if (groups.isLoading || types.isLoading) return <LoadingState />;
  if (groups.error || types.error) {
    return (
      <ErrorMessage
        error={groups.error ?? types.error}
        fallback="グループ詳細の取得に失敗しました。"
      />
    );
  }
  if (!isNew && !group) {
    return (
      <p className="text-sm text-muted-foreground">
        グループが見つかりません。
      </p>
    );
  }

  const mutation = isNew ? createGroup : updateGroup;
  const parentCandidates = groups.data?.filter(
    (candidate) =>
      candidate.id !== id &&
      candidate.status === "active" &&
      String(candidate.group_type_id) === form.group_type_id,
  );

  return (
    <div className="space-y-4">
      <Card title={isNew ? "グループを新規作成" : `${group!.name}の詳細`}>
        {mutation.error && <ErrorMessage error={mutation.error} />}
        <div className="grid gap-3 md:grid-cols-2">
          <FormField label="グループ種別" htmlFor="group-detail-type">
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
          <FormField label="名称" htmlFor="group-detail-name">
            <Input
              id="group-detail-name"
              value={form.name}
              onChange={(event) =>
                setForm({ ...form, name: event.target.value })
              }
            />
          </FormField>
          <FormField label="親グループ" htmlFor="group-detail-parent">
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
            <FormField label="状態" htmlFor="group-detail-status">
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
        </div>
        <div className="mt-4 flex flex-wrap gap-2">
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
                  },
                  { onSuccess: () => navigate("/admin/groups") },
                );
                return;
              }
              updateGroup.mutate({
                id,
                input: {
                  name: form.name,
                  parent_group_id: form.parent_group_id || null,
                  status: form.status,
                },
              });
            }}
          >
            {isNew ? "作成する" : "変更を保存"}
          </Button>
        </div>
      </Card>
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
      { onSuccess: () => setDialogOpen(false) },
    );
  };

  return (
    <Card title="所属メンバー">
      {(applyNow.error || scheduleChange.error || cancelChange.error) && (
        <ErrorMessage
          error={applyNow.error ?? scheduleChange.error ?? cancelChange.error}
        />
      )}
      <div className="mb-4 flex justify-end">
        <Button variant="secondary" onClick={() => openChange("add")}>
          所属を追加
        </Button>
      </div>

      {group.memberships.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          所属メンバーはいません。
        </p>
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
                >
                  {membership.user.name}
                </Link>
                <p className="text-xs text-muted-foreground">
                  {membership.user.email} / {membership.membership_kind}
                  {membership.is_primary ? " / 主所属" : ""}
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
          <p className="text-sm text-muted-foreground">
            変更履歴はありません。
          </p>
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
                    onConfirm={() => cancelChange.mutateAsync(change.id)}
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
          <div className="grid gap-3">
            {operation === "add" ? (
              <FormField label="対象ユーザー" htmlFor="group-membership-user">
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
              <p className="rounded bg-muted p-3 text-sm">
                対象: {selectedUser?.name ?? userId}
              </p>
            )}
            {operation === "add" && (
              <FormField label="所属区分" htmlFor="group-membership-primary">
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
              >
                <DateTimePicker
                  id="group-membership-effective-at"
                  value={effectiveAt}
                  onChange={(value) => setEffectiveAt(value ?? "")}
                />
              </FormField>
            )}
            <FormField label="メモ" htmlFor="group-membership-note">
              <Input
                id="group-membership-note"
                value={note}
                onChange={(event) => setNote(event.target.value)}
              />
            </FormField>
          </div>
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
