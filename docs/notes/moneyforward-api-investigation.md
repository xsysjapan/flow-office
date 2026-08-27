# 外部API調査メモ(2026-08-27)

> 追記: freee人事労務APIについても、ユーザーから公式OpenAPIスキーマ
> (https://raw.githubusercontent.com/freee/freee-api-schema/master/hr/open-api-3/api-schema.json)
> の提供を受け一次資料で確認できたため、末尾に追記する。

外部連携(勤怠・経費)フェーズ2/3の実装が「推測ベース」だったため、公開されている一次情報を
直接調査した結果をまとめる。実装(PayloadBuilder)はこの内容に合わせて修正する。

## 調査した情報源

- 開発者ポータル: https://developers.biz.moneyforward.com/ , /docs
- クラウド会計API OpenAPI定義: https://developers.api-accounting.moneyforward.com/v3/openapi.yaml (取得成功)
- クラウド経費API仕様: https://expense.moneyforward.com/api/index.json?urls.primaryName=External%20API%20with%20user%20token
  (Swagger 2.0形式、取得成功。`gh api repos/moneyforward/expense-api-doc` のREADMEでOAuth2認可フローを確認)
- クラウド給与API: https://payroll.moneyforward.com/api/v2/document (取得成功、内容は参照専用)

## 1. マネーフォワード クラウド会計API(仕訳)

- `POST /api/v3/journals`
- 認証: OAuth2.0、スコープ `mfc/accounting/journal.write`
- リクエストボディ:
  ```
  journal:
    transaction_date: string(date) 必須
    journal_type: "journal_entry" | "adjusting_entry" 必須
    memo: string 任意
    tags: string[] 任意
    branches: [] 必須  # 仕訳行
      - creditor: { value, account_id, tax_id, department_id?, sub_account_id?, trade_partner_code?, invoice_kind? }
        debitor:  { value, account_id, tax_id, department_id?, sub_account_id?, trade_partner_code?, invoice_kind? }
        remark: string 任意
  ```
- **重要**: `account_id`・`tax_id`は「コード」ではなく、MF側マスタの内部ID。事前に
  `GET /api/v3/office/{office_id}/accounts` 等でID一覧を取得し、flow-office側の勘定科目マスタと
  紐付けるマッピングテーブルが必要(現行実装の`expense_categories.account_code`を単純に文字列として
  送るだけでは不整合になる可能性が高い)。

## 2. マネーフォワード クラウド経費API

想定と異なり、経費側は「仕訳を直接POSTする」のではなく、**経費明細(ex_transaction)を
クラウド経費側に作成し、仕訳への変換はMF側が内部で行う**モデルだった。

- 経費明細作成: `POST /api/external/v1/offices/{office_id}/office_members/{office_member_id}/ex_transactions`
  - 認証: OAuth2.0 Authorization Code Grant(`expense.moneyforward.com/oauth/authorize` 等)
  - リクエストボディ(`ExTransactionCreateInput`、必須項目: value, recognized_at, remark, ex_item_id):
    ```
    remark: string(≤100)          支払先・内容
    recognized_at: date           日付
    value: number                 金額(税込)
    memo: string(≤800)            メモ
    report_number: string(≤50)    事前申請番号
    currency: string(3文字, 既定JPY)
    jpyrate / use_custom_jpy_rate 外貨対応
    ex_item_id: string(≤40)       経費科目id(勘定科目に相当。MF内部IDでコードではない)
    dr_excise_id: string(≤40)     税区分id(同上、MF内部ID)
    dept_id / project_id: 任意
    cr_item_id / cr_sub_item_id: 貸方勘定科目/補助科目(任意)
    receipt_input: 領収書データ(下記参照)
    invoice_registration_number: string  適格請求書発行事業者登録番号
    invoice_kind: 1|2|3|5 (1:対象外,2:適格,3:80%控除,5:70%控除)
    excise_value: integer         消費税額
    ex_transaction_attendant_*: 出席者情報(会食等)
    ```
  - **領収書添付は同一APIファミリ内で完結**: `POST .../office_members/{office_member_id}/upload_receipt`
    (body: `ReceiptInput`)で領収書画像をアップロードし、`ex_transaction`作成時に`receipt_input`として
    紐付け可能。CSVでは運べない領収書画像も、この経路なら**APIで一体的に送れる**。
  - ほぼ全てのGETエンドポイント(`ex_reports`, `ex_journals_by_*`, `attendants`等)は参照専用。
    `ex_transactions`とその関連(`upload_receipt`, `reimburse_bank_account`, `office_member_workflows`)
    以外に書き込み系(POST/PUT/DELETE)はほぼ無い。

### 従来実装との差分

フェーズ3の`MoneyForwardExpenseApiPayloadBuilder`は「仕訳(journal)を組み立てて送信する」設計
だったが、実際は**「経費明細(ex_transaction)を1件ずつ作成する」設計に直すべき**。
`ex_item_id`/`dr_excise_id`はMF内部IDのため、`expense_categories.account_code`/`tax_category`を
そのまま送るのではなく、**MF側マスタIDとのマッピングテーブル**(または`ExternalIntegrationConnection`
の設定値としてコード↔ID対応表)を持たせる必要がある。

## 3. マネーフォワード クラウド給与API・勤怠連携

- `https://payroll.moneyforward.com/api/v2/document` は**参照専用**(従業員情報・給与計算結果・
  控除項目マスタの取得のみ)。勤怠データの登録・更新エンドポイントは存在しない。
- 開発者ポータル(developers.biz.moneyforward.com)のAPIリファレンス一覧には「クラウド会計」
  「クラウド経費」「クラウド請求書」「クラウド債務支払」のみが掲載され、**「クラウド勤怠」
  「クラウド給与」向けの外部プッシュ型公開APIは案内されていない**。
- 「クラウド勤怠→クラウド給与」の連携は、MoneyForward自社製品間を繋ぐための専用の仕組み
  (全権管理者メニューで発行するAPI KEYを使う内部連携)であり、flow-officeのような外部システムが
  任意の勤怠データをAPIでプッシュ登録できる一般公開APIとしては、現時点で確認できなかった。

### 結論(勤怠)

公開情報の範囲では、**flow-office→マネーフォワードへの勤怠データAPIプッシュは実現できない**。
選択肢:
1. この経路は「未対応」とし、CSV(フェーズ1で対応済み)のみ案内する
2. 御社が別途MoneyForwardと契約している専用連携仕様書があれば、それに基づいて実装する

## 対応方針(実装への反映)

- 経費: `MoneyForwardExpenseApiPayloadBuilder`を`ex_transactions`作成APIベースに書き換え、
  領収書は`upload_receipt`経由で送信する2ステップ構成にする。`account_code`/`tax_category`から
  MF内部IDへのマッピングが必要なため、マッピング設定を追加する。
- 勤怠: マネーフォワードへのAPIプッシュは実装せず、CSVのみ対応とする(freeeの勤怠サマリーAPIは
  実在するためそのまま維持)。

## 4. freee人事労務API(勤怠サマリー、公式OpenAPIスキーマで確認済み)

情報源: https://raw.githubusercontent.com/freee/freee-api-schema/master/hr/open-api-3/api-schema.json
(freee公式リポジトリ、直接取得・パース済み)

- 参照: `GET /api/v1/employees/{employee_id}/work_record_summaries/{year}/{month}`
  (クエリ: `company_id`必須、`work_records`(bool)で日次明細も同時取得可)
- **更新: `PUT /api/v1/employees/{employee_id}/work_record_summaries/{year}/{month}`**
  (「勤怠情報月次サマリの更新」。管理者権限が必要。日次勤怠の更新はこのAPIでは不可、別の勤怠APIを使う。
  データが無ければ新規作成、あれば上書き。値未設定の項目は自動的に0になる点に注意)
- リクエストボディ(`ApiV1EmployeesWorkRecordSummaryController.update_body`、必須は`company_id`のみ):
  ```
  company_id: integer(必須)                                  事業所ID
  work_days / work_days_on_weekdays / work_days_on_prescribed_holidays /
  work_days_on_legal_holidays: number(float, 0-31)            勤務日数各種
  total_work_mins: integer                                    労働時間(分)
  total_normal_work_mins: integer                              所定労働時間(分)
  total_excess_statutory_work_mins: integer                    給与計算に用いる法定内残業時間(分)
  total_holiday_work_mins: integer                             法定休日労働時間(分)
  total_latenight_work_mins: integer                           深夜労働時間(分)
  total_actual_excess_statutory_work_mins: integer              実労働時間ベースの法定内残業時間(分)
  total_overtime_work_mins: integer                             時間外労働時間(分)
  total_prescribed_holiday_work_mins: integer                   所定休日労働時間(分)
  num_absences: number(float)                                   欠勤日数
  num_absences_for_deduction: number(float)                     控除対象欠勤日数(フレックスは無視)
  total_lateness_mins / total_lateness_mins_for_deduction: integer   遅刻時間(分)
  total_early_leaving_mins / total_early_leaving_mins_for_deduction: integer  早退時間(分)
  num_paid_holidays: number(float)                              有給取得日数
  total_shortage_work_mins: integer                             不足時間(分、フレックス制のみ)
  total_deemed_paid_excess_statutory_work_mins: integer          みなし外法定内残業(分、裁量労働制のみ)
  total_deemed_paid_overtime_except_normal_work_mins: integer    みなし外時間外労働(分、裁量労働制のみ)
  ```
- `employee_id`はflow-office側の従業員IDではなくfreee内部の数値ID(integer)のため、
  `ExternalEmployeeMapping`で解決する。単位は全て「分(minutes)」であることに注意
  (flow-office側の`attendance_months`が時間単位/分単位のどちらで保持しているか要確認し、変換する)。

### 結論(勤怠・freee)

一次資料で仕様が確定できたため、`FreeeAttendanceApiPayloadBuilder`の「本番投入前に要検証」という
注記コメントは不要になった。上記フィールド名・エンドポイントに正確に合わせて実装を修正する。
