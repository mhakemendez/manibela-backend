<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VehicleAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create([
            'role' => User::ROLE_VEHICLE_OWNER,
        ]);

        $this->vehicle = $this->createVehicle($this->owner);

        $this->actingAs($this->owner);
    }

    private function createVehicle(User $owner): Vehicle
    {
        $vehicle = $owner->vehicles()->create([
            'type' => Vehicle::TYPE_CAR,
            'brand' => 'Toyota',
            'model' => 'Vios',
            'year' => 2022,
            'plate_number' => 'TEST-' . Str::upper(Str::random(6)),
            'transmission' => 'automatic',
            'fuel_type' => 'gasoline',
            'seats' => 5,
        ]);

        $vehicle->status = Vehicle::STATUS_PENDING;
        $vehicle->save();

        return $vehicle;
    }

    private function availabilityData(array $overrides = []): array
    {
        return array_merge([
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'reason' => 'Personal use',
        ], $overrides);
    }

    public function test_owner_can_list_vehicle_availability(): void
    {
        $availability = $this->vehicle->availabilities()->create(
            $this->availabilityData()
        );

        $response = $this->getJson(
            "/api/vehicles/{$this->vehicle->id}/availability"
        );

        $response->assertOk()
            ->assertJsonPath('data.0.id', $availability->id);
    }

    public function test_owner_can_create_vehicle_availability(): void
    {
        $response = $this->postJson(
            "/api/vehicles/{$this->vehicle->id}/availability",
            $this->availabilityData()
        );

        $response->assertCreated()
            ->assertJsonPath(
                'message',
                'Vehicle availability created successfully.'
            );

        $this->assertDatabaseHas('vehicle_availabilities', [
            'vehicle_id' => $this->vehicle->id,
            'reason' => 'Personal use',
        ]);
    }

    public function test_owner_cannot_manage_another_owners_vehicle_availability(): void
    {
        $anotherOwner = User::factory()->create([
            'role' => User::ROLE_VEHICLE_OWNER,
        ]);

        $anotherVehicle = $this->createVehicle($anotherOwner);

        $response = $this->postJson(
            "/api/vehicles/{$anotherVehicle->id}/availability",
            $this->availabilityData()
        );

        $response->assertForbidden();
    }

    public function test_owner_cannot_list_another_owners_vehicle_availability(): void
    {
        $anotherOwner = User::factory()->create([
            'role' => User::ROLE_VEHICLE_OWNER,
        ]);

        $anotherVehicle = $this->createVehicle($anotherOwner);

        $response = $this->getJson(
            "/api/vehicles/{$anotherVehicle->id}/availability"
        );

        $response->assertForbidden();
    }

    public function test_availability_requires_valid_dates(): void
    {
        $response = $this->postJson(
            "/api/vehicles/{$this->vehicle->id}/availability",
            [
                'start_date' => now()->subDays(2)->toDateString(),
                'end_date' => now()->subDays(1)->toDateString(),
            ]
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'start_date',
            ]);
    }

    public function test_end_date_cannot_be_before_start_date(): void
    {
        $response = $this->postJson(
            "/api/vehicles/{$this->vehicle->id}/availability",
            $this->availabilityData([
                'start_date' => now()->addDays(10)->toDateString(),
                'end_date' => now()->addDays(5)->toDateString(),
            ])
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'end_date',
            ]);
    }

    public function test_owner_cannot_create_overlapping_availability_periods(): void
    {
        $this->vehicle->availabilities()->create(
            $this->availabilityData()
        );

        $response = $this->postJson(
            "/api/vehicles/{$this->vehicle->id}/availability",
            $this->availabilityData([
                'start_date' => now()->addDays(6)->toDateString(),
                'end_date' => now()->addDays(9)->toDateString(),
            ])
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'start_date',
            ]);
    }

    public function test_owner_can_update_vehicle_availability(): void
    {
        $availability = $this->vehicle->availabilities()->create(
            $this->availabilityData()
        );

        $response = $this->putJson(
            "/api/vehicles/{$this->vehicle->id}/availability/{$availability->id}",
            $this->availabilityData([
                'start_date' => now()->addDays(10)->toDateString(),
                'end_date' => now()->addDays(12)->toDateString(),
                'reason' => 'Vehicle maintenance',
            ])
        );

        $response->assertOk()
            ->assertJsonPath(
                'message',
                'Vehicle availability updated successfully.'
            );

        $this->assertDatabaseHas('vehicle_availabilities', [
            'id' => $availability->id,
            'reason' => 'Vehicle maintenance',
        ]);
    }

    public function test_updating_availability_does_not_conflict_with_itself(): void
    {
        $availability = $this->vehicle->availabilities()->create(
            $this->availabilityData()
        );

        $response = $this->putJson(
            "/api/vehicles/{$this->vehicle->id}/availability/{$availability->id}",
            $this->availabilityData([
                'reason' => 'Updated reason',
            ])
        );

        $response->assertOk();

        $this->assertDatabaseHas('vehicle_availabilities', [
            'id' => $availability->id,
            'reason' => 'Updated reason',
        ]);
    }

    public function test_owner_cannot_update_availability_belonging_to_another_vehicle(): void
    {
        $anotherOwner = User::factory()->create([
            'role' => User::ROLE_VEHICLE_OWNER,
        ]);

        $anotherVehicle = $this->createVehicle($anotherOwner);

        $availability = $anotherVehicle->availabilities()->create(
            $this->availabilityData()
        );

        $response = $this->putJson(
            "/api/vehicles/{$this->vehicle->id}/availability/{$availability->id}",
            $this->availabilityData()
        );

        $response->assertNotFound();
    }

    public function test_owner_can_delete_vehicle_availability(): void
    {
        $availability = $this->vehicle->availabilities()->create(
            $this->availabilityData()
        );

        $response = $this->deleteJson(
            "/api/vehicles/{$this->vehicle->id}/availability/{$availability->id}"
        );

        $response->assertOk();

        $this->assertDatabaseMissing('vehicle_availabilities', [
            'id' => $availability->id,
        ]);
    }

    public function test_owner_cannot_delete_another_owners_vehicle_availability(): void
    {
        $anotherOwner = User::factory()->create([
            'role' => User::ROLE_VEHICLE_OWNER,
        ]);

        $anotherVehicle = $this->createVehicle($anotherOwner);

        $availability = $anotherVehicle->availabilities()->create(
            $this->availabilityData()
        );

        $response = $this->deleteJson(
            "/api/vehicles/{$anotherVehicle->id}/availability/{$availability->id}"
        );

        $response->assertForbidden();
    }
}