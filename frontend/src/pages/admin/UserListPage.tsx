import { useState } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import { Badge } from "../../components/Badge/Badge";
import { Card } from "../../components/Card/Card";
import { Button } from "../../components/Button/Button";
import { ClickableTableRow } from "../../components/ClickableTableRow/ClickableTableRow";
import { EmptyState } from "../../components/EmptyState/EmptyState";
import { ErrorMessage } from "../../components/ErrorMessage/ErrorMessage";
import { LoadingState } from "../../components/LoadingState/LoadingState";
import { Pagination } from "../../components/Pagination/Pagination";
import { Checkbox } from "../../components/ui/checkbox";
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
import { useGroupTypes, useManagedGroups } from "../../hooks/useUserManagement";
import {
  ACCOUNT_STATUS_OPTIONS,
  accountStatusLabel,
  employmentStatusLabel,
} from "../../utils/userLabels";

const FILTER_PARAM_KEYS = [
  "q",
  "account_status",
  "external_unlinked",
  "external_hr",
  "group_id",
  "group_type_id",
] as const;

const PER_PAGE = 20;

/**
 * UC-M001: ユーザーを検索し、人事・所属情報を確認する一覧。
 * 検索文字列・フィルター・ページはURL(`?q=...&account_status=...&page=...`)へ反映し、
 * ブラウザの戻る/リロード/URL共有で状態が壊れないようにする(SKILL.md §2.10)。
 */
