import { useMemo, useState } from "react";
import type * as access from "../../hooks/useAccessControl";
import type { AccessRole, RoleAssignment } from "../../api/accessControl";
import type { ManagedGroup } from "../../api/userManagement";
import { Button } from "../Button/Button";
import { Card } from "../Card/Card";
import { ConfirmActionDialog } from "../ConfirmActionDialog/ConfirmActionDialog";
import { DateTimePicker } from "../DateTimePicker/DateTimePicker";
import { EmptyState } from "../EmptyState/EmptyState";
import { ErrorMessage } from "../ErrorMessage/ErrorMessage";
import { FormField } from "../FormField/FormField";
import { Checkbox } from "../ui/checkbox";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "../ui/dialog";
import { NativeSelect } from "../ui/native-select";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "../ui/table";

const scopeLabel = (scope: RoleAssignment["scope_type"]): string =>
  scope === "global"
    ? "全社"
    : scope === "group"
      ? "グループ"
      : scope === "self"
        ? "本人"
        : "担当承認タスク";

function toDateTimeLocal(value: string | null): string {
  if (!value) return "";
  const date = new Date(value);
  const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);
  return local.toISOString().slice(0, 16);
}

interface AssignmentFormState {
  targetId: string;
  scope_type: "" | RoleAssignment["scope_type"];
  scope_group_id: string;
  include_descendants: boolean;
  starts_at: string;
  ends_at: string;
}

const emptyForm: AssignmentFormState = {
  targetId: "",
  scope_type: "",
  scope_group_id: "",
  include_descendants: false,
  starts_at: "",
  ends_at: "",
};

export interface RoleAssignmentSectionProps {
  /**
   * "pick-group": Roleを固定し、割当先のグループを選択する(ロール定義ページの「割当グループ」)。
   * "pick-role": グループを固定し、割り当てるRoleを選択する(グループ詳細ページのRole割当)。
   */
  mode: "pick-group" | "pick-role";
  /** カードのタイトル。省略時は「割当グループ」。 */
  title?: string;
  roles: AccessRole[];
  groups: ManagedGroup[];
  /** 呼び出し側で対象(固定Role or 固定グループ)にフィルタ済みの、有効な割当一覧。 */
  assignments: RoleAssignment[];
  fixedRoleId?: number;
  fixedGroupId?: string;
  createAssignment: ReturnType<typeof access.useCreateRoleAssignment>;
  updateAssignment: ReturnType<typeof access.useUpdateRoleAssignment>;
  removeAssignment: ReturnType<typeof access.useRemoveRoleAssignment>;
}

/**
 * グループへのRole割当を追加/編集/解除する共通セクション。
 *
 * ロール定義ページ(Role起点で割当先グループを選ぶ)とグループ詳細ページ(グループ起点で
 * 割り当てるRoleを選ぶ)の両方から、主体・対象の一方を固定して再利用する
 * (ユーザーへの直接割当UIは設けない — グループのみ)。
 */
