import { useState } from 'react'
import { Badge } from '../../components/ui/badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { Checkbox } from '../../components/ui/checkbox'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { Input } from '../../components/ui/input'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import type { AssetNumberRule } from '../../api/assetNumberRules'
import {
  useAssetNumberRules,
  useUpdateAssetNumberRule,
  useUpdateDefaultAssetNumberRule,
} from '../../hooks/useAssetNumberRules'

interface RuleFormState {
  prefix: string
  digitCount: string
  enabled: boolean
}

function toFormState(rule: AssetNumberRule | undefined): RuleFormState {
  return { prefix: rule?.prefix ?? '', digitCount: String(rule?.digitCount ?? 5), enabled: rule?.enabled ?? true }
}

/**
 * 適用状態バッジ(spec 仕様確定事項11): このカテゴリのルールで採番/無効化中(手入力)/
 * デフォルトにフォールバック、の3通り。判定は`AssetRegisterPage`と同じ優先順(論点10)。
 */
function applicationStatus(
  rule: AssetNumberRule,
  defaultRule: AssetNumberRule | undefined,
): { label: string; variant: 'success' | 'neutral' | 'info' } {
  if (rule.enabled) return { label: 'このルールで採番', variant: 'success' }
  if (defaultRule?.enabled) return { label: 'デフォルトにフォールバック', variant: 'info' }
  return { label: '無効化中(手入力)', variant: 'neutral' }
}

/**
 * 管理番号自動採番ルールの管理画面(spec 仕様確定事項11)。デフォルトルール(1行)と
 * カテゴリ別ルール一覧を編集する。`next_number`は表示のみで編集不可(spec 対象外)。
 */
