<?php

namespace Tests\Feature;

use Tests\TestCase;

class SwaggerDocumentationTest extends TestCase
{
    public function test_generated_documentation_comes_from_manual_controller_attributes(): void
    {
        $this->assertSame([], config('l5-swagger.defaults.scanOptions.processors'));
        $this->assertFileDoesNotExist(app_path('OpenApi/OpenApiRouteProcessor.php'));

        $this->artisan('l5-swagger:generate')->assertSuccessful();

        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('/users', $document['paths']);
        $this->assertArrayHasKey('/auth/token', $document['paths']);
        $this->assertArrayHasKey('/attendance/today', $document['paths']);
        $this->assertArrayHasKey('/attendance-punches', $document['paths']);
        $this->assertArrayHasKey('/workflow-requests', $document['paths']);
        $this->assertArrayHasKey('/paid-leave/requests', $document['paths']);
        $this->assertArrayHasKey('/work-styles', $document['paths']);
        $this->assertArrayHasKey('/attachments', $document['paths']);
        $this->assertArrayNotHasKey('/oauth2-callback', $document['paths']);

        $attendanceWeek = $document['paths']['/attendance/week']['get'];
        $this->assertSame('勤怠', $attendanceWeek['tags'][0]);
        $this->assertSame('週次勤怠を取得する', $attendanceWeek['summary']);
        $this->assertArrayNotHasKey('requestBody', $attendanceWeek);
        $this->assertContains('start_date', array_column($attendanceWeek['parameters'], 'name'));

        $attendancePunchesIndex = $document['paths']['/attendance-punches']['get'];
        $this->assertContains('from', array_column($attendancePunchesIndex['parameters'], 'name'));
        $this->assertContains('to', array_column($attendancePunchesIndex['parameters'], 'name'));

        $attendancePunchBody = $document['paths']['/attendance-punches']['post']['requestBody']['content']['application/json']['schema'];
        $this->assertContains('work_date', $attendancePunchBody['required']);
        $this->assertContains('punch_type', $attendancePunchBody['required']);
        $this->assertArrayHasKey('punched_at', $attendancePunchBody['properties']);

        $usersIndex = $document['paths']['/users']['get'];
        $this->assertSame('ユーザー', $usersIndex['tags'][0]);
        $this->assertContains('q', array_column($usersIndex['parameters'], 'name'));

        $tagNames = array_column($document['tags'], 'name');
        $this->assertContains('汎用申請', $tagNames);
        $this->assertContains('添付ファイル', $tagNames);

        $workflowRequestBody = $document['paths']['/workflow-requests']['post']['requestBody']['content']['application/json']['schema'];
        $this->assertContains('request_type_code', $workflowRequestBody['required']);
        $this->assertContains('title', $workflowRequestBody['required']);
        $this->assertArrayHasKey('form_data', $workflowRequestBody['properties']);

        $requestTypesIndex = $document['paths']['/request-types']['get'];
        $this->assertContains('include_inactive', array_column($requestTypesIndex['parameters'], 'name'));

        $requestTypeBody = $document['paths']['/request-types']['post']['requestBody']['content']['application/json']['schema'];
        $this->assertArrayHasKey('form_schema', $requestTypeBody['properties']);
        $this->assertArrayHasKey('requires_backoffice_task', $requestTypeBody['properties']);

        $attachmentBody = $document['paths']['/attachments']['post']['requestBody']['content'];
        $this->assertArrayHasKey('multipart/form-data', $attachmentBody);
        $this->assertSame('binary', $attachmentBody['multipart/form-data']['schema']['properties']['file']['format']);

        $securityScheme = $document['components']['securitySchemes']['sanctum'];
        $this->assertSame('http', $securityScheme['type']);
        $this->assertSame('bearer', $securityScheme['scheme']);
        $this->assertSame([['sanctum' => []]], $document['security']);
        $this->assertArrayNotHasKey('name', $securityScheme);
        $this->assertArrayNotHasKey('in', $securityScheme);
    }
}
