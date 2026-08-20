import { useEffect, useMemo, useState } from 'react'
import { Card } from '../../components/Card/Card'
import { ConfirmActionDialog } from '../../components/ConfirmActionDialog/ConfirmActionDialog'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { YearMonthPicker } from '../../components/YearMonthPicker/YearMonthPicker'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { useAdminCommandRuns, useAdminCommands, useRunAdminCommand } from '../../hooks/useAdminCommands'
import type { AdminCommandParameter } from '../../api/types'

function formatDateTime(value: string | null): string {
  return value ? new Date(value).toLocaleString('ja-JP') : '—'
}

export function AdminCommandsPage() {
  const commands = useAdminCommands()
  const runs = useAdminCommandRuns()
  const execute = useRunAdminCommand()
  const [commandName, setCommandName] = useState('')
  const [parameters, setParameters] = useState<Record<string, unknown>>({})

  const command = useMemo(() => commands.data?.data.find((item) => item.name === commandName), [commands.data, commandName])
  useEffect(() => {
    if (!commandName && commands.data?.data[0]) setCommandName(commands.data.data[0].name)
  }, [commands.data, commandName])
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

  if (commands.isLoading) return <LoadingState />
  if (commands.error) return <ErrorMessage error={commands.error} fallback="運用コマンドを取得できませんでした。" />

  return (
    <div className="space-y-6">
      <Card title="運用コマンド">
        {(commands.data?.data.length ?? 0) === 0 ? <EmptyState title="実行可能なコマンドはありません。" description="AdminExecutableが設定されたコマンドがここに表示されます。" /> : <>
          <FormField label="コマンド" htmlFor="admin-command-select">
            <NativeSelect id="admin-command-select" value={commandName} onChange={(event) => setCommandName(event.target.value)}>
              {commands.data?.data.map((item) => <option key={item.name} value={item.name}>{item.label}</option>)}
            </NativeSelect>
          </FormField>
          {command && <div className="space-y-4">
            <div><p className="text-sm text-foreground">{command.description}</p><code className="text-sm text-muted-foreground">{command.name}</code></div>
            {command.parameters.map((parameter) => <FormField key={parameter.name} label={parameter.name} htmlFor={`command-${parameter.name}`} required={parameter.required}>
              {parameterControl(parameter)}
              {parameter.description && <p className="text-xs text-muted-foreground">{parameter.description}</p>}
            </FormField>)}
            <ConfirmActionDialog triggerLabel="実行" triggerVariant="primary" title={`${command.label}を実行`} description="処理はDBキューへ投入され、実行結果は履歴に記録されます。" confirmLabel="実行" isPending={execute.isPending} error={execute.error} onConfirm={() => execute.mutateAsync({ command: command.name, parameters: Object.fromEntries(Object.entries(parameters).filter(([, value]) => value !== undefined && value !== '')) })} />
          </div>}
        </>}
      </Card>
      <Card title="実行履歴">
        {runs.isLoading ? <LoadingState /> : runs.error ? <ErrorMessage error={runs.error} fallback="実行履歴を取得できませんでした。" /> : (runs.data?.data.length ?? 0) === 0 ? <EmptyState title="実行履歴はまだありません。" description="コマンドを実行するとここに結果が表示されます。" /> : <ul className="divide-y divide-border">
          {runs.data?.data.map((run) => <li key={run.id} className="py-3 text-sm"><div className="flex flex-wrap justify-between gap-2"><span className="font-medium text-foreground">{run.command_name}</span><span>{run.status}</span></div><p className="text-muted-foreground">依頼: {run.requested_by_user?.name ?? run.requested_by_user_id} / {formatDateTime(run.created_at)}</p>{(run.output || run.error_message) && <details className="mt-2"><summary className="cursor-pointer">出力</summary><pre className="mt-2 overflow-x-auto rounded-md bg-muted p-3 text-xs">{run.error_message ?? run.output}</pre></details>}</li>)}
        </ul>}
      </Card>
    </div>
  )
}