export function AssetNumberRuleListPage() {
  const rules = useAssetNumberRules()
  const updateRule = useUpdateAssetNumberRule()
  const updateDefaultRule = useUpdateDefaultAssetNumberRule()

  const [newCategory, setNewCategory] = useState('')
  const [newForm, setNewForm] = useState<RuleFormState>({ prefix: '', digitCount: '5', enabled: true })
  const [editingCategory, setEditingCategory] = useState<string | null>(null)
  const [editForm, setEditForm] = useState<RuleFormState>({ prefix: '', digitCount: '5', enabled: true })
  const [editingDefault, setEditingDefault] = useState(false)
  const [defaultForm, setDefaultForm] = useState<RuleFormState>({ prefix: '', digitCount: '5', enabled: true })

  if (rules.isLoading) return <LoadingState />
  if (rules.error) return <ErrorMessage error={rules.error} fallback="採番ルールの取得に失敗しました。" />

  const allRules = rules.data ?? []
  const defaultRule = allRules.find((rule) => rule.isDefault)
  const categoryRules = allRules.filter((rule) => !rule.isDefault)
  const mutationError = updateRule.error ?? updateDefaultRule.error ?? undefined

  function startEditCategory(rule: AssetNumberRule) {
    setEditingCategory(rule.category)
    setEditForm(toFormState(rule))
  }

  function saveCategoryEdit(category: string) {
    updateRule.mutate(
      { category, input: { prefix: editForm.prefix, digitCount: Number(editForm.digitCount) || 1, enabled: editForm.enabled } },
      { onSuccess: () => setEditingCategory(null) },
    )
  }

  function saveNewCategory() {
    updateRule.mutate(
      { category: newCategory.trim(), input: { prefix: newForm.prefix, digitCount: Number(newForm.digitCount) || 1, enabled: newForm.enabled } },
      {
        onSuccess: () => {
          setNewCategory('')
          setNewForm({ prefix: '', digitCount: '5', enabled: true })
        },
      },
    )
  }

  function startEditDefault() {
    setEditingDefault(true)
    setDefaultForm(toFormState(defaultRule))
  }

  function saveDefault() {
    updateDefaultRule.mutate(
      { prefix: defaultForm.prefix, digitCount: Number(defaultForm.digitCount) || 1, enabled: defaultForm.enabled },
      { onSuccess: () => setEditingDefault(false) },
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-lg font-semibold">採番ルール設定</h1>
        <p className="text-sm text-muted-foreground">
          備品のカテゴリ別に管理番号の自動採番ルール(プレフィックス・桁数)を設定します。
          有効なルールが無いカテゴリは手入力になります。
        </p>
      </div>

      {mutationError && <ErrorMessage error={mutationError} />}

      <Card title="デフォルトルール">
        {!defaultRule && !editingDefault && (
          <div className="flex items-center gap-3">
            <p className="text-sm text-muted-foreground">
              未設定です。カテゴリ一致ルールが無い場合、常に手入力になります。
            </p>
            <Button variant="secondary" onClick={startEditDefault}>
              デフォルトルールを作成
            </Button>
          </div>
        )}
        {defaultRule && !editingDefault && (
          <div className="flex flex-wrap items-center gap-4 text-sm">
            <span>
              プレフィックス: <span className="font-medium text-foreground">{defaultRule.prefix}</span>
            </span>
            <span>桁数: {defaultRule.digitCount}</span>
            <span>次番号(参考): {String(defaultRule.nextNumber).padStart(defaultRule.digitCount, '0')}</span>
            <Badge variant={defaultRule.enabled ? 'success' : 'neutral'}>{defaultRule.enabled ? '有効' : '無効'}</Badge>
            <Button variant="secondary" onClick={startEditDefault}>
              編集
            </Button>
          </div>
        )}
        {editingDefault && (
          <div className="grid grid-cols-1 items-end gap-3 sm:grid-cols-4">
            <FormField label="プレフィックス" htmlFor="default-rule-prefix" required>
              <Input
                id="default-rule-prefix"
                value={defaultForm.prefix}
                onChange={(e) => setDefaultForm({ ...defaultForm, prefix: e.target.value })}
                placeholder="例: AST"
              />
            </FormField>
            <FormField label="桁数" htmlFor="default-rule-digit-count" required>
              <Input
                id="default-rule-digit-count"
                type="number"
                min="1"
                value={defaultForm.digitCount}
                onChange={(e) => setDefaultForm({ ...defaultForm, digitCount: e.target.value })}
              />
            </FormField>
            <label className="flex items-center gap-2 pb-2">
              <Checkbox
                checked={defaultForm.enabled}
                onCheckedChange={(checked) => setDefaultForm({ ...defaultForm, enabled: checked === true })}
              />
              有効
            </label>
            <div className="flex gap-2 pb-2">
              <Button
                disabled={!defaultForm.prefix.trim()}
                isLoading={updateDefaultRule.isPending}
                onClick={saveDefault}
              >
                保存
              </Button>
              <Button variant="secondary" onClick={() => setEditingDefault(false)}>
                キャンセル
              </Button>
            </div>
          </div>
        )}
      </Card>

      <Card title="カテゴリ別ルール">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>カテゴリ</TableHead>
              <TableHead>プレフィックス</TableHead>
              <TableHead>桁数</TableHead>
              <TableHead>次番号(参考)</TableHead>
              <TableHead>有効/無効</TableHead>
              <TableHead>適用状態</TableHead>
              <TableHead>操作</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {categoryRules.map((rule) => {
              const category = rule.category as string
              const isEditing = editingCategory === category
              const status = applicationStatus(rule, defaultRule)
              return (
                <TableRow key={category}>
                  <TableCell>{category}</TableCell>
                  {isEditing ? (
                    <>
                      <TableCell>
                        <Input
                          aria-label={`${category}のプレフィックス`}
                          value={editForm.prefix}
                          onChange={(e) => setEditForm({ ...editForm, prefix: e.target.value })}
                        />
                      </TableCell>
                      <TableCell>
                        <Input
                          aria-label={`${category}の桁数`}
                          type="number"
                          min="1"
                          value={editForm.digitCount}
                          onChange={(e) => setEditForm({ ...editForm, digitCount: e.target.value })}
                        />
                      </TableCell>
                      <TableCell>{String(rule.nextNumber).padStart(rule.digitCount, '0')}</TableCell>
                      <TableCell>
                        <Checkbox
                          aria-label={`${category}の有効/無効`}
                          checked={editForm.enabled}
                          onCheckedChange={(checked) => setEditForm({ ...editForm, enabled: checked === true })}
                        />
                      </TableCell>
                      <TableCell>
                        <Badge variant={status.variant}>{status.label}</Badge>
                      </TableCell>
                      <TableCell>
                        <div className="flex gap-2">
                          <Button
                            disabled={!editForm.prefix.trim()}
                            isLoading={updateRule.isPending}
                            onClick={() => saveCategoryEdit(category)}
                          >
                            保存
                          </Button>
                          <Button variant="secondary" onClick={() => setEditingCategory(null)}>
                            キャンセル
                          </Button>
                        </div>
                      </TableCell>
                    </>
                  ) : (
                    <>
                      <TableCell>{rule.prefix}</TableCell>
                      <TableCell>{rule.digitCount}</TableCell>
                      <TableCell>{String(rule.nextNumber).padStart(rule.digitCount, '0')}</TableCell>
                      <TableCell>
                        <Badge variant={rule.enabled ? 'success' : 'neutral'}>{rule.enabled ? '有効' : '無効'}</Badge>
                      </TableCell>
                      <TableCell>
                        <Badge variant={status.variant}>{status.label}</Badge>
                      </TableCell>
                      <TableCell>
                        <Button variant="secondary" onClick={() => startEditCategory(rule)}>
                          編集
                        </Button>
                      </TableCell>
                    </>
                  )}
                </TableRow>
              )
            })}
            <TableRow>
              <TableCell>
                <Input
                  aria-label="新規カテゴリ名"
                  value={newCategory}
                  onChange={(e) => setNewCategory(e.target.value)}
                  placeholder="例: ノートPC"
                />
              </TableCell>
              <TableCell>
                <Input
                  aria-label="新規カテゴリのプレフィックス"
                  value={newForm.prefix}
                  onChange={(e) => setNewForm({ ...newForm, prefix: e.target.value })}
                  placeholder="例: NPC"
                />
              </TableCell>
              <TableCell>
                <Input
                  aria-label="新規カテゴリの桁数"
                  type="number"
                  min="1"
                  value={newForm.digitCount}
                  onChange={(e) => setNewForm({ ...newForm, digitCount: e.target.value })}
                />
              </TableCell>
              <TableCell>-</TableCell>
              <TableCell>
                <Checkbox
                  aria-label="新規カテゴリの有効/無効"
                  checked={newForm.enabled}
                  onCheckedChange={(checked) => setNewForm({ ...newForm, enabled: checked === true })}
                />
              </TableCell>
              <TableCell>-</TableCell>
              <TableCell>
                <Button
                  disabled={!newCategory.trim() || !newForm.prefix.trim()}
                  isLoading={updateRule.isPending}
                  onClick={saveNewCategory}
                >
                  追加
                </Button>
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
        {categoryRules.length === 0 && (
          <p className="mt-3 text-sm text-muted-foreground">カテゴリ別ルールはまだありません。上の行から追加できます。</p>
        )}
      </Card>
    </div>
  )
}
