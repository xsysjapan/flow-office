import { useState } from 'react'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { NativeSelect } from '../../components/ui/native-select'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import { YearMonthPicker } from '../../components/YearMonthPicker/YearMonthPicker'
import { useDownloadAttendanceCsv, useDownloadAttendanceExcel } from '../../hooks/useAttendance'
import type { AttendanceExportFormat } from '../../api/types'

interface FormatOption {
  value: AttendanceExportFormat
  label: string
  description: string
  links?: Array<{ label: string; href: string }>
}

const FORMAT_OPTIONS: FormatOption[] = [
  {
    value: 'generic',
    label: '汎用CSV(カンマ区切り・分単位)',
    description:
      '汎用フォーマット(カンマ区切り・分単位)。どの給与計算ソフトでも列マッピング機能があれば取り込めます。',
  },
  {
    value: 'generic_tsv',
    label: '汎用TSV(タブ区切り・時:分表記)',
    description:
      '汎用フォーマット(タブ区切り・時:分表記)。弥生給与Next・給与奉行クラウドなど、取込時に区切り文字や時刻表記を設定できるソフト向けです。',
    links: [
      { label: '弥生給与Next「勤怠データのインポート(CSV)」', href: 'https://support.yayoi-kk.co.jp/subcontents.html?page_id=28107' },
      { label: '給与奉行クラウド「給与奉行クラウドAPI」', href: 'https://www.obc.co.jp/bugyo-cloud/kyuyo/function/api' },
    ],
  },
  {
    value: 'generic_sjis',
    label: '汎用CSV(カンマ区切り・分単位・Shift-JIS)',
    description:
      '汎用フォーマット(カンマ区切り・分単位・Shift-JIS)。文字コードにShift-JISを要求するレガシーなソフト向けです。',
  },
  {
    value: 'moneyforward',
    label: 'マネーフォワードクラウド給与B形式',
    description:
      'マネーフォワードクラウド給与のCSVインポート(MFクラウド給与B形式)に対応した列構成です。取り込む前に、クラウド給与側の「勤怠項目設定」の項目名がこの出力の項目名と一致しているか確認してください。',
    links: [{ label: 'マネーフォワードクラウド給与 CSVインポートガイド', href: 'https://biz.moneyforward.com/support/payroll/guide/payroll/payr07.html' }],
  },
  {
    value: 'freee',
    label: 'freee人事労務 勤怠サマリー形式',
    description: 'freee人事労務の勤怠データインポート(勤怠サマリー形式)に対応した列構成です。',
    links: [{ label: 'freee人事労務 勤怠データインポート', href: 'https://support.freee.co.jp/hc/ja/articles/204922194' }],
  },
]

/**
 * UC-E001: 勤怠CSV・Excelを出力する。承認済み(approved)・締め済み(closed)どちらの月次勤怠も対象。
 * CSVは出力フォーマットを選択でき(汎用/給与計算ソフト向け)、Excelは対象社員が複数の場合ZIPで
 * まとめて返る(バックエンド側の仕様。フロントはContent-Type/Content-Dispositionで判定するのみ)。
 */
export function AttendanceExportPage() {
  const [yearMonth, setYearMonth] = useState('')
  const [userId, setUserId] = useState<string | undefined>(undefined)
  const [format, setFormat] = useState<AttendanceExportFormat>('generic')

  const downloadCsv = useDownloadAttendanceCsv()
  const downloadExcel = useDownloadAttendanceExcel()

  const selectedFormat = FORMAT_OPTIONS.find((option) => option.value === format) ?? FORMAT_OPTIONS[0]

  return (
    <Card title="勤怠CSV出力">
      <p className="mb-4 text-sm text-muted-foreground">
        承認済み・締め処理済みの月次勤怠がCSVに含まれます。対象社員を指定しない場合は全社員が対象です。
      </p>

      {downloadCsv.error && <ErrorMessage error={downloadCsv.error} fallback="勤怠CSVの取得に失敗しました。" />}
      {downloadExcel.error && <ErrorMessage error={downloadExcel.error} fallback="勤怠Excelの取得に失敗しました。" />}

      <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="対象月" htmlFor="attendance-export-year-month" required>
          <YearMonthPicker
            id="attendance-export-year-month"
            value={yearMonth || undefined}
            onChange={(value) => setYearMonth(value ?? '')}
          />
        </FormField>
        <FormField label="対象社員(任意)" htmlFor="attendance-export-user">
          <UserPicker id="attendance-export-user" value={userId} onChange={setUserId} />
        </FormField>
        <FormField label="CSV出力フォーマット" htmlFor="attendance-export-format">
          <NativeSelect
            id="attendance-export-format"
            value={format}
            onChange={(event) => setFormat(event.target.value as AttendanceExportFormat)}
          >
            {FORMAT_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </NativeSelect>
        </FormField>
      </div>

      <div className="mb-4 rounded-md border border-border bg-muted/40 p-3 text-sm text-muted-foreground">
        <p>{selectedFormat.description}</p>
        {selectedFormat.links && (
          <ul className="mt-2 list-inside list-disc">
            {selectedFormat.links.map((link) => (
              <li key={link.href}>
                <a href={link.href} target="_blank" rel="noreferrer" className="underline">
                  {link.label}
                </a>
              </li>
            ))}
          </ul>
        )}
      </div>

      <p className="mb-4 text-xs text-muted-foreground">
        従業員番号列には、flow-office内部の社員ID(UUID)を出力します。給与計算ソフト側の従業員番号と直接一致しない場合は、事前にマッピングを確認してください。
      </p>

      <div className="flex flex-wrap items-center gap-3">
        <Button
          isLoading={downloadCsv.isPending}
          disabled={!yearMonth}
          onClick={() => {
            downloadCsv.mutate({ year_month: yearMonth, user_id: userId, format })
          }}
        >
          CSVダウンロード
        </Button>
        <Button
          variant="secondary"
          isLoading={downloadExcel.isPending}
          disabled={!yearMonth}
          onClick={() => {
            downloadExcel.mutate({ year_month: yearMonth, user_id: userId })
          }}
        >
          Excelダウンロード
        </Button>
        <p className="text-xs text-muted-foreground">
          対象社員を指定した場合、または指定なしで対象月の承認済み社員が1名の場合は.xlsxが、
          指定なしで対象が複数名の場合は全員分をまとめたZIPがダウンロードされます。
        </p>
      </div>
    </Card>
  )
}
