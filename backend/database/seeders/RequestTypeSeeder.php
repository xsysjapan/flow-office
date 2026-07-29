<?php

namespace Database\Seeders;

use App\Models\RequestType;
use Illuminate\Database\Seeder;

/**
 * docs/10-usecases-workflow.md UC-W001 の申請種別例を初期データとして投入する。
 */
class RequestTypeSeeder extends Seeder
{
    public function run(): void
    {
        // UC-B005: 未着手→確認中→発注済→発送済→完了。
        $businessCardTransitions = [
            'not_started' => ['in_review', 'cancelled'],
            'in_review' => ['needs_fix', 'ordered', 'cancelled'],
            'needs_fix' => ['in_review', 'cancelled'],
            'ordered' => ['shipped', 'cancelled'],
            'shipped' => ['completed'],
        ];

        // UC-B006: 未着手→確認中→(在庫引当=処理中 または 欠品=発注済)→発送済→完了。
        $supplyTransitions = [
            'not_started' => ['in_review', 'cancelled'],
            'in_review' => ['needs_fix', 'processing', 'ordered', 'cancelled'],
            'needs_fix' => ['in_review', 'cancelled'],
            'processing' => ['shipped', 'cancelled'],
            'ordered' => ['shipped', 'cancelled'],
            'shipped' => ['completed'],
        ];

        // 通勤費・業務交通費・その他経費は docs/30-usecases-expense.md の専用ドメイン
        // (expense_claims / expense_items) に統合したため、'expense_reimbursement'/
        // 'commuting_expense' の汎用申請種別は定義しない。

        $types = [
            [
                'code' => 'business_card',
                'name' => '名刺申請',
                'description' => '名刺の新規作成・再作成申請',
                'form_schema' => [
                    ['key' => 'quantity', 'label' => '枚数', 'type' => 'number', 'required' => true],
                ],
                'requires_backoffice_task' => true,
                'backoffice_task_type' => 'business_card',
                'backoffice_department' => '総務部',
                'allowed_status_transitions' => $businessCardTransitions,
            ],
            [
                'code' => 'supply_request',
                'name' => '備品申請',
                'description' => '業務用備品の購入・貸与申請',
                'form_schema' => [
                    ['key' => 'item_name', 'label' => '品名', 'type' => 'text', 'required' => true],
                    ['key' => 'quantity', 'label' => '数量', 'type' => 'number', 'required' => true],
                ],
                'requires_backoffice_task' => true,
                'backoffice_task_type' => 'supply_request',
                'backoffice_department' => '総務部',
                'allowed_status_transitions' => $supplyTransitions,
            ],
            [
                'code' => 'address_change',
                'name' => '住所変更',
                'description' => '住所変更の届出',
                'form_schema' => [
                    ['key' => 'new_address', 'label' => '新しい住所', 'type' => 'text', 'required' => true],
                    ['key' => 'effective_date', 'label' => '変更日', 'type' => 'date', 'required' => true],
                ],
                'requires_backoffice_task' => true,
                'backoffice_task_type' => 'general_affairs',
                'backoffice_department' => '総務部',
            ],
            [
                'code' => 'certificate_issuance',
                'name' => '証明書発行',
                'description' => '在籍証明書等の発行申請',
                'form_schema' => [
                    ['key' => 'certificate_type', 'label' => '証明書種別', 'type' => 'text', 'required' => true],
                    ['key' => 'purpose', 'label' => '提出先・用途', 'type' => 'text', 'required' => true],
                ],
                'requires_backoffice_task' => true,
                'backoffice_task_type' => 'general_affairs',
                'backoffice_department' => '総務部',
            ],
            [
                'code' => 'general_request',
                'name' => '一般申請',
                'description' => 'その他一般的な申請',
                'form_schema' => [
                    ['key' => 'detail', 'label' => '内容', 'type' => 'text', 'required' => true],
                ],
                'requires_backoffice_task' => false,
                'backoffice_task_type' => null,
            ],
        ];

        foreach ($types as $type) {
            RequestType::query()->firstOrCreate(['code' => $type['code']], $type);
        }
    }
}
