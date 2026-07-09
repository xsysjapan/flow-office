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
        $types = [
            [
                'code' => 'expense_reimbursement',
                'name' => '経費精算',
                'description' => '業務で発生した経費の精算申請',
                'form_schema' => [
                    ['key' => 'amount', 'label' => '金額', 'type' => 'number', 'required' => true],
                    ['key' => 'expense_date', 'label' => '利用日', 'type' => 'date', 'required' => true],
                    ['key' => 'purpose', 'label' => '用途', 'type' => 'text', 'required' => true],
                    ['key' => 'account_item', 'label' => '勘定科目', 'type' => 'text', 'required' => true],
                ],
                'requires_backoffice_task' => true,
                'backoffice_task_type' => 'expense_reimbursement',
            ],
            [
                'code' => 'commuting_expense',
                'name' => '交通費精算',
                'description' => '通勤・出張にかかる交通費の精算申請',
                'form_schema' => [
                    ['key' => 'amount', 'label' => '金額', 'type' => 'number', 'required' => true],
                    ['key' => 'route', 'label' => '経路', 'type' => 'text', 'required' => true],
                ],
                'requires_backoffice_task' => true,
                'backoffice_task_type' => 'expense_reimbursement',
            ],
            [
                'code' => 'business_card',
                'name' => '名刺申請',
                'description' => '名刺の新規作成・再作成申請',
                'form_schema' => [
                    ['key' => 'quantity', 'label' => '枚数', 'type' => 'number', 'required' => true],
                ],
                'requires_backoffice_task' => true,
                'backoffice_task_type' => 'business_card',
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
