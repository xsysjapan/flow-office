import { useMemo, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { Badge } from "../../components/Badge/Badge";
import { Button } from "../../components/Button/Button";
import { Card } from "../../components/Card/Card";
import { ConfirmActionDialog } from "../../components/ConfirmActionDialog/ConfirmActionDialog";
import { DateTimePicker } from "../../components/DateTimePicker/DateTimePicker";
import { EmptyState } from "../../components/EmptyState/EmptyState";
import { ErrorMessage } from "../../components/ErrorMessage/ErrorMessage";
import { FormField } from "../../components/FormField/FormField";
import { LoadingState } from "../../components/LoadingState/LoadingState";
import { UserPicker } from "../../components/UserPicker/UserPicker";
import { Checkbox } from "../../components/ui/checkbox";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "../../components/ui/dialog";
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
import { Tabs, TabsList, TabsTrigger } from "../../components/ui/tabs";
import * as access from "../../hooks/useAccessControl";
import * as userManagement from "../../hooks/useUserManagement";
import type { RoleAssignment } from "../../api/accessControl";

type SubjectType = "user" | "group";

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
  role_id: string;
  scope_type: "" | RoleAssignment["scope_type"];
  scope_group_id: string;
  include_descendants: boolean;
  starts_at: string;
  ends_at: string;
}

const emptyAssignmentForm: AssignmentFormState = {
  role_id: "",
  scope_type: "",
  scope_group_id: "",
  include_descendants: false,
  starts_at: "",
  ends_at: "",
};

