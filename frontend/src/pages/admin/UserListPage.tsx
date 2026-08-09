import { useState } from "react";
import { Link } from "react-router-dom";
import { Badge } from "../../components/Badge/Badge";
import { Card } from "../../components/Card/Card";
import { Button } from "../../components/Button/Button";
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
import { useCreateUser, useUsers } from "../../hooks/useUsers";

/**
 * UC-M001: ユーザーを検索し、権限編集画面へ遷移する一覧。
 */
export function UserListPage() {
  const [query, setQuery] = useState("");
  const [accountStatus, setAccountStatus] = useState("");
  const [externalUnlinked, setExternalUnlinked] = useState(false);
  const [externalHr, setExternalHr] = useState(false);
  const [groupId, setGroupId] = useState("");
  const [groupTypeId, setGroupTypeId] = useState("");
  const [showCreate, setShowCreate] = useState(false);
  const [newUser, setNewUser] = useState({
    name: "",
    email: "",
    employee_number: "",
    department: "",
    job_title: "",
  });
  const createUser = useCreateUser();
  const { data, isLoading, error } = useUsers(query, undefined, {
    account_status: accountStatus || undefined,
    external_unlinked: externalUnlinked || undefined,
    external_hr: externalHr || undefined,
    group_id: groupId || undefined,
    group_type_id: groupTypeId ? Number(groupTypeId) : undefined,
  });

  if (isLoading) return <LoadingState />;
  if (error)
    return (
      <ErrorMessage
        error={error}
        fallback="ユーザー一覧の取得に失敗しました。"
      />
    );

  const users = data?.data ?? [];
  const groupOptions = Array.from(
    new Map(
      users
        .flatMap((user) => user.memberships ?? [])
        .map((membership) => [membership.group.id, membership.group]),
    ).values(),
  );
  const groupTypeOptions = Array.from(
    new Map(
      groupOptions.map((group) => [
        group.group_type_id,
        { id: group.group_type_id, code: group.group_type },
      ]),
    ).values(),
  );

  return (
    <Card
      title="ユーザー一覧"
      actions={
        <Link
          className="text-sm font-medium text-primary hover:underline"
          to="/admin/users/operations"
        >
          外部ID・HR連携
        </Link>
      }
    >
      <div className="mb-4">
        <Button
          variant="secondary"
          onClick={() => setShowCreate((value) => !value)}
        >
          {showCreate ? "登録フォームを閉じる" : "ユーザーを登録"}
        </Button>
        {showCreate && (
          <div className="mt-3 grid gap-2 rounded border p-3 md:grid-cols-3">
            <Input
              aria-label="氏名"
              placeholder="氏名"
              value={newUser.name}
              onChange={(e) => setNewUser({ ...newUser, name: e.target.value })}
            />
            <Input
              aria-label="メールアドレス"
              type="email"
              placeholder="メールアドレス"
              value={newUser.email}
              onChange={(e) =>
                setNewUser({ ...newUser, email: e.target.value })
              }
            />
            <Input
              aria-label="社員番号"
              placeholder="社員番号（任意）"
              value={newUser.employee_number}
              onChange={(e) =>
                setNewUser({ ...newUser, employee_number: e.target.value })
              }
            />
            <Input
              aria-label="部署"
              placeholder="部署（任意）"
              value={newUser.department}
              onChange={(e) =>
                setNewUser({ ...newUser, department: e.target.value })
              }
            />
            <Input
              aria-label="役職"
              placeholder="役職（任意）"
              value={newUser.job_title}
              onChange={(e) =>
                setNewUser({ ...newUser, job_title: e.target.value })
              }
            />
            <Button
              disabled={!newUser.name || !newUser.email}
              isLoading={createUser.isPending}
              onClick={() =>
                createUser.mutate(
                  {
                    name: newUser.name,
                    email: newUser.email,
                    employee_number: newUser.employee_number || null,
                    department: newUser.department || null,
                    job_title: newUser.job_title || null,
                    employment_status: "active",
                    account_status: "active",
                  },
                  {
                    onSuccess: () => {
                      setNewUser({
                        name: "",
                        email: "",
                        employee_number: "",
                        department: "",
                        job_title: "",
                      });
                      setShowCreate(false);
                    },
                  },
                )
              }
            >
              登録する
            </Button>
            {createUser.error && (
              <div className="md:col-span-3">
                <ErrorMessage error={createUser.error} />
              </div>
            )}
          </div>
        )}
      </div>
      <Input
        className="mb-4 max-w-xs"
        placeholder="氏名またはメールアドレスで検索"
        value={query}
        onChange={(e) => setQuery(e.target.value)}
      />
      <div className="mb-4 flex flex-wrap gap-3">
        <NativeSelect
          aria-label="GroupTypeで絞り込み"
          value={groupTypeId}
          onChange={(e) => setGroupTypeId(e.target.value)}
        >
          <option value="">全GroupType</option>
          {groupTypeOptions.map((type) => (
            <option key={type.id} value={type.id}>
              {type.code}
            </option>
          ))}
        </NativeSelect>
        <NativeSelect
          aria-label="Groupで絞り込み"
          value={groupId}
          onChange={(e) => setGroupId(e.target.value)}
        >
          <option value="">全Group</option>
          {groupOptions.map((group) => (
            <option key={group.id} value={group.id}>
              {group.name}
            </option>
          ))}
        </NativeSelect>
        <NativeSelect
          value={accountStatus}
          onChange={(e) => setAccountStatus(e.target.value)}
        >
          <option value="">全アカウント状態</option>
          {[
            "pending",
            "active",
            "suspended",
            "leave",
            "retired",
            "disabled",
          ].map((status) => (
            <option key={status}>{status}</option>
          ))}
        </NativeSelect>
        <label className="flex items-center gap-2">
          <input
            type="checkbox"
            checked={externalUnlinked}
            onChange={(e) => setExternalUnlinked(e.target.checked)}
          />
          Microsoft未連携
        </label>
        <label className="flex items-center gap-2">
          <input
            type="checkbox"
            checked={externalHr}
            onChange={(e) => setExternalHr(e.target.checked)}
          />
          外部HR管理
        </label>
      </div>

      {users.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          該当するユーザーはいません。
        </p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>氏名</TableHead>
              <TableHead>メールアドレス</TableHead>
              <TableHead>社員番号</TableHead>
              <TableHead>Microsoft</TableHead>
              <TableHead>主所属</TableHead>
              <TableHead>雇用区分</TableHead>
              <TableHead>部署</TableHead>
              <TableHead>役職</TableHead>
              <TableHead>在籍状況</TableHead>
              <TableHead>管理元</TableHead>
              <TableHead>権限</TableHead>
              <TableHead>Feature</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {users.map((user) => (
              <TableRow key={user.id}>
                <TableCell>
                  <Link
                    to={`/admin/users/${user.id}`}
                    className="font-medium text-foreground hover:text-primary hover:underline"
                  >
                    {user.name}
                  </Link>
                </TableCell>
                <TableCell className="text-muted-foreground">
                  {user.email}
                </TableCell>
                <TableCell className="text-muted-foreground">
                  {user.employee_number ?? "-"}
                </TableCell>
                <TableCell>
                  <Badge tone={user.sso_linked ? "success" : "neutral"}>
                    {user.sso_linked ? "連携済" : "未連携"}
                  </Badge>
                  <div className="text-xs text-muted-foreground">
                    最終同期{" "}
                    {user.external_identities?.find(
                      (identity) => identity.provider === "MICROSOFT_ENTRA",
                    )?.last_synced_at
                      ? new Date(
                          user.external_identities.find(
                            (identity) =>
                              identity.provider === "MICROSOFT_ENTRA",
                          )!.last_synced_at!,
                        ).toLocaleString()
                      : "-"}
                  </div>
                </TableCell>
                <TableCell className="text-muted-foreground">
                  {user.memberships?.find((m) => m.is_primary)?.group.name ??
                    "-"}
                </TableCell>
                <TableCell className="text-muted-foreground">
                  {user.memberships?.find(
                    (m) => m.group.group_type === "EMPLOYMENT",
                  )?.group.name ?? "-"}
                </TableCell>
                <TableCell className="text-muted-foreground">
                  {user.department ?? "-"}
                </TableCell>
                <TableCell className="text-muted-foreground">
                  {user.job_title ?? "-"}
                </TableCell>
                <TableCell className="text-muted-foreground">
                  {user.employment_status}
                </TableCell>
                <TableCell>
                  <Badge
                    tone={
                      user.source_type === "external_hr" ? "info" : "neutral"
                    }
                  >
                    {user.source_type ?? "local"}
                  </Badge>
                </TableCell>
                <TableCell>
                  {(user.roles ?? []).length === 0 ? (
                    <span className="text-sm text-muted-foreground">
                      未設定
                    </span>
                  ) : (
                    <span className="flex flex-wrap gap-1">
                      {user.roles?.map((role) => (
                        <Badge key={role} tone="info">
                          {role}
                        </Badge>
                      ))}
                    </span>
                  )}
                </TableCell>
                <TableCell>
                  <span className="flex flex-wrap gap-1">
                    {user.effective_features?.map((feature) => (
                      <Badge key={feature} tone="info">
                        {feature}
                      </Badge>
                    ))}
                  </span>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </Card>
  );
}
