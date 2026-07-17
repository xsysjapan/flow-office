<?php

namespace Tests\Feature\Attachment;

use App\Models\AttendanceDay;
use App\Models\BackOfficeTask;
use App\Models\RequestType;
use App\Models\StoredEvent;
use App\Models\User;
use App\Models\WorkflowRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * UC-F001/UC-F002: 添付ファイルのアップロード・閲覧。
 */
class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function makeWorkflowRequest(User $applicant, User $approver, ?RequestType $requestType = null): WorkflowRequest
    {
        $requestType ??= RequestType::query()->create([
            'code' => 'expense_reimbursement', 'name' => '経費精算', 'form_schema' => [], 'is_active' => true,
        ]);

        return WorkflowRequest::query()->create([
            'request_type_id' => $requestType->id, 'title' => 'タクシー代', 'applicant_user_id' => $applicant->id,
            'approver_user_id' => $approver->id, 'status' => 'submitted', 'form_data' => [],
        ]);
    }

    public function test_applicant_can_upload_an_attachment_to_their_own_workflow_request(): void
    {
        $applicant = User::factory()->create();
        $approver = User::factory()->create();
        $workflowRequest = $this->makeWorkflowRequest($applicant, $approver);

        $response = $this->actingAs($applicant)->postJson('/api/attachments', [
            'owner_type' => 'workflow_request',
            'owner_id' => $workflowRequest->id,
            'file' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
        ]);

        $response->assertCreated();
        $attachmentId = $response->json('id');

        $event = StoredEvent::query()->where('aggregate_type', 'attachment')->where('aggregate_id', (string) $attachmentId)->first();
        $this->assertNotNull($event);
        $this->assertSame('attachment.uploaded', $event->event_type);
    }

    public function test_upload_is_rejected_when_it_exceeds_the_request_types_max_size(): void
    {
        $applicant = User::factory()->create();
        $approver = User::factory()->create();
        $requestType = RequestType::query()->create([
            'code' => 'expense_reimbursement', 'name' => '経費精算', 'form_schema' => [], 'is_active' => true,
            'attachment_max_size_kb' => 50,
        ]);
        $workflowRequest = $this->makeWorkflowRequest($applicant, $approver, $requestType);

        $this->actingAs($applicant)->postJson('/api/attachments', [
            'owner_type' => 'workflow_request',
            'owner_id' => $workflowRequest->id,
            'file' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
        ])->assertStatus(422);
    }

    public function test_upload_is_rejected_when_the_extension_is_not_allowed(): void
    {
        $applicant = User::factory()->create();
        $approver = User::factory()->create();
        $requestType = RequestType::query()->create([
            'code' => 'expense_reimbursement', 'name' => '経費精算', 'form_schema' => [], 'is_active' => true,
            'attachment_allowed_extensions' => ['pdf'],
        ]);
        $workflowRequest = $this->makeWorkflowRequest($applicant, $approver, $requestType);

        $this->actingAs($applicant)->postJson('/api/attachments', [
            'owner_type' => 'workflow_request',
            'owner_id' => $workflowRequest->id,
            'file' => UploadedFile::fake()->create('receipt.exe', 10),
        ])->assertStatus(422);
    }

    public function test_download_records_an_audit_event_and_a_stranger_is_forbidden(): void
    {
        $applicant = User::factory()->create();
        $approver = User::factory()->create();
        $stranger = User::factory()->create();
        $workflowRequest = $this->makeWorkflowRequest($applicant, $approver);

        $uploadResponse = $this->actingAs($applicant)->postJson('/api/attachments', [
            'owner_type' => 'workflow_request',
            'owner_id' => $workflowRequest->id,
            'file' => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
        ])->assertCreated();
        $attachmentId = $uploadResponse->json('id');

        $this->actingAs($stranger)->get("/api/attachments/{$attachmentId}/download")->assertForbidden();

        $this->actingAs($approver)->get("/api/attachments/{$attachmentId}/download")->assertSuccessful();

        $downloadedEvent = StoredEvent::query()
            ->where('aggregate_type', 'attachment')->where('aggregate_id', (string) $attachmentId)
            ->where('event_type', 'attachment.downloaded')->first();
        $this->assertNotNull($downloadedEvent);
        $this->assertSame($approver->id, $downloadedEvent->payload['downloaded_by_user_id']);
    }

    public function test_assigned_backoffice_staff_can_download_an_attachment_on_their_task(): void
    {
        $applicant = User::factory()->create();
        $approver = User::factory()->create();
        $staff = User::factory()->create();
        $workflowRequest = $this->makeWorkflowRequest($applicant, $approver);
        BackOfficeTask::query()->create([
            'source_type' => 'workflow_request', 'source_id' => $workflowRequest->id,
            'task_type' => 'expense_reimbursement', 'title' => '経費精算', 'status' => 'in_review',
            'assigned_user_id' => $staff->id,
        ]);

        $uploadResponse = $this->actingAs($applicant)->postJson('/api/attachments', [
            'owner_type' => 'workflow_request',
            'owner_id' => $workflowRequest->id,
            'file' => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
        ])->assertCreated();

        $this->actingAs($staff)
            ->get("/api/attachments/{$uploadResponse->json('id')}/download")
            ->assertSuccessful();
    }

    public function test_can_upload_and_restrict_access_to_an_attendance_day_attachment(): void
    {
        $employee = User::factory()->create();
        $otherEmployee = User::factory()->create();
        $attendanceDay = AttendanceDay::query()->create([
            'user_id' => $employee->id, 'work_date' => '2026-08-01', 'status' => 'not_started',
            'source' => 'manual', 'utc_offset_minutes' => 540,
        ]);

        $response = $this->actingAs($employee)->postJson('/api/attachments', [
            'owner_type' => 'attendance_day',
            'owner_id' => $attendanceDay->id,
            'file' => UploadedFile::fake()->create('note.pdf', 10, 'application/pdf'),
        ]);
        $response->assertCreated();

        $this->actingAs($otherEmployee)
            ->get("/api/attachments/{$response->json('id')}/download")
            ->assertForbidden();

        $this->actingAs($employee)
            ->get("/api/attachments/{$response->json('id')}/download")
            ->assertSuccessful();
    }
}
