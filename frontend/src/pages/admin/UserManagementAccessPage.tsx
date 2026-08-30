import { useMemo, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { Badge } from "../../components/Badge/Badge";
import { Button } from "../../components/Button/Button";
import { Card } from "../../components/Card/Card";
import { ClickableTableRow } from "../../components/ClickableTableRow/ClickableTableRow";
import { ConfirmActionDialog } from "../../components/ConfirmActionDialog/ConfirmActionDialog";
import { AuthenticationKeysPanel } from "../../components/AuthenticationKeysPanel/AuthenticationKeysPanel";
import { DateTimePicker } from "../../components/DateTimePicker/DateTimePicker";
import { EmptyState } from "../../components/EmptyState/EmptyState";
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
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "../../components/ui/table";
import * as userManagement from "../../hooks/useUserManagement";
import { useUsers } from "../../hooks/useUsers";
import type { ChangeItem, MembershipChangeSet } from "../../api/userManagement";
import {
  membershipChangeDescription,
  membershipChangeStatusLabel,
} from "../../utils/membershipChangeLabels";

export function UserManagementAccessPage({
  section = "groups",
}: {
  section?: "groups" | "membershipChanges" | "hr" | "identities";
}) {
  const navigate = useNavigate();
  const groups = userManagement.useManagedGroups(),
    types = userManagement.useGroupTypes(),
    identities = userManagement.useExternalIdentities(section === "identities"),
    authorities = userManagement.useFieldAuthorities(
      section === "identities" || section === "hr",
    ),
    changeSets = userManagement.useMembershipChangeSets(
      section === "membershipChanges",
    ),
    users = useUsers(undefined, 100);
  const linkIdentity = userManagement.useLinkExternalIdentity(),
    unlinkIdentity = userManagement.useUnlinkExternalIdentity(),
    updateAuthority = userManagement.useUpdateFieldAuthority(),
    scheduleChange = userManagement.useScheduleMembershipChange(),
    createDraft = userManagement.useCreateMembershipChangeDraft(),
    updateChange = userManagement.useUpdateMembershipChange(),
    scheduleExisting = userManagement.useScheduleExistingMembershipChange(),
    applyChange = userManagement.useApplyMembershipChange(),
    cancelChange = userManagement.useCancelMembershipChange(),
    previewCsv = userManagement.usePreviewExternalHrCsv(),
    applyCsv = userManagement.useApplyExternalHrImport();
  const [identityForm, setIdentityForm] = useState({
    user_id: "",
    provider: "MICROSOFT_ENTRA",
    external_subject_id: "",
    external_tenant_id: "",
    external_code: "",
    email: "",
  });
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
  const [changeDialogOpen, setChangeDialogOpen] = useState(false);
  const [csvFile, setCsvFile] = useState<File | null>(null);
  const [csvInputKey, setCsvInputKey] = useState(0);
  const [csvApplySummary, setCsvApplySummary] = useState<{
    total: number;
    new: number;
    changed: number;
  } | null>(null);
  const [groupTypeFilter, setGroupTypeFilter] = useState("");
  const queries =
    section === "identities"
      ? [identities, authorities, users]
      : section === "membershipChanges"
        ? [groups, changeSets, users]
        : section === "hr"
          ? [groups]
          : [groups, types, users];
  const mutationError = [
    linkIdentity,
    unlinkIdentity,
    updateAuthority,
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
  const resetChangeEditor = () => {
    setEditingChangeSet("");
    setChangeItems([]);
    setChangeForm({
      user_id: "",
      effective_at: "",
      operation: "add",
      from_group_id: "",
      to_group_id: "",
      note: "",
    });
  };
  const closeChangeDialog = () => {
    setChangeDialogOpen(false);
    resetChangeEditor();
  };
  const openCreateChangeDialog = () => {
    resetChangeEditor();
    setChangeDialogOpen(true);
  };
  const openEditChangeDialog = (changeSet: MembershipChangeSet) => {
    setEditingChangeSet(changeSet.id);
    setChangeItems(changeSet.items);
    setChangeForm({
      user_id: changeSet.user_id,
      effective_at: new Date(changeSet.effective_at).toISOString().slice(0, 16),
      operation: "add",
      from_group_id: "",
      to_group_id: "",
      note: changeSet.note ?? "",
    });
    setChangeDialogOpen(true);
  };
  if (queries.some((x) => x.isLoading)) return <LoadingState />;
  const error = queries.find((x) => x.error)?.error;
  if (error)
    return (
      <ErrorMessage
        error={error}
        fallback="グループ管理設定の取得に失敗しました。"
      />
    );

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-lg font-semibold">
          {section === "groups"
            ? "グループ管理"
            : section === "membershipChanges"
              ? "所属変更"
              : section === "identities"
                ? "ID・管理元設定"
                : "人事データ連携"}
        </h1>
        <p className="text-sm text-muted-foreground">
          {section === "groups"
            ? "グループの基本情報と所属メンバーを管理します。"
            : section === "membershipChanges"
              ? "所属変更の予約、下書き、適用状況を一覧で管理します。"
              : section === "identities"
                ? "外部IDとの連携と、ユーザー項目の管理元を設定します。"
                : "外部HR CSVの差分確認と取込を管理します。"}
        </p>
      </div>
      {mutationError && <ErrorMessage error={mutationError} />}
      {section === "groups" && (
        <>
          <Card
            title="グループ一覧"
            actions={
              <Link
                className="text-sm font-medium text-primary hover:underline"
                to="/admin/groups/new"
              >
                新規グループ
              </Link>
            }
          >
            <NativeSelect
              aria-label="表示するグループ種別"
              className="mb-3"
              value={groupTypeFilter}
              onChange={(event) => setGroupTypeFilter(event.target.value)}
            >
              <option value="">すべてのグループ種別</option>
              {types.data?.map((type) => (
                <option key={type.id} value={type.id}>
                  {type.name}
                </option>
              ))}
            </NativeSelect>
            {visibleGroups?.length === 0 ? (
              <EmptyState
                title={
                  groupTypeFilter
                    ? "条件に一致するグループがありません。"
                    : "グループがまだありません。"
                }
                description={
                  groupTypeFilter
                    ? "グループ種別の絞り込みを変更してください。"
                    : "「新規グループ」からグループを作成すると、社員を組織単位で管理できます。"
                }
                action={
                  groupTypeFilter ? (
                    <Button variant="secondary" onClick={() => setGroupTypeFilter("")}>
                      絞り込みをクリア
                    </Button>
                  ) : undefined
                }
              />
            ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>種別</TableHead>
                  <TableHead>名称</TableHead>
                  <TableHead>親グループ</TableHead>
                  <TableHead>メンバー</TableHead>
                  <TableHead>状態</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {visibleGroups?.map((g) => (
                  <ClickableTableRow
                    key={g.id}
                    onRowClick={() => navigate(`/admin/groups/${g.id}`)}
                    rowLabel={`${g.name}の詳細を開く`}
                  >
                    <TableCell>{g.type.name}</TableCell>
                    <TableCell>
                      <Link
                        className="font-medium text-foreground hover:text-primary hover:underline"
                        to={`/admin/groups/${g.id}`}
                        onClick={(e) => e.stopPropagation()}
                      >
                        {g.name}
                      </Link>
                    </TableCell>
                    <TableCell>
                      {groups.data?.find(
                        (parent) => parent.id === g.parent_group_id,
                      )?.name ?? "親なし"}
                    </TableCell>
                    <TableCell>{g.memberships_count}人</TableCell>
                    <TableCell>
                      <Badge
                        tone={g.status === "active" ? "success" : "neutral"}
                      >
                        {g.status}
                      </Badge>
                    </TableCell>
                  </ClickableTableRow>
                ))}
              </TableBody>
            </Table>
            )}
          </Card>
        </>
      )}

      {section === "identities" && (
        <>
          <Card title="外部ID・項目管理責任">
            <div className="grid gap-2 md:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-7">
              <UserSelect
                value={identityForm.user_id}
                onChange={(v) =>
                  setIdentityForm({ ...identityForm, user_id: v })
                }
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
                      external_tenant_id:
                        identityForm.external_tenant_id || null,
                      external_code: identityForm.external_code || null,
                      email: identityForm.email || null,
                    },
                  })
                }
              >
                リンク
              </Button>
              {(!identityForm.user_id ||
                !identityForm.provider ||
                !identityForm.external_subject_id) && (
                <p className="text-xs text-muted-foreground md:col-span-2 xl:col-span-4 2xl:col-span-7">
                  対象ユーザー・Provider・Subject IDを入力してください。
                </p>
              )}
            </div>
            <div className="mt-3 flex flex-wrap gap-2">
              {(identities.data?.filter((i) => i.status === "active") ?? []).length === 0 && (
                <EmptyState
                  title="連携済みの外部IDはありません。"
                  description="上のフォームから外部IDを紐付けられます。"
                />
              )}
              {identities.data
                ?.filter((i) => i.status === "active")
                .map((i) => (
                  <span
                    className="inline-flex items-center gap-2 rounded border p-2 text-sm"
                    key={i.id}
                  >
                    <span>
                      {i.user.name}: {i.provider} / {i.external_subject_id}
                      <span className="ml-2 text-muted-foreground">
                        最終同期 {i.last_synced_at ?? "-"}
                      </span>
                    </span>
                    <ConfirmActionDialog
                      triggerLabel="解除"
                      title="外部ID連携を解除"
                      description={`${i.user.name}の${i.provider}連携を解除します。`}
                      confirmLabel="解除する"
                      isPending={unlinkIdentity.isPending}
                      error={unlinkIdentity.error}
                      onConfirm={() => unlinkIdentity.mutateAsync(i.id)}
                    />
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
                        authorityType: e.target.value as
                          "LOCAL" | "EXTERNAL_HR",
                        provider:
                          e.target.value === "EXTERNAL_HR"
                            ? "EXTERNAL_HR"
                            : null,
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
          {identityForm.user_id && (
            <Card title="認証キー">
              <AuthenticationKeysPanel userId={identityForm.user_id} />
            </Card>
          )}
        </>
      )}

      {section === "membershipChanges" && (
        <>
          <Card
            title="所属変更一覧"
            actions={
              <Button onClick={openCreateChangeDialog}>変更予約作成</Button>
            }
          >
            <Dialog
              open={changeDialogOpen}
              onOpenChange={(open) => {
                if (!open) closeChangeDialog();
              }}
            >
              <DialogContent size="large" className="sm:max-h-[90vh] sm:max-w-3xl">
                <DialogHeader>
                  <DialogTitle>
                    {editingChangeSet ? "所属変更予約を変更" : "変更予約作成"}
                  </DialogTitle>
                  <DialogDescription>
                    対象ユーザー、適用日時、変更内容を指定してください。複数の変更を一度に予約できます。
                  </DialogDescription>
                </DialogHeader>
                <div className="grid gap-3 md:grid-cols-2">
                  <FormField
                    label="対象ユーザー"
                    htmlFor="membership-change-user"
                  >
                    <UserSelect
                      id="membership-change-user"
                      value={changeForm.user_id}
                      onChange={(v) =>
                        setChangeForm({ ...changeForm, user_id: v })
                      }
                      users={users.data?.data}
                    />
                  </FormField>
                  <FormField
                    label="適用日時"
                    htmlFor="membership-change-effective-at"
                  >
                    <DateTimePicker
                      id="membership-change-effective-at"
                      aria-label="所属変更の適用日時"
                      value={changeForm.effective_at}
                      onChange={(value) =>
                        setChangeForm({
                          ...changeForm,
                          effective_at: value ?? "",
                        })
                      }
                    />
                  </FormField>
                  <FormField
                    label="変更内容"
                    htmlFor="membership-change-operation"
                  >
                    <NativeSelect
                      id="membership-change-operation"
                      value={changeForm.operation}
                      onChange={(e) =>
                        setChangeForm({
                          ...changeForm,
                          operation: e.target
                            .value as typeof changeForm.operation,
                          from_group_id: "",
                          to_group_id: "",
                        })
                      }
                    >
                      <option value="add">所属を追加</option>
                      <option value="remove">所属を解除</option>
                      <option value="replace">所属を置換</option>
                      <option value="set_primary">主所属を変更</option>
                    </NativeSelect>
                  </FormField>
                  {changeForm.operation !== "add" && (
                    <FormField
                      label="変更元グループ"
                      htmlFor="membership-change-from"
                    >
                      <GroupSelect
                        id="membership-change-from"
                        value={changeForm.from_group_id}
                        onChange={(v) =>
                          setChangeForm({ ...changeForm, from_group_id: v })
                        }
                        groups={groups.data}
                      />
                    </FormField>
                  )}
                  {["add", "replace"].includes(changeForm.operation) && (
                    <FormField
                      label="変更先グループ"
                      htmlFor="membership-change-to"
                    >
                      <GroupSelect
                        id="membership-change-to"
                        value={changeForm.to_group_id}
                        onChange={(v) =>
                          setChangeForm({ ...changeForm, to_group_id: v })
                        }
                        groups={groups.data}
                      />
                    </FormField>
                  )}
                  <FormField label="メモ" htmlFor="membership-change-note">
                    <Input
                      id="membership-change-note"
                      value={changeForm.note}
                      onChange={(e) =>
                        setChangeForm({ ...changeForm, note: e.target.value })
                      }
                    />
                  </FormField>
                  <div className="flex items-end pb-4">
                    <Button
                      variant="secondary"
                      disabled={!selectedFrom && !selectedTo}
                      onClick={appendChangeItem}
                    >
                      明細に追加
                    </Button>
                  </div>
                </div>
                <div className="my-3 rounded border p-3">
                  <div className="mb-2 text-sm font-medium">
                    変更明細（{changeItems.length}件）
                  </div>
                  {changeItems.map((item, index) => (
                    <div
                      className="flex flex-wrap items-center justify-between gap-2 text-sm"
                      key={`${item.operation}-${index}`}
                    >
                      <span>
                        {membershipChangeDescription(item, groups.data)}
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
                {(scheduleChange.error ||
                  createDraft.error ||
                  updateChange.error) && (
                  <ErrorMessage
                    error={
                      scheduleChange.error ??
                      createDraft.error ??
                      updateChange.error
                    }
                  />
                )}
                <DialogFooter>
                  <Button variant="secondary" onClick={closeChangeDialog}>
                    キャンセル
                  </Button>
                  {editingChangeSet ? (
                    <Button
                      disabled={
                        !changeForm.user_id ||
                        !changeForm.effective_at ||
                        changeItems.length === 0
                      }
                      isLoading={updateChange.isPending}
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
                          { onSuccess: closeChangeDialog },
                        )
                      }
                    >
                      変更を保存
                    </Button>
                  ) : (
                    <>
                      <Button
                        disabled={
                          !changeForm.user_id ||
                          !changeForm.effective_at ||
                          changeItems.length === 0
                        }
                        variant="secondary"
                        isLoading={createDraft.isPending}
                        onClick={() =>
                          createDraft.mutate(
                            {
                              user_id: changeForm.user_id,
                              effective_at: new Date(
                                changeForm.effective_at,
                              ).toISOString(),
                              source_type: "manual",
                              note: changeForm.note,
                              items: changeItems,
                            },
                            { onSuccess: closeChangeDialog },
                          )
                        }
                      >
                        下書き保存
                      </Button>
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
                              effective_at: new Date(
                                changeForm.effective_at,
                              ).toISOString(),
                              source_type: "manual",
                              note: changeForm.note,
                              items: changeItems,
                            },
                            { onSuccess: closeChangeDialog },
                          )
                        }
                      >
                        変更を予約
                      </Button>
                    </>
                  )}
                </DialogFooter>
              </DialogContent>
            </Dialog>
            {changeSets.data?.length === 0 ? (
              <EmptyState
                title="所属変更の予約はまだありません。"
                description="「変更予約作成」から所属変更を即時または日時指定で予約できます。"
              />
            ) : (
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
                      <span className="text-xs text-muted-foreground">
                        {new Date(s.effective_at).toLocaleString()}
                      </span>
                    </TableCell>
                    <TableCell>
                      {s.user_name ??
                        users.data?.data.find((u) => u.id === s.user_id)
                          ?.name ??
                        s.user_id}
                      <div className="mt-1 text-sm font-medium text-foreground">
                        {s.items.map((item, index) => (
                          <div key={`${item.operation}-${index}`}>
                            {membershipChangeDescription(item, groups.data)}
                          </div>
                        ))}
                      </div>
                    </TableCell>
                    <TableCell>
                      <Badge tone={s.status === "applied" ? "success" : "info"}>
                        {membershipChangeStatusLabel(s.status)}
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
                          onClick={() => openEditChangeDialog(s)}
                        >
                          変更
                        </Button>
                      )}
                      {s.status === "draft" && (
                        <span className="flex flex-wrap gap-2">
                          <Button
                            size="sm"
                            onClick={() => scheduleExisting.mutate(s.id)}
                          >
                            予約確定
                          </Button>
                          <ConfirmActionDialog
                            triggerLabel="取消"
                            title="所属変更を取消"
                            description="この所属変更の下書きを取り消します。"
                            confirmLabel="取り消す"
                            isPending={cancelChange.isPending}
                            error={cancelChange.error}
                            onConfirm={() => cancelChange.mutateAsync(s.id)}
                          />
                        </span>
                      )}
                      {s.status === "scheduled" && (
                        <span className="flex flex-wrap gap-2">
                          <Button
                            size="sm"
                            onClick={() => applyChange.mutate(s.id)}
                          >
                            適用
                          </Button>
                          <ConfirmActionDialog
                            triggerLabel="取消"
                            title="所属変更を取消"
                            description="予約済みの所属変更を取り消します。"
                            confirmLabel="取り消す"
                            isPending={cancelChange.isPending}
                            error={cancelChange.error}
                            onConfirm={() => cancelChange.mutateAsync(s.id)}
                          />
                        </span>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            )}
          </Card>
        </>
      )}

      {section === "hr" && (
        <>
          <Card title="外部HR CSV差分取込">
            <div className="mb-4 rounded-md border border-border bg-muted/40 p-3 text-sm text-muted-foreground">
              <p className="mb-2">
                取り込めるCSVの列構成は次のとおりです。1行目はヘッダー行として扱われ、文字コードはUTF-8(BOMあり・なしどちらも可)を想定しています。
              </p>
              <div className="min-w-0 overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>列名</TableHead>
                      <TableHead>必須</TableHead>
                      <TableHead>内容</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    <TableRow>
                      <TableCell>external_subject_id</TableCell>
                      <TableCell>必須</TableCell>
                      <TableCell>
                        外部HRシステム側の社員ID。空の行は取り込み対象から除外されます。
                      </TableCell>
                    </TableRow>
                    <TableRow>
                      <TableCell>employee_number</TableCell>
                      <TableCell>任意</TableCell>
                      <TableCell>
                        社員番号。external_subject_idで既存社員に一致しない場合の照合キーとして使われます。
                      </TableCell>
                    </TableRow>
                    <TableRow>
                      <TableCell>email</TableCell>
                      <TableCell>任意</TableCell>
                      <TableCell>
                        メールアドレス。employee_numberでも一致しない場合の照合キーとして使われます。
                      </TableCell>
                    </TableRow>
                    <TableRow>
                      <TableCell>group_code</TableCell>
                      <TableCell>任意</TableCell>
                      <TableCell>所属グループのコード。</TableCell>
                    </TableRow>
                    <TableRow>
                      <TableCell>effective_at</TableCell>
                      <TableCell>任意</TableCell>
                      <TableCell>
                        反映日時(未指定の場合は取込実行時点が使われます)。
                      </TableCell>
                    </TableRow>
                    {authorities.data
                      ?.filter((a) => a.authority_type === "EXTERNAL_HR")
                      .map((a) => (
                        <TableRow key={a.field_key}>
                          <TableCell>{a.field_key}</TableCell>
                          <TableCell>任意</TableCell>
                          <TableCell>
                            「外部ID・項目管理責任」で外部HR管理に設定されている項目。
                          </TableCell>
                        </TableRow>
                      ))}
                  </TableBody>
                </Table>
              </div>
              <p className="mt-2">
                上記以外の項目(氏名など)を外部HRから取り込みたい場合は、先に
                <Link to="/admin/identity-settings" className="underline">
                  外部ID・項目管理責任
                </Link>
                の画面で対象項目を「外部HR管理」に設定してください。設定していない項目はCSVに列があっても反映されません。
              </p>
            </div>
            <div className="grid min-w-0 gap-3 md:grid-cols-[minmax(0,1fr)_auto_auto] md:items-end">
              <Input
                key={csvInputKey}
                aria-label="外部HR CSVファイル"
                className="min-w-0"
                type="file"
                accept=".csv,text/csv"
                onChange={(e) => {
                  setCsvFile(e.target.files?.[0] ?? null);
                  setCsvApplySummary(null);
                  previewCsv.reset();
                  applyCsv.reset();
                }}
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
                  onClick={() => {
                    const summary = previewCsv.data.summary;
                    applyCsv.mutate(previewCsv.data.rows, {
                      onSuccess: () => {
                        setCsvApplySummary(summary);
                        setCsvFile(null);
                        setCsvInputKey((key) => key + 1);
                        previewCsv.reset();
                      },
                    });
                  }}
                >
                  確認した差分を適用
                </Button>
              )}
              {!csvFile && (
                <p className="text-xs text-muted-foreground">
                  CSVファイルを選択してください。
                </p>
              )}
              {previewCsv.data && previewCsv.data.summary.changed === 0 && (
                <p className="text-xs text-muted-foreground">
                  適用が必要な差分はありません。
                </p>
              )}
            </div>
            {applyCsv.error && (
              <ErrorMessage
                error={applyCsv.error}
                fallback="差分の適用に失敗しました。"
              />
            )}
            {csvApplySummary && (
              <p className="mt-3 text-sm text-foreground">
                適用しました(新規{csvApplySummary.new}件・変更
                {csvApplySummary.changed}件)。反映結果は
                <Link to="/admin/users" className="underline">
                  ユーザー一覧
                </Link>
                から確認できます。
              </p>
            )}
            {previewCsv.data && (
              <div className="mt-4 min-w-0">
                <p className="mb-3 text-sm">
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
                      <TableHead>グループ</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {previewCsv.data.rows.map((row) => (
                      <TableRow key={row.external_subject_id}>
                        <TableCell>{row.external_subject_id}</TableCell>
                        <TableCell>{row.is_new ? "新規" : "更新"}</TableCell>
                        <TableCell className="min-w-64 whitespace-normal break-words">
                          {Object.entries(row.diff).map(([key, value]) => (
                            <div key={key}>
                              {key}: {String(value.before ?? "")} →{" "}
                              {String(value.after ?? "")}
                            </div>
                          ))}
                        </TableCell>
                        <TableCell>
                          {row.group_code
                            ? (groups.data?.find(
                                (group) => group.code === row.group_code,
                              )?.name ?? "対象グループ不明")
                            : "-"}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            )}
          </Card>
        </>
      )}
    </div>
  );
}

export function UserOperationsPage() {
  return <UserManagementAccessPage section="hr" />;
}

export function MembershipChangesPage() {
  return <UserManagementAccessPage section="membershipChanges" />;
}

export function IdentitySettingsPage() {
  return <UserManagementAccessPage section="identities" />;
}

type UserOption = { id: string; name: string };
function UserSelect({
  id,
  value,
  onChange,
  users,
  disabled = false,
}: {
  id?: string;
  value: string;
  onChange: (v: string) => void;
  users?: UserOption[];
  disabled?: boolean;
}) {
  return (
    <NativeSelect
      id={id}
      disabled={disabled}
      aria-label={id ? undefined : "ユーザー"}
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
  id,
  value,
  onChange,
  groups,
  disabled = false,
}: {
  id?: string;
  value: string;
  onChange: (v: string) => void;
  groups?: { id: string; name: string }[];
  disabled?: boolean;
}) {
  return (
    <NativeSelect
      id={id}
      disabled={disabled}
      aria-label={id ? undefined : "グループ"}
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
