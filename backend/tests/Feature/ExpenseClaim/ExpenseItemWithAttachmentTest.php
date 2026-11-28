<?php

namespace Tests\Feature\ExpenseClaim;

use App\Domain\Attachment\Commands\UploadAttachment;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\ExpenseClaim\Commands\AddExpenseItem;
use App\Domain\ExpenseClaim\Commands\AddExpenseItems;
use App\Models\Attachment;
use App\Models\ExpenseCategory;
use App\Models\ExpenseItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\Support\AssertsSingleTopLevelCommand;
use Tests\TestCase;

/**
 * 経費明細の追加と添付を1回のAPIで記録する(原則17。明細の追加のイベントを添付の文脈のReactorが受ける)。
 */
class ExpenseItemWithAttachmentTest extends TestCase
{
    use AssertsSingleTopLevelCommand;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function makeCategory(bool $active = true): ExpenseCategory
    {
        return ExpenseCategory::query()->create([
            'code' => 'lodging'.($active ? '' : '_off'), 'name' => '宿泊費', 'is_active' => $active,
            'evidence_type_default' => ExpenseCategory::EVIDENCE_RECEIPT_REQUIRED,
        ]);
    }

    private function draftClaim(User $employee): string
    {
        return $this->actingAs($employee)->postJson('/api/expense-claims')->json('id');
    }

    private function uploadedEventCount(): int
    {
        return EloquentStoredEvent::query()->where('event_class', 'attachment.uploaded')->count();
    }

    public function test_single_add_records_item_and_attachments_with_one_command(): void
    {
        $employee = User::factory()->create();
        $category = $this->makeCategory();
        $claimId = $this->draftClaim($employee);
        $bus = $this->recordCommands();

        $response = $this->actingAs($employee)->post("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'amount' => 8000, 'attributes' => '{"nights":1}',
            'files' => [
                UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->create('b.png', 50, 'image/png'),
            ],
        ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $this->assertSingleTopLevelCommand($bus, AddExpenseItem::class);

        $itemId = $response->json('id');
        $attachments = Attachment::query()->where('owner_type', 'expense_item')->where('owner_id', $itemId)->get();
        $this->assertCount(2, $attachments);
        foreach ($attachments as $attachment) {
            $this->assertStringStartsWith("attachments/expense_items/{$itemId}/", $attachment->stored_path);
            Storage::disk('local')->assertExists($attachment->stored_path);
            $this->assertSame($employee->id, $attachment->uploaded_by);
        }
        $this->assertSame(2, $this->uploadedEventCount());
    }

    public function test_bulk_add_records_items_and_attachments_by_row_with_one_command(): void
    {
        $employee = User::factory()->create();
        $category = $this->makeCategory();
        $claimId = $this->draftClaim($employee);
        $bus = $this->recordCommands();

        $response = $this->actingAs($employee)->post("/api/expense-claims/{$claimId}/items/bulk", [
            'items' => json_encode([
                ['category_id' => $category->id, 'amount' => 100],
                ['category_id' => $category->id, 'amount' => 200],
            ]),
            'files' => [1 => [UploadedFile::fake()->create('r.pdf', 10, 'application/pdf')]],
        ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $this->assertSingleTopLevelCommand($bus, AddExpenseItems::class);

        $ids = collect($response->json())->pluck('id');
        $this->assertCount(2, $ids);
        $this->assertSame(0, Attachment::query()->where('owner_id', $ids[0])->count());
        $this->assertSame(1, Attachment::query()->where('owner_id', $ids[1])->count());
    }

    public function test_validation_failure_stores_nothing(): void
    {
        $employee = User::factory()->create();
        $category = $this->makeCategory();
        $claimId = $this->draftClaim($employee);

        $this->actingAs($employee)->post("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'amount' => 100,
            'files' => [
                UploadedFile::fake()->create('ok.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('bad.exe', 10, 'application/octet-stream'),
            ],
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors(['files.1']);

        $this->actingAs($employee)->post("/api/expense-claims/{$claimId}/items/bulk", [
            'items' => json_encode([['category_id' => $category->id, 'amount' => 100]]),
            'files' => [0 => [UploadedFile::fake()->create('big.pdf', 20000, 'application/pdf')]],
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors(['files.0.0']);

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, ExpenseItem::query()->count());
        $this->assertSame(0, EloquentStoredEvent::query()->where('event_class', 'expense_claim.item_added')->count());
        $this->assertSame(0, $this->uploadedEventCount());
    }

    public function test_command_rejection_deletes_saved_files_and_records_nothing(): void
    {
        $employee = User::factory()->create();
        $inactive = $this->makeCategory(false);
        $claimId = $this->draftClaim($employee);

        $this->actingAs($employee)->post("/api/expense-claims/{$claimId}/items", [
            'category_id' => $inactive->id, 'amount' => 100,
            'files' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $active = ExpenseCategory::query()->create(['code' => 'ok', 'name' => 'OK', 'evidence_type_default' => ExpenseCategory::EVIDENCE_RECEIPT_OPTIONAL]);
        $this->actingAs($employee)->post("/api/expense-claims/{$claimId}/items/bulk", [
            'items' => json_encode([
                ['category_id' => $active->id, 'amount' => 100],
                ['category_id' => $inactive->id, 'amount' => 100],
            ]),
            'files' => [0 => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]],
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors(['items.1.category_id']);

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, ExpenseItem::query()->count());
        $this->assertSame(0, Attachment::query()->count());
        $this->assertSame(0, $this->uploadedEventCount());
    }

    public function test_bulk_rejects_a_files_row_beyond_the_items(): void
    {
        $employee = User::factory()->create();
        $category = $this->makeCategory();
        $claimId = $this->draftClaim($employee);

        $this->actingAs($employee)->post("/api/expense-claims/{$claimId}/items/bulk", [
            'items' => json_encode([['category_id' => $category->id, 'amount' => 100]]),
            'files' => [3 => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]],
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors(['files']);

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_reactor_is_idempotent_when_the_attachment_already_exists(): void
    {
        $employee = User::factory()->create();
        $category = $this->makeCategory();
        $claimId = $this->draftClaim($employee);
        $bus = app(CommandBus::class);

        $reference = [
            'attachmentId' => '11111111-1111-4111-8111-111111111111', 'storedPath' => 'attachments/expense_items/x/a.pdf',
            'fileName' => 'a.pdf', 'mimeType' => 'application/pdf', 'fileSize' => 10, 'uploadedByUserId' => $employee->id,
        ];
        $bus->dispatch(new AddExpenseItem(
            claimId: $claimId, addedByUserId: $employee->id, categoryId: $category->id, usageDate: null,
            description: null, amount: 100, projectId: null, attachments: [$reference],
        ));
        $this->assertSame(1, $this->uploadedEventCount());

        $again = $bus->dispatch(new UploadAttachment(
            ownerTypeAlias: 'expense_item', ownerId: 'x', uploadedByUserId: $employee->id, fileName: 'a.pdf',
            storedPath: 'attachments/expense_items/x/a.pdf', mimeType: 'application/pdf', fileSize: 10,
            attachmentId: $reference['attachmentId'], viaReactor: true,
        ));

        $this->assertSame($reference['attachmentId'], $again->id);
        $this->assertSame(1, $this->uploadedEventCount());
        $this->assertSame(1, Attachment::query()->count());
    }

    public function test_json_request_without_files_still_works(): void
    {
        $employee = User::factory()->create();
        $category = $this->makeCategory();
        $claimId = $this->draftClaim($employee);

        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'amount' => 100,
        ])->assertCreated();
        $this->assertSame(0, Attachment::query()->count());
    }
}
