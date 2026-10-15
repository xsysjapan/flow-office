<?php

namespace Tests\Unit\EventSourcing\Rebuild;

use App\Domain\EventSourcing\Rebuild\ExternalTenantIdRebuild;
use App\Domain\EventSourcing\Rebuild\ExternalTenantIdRebuilder;
use App\Domain\EventSourcing\Rebuild\RebuiltEvent;
use App\Domain\EventSourcing\Rebuild\StoredEventRow;
use PHPUnit\Framework\TestCase;

/**
 * 旧イベントへの externalTenantId の補完(論点19 決定9)の純粋な検証。DBは使わない。
 * 「行があれば補う」「行が無ければ補わない」「前提(行のtenantが1種類・設定との一致)を満たさなければ止める」を確かめる。
 */
class ExternalTenantIdRebuilderTest extends TestCase
{
    private const AT = '2026-08-04 08:43:09';

    private const USER = '11111111-1111-4111-8111-111111111111';

    private const USER2 = '22222222-2222-4222-8222-222222222222';

    private const ENTRA = 'entra-1';

    private const ENTRA2 = 'entra-2';

    private const TENANT = 'tenant-A';

    // ---- 補う ----

    public function test_old_sso_events_get_the_tenant_from_the_current_identity_row_when_the_setting_is_null(): void
    {
        $result = $this->rebuild([
            $this->event(1, 'user.created_from_sso_login', ['entraUserId' => self::ENTRA, 'name' => 'A', 'email' => 'a@example.com']),
            $this->event(2, 'user.sso_account_linked', ['entraUserId' => self::ENTRA]),
        ], [$this->identity(self::ENTRA, self::TENANT)], null);

        $this->assertSame([], $result->issues);
        $this->assertSame('row_only', $result->mode);
        $this->assertSame(self::TENANT, $result->tenant);
        $this->assertSame([1, 2], $result->consumedIds);
        $this->assertSame(['user.created_from_sso_login' => 1, 'user.sso_account_linked' => 1], $result->filledByClass);

        $first = $result->events[0];
        $this->assertInstanceOf(RebuiltEvent::class, $first);
        $this->assertSame('user.created_from_sso_login', $first->eventClass);
        $this->assertSame(self::USER, $first->aggregateUuid);
        $this->assertSame(1, $first->anchorId);
        $this->assertSame(0, $first->subOrder);
        $this->assertSame(self::AT, $first->createdAt);
        // 元の payload を保ち、externalTenantId だけを足す。
        $this->assertSame(['entraUserId' => self::ENTRA, 'name' => 'A', 'email' => 'a@example.com', 'externalTenantId' => self::TENANT], $first->payload);
    }

    public function test_the_tenant_is_filled_when_the_setting_matches_the_row_ignoring_case(): void
    {
        $result = $this->rebuild([
            $this->event(1, 'user.synced_from_ms365', ['entraUserId' => self::ENTRA, 'name' => 'A', 'email' => null, 'department' => null, 'jobTitle' => null, 'employmentStatus' => 'active']),
        ], [$this->identity(self::ENTRA, self::TENANT)], 'TENANT-a');

        $this->assertSame([], $result->issues);
        $this->assertSame('checked', $result->mode);
        $this->assertSame(self::TENANT, $result->events[0]->payload['externalTenantId']);
    }

    public function test_every_entra_projecting_class_is_filled_and_other_classes_are_untouched(): void
    {
        $result = $this->rebuild([
            $this->event(1, 'user.onboarded_as_admin', ['entraUserId' => self::ENTRA, 'name' => 'A', 'email' => null, 'authMethod' => 'sso']),
            $this->event(2, 'user.created_from_sso_login', ['entraUserId' => self::ENTRA, 'name' => 'A', 'email' => 'a@example.com']),
            $this->event(3, 'user.synced_from_ms365', ['entraUserId' => self::ENTRA, 'name' => 'A', 'email' => null, 'department' => null, 'jobTitle' => null, 'employmentStatus' => 'active']),
            $this->event(4, 'user.sso_account_linked', ['entraUserId' => self::ENTRA]),
            $this->event(5, 'user.hire_date_set', ['hireDate' => '2026-04-01']),
        ], [$this->identity(self::ENTRA, self::TENANT)], null);

        $this->assertSame([1, 2, 3, 4], $result->consumedIds);
        $this->assertSame(4, array_sum($result->filledByClass));
        $this->assertSame(1, $result->filledByClass['user.onboarded_as_admin']);
    }

    // ---- 補わない ----

    public function test_an_event_whose_row_is_missing_is_not_filled(): void
    {
        $result = $this->rebuild([
            $this->event(1, 'user.sso_account_linked', ['entraUserId' => self::ENTRA2]),
        ], [$this->identity(self::ENTRA, self::TENANT)], null);

        $this->assertSame([], $result->issues);
        $this->assertSame([], $result->events);
        $this->assertSame([], $result->consumedIds);
        $this->assertSame(1, $result->noRow);
    }

    public function test_an_event_whose_row_has_a_null_tenant_is_not_filled(): void
    {
        $result = $this->rebuild([
            $this->event(1, 'user.created_from_sso_login', ['entraUserId' => self::ENTRA, 'name' => 'A', 'email' => 'a@example.com']),
        ], [$this->identity(self::ENTRA2, self::TENANT), $this->identity(self::ENTRA, null)], null);

        $this->assertSame([], $result->issues);
        $this->assertSame([], $result->events);
        $this->assertSame(1, $result->nullTenant);
    }

