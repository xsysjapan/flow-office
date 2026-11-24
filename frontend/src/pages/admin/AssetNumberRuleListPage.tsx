import { useState } from 'react'
import { Badge } from '../../components/ui/badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { Checkbox } from '../../components/ui/checkbox'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { Input } from '../../components/ui/input'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { PageHeader } from '../../components/PageHeader/PageHeader'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import type { AssetNumberRule } from '../../api/assetNumberRules'
import { notify } from '../../lib/notify'
import {
  useAssetNumberRules,
  useUpdateAssetNumberRule,
  useUpdateDefaultAssetNumberRule,
} from '../../hooks/useAssetNumberRules'

/** 編集中の対象。「編集中でない」とデフォルト行(`category: null`)の編集中を別の値で表す。 */
type EditingTarget = { kind: 'none' } | { kind: 'default' } | { kind: 'category'; category: string }

interface RuleFormState {
  prefix: string
  digitCount: string
  isActive: boolean
}

function toFormState(rule: AssetNumberRule | undefined): RuleFormState {
  return { prefix: rule?.prefix ?? '', digitCount: String(rule?.digit_count ?? 5), isActive: rule?.is_active ?? true }
}

/**
 * 適用状態バッジ(spec 仕様確定事項11): このカテゴリのルールで採番/無効化中(手入力)/
 * デフォルトにフォールバック、の3通り。判定は`AssetRegisterPage`と同じ優先順(論点10)。
 */
function applicationStatus(
  rule: AssetNumberRule,
  defaultRule: AssetNumberRule | undefined,
): { label: string; variant: 'success' | 'neutral' | 'info' } {
  if (rule.is_active) return { label: 'このルールで採番', variant: 'success' }
  if (defaultRule?.is_active) return { label: 'デフォルトにフォールバック', variant: 'info' }
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
  const [newForm, setNewForm] = useState<RuleFormState>({ prefix: '', digitCount: '5', isActive: true })
  const [editing, setEditing] = useState<EditingTarget>({ kind: 'none' })
  const [editForm, setEditForm] = useState<RuleFormState>({ prefix: '', digitCount: '5', isActive: true })
  const [defaultForm, setDefaultForm] = useState<RuleFormState>({ prefix: '', digitCount: '5', isActive: true })

  const header = (
    <PageHeader description="備品のカテゴリ別に管理番号の自動採番ルール(プレフィックス・桁数)を設定します。有効なルールが無いカテゴリは手入力になります。" />
  )
  if (rules.isLoading) return <div className="space-y-6">{header}<LoadingState /></div>
  if (rules.error) return <div className="space-y-6">{header}<ErrorMessage error={rules.error} fallback="採番ルールの取得に失敗しました。" /></div>

  const allRules = rules.data ?? []
  const defaultRule = allRules.find((rule) => rule.is_default)
  const categoryRules = allRules.filter((rule) => !rule.is_default)
  const mutationError = updateRule.error ?? updateDefaultRule.error ?? undefined

  function startEditCategory(rule: AssetNumberRule) {
    setEditing({ kind: 'category', category: rule.category as string })
    setEditForm(toFormState(rule))
  }

  function saveCategoryEdit(category: string) {
    updateRule.mutate(
      { category, input: { prefix: editForm.prefix, digit_count: Number(editForm.digitCount) || 1, is_active: editForm.isActive } },
      {
        onSuccess: () => {
          notify.success(`${category}の採番ルールを保存しました`)
          setEditing({ kind: 'none' })
        },
      },
    )
  }

  function saveNewCategory() {
    const category = newCategory.trim()
    updateRule.mutate(
      { category, input: { prefix: newForm.prefix, digit_count: Number(newForm.digitCount) || 1, is_active: newForm.isActive } },
      {
        onSuccess: () => {
          notify.success(`${category}の採番ルールを追加しました`)
          setNewCategory('')
          setNewForm({ prefix: '', digitCount: '5', isActive: true })
        },
      },
    )
  }

  function startEditDefault() {
    setEditing({ kind: 'default' })
    setDefaultForm(toFormState(defaultRule))
  }

  function saveDefault() {
    updateDefaultRule.mutate(
      { prefix: defaultForm.prefix, digit_count: Number(defaultForm.digitCount) || 1, is_active: defaultForm.isActive },
      {
        onSuccess: () => {
          notify.success('デフォルトの採番ルールを保存しました')
          setEditing({ kind: 'none' })
        },
      },
    )
  }

  return (
    <div className="space-y-6">
      {header}

      {mutationError && <ErrorMessage error={mutationError} />}

      <Card title="デフォルトルール">
        {!defaultRule && editing.kind !== 'default' && (
          <div className="flex items-center gap-3">
            <p className="text-sm text-muted-foreground">
              未設定です。カテゴリ一致ルールが無い場合、常に手入力になります。
            </p>
            <Button variant="secondary" onClick={startEditDefault}>
              デフォルトルールを作成
            </Button>
          </div>
        )}
        {defaultRule && editing.kind !== 'default' && (
          <div className="flex flex-wrap items-center gap-4 text-sm">
            <span>
              プレフィックス: <span className="font-medium text-foreground">{defaultRule.prefix}</span>
            </span>
            <span>桁数: {defaultRule.digit_count}</span>
            <span>次番号(参考): {String(defaultRule.next_number).padStart(defaultRule.digit_count, '0')}</span>
            <Badge variant={defaultRule.is_active ? 'success' : 'neutral'}>{defaultRule.is_active ? '有効' : '無効'}</Badge>
            <Button variant="secondary" onClick={startEditDefault}>
              編集
            </Button>
          </div>
        )}
        {editing.kind === 'default' && (
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
                checked={defaultForm.isActive}
                onCheckedChange={(checked) => setDefaultForm({ ...defaultForm, isActive: checked === true })}
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
              <Button variant="secondary" onClick={() => setEditing({ kind: 'none' })}>
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
              const isEditing = editing.kind === 'category' && editing.category === category
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
                      <TableCell>{String(rule.next_number).padStart(rule.digit_count, '0')}</TableCell>
                      <TableCell>
                        <Checkbox
                          aria-label={`${category}の有効/無効`}
                          checked={editForm.isActive}
                          onCheckedChange={(checked) => setEditForm({ ...editForm, isActive: checked === true })}
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
                          <Button variant="secondary" onClick={() => setEditing({ kind: 'none' })}>
                            キャンセル
                          </Button>
                        </div>
                      </TableCell>
                    </>
                  ) : (
                    <>
                      <TableCell>{rule.prefix}</TableCell>
                      <TableCell>{rule.digit_count}</TableCell>
                      <TableCell>{String(rule.next_number).padStart(rule.digit_count, '0')}</TableCell>
                      <TableCell>
                        <Badge variant={rule.is_active ? 'success' : 'neutral'}>{rule.is_active ? '有効' : '無効'}</Badge>
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
                  checked={newForm.isActive}
                  onCheckedChange={(checked) => setNewForm({ ...newForm, isActive: checked === true })}
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