export function RoleAssignmentSection({
  mode,
  title = "割当グループ",
  roles,
  groups,
  assignments,
  fixedRoleId,
  fixedGroupId,
  createAssignment,
  updateAssignment,
  removeAssignment,
}: RoleAssignmentSectionProps) {
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editingId, setEditingId] = useState("");
  const [form, setForm] = useState<AssignmentFormState>(emptyForm);

  const activeRoleId =
    mode === "pick-group" ? fixedRoleId : Number(form.targetId) || undefined;

  const allowedScopes = useMemo(
    () =>
      Array.from(
        new Set(
          roles
            .find((role) => role.id === activeRoleId)
            ?.permissions.flatMap(
              (permission) => permission.allowed_scope_types,
            ) ?? [],
        ),
      ),
    [roles, activeRoleId],
  );

  const closeDialog = () => {
    setDialogOpen(false);
    setEditingId("");
    setForm(emptyForm);
  };

  const openCreateDialog = () => {
    setEditingId("");
    setForm(emptyForm);
    setDialogOpen(true);
  };

  const openEditDialog = (assignment: RoleAssignment) => {
    setEditingId(assignment.id);
    setForm({
      targetId:
        mode === "pick-group"
          ? assignment.subject_id
          : String(assignment.role_id),
      scope_type: assignment.scope_type,
      scope_group_id: assignment.scope_group_id ?? "",
      include_descendants: assignment.include_descendants,
      starts_at: toDateTimeLocal(assignment.starts_at),
      ends_at: toDateTimeLocal(assignment.ends_at),
    });
    setDialogOpen(true);
  };

  const mutationError =
    createAssignment.error ?? updateAssignment.error ?? removeAssignment.error;

  const targetLabel = mode === "pick-group" ? "対象グループ" : "Role";

  return (
    <Card
      title={title}
      actions={<Button onClick={openCreateDialog}>Roleを割り当てる</Button>}
    >
      {mutationError && <ErrorMessage error={mutationError} />}

      <Dialog
        open={dialogOpen}
        onOpenChange={(open) => {
          if (!open) closeDialog();
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{editingId ? "Role割当を編集" : "Roleを割り当てる"}</DialogTitle>
            <DialogDescription>
              {mode === "pick-group"
                ? "このロールを割り当てるグループと対象範囲、有効期間を指定してください。"
                : "このグループに割り当てるRoleと対象範囲、有効期間を指定してください。"}
            </DialogDescription>
          </DialogHeader>
          <FormField label={targetLabel} htmlFor="role-assignment-target">
            <NativeSelect
              id="role-assignment-target"
              disabled={Boolean(editingId)}
              value={form.targetId}
              onChange={(e) =>
                setForm({
                  ...form,
                  targetId: e.target.value,
                  scope_type: "",
                  scope_group_id: "",
                })
              }
            >
              <option value="">{targetLabel}を選択</option>
              {mode === "pick-group"
                ? groups.map((group) => (
                    <option key={group.id} value={group.id}>
                      {group.name}
                    </option>
                  ))
                : roles.map((role) => (
                    <option key={role.id} value={role.id}>
                      {role.name}
                    </option>
                  ))}
            </NativeSelect>
          </FormField>
          <FormField label="対象範囲" htmlFor="role-assignment-scope">
            <NativeSelect
              id="role-assignment-scope"
              value={form.scope_type}
              onChange={(e) =>
                setForm({
                  ...form,
                  scope_type: e.target.value as AssignmentFormState["scope_type"],
                  scope_group_id: "",
                })
              }
            >
              <option value="">対象範囲を選択</option>
              {allowedScopes.map((scope) => (
                <option key={scope} value={scope}>
                  {scopeLabel(scope)}
                </option>
              ))}
            </NativeSelect>
          </FormField>
          {form.scope_type === "group" && (
            <FormField label="対象範囲グループ" htmlFor="role-assignment-scope-group">
              <NativeSelect
                id="role-assignment-scope-group"
                value={form.scope_group_id}
                onChange={(e) =>
                  setForm({ ...form, scope_group_id: e.target.value })
                }
              >
                <option value="">グループを選択</option>
                {groups.map((group) => (
                  <option key={group.id} value={group.id}>
                    {group.name}
                  </option>
                ))}
              </NativeSelect>
            </FormField>
          )}
          <label className="mb-4 flex items-center gap-2 text-sm">
            <Checkbox
              disabled={form.scope_type !== "group"}
              checked={form.include_descendants}
              onCheckedChange={(checked) =>
                setForm({ ...form, include_descendants: checked === true })
              }
            />
            配下を含む
          </label>
          <FormField label="有効開始日時" htmlFor="role-assignment-starts-at">
            <DateTimePicker
              id="role-assignment-starts-at"
              aria-label="Role有効開始日時"
              value={form.starts_at}
              onChange={(value) => setForm({ ...form, starts_at: value ?? "" })}
            />
          </FormField>
          <FormField label="有効終了日時" htmlFor="role-assignment-ends-at">
            <DateTimePicker
              id="role-assignment-ends-at"
              aria-label="Role有効終了日時"
              value={form.ends_at}
              onChange={(value) => setForm({ ...form, ends_at: value ?? "" })}
            />
          </FormField>
          {(createAssignment.error || updateAssignment.error) && (
            <ErrorMessage error={createAssignment.error ?? updateAssignment.error} />
          )}
          <DialogFooter>
            <Button variant="secondary" onClick={closeDialog}>
              キャンセル
            </Button>
            <Button
              disabled={
                !form.targetId ||
                !form.scope_type ||
                (form.scope_type === "group" && !form.scope_group_id)
              }
              isLoading={
                editingId ? updateAssignment.isPending : createAssignment.isPending
              }
              onClick={() => {
                const scope = {
                  scope_type: form.scope_type as Exclude<
                    AssignmentFormState["scope_type"],
                    ""
                  >,
                  scope_group_id:
                    form.scope_type === "group" ? form.scope_group_id : null,
                  include_descendants: form.include_descendants,
                  starts_at: form.starts_at
                    ? new Date(form.starts_at).toISOString()
                    : null,
                  ends_at: form.ends_at
                    ? new Date(form.ends_at).toISOString()
                    : null,
                };
                if (editingId) {
                  updateAssignment.mutate(
                    { id: editingId, input: scope },
                    { onSuccess: closeDialog },
                  );
                } else {
                  createAssignment.mutate(
                    {
                      subject_type: "group",
                      subject_id:
                        mode === "pick-group" ? form.targetId : (fixedGroupId ?? ""),
                      role_id:
                        mode === "pick-group"
                          ? (fixedRoleId ?? 0)
                          : Number(form.targetId),
                      ...scope,
                    },
                    { onSuccess: closeDialog },
                  );
                }
              }}
            >
              {editingId ? "保存" : "割り当てる"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {assignments.length === 0 ? (
        <EmptyState
          title="有効なRole割当はまだありません。"
          description="「Roleを割り当てる」から追加できます。"
        />
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{targetLabel}</TableHead>
              <TableHead>対象範囲</TableHead>
              <TableHead>有効期間</TableHead>
              <TableHead>操作</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {assignments.map((assignment) => (
              <TableRow key={assignment.id}>
                <TableCell>
                  {mode === "pick-group"
                    ? (groups.find((g) => g.id === assignment.subject_id)?.name ??
                      assignment.subject_id)
                    : assignment.role?.name}
                </TableCell>
                <TableCell>
                  {scopeLabel(assignment.scope_type)}
                  {assignment.scope_type === "group" &&
                    assignment.include_descendants &&
                    "(配下を含む)"}
                </TableCell>
                <TableCell className="text-xs text-muted-foreground">
                  {assignment.starts_at
                    ? new Date(assignment.starts_at).toLocaleString()
                    : "開始日時なし"}
                  {" 〜 "}
                  {assignment.ends_at
                    ? new Date(assignment.ends_at).toLocaleString()
                    : "終了日時なし"}
                </TableCell>
                <TableCell>
                  <div className="flex flex-wrap gap-2">
                    <Button
                      size="sm"
                      variant="secondary"
                      onClick={() => openEditDialog(assignment)}
                    >
                      編集
                    </Button>
                    <ConfirmActionDialog
                      triggerLabel="解除"
                      title="Role割当を解除"
                      description={`${
                        mode === "pick-group"
                          ? (groups.find((g) => g.id === assignment.subject_id)
                              ?.name ?? "このグループ")
                          : (assignment.role?.name ?? "このRole")
                      }の割当を解除します。この操作は元に戻せません。`}
                      confirmLabel="解除する"
                      isPending={removeAssignment.isPending}
                      error={removeAssignment.error}
                      onConfirm={() => removeAssignment.mutateAsync(assignment.id)}
                    />
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </Card>
  );
}