export function RoleAssignmentPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const subjectType = (searchParams.get("subjectType") as SubjectType) || "user";
  const subjectId = searchParams.get("subjectId") ?? "";

  const groups = userManagement.useManagedGroups();
  const roles = access.useAccessRoles();
  const assignments = access.useRoleAssignments();
  const suspensions = access.useFeatureSuspensions(subjectType === "user");
  const features = access.useFeatures(subjectType === "user");

  const createAssignment = access.useCreateRoleAssignment();
  const updateAssignment = access.useUpdateRoleAssignment();
  const removeAssignment = access.useRemoveRoleAssignment();
  const suspendFeature = access.useSuspendUserFeature();
  const removeSuspension = access.useRemoveFeatureSuspension();

  const [assignmentDialogOpen, setAssignmentDialogOpen] = useState(false);
  const [editingAssignmentId, setEditingAssignmentId] = useState("");
  const [assignmentForm, setAssignmentForm] = useState<AssignmentFormState>(
    emptyAssignmentForm,
  );

  const [suspensionForm, setSuspensionForm] = useState({
    feature_id: "",
    reason: "",
    starts_at: "",
    ends_at: "",
  });

  const selectedGroup = useMemo(
    () => groups.data?.find((g) => g.id === subjectId),
    [groups.data, subjectId],
  );

  const allowedRoleScopes = useMemo(
    () =>
      Array.from(
        new Set(
          roles.data
            ?.find((role) => role.id === Number(assignmentForm.role_id))
            ?.permissions.flatMap(
              (permission) => permission.allowed_scope_types,
            ) ?? [],
        ),
      ),
    [roles.data, assignmentForm.role_id],
  );

  const subjectAssignments = useMemo(
    () =>
      (assignments.data ?? []).filter(
        (a) =>
          a.subject_type === subjectType &&
          a.subject_id === subjectId &&
          a.status === "active",
      ),
    [assignments.data, subjectType, subjectId],
  );

  const subjectSuspensions = useMemo(
    () =>
      subjectType === "user"
        ? (suspensions.data ?? []).filter((s) => s.user_id === subjectId)
        : [],
    [suspensions.data, subjectType, subjectId],
  );

  const handleSubjectTypeChange = (nextType: SubjectType) => {
    setSearchParams((params) => {
      const next = new URLSearchParams(params);
      next.set("subjectType", nextType);
      next.delete("subjectId");
      return next;
    });
  };

  const handleSubjectIdChange = (nextId: string | undefined) => {
    setSearchParams((params) => {
      const next = new URLSearchParams(params);
      if (nextId) next.set("subjectId", nextId);
      else next.delete("subjectId");
      return next;
    });
  };

  const closeAssignmentDialog = () => {
    setAssignmentDialogOpen(false);
    setEditingAssignmentId("");
    setAssignmentForm(emptyAssignmentForm);
  };

  const openCreateAssignmentDialog = () => {
    setEditingAssignmentId("");
    setAssignmentForm(emptyAssignmentForm);
    setAssignmentDialogOpen(true);
  };

  const openEditAssignmentDialog = (assignment: RoleAssignment) => {
    setEditingAssignmentId(assignment.id);
    setAssignmentForm({
      role_id: String(assignment.role_id),
      scope_type: assignment.scope_type,
      scope_group_id: assignment.scope_group_id ?? "",
      include_descendants: assignment.include_descendants,
      starts_at: toDateTimeLocal(assignment.starts_at),
      ends_at: toDateTimeLocal(assignment.ends_at),
    });
    setAssignmentDialogOpen(true);
  };

  const assignmentMutationError =
    createAssignment.error ?? updateAssignment.error ?? removeAssignment.error;
  const suspensionMutationError =
    suspendFeature.error ?? removeSuspension.error;

  const queries =
    subjectType === "user"
      ? [groups, roles, assignments, suspensions]
      : [groups, roles, assignments];
  const isLoading = queries.some((q) => q.isLoading);
  const loadError = queries.find((q) => q.error)?.error;

  if (isLoading) return <LoadingState />;
  if (loadError)
    return (
      <ErrorMessage
        error={loadError}
        fallback="ロール割当情報の取得に失敗しました。"
      />
    );

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-lg font-semibold">ロール割当</h1>
        <p className="text-sm text-muted-foreground">
          ユーザーまたはグループを選び、Roleの割当や個別のFeature停止を管理します。
        </p>
      </div>

      <Card title="対象を選択">
        <div className="flex flex-col gap-3 md:flex-row md:items-end">
          <div>
            <p className="mb-1.5 text-sm font-medium text-foreground">対象種別</p>
            <Tabs
              value={subjectType}
              onValueChange={(value) =>
                handleSubjectTypeChange(value as SubjectType)
              }
            >
              <TabsList aria-label="対象種別">
                <TabsTrigger value="user">ユーザー</TabsTrigger>
                <TabsTrigger value="group">グループ</TabsTrigger>
              </TabsList>
            </Tabs>
          </div>
          {subjectType === "user" ? (
            <FormField label="対象ユーザー" htmlFor="subject-user">
              <UserPicker
                id="subject-user"
                value={subjectId || undefined}
                onChange={handleSubjectIdChange}
              />
            </FormField>
          ) : (
            <FormField label="対象グループ" htmlFor="subject-group">
              <NativeSelect
                id="subject-group"
                value={subjectId}
                onChange={(e) => handleSubjectIdChange(e.target.value || undefined)}
              >
                <option value="">グループを選択</option>
                {groups.data?.map((group) => (
                  <option key={group.id} value={group.id}>
                    {group.name}
                  </option>
                ))}
              </NativeSelect>
            </FormField>
          )}
        </div>
      </Card>

      {!subjectId ? (
        <Card title="詳細">
          <EmptyState
            title="ユーザーまたはグループを選択してください。"
            description="対象を選ぶと、その対象に割り当てられているRoleや個別のFeature停止状況を確認・編集できます。"
          />
        </Card>
      ) : (
        <>
          <h2 className="text-base font-semibold">
            {subjectType === "user"
              ? "選択したユーザーの詳細"
              : (selectedGroup?.name ?? "選択したグループ")}
          </h2>

          {assignmentMutationError && (
            <ErrorMessage error={assignmentMutationError} />
          )}

          <Card
            title="Role割当"
            actions={
              <Button onClick={openCreateAssignmentDialog}>Roleを追加</Button>
            }
          >
            <Dialog
              open={assignmentDialogOpen}
              onOpenChange={(open) => {
                if (!open) closeAssignmentDialog();
              }}
            >
              <DialogContent>
                <DialogHeader>
                  <DialogTitle>
                    {editingAssignmentId ? "Role割当を編集" : "Roleを追加"}
                  </DialogTitle>
                  <DialogDescription>
                    付与するRoleと対象範囲、有効期間を指定してください。
                  </DialogDescription>
                </DialogHeader>
                <FormField label="Role" htmlFor="assignment-role">
                  <NativeSelect
                    id="assignment-role"
                    disabled={Boolean(editingAssignmentId)}
                    value={assignmentForm.role_id}
                    onChange={(e) =>
                      setAssignmentForm({
                        ...assignmentForm,
                        role_id: e.target.value,
                        scope_type: "",
                        scope_group_id: "",
                      })
                    }
                  >
                    <option value="">Roleを選択</option>
                    {roles.data?.map((role) => (
                      <option key={role.id} value={role.id}>
                        {role.name}
                      </option>
                    ))}
                  </NativeSelect>
                </FormField>
                <FormField label="対象範囲" htmlFor="assignment-scope">
                  <NativeSelect
                    id="assignment-scope"
                    value={assignmentForm.scope_type}
                    onChange={(e) =>
                      setAssignmentForm({
                        ...assignmentForm,
                        scope_type: e.target
                          .value as AssignmentFormState["scope_type"],
                        scope_group_id: "",
                      })
                    }
                  >
                    <option value="">対象範囲を選択</option>
                    {allowedRoleScopes.map((scope) => (
                      <option key={scope} value={scope}>
                        {scopeLabel(scope)}
                      </option>
                    ))}
                  </NativeSelect>
                </FormField>
                {assignmentForm.scope_type === "group" && (
                  <FormField label="対象グループ" htmlFor="assignment-scope-group">
                    <NativeSelect
                      id="assignment-scope-group"
                      value={assignmentForm.scope_group_id}
                      onChange={(e) =>
                        setAssignmentForm({
                          ...assignmentForm,
                          scope_group_id: e.target.value,
                        })
                      }
                    >
                      <option value="">グループを選択</option>
                      {groups.data?.map((group) => (
                        <option key={group.id} value={group.id}>
                          {group.name}
                        </option>
                      ))}
                    </NativeSelect>
                  </FormField>
                )}
                <label className="mb-4 flex items-center gap-2 text-sm">
                  <Checkbox
                    disabled={assignmentForm.scope_type !== "group"}
                    checked={assignmentForm.include_descendants}
                    onCheckedChange={(checked) =>
                      setAssignmentForm({
                        ...assignmentForm,
                        include_descendants: checked === true,
                      })
                    }
                  />
                  配下を含む
                </label>
                <FormField label="有効開始日時" htmlFor="assignment-starts-at">
                  <DateTimePicker
                    id="assignment-starts-at"
                    aria-label="Role有効開始日時"
                    value={assignmentForm.starts_at}
                    onChange={(value) =>
                      setAssignmentForm({
                        ...assignmentForm,
                        starts_at: value ?? "",
                      })
                    }
                  />
                </FormField>
                <FormField label="有効終了日時" htmlFor="assignment-ends-at">
                  <DateTimePicker
                    id="assignment-ends-at"
                    aria-label="Role有効終了日時"
                    value={assignmentForm.ends_at}
                    onChange={(value) =>
                      setAssignmentForm({
                        ...assignmentForm,
                        ends_at: value ?? "",
                      })
                    }
                  />
                </FormField>
                {(createAssignment.error || updateAssignment.error) && (
                  <ErrorMessage
                    error={createAssignment.error ?? updateAssignment.error}
                  />
                )}
                <DialogFooter>
                  <Button variant="secondary" onClick={closeAssignmentDialog}>
                    キャンセル
                  </Button>
                  <Button
                    disabled={
                      !assignmentForm.role_id ||
                      !assignmentForm.scope_type ||
                      (assignmentForm.scope_type === "group" &&
                        !assignmentForm.scope_group_id)
                    }
                    isLoading={
                      editingAssignmentId
                        ? updateAssignment.isPending
                        : createAssignment.isPending
                    }
                    onClick={() => {
                      const scope = {
                        scope_type: assignmentForm.scope_type as Exclude<
                          AssignmentFormState["scope_type"],
                          ""
                        >,
                        scope_group_id:
                          assignmentForm.scope_type === "group"
                            ? assignmentForm.scope_group_id
                            : null,
                        include_descendants: assignmentForm.include_descendants,
                        starts_at: assignmentForm.starts_at
                          ? new Date(assignmentForm.starts_at).toISOString()
                          : null,
                        ends_at: assignmentForm.ends_at
                          ? new Date(assignmentForm.ends_at).toISOString()
                          : null,
                      };
                      if (editingAssignmentId)
                        updateAssignment.mutate(
                          { id: editingAssignmentId, input: scope },
                          { onSuccess: closeAssignmentDialog },
                        );
                      else
                        createAssignment.mutate(
                          {
                            subject_type: subjectType,
                            subject_id: subjectId,
                            role_id: Number(assignmentForm.role_id),
                            ...scope,
                          },
                          { onSuccess: closeAssignmentDialog },
                        );
                    }}
                  >
                    {editingAssignmentId ? "保存" : "追加"}
                  </Button>
                </DialogFooter>
              </DialogContent>
            </Dialog>

            {subjectAssignments.length === 0 ? (
              <EmptyState
                title="有効なRole割当はまだありません。"
                description="「Roleを追加」からこの対象にRoleを割り当てられます。"
              />
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Role</TableHead>
                    <TableHead>対象範囲</TableHead>
                    <TableHead>有効期間</TableHead>
                    <TableHead>操作</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {subjectAssignments.map((a) => (
                    <TableRow key={a.id}>
                      <TableCell>{a.role?.name}</TableCell>
                      <TableCell>
                        {scopeLabel(a.scope_type)}
                        {a.scope_type === "group" &&
                          a.include_descendants &&
                          "(配下を含む)"}
                      </TableCell>
                      <TableCell className="text-xs text-muted-foreground">
                        {a.starts_at
                          ? new Date(a.starts_at).toLocaleString()
                          : "開始日時なし"}
                        {" 〜 "}
                        {a.ends_at
                          ? new Date(a.ends_at).toLocaleString()
                          : "終了日時なし"}
                      </TableCell>
                      <TableCell>
                        <div className="flex flex-wrap gap-2">
                          <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => openEditAssignmentDialog(a)}
                          >
                            編集
                          </Button>
                          <ConfirmActionDialog
                            triggerLabel="解除"
                            title="Role割当を解除"
                            description={`${a.role?.name ?? "このRole"}の割当を解除します。この操作は元に戻せません。`}
                            confirmLabel="解除する"
                            isPending={removeAssignment.isPending}
                            error={removeAssignment.error}
                            onConfirm={() => removeAssignment.mutateAsync(a.id)}
                          />
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </Card>

          {subjectType === "user" && (
            <Card title="個別Feature停止">
              {suspensionMutationError && (
                <ErrorMessage error={suspensionMutationError} />
              )}
              <div className="grid gap-2 md:grid-cols-2 lg:grid-cols-4">
                <FormField label="Feature" htmlFor="suspension-feature">
                  <NativeSelect
                    id="suspension-feature"
                    value={suspensionForm.feature_id}
                    onChange={(e) =>
                      setSuspensionForm({
                        ...suspensionForm,
                        feature_id: e.target.value,
                      })
                    }
                  >
                    <option value="">Featureを選択</option>
                    {features.data?.map((feature) => (
                      <option key={feature.id} value={feature.id}>
                        {feature.name}
                      </option>
                    ))}
                  </NativeSelect>
                </FormField>
                <FormField label="停止理由" htmlFor="suspension-reason">
                  <Input
                    id="suspension-reason"
                    value={suspensionForm.reason}
                    onChange={(e) =>
                      setSuspensionForm({
                        ...suspensionForm,
                        reason: e.target.value,
                      })
                    }
                  />
                </FormField>
                <FormField label="停止開始日時" htmlFor="suspension-starts-at">
                  <DateTimePicker
                    id="suspension-starts-at"
                    aria-label="停止開始日時"
                    value={suspensionForm.starts_at}
                    onChange={(value) =>
                      setSuspensionForm({
                        ...suspensionForm,
                        starts_at: value ?? "",
                      })
                    }
                  />
                </FormField>
                <FormField label="停止終了日時" htmlFor="suspension-ends-at">
                  <DateTimePicker
                    id="suspension-ends-at"
                    aria-label="停止終了日時"
                    value={suspensionForm.ends_at}
                    onChange={(value) =>
                      setSuspensionForm({
                        ...suspensionForm,
                        ends_at: value ?? "",
                      })
                    }
                  />
                </FormField>
              </div>
              <div className="mt-3">
                <Button
                  disabled={
                    !suspensionForm.feature_id || !suspensionForm.reason
                  }
                  isLoading={suspendFeature.isPending}
                  onClick={() =>
                    suspendFeature.mutate(
                      {
                        user_id: subjectId,
                        feature_id: Number(suspensionForm.feature_id),
                        reason: suspensionForm.reason,
                        starts_at: suspensionForm.starts_at
                          ? new Date(suspensionForm.starts_at).toISOString()
                          : null,
                        ends_at: suspensionForm.ends_at
                          ? new Date(suspensionForm.ends_at).toISOString()
                          : null,
                      },
                      {
                        onSuccess: () =>
                          setSuspensionForm({
                            feature_id: "",
                            reason: "",
                            starts_at: "",
                            ends_at: "",
                          }),
                      },
                    )
                  }
                >
                  停止
                </Button>
                {(!suspensionForm.feature_id || !suspensionForm.reason) && (
                  <p className="mt-1 text-xs text-muted-foreground">
                    Featureと停止理由を入力してください。
                  </p>
                )}
              </div>
              <div className="mt-4">
                {subjectSuspensions.length === 0 ? (
                  <EmptyState
                    title="個別停止中のFeatureはありません。"
                    description="上のフォームからこのユーザーのFeatureを個別に停止できます。"
                  />
                ) : (
                  <div className="flex flex-wrap gap-2">
                    {subjectSuspensions.map((s) => (
                      <span
                        className="inline-flex items-center gap-2 rounded border p-2 text-sm"
                        key={s.id}
                      >
                        {s.feature.name} ({s.reason})
                        <ConfirmActionDialog
                          triggerLabel="解除"
                          triggerVariant="secondary"
                          title="個別Feature停止を解除"
                          description={`${s.feature.name}の停止を解除します。`}
                          confirmLabel="解除する"
                          isPending={removeSuspension.isPending}
                          error={removeSuspension.error}
                          onConfirm={() => removeSuspension.mutateAsync(s.id)}
                        />
                      </span>
                    ))}
                  </div>
                )}
              </div>
            </Card>
          )}

          {subjectType === "group" && (
            <Card
              title="有効なFeature(参照専用)"
              actions={
                <Link
                  className="text-sm font-medium text-primary hover:underline"
                  to="/admin/access/roles"
                >
                  ロール定義ページで編集
                </Link>
              }
            >
              {!selectedGroup || selectedGroup.features.length === 0 ? (
                <EmptyState
                  title="有効なFeatureはありません。"
                  description="このグループに割り当てられたRoleにFeatureを追加すると、ここに表示されます。"
                />
              ) : (
                <div className="flex flex-wrap gap-2">
                  {selectedGroup.features.map((feature) => (
                    <Badge tone="info" key={feature.id}>
                      {feature.name}
                    </Badge>
                  ))}
                </div>
              )}
            </Card>
          )}
        </>
      )}
    </div>
  );
}
