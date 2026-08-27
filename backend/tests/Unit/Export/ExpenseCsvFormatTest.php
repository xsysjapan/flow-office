<?php

namespace Tests\Unit\Export;

use App\Domain\Export\Services\ExpenseCsv\FreeeExpenseCsvFormat;
use App\Domain\Export\Services\ExpenseCsv\GenericExpenseCsvFormat;
use App\Domain\Export\Services\ExpenseCsv\MoneyForwardExpenseCsvFormat;
use App\Models\BackOfficeTask;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\ExpenseItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-X012: ExpenseCsvFormat各実装(Generic/Freee/MoneyForward)の単体テスト。
 * AttendanceCsvFormat群と同じ形の実装であることを、ヘッダー・行の内容で確認する。
 */
class ExpenseCsvFormatTest extends TestCase
{
    use RefreshDatabase;

    private function buildFixture(): array
    {
        $applicant = User::factory()->create(['name' => '申請者太郎']);
        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費', 'evidence_type_default' => 'fact_reference_available',
            'account_code' => '7101', 'tax_category' => '課税仕入10%',
        ]);
        $claim = ExpenseClaim::query()->create([
            'employee_id' => $applicant->id, 'period_from' => '2020-01-01', 'period_to' => '2020-01-31',
            'status' => 'approved', 'total_amount' => 3400,
        ]);
        $item = ExpenseItem::query()->create([
            'claim_id' => $claim->id, 'category_id' => $category->id, 'amount' => 3400,
            'reimbursement_amount' => 3400, 'evidence_type' => 'fact_reference_available',
            'usage_date' => '2020-01-15', 'description' => '電車代',
        ]);
        $task = BackOfficeTask::query()->create([
            'source_type' => 'expense_claim', 'source_id' => $claim->id,
            'task_type' => 'expense_reimbursement', 'title' => '経費精算: 申請者太郎', 'status' => 'payment_scheduled',
        ]);

        return [$task->fresh(['source.items.category', 'source.employee']), $claim->fresh(['items.category', 'employee']), $item];
    }

    public function test_generic_format_outputs_task_level_row(): void
    {
        [$task, $claim] = $this->buildFixture();
        $format = new GenericExpenseCsvFormat;

        $this->assertSame(['task_id', 'title', 'employee_name', 'amount', 'status', 'created_at'], $format->header());
        $rows = $format->rows($task, $claim);
        $this->assertCount(1, $rows);
        $this->assertSame($task->id, $rows[0][0]);
        $this->assertSame(3400, $rows[0][3]);
        $this->assertSame(',', $format->delimiter());
        $this->assertSame('UTF-8', $format->encoding());
        $this->assertSame('csv', $format->fileExtension());
    }

    public function test_freee_format_outputs_item_level_row_with_account_code_and_tax_category(): void
    {
        [$task, $claim] = $this->buildFixture();
        $format = new FreeeExpenseCsvFormat;

        $rows = $format->rows($task, $claim);
        $this->assertCount(1, $rows);
        [$date, $accountCode, $taxCategory, $amount, $description] = $rows[0];
        $this->assertSame('2020/01/15', $date);
        $this->assertSame('7101', $accountCode);
        $this->assertSame('課税仕入10%', $taxCategory);
        $this->assertSame(3400, $amount);
        $this->assertSame('電車代', $description);
    }

    public function test_moneyforward_format_outputs_item_level_row_with_account_code_and_tax_category(): void
    {
        [$task, $claim] = $this->buildFixture();
        $format = new MoneyForwardExpenseCsvFormat;

        $rows = $format->rows($task, $claim);
        $this->assertCount(1, $rows);
        [$date, $accountCode, $amount, $taxCategory] = $rows[0];
        $this->assertSame('2020/01/15', $date);
        $this->assertSame('7101', $accountCode);
        $this->assertSame(3400, $amount);
        $this->assertSame('課税仕入10%', $taxCategory);
        $this->assertSame($claim->id, $rows[0][7]);
    }
}
