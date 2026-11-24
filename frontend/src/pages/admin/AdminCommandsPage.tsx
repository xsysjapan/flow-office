import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Card } from '../../components/Card/Card'
import { ConfirmActionDialog } from '../../components/ConfirmActionDialog/ConfirmActionDialog'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormActions } from '../../components/FormActions/FormActions'
import { FormField } from '../../components/FormField/FormField'
import { FormLayout } from '../../components/FormLayout/FormLayout'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { PageHeader } from '../../components/PageHeader/PageHeader'
import { YearMonthPicker } from '../../components/YearMonthPicker/YearMonthPicker'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { useAdminCommandRuns, useAdminCommands, useRunAdminCommand } from '../../hooks/useAdminCommands'
import type { AdminCommandParameter, AdminCommandRun } from '../../api/types'
import { notify } from '../../lib/notify'
import { adminCommandRunStatusLabel } from '../../utils/statusLabels'

function formatDateTime(value: string | null): string {
  return value ? new Date(value).toLocaleString('ja-JP') : '—'
}

/** 依頼者。定期の実行(システムの実行)は依頼者が無いので「システム」と表示する。 */
function requesterLabel(run: AdminCommandRun): string {
  if (run.requested_by_user_id === null) return 'システム'
  return run.requested_by_user?.name ?? run.requested_by_user_id
}

const PAGE_DESCRIPTION = '保守用のコマンドを選んで実行を依頼し、実行履歴を確認できます。実行は約1分以内に始まります。'

export function AdminCommandsPage() {
  const commands = useAdminCommands()
  const runs = useAdminCommandRuns()
  const execute = useRunAdminCommand()
  const [searchParams, setSearchParams] = useSearchParams()
  const [parameters, setParameters] = useState<Record<string, unknown>>({})

  /** 選んだコマンドはURLの`command`に載せる(SKILL.md §2.10)。キーなしは先頭のコマンドを表示上の既定とし、
   *  URLには書かない。一覧に無い名前は先頭のコマンドとして表示し、一覧の取得後にキーを消す。 */
  const commandList = commands.data?.data
  const commandParam = searchParams.get('command')
  const isUnknownCommand = commandList !== undefined && commandParam !== null && !commandList.some((item) => item.name === commandParam)
  const commandName = commandParam !== null && !isUnknownCommand ? commandParam : (commandList?.[0]?.name ?? '')
  function setCommandName(name: string) {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        next.set('command', name)
        return next
      },
      { replace: true },
    )
  }

  const command = useMemo(() => commandList?.find((item) => item.name === commandName), [commandList, commandName])
  useEffect(() => {
    if (!isUnknownCommand) return
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        next.delete('command')
        return next
      },
      { replace: true },
    )
  }, [isUnknownCommand, setSearchParams])
  useEffect(() => setParameters({}), [commandName])

  function parameterControl(parameter: AdminCommandParameter) {
    const value = parameters[parameter.name]
    if (parameter.ui.control === 'checkbox' || parameter.accepts_value === false) {
      return <Checkbox id={`command-${parameter.name}`} checked={Boolean(value)} onCheckedChange={(checked) => setParameters((current) => ({ ...current, [parameter.name]: checked === true }))} />
    }
    if (parameter.ui.control === 'year-month') {
      return <YearMonthPicker id={`command-${parameter.name}`} value={typeof value === 'string' ? value : undefined} onChange={(next) => setParameters((current) => ({ ...current, [parameter.name]: next }))} />
    }
    return <Input id={`command-${parameter.name}`} value={typeof value === 'string' ? value : ''} onChange={(event) => setParameters((current) => ({ ...current, [parameter.name]: event.target.value }))} />
  }

  if (commands.isLoading) return <div className="space-y-6"><PageHeader description={PAGE_DESCRIPTION} /><LoadingState /></div>
  if (commands.error) return <div className="space-y-6"><PageHeader description={PAGE_DESCRIPTION} /><ErrorMessage error={commands.error} fallback="運用コマンドを取得できませんでした。" /></div>

  return (
    <div className="space-y-6">
      <PageHeader description={PAGE_DESCRIPTION} />
      <Card title="コマンドの実行">
        {(commands.data?.data.length ?? 0) === 0 ? <EmptyState title="実行可能なコマンドはありません。" description="AdminExecutableが設定されたコマンドがここに表示されます。" /> : <>
          <FormLayout>
          <FormField label="コマンド" htmlFor="admin-command-select">
            <NativeSelect id="admin-command-select" value={commandName} onChange={(event) => setCommandName(event.target.value)}>
              {commands.data?.data.map((item) => <option key={item.name} value={item.name}>{item.label}</option>)}
            </NativeSelect>
          </FormField>
          {command && <>
            <div className="mb-4"><p className="text-sm text-foreground">{command.description}</p><code className="text-sm text-muted-foreground">{command.name}</code></div>
            {command.parameters.map((parameter) => <FormField key={parameter.name} label={parameter.name} htmlFor={`command-${parameter.name}`} required={parameter.required} description={parameter.description || undefined}>
              {parameterControl(parameter)}
            </FormField>)}
            <FormActions>
            <ConfirmActionDialog triggerLabel="実行" triggerVariant="primary" title={`${command.label}を実行`} description="実行は約1分以内に始まり、実行結果は履歴に記録されます。" confirmLabel="実行" isPending={execute.isPending} error={execute.error} onConfirm={async () => {
              await execute.mutateAsync({ command: command.name, parameters: Object.fromEntries(Object.entries(parameters).filter(([, value]) => value !== undefined && value !== '')) })
              notify.success(`${command.label}の実行を依頼しました`, { description: '実行は約1分以内に始まります。結果は実行履歴に表示されます。' })
            }} />
            </FormActions>
          </>}
          </FormLayout>
        </>}
      </Card>
      <Card title="実行履歴">
        {runs.isLoading ? <LoadingState /> : runs.error ? <ErrorMessage error={runs.error} fallback="実行履歴を取得できませんでした。" /> : (runs.data?.data.length ?? 0) === 0 ? <EmptyState title="実行履歴はまだありません。" description="コマンドを実行するとここに結果が表示されます。" /> : <ul className="divide-y divide-border">
          {runs.data?.data.map((run) => <li key={run.id} className="py-3 text-sm"><div className="flex flex-wrap justify-between gap-2"><span className="font-medium text-foreground">{run.command_name}</span><span>{adminCommandRunStatusLabel(run.status)}</span></div><p className="text-muted-foreground">依頼: {requesterLabel(run)} / {formatDateTime(run.created_at)}</p>{(run.output || run.error_message) && <details className="mt-2"><summary className="cursor-pointer">出力</summary><pre className="mt-2 overflow-x-auto rounded-md bg-muted p-3 text-xs">{run.error_message ?? run.output}</pre></details>}</li>)}
        </ul>}
      </Card>
    </div>
  )
}
