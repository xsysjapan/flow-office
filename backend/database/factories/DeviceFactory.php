<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\DeviceOwnerType;
use App\Models\DeviceStatus;
use App\Models\DeviceType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_type' => DeviceOwnerType::ORGANIZATION_SHARED,
            'owner_user_id' => null,
            'name' => fake()->words(2, true),
            'device_type' => DeviceType::ANDROID,
            'status' => DeviceStatus::ACTIVE,
            'location_name' => fake()->words(3, true),
        ];
    }
}
