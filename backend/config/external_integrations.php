<?php

/**
 * 外部連携(勤怠API・経費API)の接続先設定。トークン・APIキー自体は
 * `external_integration_connections`(暗号化保存)側で管理し、ここではエンドポイントURLのみを
 * 保持する。docs/33-usecases-attendance-external-api.md, docs/30-usecases-expense.md参照。
 *
 * 勤怠のAPIプッシュ連携はfreeeのみ対応する。MoneyForwardクラウド勤怠/給与には外部から
 * 勤怠データをプッシュする公開APIが存在しないため(docs/notes/moneyforward-api-investigation.md)、
 * MoneyForward向けの勤怠出力はCSVのみとする(勤怠CSVのMoneyForwardフォーマット自体は
 * 従来通り利用可能)。
 */
return [
    'freee' => [
        'token_endpoint' => env('FREEE_TOKEN_ENDPOINT', 'https://accounts.secure.freee.co.jp/public_api/token'),
        // フェーズ2: 勤怠API連携。freee人事労務「勤怠情報月次サマリの更新」API
        // (PUT /api/v1/employees/{employee_id}/work_record_summaries/{year}/{month})。
        // {employee_id}/{year}/{month}はExternalApiPublisherがペイロードの`_path`で置換する
        // (FreeeAttendanceApiPayloadBuilder参照)。公式OpenAPIスキーマで確認済み
        // (docs/notes/moneyforward-api-investigation.md 4.freee人事労務API)。
        'api_endpoint' => env(
            'FREEE_ATTENDANCE_API_ENDPOINT',
            'https://api.freee.co.jp/hr/api/v1/employees/{employee_id}/work_record_summaries/{year}/{month}',
        ),
        // フェーズ3: 経費(仕訳)API連携。docs/30-usecases-expense.md UC-X012参照。
        'expense_api_endpoint' => env('FREEE_EXPENSE_API_ENDPOINT', 'https://api.freee.co.jp/api/1/deals'),
    ],
    'moneyforward' => [
        // 経費: MoneyForwardクラウド経費の経費明細(ex_transaction)作成・領収書アップロードAPI。
        // {office_id}/{office_member_id}はMoneyForwardExpensePublisherが実行時に置換する
        // (office_idはexternal_integration_connections.external_office_id、office_member_idは
        // external_employee_mappings.external_employee_codeを使う)。
        'expense_api_endpoint' => env(
            'MF_EXPENSE_EX_TRANSACTIONS_ENDPOINT',
            'https://expense.moneyforward.com/api/external/v1/offices/{office_id}/office_members/{office_member_id}/ex_transactions',
        ),
        'expense_receipt_upload_endpoint' => env(
            'MF_EXPENSE_UPLOAD_RECEIPT_ENDPOINT',
            'https://expense.moneyforward.com/api/external/v1/offices/{office_id}/office_members/{office_member_id}/upload_receipt',
        ),
        'token_endpoint' => env('MF_EXPENSE_TOKEN_ENDPOINT', 'https://expense.moneyforward.com/oauth/token'),
    ],
];
