<?php

namespace Tests\Unit\Models;

use App\Models\AuthenticationKey;
use App\Models\AuthenticationKeyDeviceRule;
use App\Models\AuthenticationKeyStatus;
use App\Models\AuthenticationKeyType;
use App\Models\Device;
use App\Models\DeviceOwnerType;
use App\Models\DeviceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * docs/16-database-schema.md「authentication_key_device_rules(認証キーの利用制限)」:
 * ルールが1件も無ければ制限なし、ルールが1件でもあれば一致する端末・事業所のみ許可する
 * (default-deny)ことを確認する。
 */
class AuthenticationKeyTest extends TestCase
{
    use RefreshDatabase;

    private function key(): AuthenticationKey
    {
        $user = User::factory()->create();

        return AuthenticationKey::query()->create([
            'user_id' => $user->id,
            'key_type' => AuthenticationKeyType::NFC_UID,
            'display_name' => 'テストキー',
            'key_hash' => 'dummy-hash',
            'status' => AuthenticationKeyStatus::ACTIVE,
            'registered_by_user_id' => $user->id,
            'registered_at' => now(),
        ]);
    }

    private function device(?string $siteId = null): Device
    {
        return Device::factory()->create([
            'owner_type' => DeviceOwnerType::ORGANIZATION_SHARED,
            'device_type' => DeviceType::NFC_READER,
            'site_id' => $siteId,
        ]);
    }

    public function test_a_key_with_no_device_rules_is_usable_on_any_device(): void
    {
        $key = $this->key();
        $device = $this->device();

        $this->assertTrue($key->isUsableOnDevice($device->id));
        $this->assertTrue($key->isUsableOnDevice(null));
    }

    public function test_a_key_restricted_to_one_device_is_not_usable_on_a_different_device(): void
    {
        $key = $this->key();
        $allowedDevice = $this->device();
        $otherDevice = $this->device();

        AuthenticationKeyDeviceRule::query()->create([
            'authentication_key_id' => $key->id,
            'device_id' => $allowedDevice->id,
            'allow' => true,
        ]);

        $this->assertTrue($key->fresh()->isUsableOnDevice($allowedDevice->id));
        $this->assertFalse($key->fresh()->isUsableOnDevice($otherDevice->id));
        $this->assertFalse($key->fresh()->isUsableOnDevice(null));
    }

    public function test_a_key_restricted_to_one_site_is_not_usable_on_a_device_at_another_site(): void
    {
        $key = $this->key();
        $allowedSiteDevice = $this->device('hq');
        $otherSiteDevice = $this->device('branch');

        AuthenticationKeyDeviceRule::query()->create([
            'authentication_key_id' => $key->id,
            'site_id' => 'hq',
            'allow' => true,
        ]);

        $this->assertTrue($key->fresh()->isUsableOnDevice($allowedSiteDevice->id));
        $this->assertFalse($key->fresh()->isUsableOnDevice($otherSiteDevice->id));
    }

    public function test_an_explicit_deny_rule_overrides_a_matching_allow_rule(): void
    {
        $key = $this->key();
        $device = $this->device();

        AuthenticationKeyDeviceRule::query()->create([
            'authentication_key_id' => $key->id,
            'device_id' => null,
            'site_id' => null,
            'allow' => true,
        ]);
        AuthenticationKeyDeviceRule::query()->create([
            'authentication_key_id' => $key->id,
            'device_id' => $device->id,
            'allow' => false,
        ]);

        $this->assertFalse($key->fresh()->isUsableOnDevice($device->id));
    }
}