export function UserListPage() {
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const query = searchParams.get("q") ?? "";
  const accountStatus = searchParams.get("account_status") ?? "";
  const externalUnlinked = searchParams.get("external_unlinked") === "1";
  const externalHr = searchParams.get("external_hr") === "1";
  const groupId = searchParams.get("group_id") ?? "";
  const groupTypeId = searchParams.get("group_type_id") ?? "";
  const pageParam = Number(searchParams.get("page"));
  const page = Number.isInteger(pageParam) && pageParam > 0 ? pageParam : 1;

  const [showCreate, setShowCreate] = useState(false);
  const [newUser, setNewUser] = useState({
    name: "",
    email: "",
    employee_number: "",
    department: "",
    job_title: "",
  });
  const createUser = useCreateUser();
  const { data: managedGroups } = useManagedGroups();
  const { data: groupTypes } = useGroupTypes();
  const { data, isLoading, error } = useUsers(
    query,
    PER_PAGE,
    {
      account_status: accountStatus || undefined,
      external_unlinked: externalUnlinked || undefined,
      external_hr: externalHr || undefined,
      group_id: groupId || undefined,
      group_type_id: groupTypeId ? Number(groupTypeId) : undefined,
    },
    page,
  );

  const isFiltered = FILTER_PARAM_KEYS.some((key) => Boolean(searchParams.get(key)));

  function updateParam(key: (typeof FILTER_PARAM_KEYS)[number], value: string) {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev);
        if (value) next.set(key, value);
        else next.delete(key);
        next.delete("page");
        return next;
      },
      { replace: true },
    );
  }

  function changePage(nextPage: number) {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev);
        if (nextPage > 1) next.set("page", String(nextPage));
        else next.delete("page");
        return next;
      },
      { replace: true },
    );
  }

  function clearFilters() {
    setSearchParams({}, { replace: true });
  }

  if (isLoading) return <LoadingState />;
  if (error)
    return (
      <ErrorMessage
        error={error}
        fallback="ユーザー一覧の取得に失敗しました。"
      />
    );

  const users = data?.data ?? [];
  // フィルターの選択肢は現在のページのユーザーからではなく、グループ・グループ種別の
  // 全件一覧(useManagedGroups/useGroupTypes)から組み立てる。ページングを導入したことで
  // 「今のページに写っているユーザーが持つグループしか選べない」状態を避けるため。
  const groupOptions = managedGroups ?? [];
  const groupTypeOptions = groupTypes ?? [];

  return (
    <Card
      title="ユーザー一覧"
      actions={
        <Link
          className="text-sm font-medium text-primary hover:underline"
          to="/admin/hr-import"
        >
          外部ID・HR連携
        </Link>
      }
    >
      <div className="mb-4">
        {/* Pattern exception: ユーザー作成をDialog/Sheet/Pageではなく、一覧のCard内に
            展開するインラインフォームで実装する(ui-interaction-patterns SKILL.md §2.11は
            Dialog/Sheet/Pageの3種のみを標準としている)。
            Reason: 入力項目が氏名・メールアドレス・社員番号・部署・役職の5項目のみで、かつ
            管理者にとって頻度の高い操作であるため、Dialog/Pageへの遷移コストを避け、
            一覧のコンテキストを保ったまま作成できる価値を優先した。 */}
        <Button
          variant="secondary"
          onClick={() => setShowCreate((value) => !value)}
        >
          {showCreate ? "キャンセル" : "ユーザーを作成"}
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
            <div>
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
                作成する
              </Button>
              {(!newUser.name || !newUser.email) && (
                <p className="mt-1.5 text-xs text-muted-foreground">
                  氏名とメールアドレスを入力してください。
                </p>
              )}
            </div>
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
        onChange={(e) => updateParam("q", e.target.value)}
      />
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <NativeSelect
          aria-label="グループ種別で絞り込み"
          value={groupTypeId}
          onChange={(e) => updateParam("group_type_id", e.target.value)}
        >
          <option value="">すべてのグループ種別</option>
          {groupTypeOptions.map((type) => (
            <option key={type.id} value={type.id}>
              {type.name}
            </option>
          ))}
        </NativeSelect>
        <NativeSelect
          aria-label="グループで絞り込み"
          value={groupId}
          onChange={(e) => updateParam("group_id", e.target.value)}
        >
          <option value="">すべてのグループ</option>
          {groupOptions.map((group) => (
            <option key={group.id} value={group.id}>
              {group.name}
            </option>
          ))}
        </NativeSelect>
        <NativeSelect
          value={accountStatus}
          onChange={(e) => updateParam("account_status", e.target.value)}
        >
          <option value="">全アカウント状態</option>
          {ACCOUNT_STATUS_OPTIONS.map((status) => (
            <option key={status.value} value={status.value}>
              {status.label}
            </option>
          ))}
        </NativeSelect>
        <label className="flex items-center gap-2 text-sm text-foreground">
          <Checkbox
            checked={externalUnlinked}
            onCheckedChange={(checked) => updateParam("external_unlinked", checked === true ? "1" : "")}
          />
          Microsoft未連携
        </label>
        <label className="flex items-center gap-2 text-sm text-foreground">
          <Checkbox
            checked={externalHr}
            onCheckedChange={(checked) => updateParam("external_hr", checked === true ? "1" : "")}
          />
          外部HR管理
        </label>
        {isFiltered && (
          <Button variant="secondary" onClick={clearFilters}>
            フィルターをクリア
          </Button>
        )}
      </div>

      {users.length === 0 ? (
        <EmptyState
          title={isFiltered ? "条件に一致するユーザーはいません。" : "該当するユーザーはいません。"}
          description={
            isFiltered
              ? "検索条件を変更するか、上の「フィルターをクリア」から条件を解除してください。"
              : "「ユーザーを作成」から新しいユーザーを追加できます。"
          }
        />
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
              <TableHead>アカウント状態</TableHead>
              <TableHead>管理元</TableHead>
              <TableHead>グループ（所属）</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {users.map((user) => (
              <ClickableTableRow
                key={user.id}
                onRowClick={() => navigate(`/admin/users/${user.id}`)}
                rowLabel={`${user.name}の詳細を開く`}
              >
                <TableCell>
                  <Link
                    to={`/admin/users/${user.id}`}
                    className="font-medium text-foreground hover:text-primary hover:underline"
                    onClick={(e) => e.stopPropagation()}
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
                  {employmentStatusLabel(user.employment_status)}
                </TableCell>
                <TableCell>
                  <Badge
                    tone={
                      (user.account_status ?? "active") === "active"
                        ? "success"
                        : "neutral"
                    }
                  >
                    {accountStatusLabel(user.account_status ?? "active")}
                  </Badge>
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
                  {(user.memberships ?? []).length === 0 ? (
                    <span className="text-sm text-muted-foreground">
                      未設定
                    </span>
                  ) : (
                    <span className="flex flex-wrap gap-1">
                      {user.memberships?.map((membership) => (
                        <Badge key={membership.id} tone="neutral">
                          {membership.group.name}
                          {membership.is_primary ? "（主所属）" : ""}
                        </Badge>
                      ))}
                    </span>
                  )}
                </TableCell>
              </ClickableTableRow>
            ))}
          </TableBody>
        </Table>
      )}

      {data && (
        <Pagination
          currentPage={data.meta.current_page}
          lastPage={data.meta.last_page}
          total={data.meta.total}
          onPageChange={changePage}
        />
      )}
    </Card>
  );
}
