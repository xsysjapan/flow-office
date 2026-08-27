<?php

namespace Tests\Unit\Export;

use App\Domain\Export\Services\ExpenseExcelBuilder;
use App\Models\Attachment;
use App\Models\BackOfficeTask;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\ExpenseItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * UC-X012: ExpenseExcelBuilder(証跡アーカイブExcel)の単体テスト。AttendanceExcelBuilderと
 * 同様に、生成したSpreadsheetのセル内容を直接検証する。
 */
class ExpenseExcelBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_sheet1_lists_no_date_category_description_payee_amount_evidence_number_and_total(): void
    {
        Storage::fake('local');

        $applicant = User::factory()->create(['name' => '申請者太郎']);
        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費', 'evidence_type_default' => 'fact_reference_available',
        ]);
        $claim = ExpenseClaim::query()->create([
            'employee_id' => $applicant->id, 'period_from' => '2020-01-01', 'period_to' => '2020-01-31',
            'status' => 'approved', 'total_amount' => 3400,
        ]);
        $item = ExpenseItem::query()->create([
            'claim_id' => $claim->id, 'category_id' => $category->id, 'amount' => 3400,
            'reimbursement_amount' => 3400, 'evidence_type' => 'fact_reference_available',
            'usage_date' => '2020-01-15', 'description' => '電車代', 'attributes' => ['payee' => 'JR東日本'],
        ]);
        Attachment::query()->create([
            'owner_type' => 'expense_item', 'owner_id' => $item->id, 'uploaded_by' => $applicant->id,
            'file_name' => 'receipt.pdf', 'stored_path' => 'attachments/receipt.pdf', 'mime_type' => 'application/pdf',
            'file_size' => 100,
        ]);
        $task = BackOfficeTask::query()->create([
            'source_type' => 'expense_claim', 'source_id' => $claim->id,
            'task_type' => 'expense_reimbursement', 'title' => '経費精算: 申請者太郎', 'status' => 'payment_scheduled',
        ]);
        $task->load(['source.items.category', 'source.items.attachments', 'source.employee']);

        $spreadsheet = app(ExpenseExcelBuilder::class)->build(collect([$task]));
        $sheet = $spreadsheet->getSheet(0);

        $this->assertSame(['No', '日付', '区分', '内容', '支払先', '金額', '証憑No'], [
            $sheet->getCell('A3')->getValue(),
            $sheet->getCell('B3')->getValue(),
            $sheet->getCell('C3')->getValue(),
            $sheet->getCell('D3')->getValue(),
            $sheet->getCell('E3')->getValue(),
            $sheet->getCell('F3')->getValue(),
            $sheet->getCell('G3')->getValue(),
        ]);
        $this->assertSame(1, $sheet->getCell('A4')->getValue());
        $this->assertSame('2020/01/15', $sheet->getCell('B4')->getValue());
        $this->assertSame('交通費', $sheet->getCell('C4')->getValue());
        $this->assertSame('電車代', $sheet->getCell('D4')->getValue());
        $this->assertSame('JR東日本', $sheet->getCell('E4')->getValue());
        $this->assertSame(3400, $sheet->getCell('F4')->getValue());
        $this->assertEquals('1', (string) $sheet->getCell('G4')->getValue());
        $this->assertSame('合計', $sheet->getCell('A5')->getValue());
        $this->assertSame(3400, $sheet->getCell('F5')->getValue());
    }

    /**
     * PDFラスタライズ用ライブラリ(Imagick等)を追加していないため、PDF証憑は画像化せず
     * ファイル名のみを証憑シートに記載する代替実装になっていることを確認する。
     */
    public function test_pdf_evidence_is_not_rasterized_and_only_the_filename_is_recorded(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('attachments/receipt.pdf', 'dummy-pdf-content');

        $applicant = User::factory()->create();
        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費', 'evidence_type_default' => 'fact_reference_available',
        ]);
        $claim = ExpenseClaim::query()->create([
            'employee_id' => $applicant->id, 'period_from' => '2020-01-01', 'period_to' => '2020-01-31',
            'status' => 'approved', 'total_amount' => 3400,
        ]);
        $item = ExpenseItem::query()->create([
            'claim_id' => $claim->id, 'category_id' => $category->id, 'amount' => 3400,
            'reimbursement_amount' => 3400, 'evidence_type' => 'fact_reference_available', 'usage_date' => '2020-01-15',
        ]);
        Attachment::query()->create([
            'owner_type' => 'expense_item', 'owner_id' => $item->id, 'uploaded_by' => $applicant->id,
            'file_name' => 'receipt.pdf', 'stored_path' => 'attachments/receipt.pdf', 'mime_type' => 'application/pdf',
            'file_size' => 100,
        ]);
        $task = BackOfficeTask::query()->create([
            'source_type' => 'expense_claim', 'source_id' => $claim->id,
            'task_type' => 'expense_reimbursement', 'title' => '経費精算', 'status' => 'payment_scheduled',
        ]);
        $task->load(['source.items.category', 'source.items.attachments', 'source.employee']);

        $spreadsheet = app(ExpenseExcelBuilder::class)->build(collect([$task]));

        $evidenceSheet = $spreadsheet->getSheetByName('証憑1');
        $this->assertNotNull($evidenceSheet);
        $this->assertStringContainsString('receipt.pdf', (string) $evidenceSheet->getCell('A1')->getValue());
        $this->assertStringContainsString('画像化せず', (string) $evidenceSheet->getCell('A2')->getValue());
        $this->assertSame(0, $evidenceSheet->getDrawingCollection()->count());
    }
}