    public function test_an_event_with_a_null_entra_user_id_is_not_filled(): void
    {
        $result = $this->rebuild([
            $this->event(1, 'user.onboarded_as_admin', ['entraUserId' => null, 'name' => 'A', 'email' => null, 'authMethod' => 'local']),
        ], [$this->identity(self::ENTRA, self::TENANT)], null);

        $this->assertSame([], $result->events);
        $this->assertSame([], $result->issues);
    }

    public function test_an_event_that_already_has_the_key_is_kept_even_when_its_value_is_null(): void
    {
        $result = $this->rebuild([
            $this->event(1, 'user.created_from_sso_login', ['entraUserId' => self::ENTRA, 'name' => 'A', 'email' => 'a@example.com', 'externalTenantId' => null]),
        ], [$this->identity(self::ENTRA, self::TENANT)], null);

        $this->assertSame([], $result->events);
        $this->assertSame(1, $result->alreadyComplete);
    }

    public function test_without_any_entra_identity_rows_nothing_is_checked_or_filled(): void
    {
        $result = $this->rebuild([
            $this->event(1, 'user.created_from_sso_login', ['entraUserId' => self::ENTRA, 'name' => 'A', 'email' => 'a@example.com']),
        ], [$this->identity(self::ENTRA, self::TENANT, 'EXTERNAL_HR')], self::TENANT);

        $this->assertSame([], $result->issues);
        $this->assertSame('no_rows', $result->mode);
        $this->assertSame([], $result->events);
        $this->assertSame(1, $result->noRow);
    }

    // ---- 止める ----

    public function test_the_rebuild_stops_when_the_rows_have_two_tenants(): void
    {
        $result = $this->rebuild([
            $this->event(1, 'user.created_from_sso_login', ['entraUserId' => self::ENTRA, 'name' => 'A', 'email' => 'a@example.com']),
        ], [$this->identity(self::ENTRA, 'tenant-A'), $this->identity(self::ENTRA2, 'tenant-B')], null);

        $this->assertCount(1, $result->issues);
        $this->assertStringContainsString('1種類ではない(2種類)', $result->issues[0]['reason']);
        $this->assertSame([], $result->events);
        $this->assertSame([], $result->consumedIds);
    }

    public function test_the_rebuild_stops_when_the_rows_have_no_non_null_tenant(): void
    {
        $result = $this->rebuild([
            $this->event(1, 'user.created_from_sso_login', ['entraUserId' => self::ENTRA, 'name' => 'A', 'email' => 'a@example.com']),
        ], [$this->identity(self::ENTRA, null)], null);

        $this->assertCount(1, $result->issues);
        $this->assertStringContainsString('0種類', $result->issues[0]['reason']);
        $this->assertSame([], $result->events);
    }

    public function test_the_rebuild_stops_when_the_row_tenant_differs_from_the_setting(): void
    {
        $result = $this->rebuild([
            $this->event(1, 'user.created_from_sso_login', ['entraUserId' => self::ENTRA, 'name' => 'A', 'email' => 'a@example.com']),
        ], [$this->identity(self::ENTRA, self::TENANT)], 'tenant-other');

        $this->assertCount(1, $result->issues);
        $this->assertStringContainsString('tenant-other', $result->issues[0]['reason']);
        $this->assertSame([], $result->events);
        $this->assertSame([], $result->consumedIds);
    }

    // ---- 報告 ----

    public function test_the_report_counts_what_was_filled_and_what_was_left(): void
    {
        $result = $this->rebuild([
            $this->event(1, 'user.created_from_sso_login', ['entraUserId' => self::ENTRA, 'name' => 'A', 'email' => 'a@example.com']),
            $this->event(2, 'user.created_from_sso_login', ['entraUserId' => self::ENTRA2, 'name' => 'B', 'email' => 'b@example.com']),
            $this->event(3, 'user.created_from_sso_login', ['entraUserId' => 'entra-none', 'name' => 'C', 'email' => 'c@example.com']),
        ], [$this->identity(self::ENTRA, self::TENANT), $this->identity(self::ENTRA2, self::TENANT)], null);

        $this->assertSame([
            'filled' => ['user.created_from_sso_login' => 2],
            'filled_total' => 2,
            'no_row' => 1,
            'null_tenant' => 0,
            'already_complete' => 0,
            'mode' => 'row_only',
            'tenant' => self::TENANT,
            'issues' => 0,
        ], $result->report());
    }

    // ---- helpers ----

    /** @param  list<array<string, mixed>>  $identities */
    private function rebuild(array $rows, array $identities, ?string $setting): ExternalTenantIdRebuild
    {
        return (new ExternalTenantIdRebuilder)->rebuild($rows, $identities, $setting);
    }

    private function event(int $id, string $class, array $payload): StoredEventRow
    {
        $aggregate = self::USER;

        return new StoredEventRow($id, $aggregate, 1, $class, $payload, ['aggregate-root-uuid' => $aggregate], self::AT);
    }

    /** @return array<string, mixed> */
    private function identity(string $subject, ?string $tenant, string $provider = 'MICROSOFT_ENTRA'): array
    {
        return ['id' => 1, 'user_id' => self::USER2, 'provider' => $provider, 'external_subject_id' => $subject, 'external_tenant_id' => $tenant, 'status' => 'active'];
    }
}
