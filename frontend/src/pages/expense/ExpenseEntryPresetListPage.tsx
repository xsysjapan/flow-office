import { useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { useAuth } from "../../auth/useAuth";
import { Badge } from "../../components/Badge/Badge";
import { Button } from "../../components/Button/Button";
import { Card } from "../../components/Card/Card";
import { ConfirmDialog } from "../../components/ConfirmDialog/ConfirmDialog";
import { ErrorMessage } from "../../components/ErrorMessage/ErrorMessage";
import { FormField } from "../../components/FormField/FormField";
import { LoadingState } from "../../components/LoadingState/LoadingState";
import { Pagination } from "../../components/Pagination/Pagination";
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
import type {
  ExpenseEntryPreset,
  ExpenseEntryPresetVisibility,
} from "../../api/types";
import { useExpenseCategories } from "../../hooks/useExpenseCategories";
import {
  useDeleteExpenseEntryPreset,
  useExpenseEntryPresets,
} from "../../hooks/useExpenseEntryPresets";

const visibilityLabel: Record<ExpenseEntryPresetVisibility, string> = {
  personal: "個人用",
  company: "全社共有",
  system: "システム標準",
};

/**
 * 「経費精算機能 設計・実装指示書」9〜10: 入力プリセット一覧。個人用は本人のみ編集でき、
 * 全社共有・システム標準は経理・管理者のみ編集できる(書き込みはAPI側でも検証する)。
 *
 * プリセットは明細側のcategory_idで経費区分に紐づいているため、名称検索と経費区分での
 * 絞り込みをつけてページングする。経費精算の入力画面から遷移してきた場合は
 * `?category_id=`が付いており、その区分で最初から絞り込まれた状態で開く。
 */
export function ExpenseEntryPresetListPage() {
  const { user } = useAuth();
  const [searchParams, setSearchParams] = useSearchParams();
  const categoryIdParam = searchParams.get("category_id");
  const [q, setQ] = useState("");
  const [page, setPage] = useState(1);

  const { data: categories } = useExpenseCategories();
  const {
    data: presets,
    isLoading,
    error,
  } = useExpenseEntryPresets({
    q: q || undefined,
    category_id: categoryIdParam ? Number(categoryIdParam) : undefined,
    page,
  });
  const deletePreset = useDeleteExpenseEntryPreset();

  const canManageShared = Boolean(
    user?.effective_permissions?.includes("expense_preset.manage"),
  );
  const canEdit = (preset: ExpenseEntryPreset) =>
    preset.visibility === "personal"
      ? preset.owner_user_id === user?.id
      : canManageShared;

  const changeCategoryFilter = (value: string) => {
    setPage(1);
    setSearchParams(value ? { category_id: value } : {});
  };

  const list = presets?.data ?? [];

  return (
    <Card
      title="入力プリセット"
      actions={
        <Button asChild>
          <Link to="/expenses/presets/new">新規作成</Link>
        </Button>
      }
    >
      {deletePreset.error && <ErrorMessage error={deletePreset.error} />}

      <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="名称で検索" htmlFor="preset-search">
          <Input
            id="preset-search"
            placeholder="例: 自宅⇔会社"
            value={q}
            onChange={(e) => {
              setQ(e.target.value);
              setPage(1);
            }}
          />
        </FormField>
        <FormField label="経費区分で絞り込む" htmlFor="preset-category-filter">
          <NativeSelect
            id="preset-category-filter"
            value={categoryIdParam ?? ""}
            onChange={(e) => changeCategoryFilter(e.target.value)}
          >
            <option value="">すべての経費区分</option>
            {categories?.map((category) => (
              <option key={category.id} value={category.id}>
                {category.name}
              </option>
            ))}
          </NativeSelect>
        </FormField>
      </div>

      {isLoading ? (
        <LoadingState />
      ) : error ? (
        <ErrorMessage error={error} fallback="プリセットの取得に失敗しました。" />
      ) : list.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          条件に一致するプリセットはありません。
        </p>
      ) : (
        <>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>名称</TableHead>
                <TableHead>公開範囲</TableHead>
                <TableHead>経費区分</TableHead>
                <TableHead>明細件数</TableHead>
                <TableHead>利用回数</TableHead>
                <TableHead>状態</TableHead>
                <TableHead>操作</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {list.map((preset) => {
                const categoryNames = [
                  ...new Set(
                    preset.definition
                      .map(
                        (item) =>
                          categories?.find((c) => c.id === item.category_id)
                            ?.name,
                      )
                      .filter((name): name is string => Boolean(name)),
                  ),
                ];
                return (
                  <TableRow key={preset.id}>
                    <TableCell>
                      {canEdit(preset) ? (
                        <Link
                          to={`/expenses/presets/${preset.id}`}
                          className="font-medium text-foreground hover:text-primary hover:underline"
                        >
                          {preset.name}
                        </Link>
                      ) : (
                        <span className="font-medium text-foreground">
                          {preset.name}
                        </span>
                      )}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {visibilityLabel[preset.visibility]}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {categoryNames.join("、") || "-"}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {preset.definition.length}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {preset.usage_count}
                    </TableCell>
                    <TableCell>
                      <Badge tone={preset.is_active ? "success" : "neutral"}>
                        {preset.is_active ? "有効" : "無効"}
                      </Badge>
                    </TableCell>
                    <TableCell>
                      {canEdit(preset) && (
                        <ConfirmDialog
                          trigger={
                            <Button variant="danger" size="sm">
                              削除
                            </Button>
                          }
                          title="このプリセットを削除しますか?"
                          description="削除すると元に戻せません。"
                          isConfirming={
                            deletePreset.isPending &&
                            deletePreset.variables === preset.id
                          }
                          onConfirm={() => deletePreset.mutate(preset.id)}
                        />
                      )}
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>

          {presets && (
            <Pagination
              currentPage={presets.meta.current_page}
              lastPage={presets.meta.last_page}
              total={presets.meta.total}
              onPageChange={setPage}
            />
          )}
        </>
      )}
    </Card>
  );
}
