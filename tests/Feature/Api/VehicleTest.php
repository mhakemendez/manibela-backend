<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithRole(string $role): User
    {
        $user = User::factory()->create();

        $user->forceFill(['role' => $role])->save();

        return $user->refresh();
    }

    private function createVehicleOwner(): User
    {
        return $this->createUserWithRole(User::ROLE_VEHICLE_OWNER);
    }

    private function createAdmin(): User
    {
        return $this->createUserWithRole(User::ROLE_ADMIN);
    }

    private function vehicleData(): array
    {
        return [
            'type' => Vehicle::TYPE_CAR,
            'brand' => 'Toyota',
            'model' => 'Vios',
            'year' => 2024,
            'plate_number' => 'ABC-1234',
            'transmission' => 'automatic',
            'fuel_type' => 'gasoline',
            'seats' => 5,
            'description' => 'Well-maintained vehicle.',
        ];
    }

    private function createVehicle(User $owner, array $attributes = []): Vehicle
    {
        $data = array_merge(
            $this->vehicleData(),
            $attributes
        );

        $status = $data['status'] ?? Vehicle::STATUS_PENDING;

        unset($data['user_id'], $data['status']);

        $vehicle = $owner->vehicles()->create($data);

        if ($status !== Vehicle::STATUS_PENDING) {
            $vehicle->forceFill([
                'status' => $status,
            ])->save();
        }

        return $vehicle;
    }

    // CREATE

    public function test_vehicle_owner_can_create_vehicle(): void
    {
        $owner = $this->createVehicleOwner();

        $response = $this->actingAs($owner, 'web')
            ->postJson('/api/vehicles', $this->vehicleData());

        $response->assertCreated()
            ->assertJsonPath('data.user_id', $owner->id)
            ->assertJsonPath('data.status', Vehicle::STATUS_PENDING);

        $this->assertDatabaseHas('vehicles', [
            'user_id' => $owner->id,
            'plate_number' => 'ABC-1234',
            'status' => Vehicle::STATUS_PENDING,
        ]);
    }

    public function test_customer_cannot_create_vehicle(): void
    {
        $customer = $this->createUserWithRole(User::ROLE_CUSTOMER);

        $this->actingAs($customer, 'web')
            ->postJson('/api/vehicles', $this->vehicleData())
            ->assertForbidden();
    }

    public function test_guest_cannot_create_vehicle(): void
    {
        $this->postJson('/api/vehicles', $this->vehicleData())
            ->assertUnauthorized();
    }

    public function test_vehicle_owner_cannot_assign_owner_or_approval_status(): void
    {
        $owner = $this->createVehicleOwner();
        $anotherOwner = $this->createVehicleOwner();

        $data = array_merge($this->vehicleData(), [
            'user_id' => $anotherOwner->id,
            'status' => Vehicle::STATUS_APPROVED,
        ]);

        $response = $this->actingAs($owner, 'web')
            ->postJson('/api/vehicles', $data);

        $response->assertCreated()
            ->assertJsonPath('data.user_id', $owner->id)
            ->assertJsonPath('data.status', Vehicle::STATUS_PENDING);
    }

    public function test_vehicle_owner_cannot_create_vehicle_with_duplicate_plate(): void
    {
        $owner = $this->createVehicleOwner();

        $this->createVehicle($owner);

        $this->actingAs($owner, 'web')
            ->postJson('/api/vehicles', $this->vehicleData())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['plate_number']);
    }

    // READ

    public function test_vehicle_owner_can_view_own_vehicles(): void
    {
        $owner = $this->createVehicleOwner();

        $vehicle = $this->createVehicle($owner);

        $this->actingAs($owner, 'web')
            ->getJson('/api/vehicles')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $vehicle->id,
                'plate_number' => 'ABC-1234',
            ]);
    }

    public function test_vehicle_owner_cannot_view_another_owners_vehicle(): void
    {
        $owner = $this->createVehicleOwner();
        $anotherOwner = $this->createVehicleOwner();

        $vehicle = $this->createVehicle($anotherOwner);

        $this->actingAs($owner, 'web')
            ->getJson("/api/vehicles/{$vehicle->id}")
            ->assertForbidden();
    }

    public function test_vehicle_owner_can_view_own_vehicle(): void
    {
        $owner = $this->createVehicleOwner();

        $vehicle = $this->createVehicle($owner);

        $this->actingAs($owner, 'web')
            ->getJson("/api/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $vehicle->id);
    }

    // UPDATE

    public function test_vehicle_owner_can_update_own_vehicle(): void
    {
        $owner = $this->createVehicleOwner();

        $vehicle = $this->createVehicle($owner);

        $this->actingAs($owner, 'web')
            ->putJson("/api/vehicles/{$vehicle->id}", [
                'brand' => 'Honda',
                'model' => 'City',
            ])
            ->assertOk()
            ->assertJsonPath('data.brand', 'Honda')
            ->assertJsonPath('data.model', 'City');
    }

    public function test_vehicle_owner_cannot_update_another_owners_vehicle(): void
    {
        $owner = $this->createVehicleOwner();
        $anotherOwner = $this->createVehicleOwner();

        $vehicle = $this->createVehicle($anotherOwner);

        $this->actingAs($owner, 'web')
            ->putJson("/api/vehicles/{$vehicle->id}", [
                'brand' => 'Honda',
            ])
            ->assertForbidden();
    }

    public function test_vehicle_owner_cannot_change_vehicle_status(): void
    {
        $owner = $this->createVehicleOwner();

        $vehicle = $this->createVehicle($owner);

        $this->actingAs($owner, 'web')
            ->putJson("/api/vehicles/{$vehicle->id}", [
                'brand' => 'Honda',
                'status' => Vehicle::STATUS_APPROVED,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Vehicle::STATUS_PENDING);
    }

    // DELETE

    public function test_vehicle_owner_can_delete_own_vehicle(): void
    {
        $owner = $this->createVehicleOwner();

        $vehicle = $this->createVehicle($owner);

        $this->actingAs($owner, 'web')
            ->deleteJson("/api/vehicles/{$vehicle->id}")
            ->assertOk();

        $this->assertDatabaseMissing('vehicles', [
            'id' => $vehicle->id,
        ]);
    }

    public function test_vehicle_owner_cannot_delete_another_owners_vehicle(): void
    {
        $owner = $this->createVehicleOwner();
        $anotherOwner = $this->createVehicleOwner();

        $vehicle = $this->createVehicle($anotherOwner);

        $this->actingAs($owner, 'web')
            ->deleteJson("/api/vehicles/{$vehicle->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
        ]);
    }

    // ADMIN APPROVAL

    public function test_admin_can_view_pending_vehicles(): void
    {
        $owner = $this->createVehicleOwner();

        $vehicle = $this->createVehicle($owner);

        $this->actingAs($this->createAdmin(), 'web')
            ->getJson('/api/admin/vehicles')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $vehicle->id,
            ]);
    }

    public function test_admin_can_approve_pending_vehicle(): void
    {
        $owner = $this->createVehicleOwner();

        $vehicle = $this->createVehicle($owner);

        $this->actingAs($this->createAdmin(), 'web')
            ->patchJson("/api/admin/vehicles/{$vehicle->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', Vehicle::STATUS_APPROVED);

        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'status' => Vehicle::STATUS_APPROVED,
        ]);
    }

    public function test_admin_can_reject_pending_vehicle(): void
    {
        $owner = $this->createVehicleOwner();

        $vehicle = $this->createVehicle($owner);

        $this->actingAs($this->createAdmin(), 'web')
            ->patchJson("/api/admin/vehicles/{$vehicle->id}/reject", [
                'reason' => 'Invalid vehicle documents.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Vehicle::STATUS_REJECTED)
            ->assertJsonPath('reason', 'Invalid vehicle documents.');
    }

    public function test_customer_cannot_approve_vehicle(): void
    {
        $owner = $this->createVehicleOwner();

        $vehicle = $this->createVehicle($owner);

        $customer = $this->createUserWithRole(User::ROLE_CUSTOMER);

        $this->actingAs($customer, 'web')
            ->patchJson("/api/admin/vehicles/{$vehicle->id}/approve")
            ->assertForbidden();
    }

    public function test_vehicle_owner_cannot_approve_vehicle(): void
    {
        $owner = $this->createVehicleOwner();

        $vehicle = $this->createVehicle($owner);

        $this->actingAs($this->createVehicleOwner(), 'web')
            ->patchJson("/api/admin/vehicles/{$vehicle->id}/approve")
            ->assertForbidden();
    }

    public function test_guest_cannot_access_vehicle_endpoints(): void
    {
        $this->getJson('/api/vehicles')->assertUnauthorized();

        $this->getJson('/api/admin/vehicles')->assertUnauthorized();
    }

    public function test_approved_vehicle_cannot_be_approved_again(): void
    {
        $owner = $this->createVehicleOwner();

        $vehicle = $this->createVehicle($owner, [
            'status' => Vehicle::STATUS_APPROVED,
        ]);

        $this->actingAs($this->createAdmin(), 'web')
            ->patchJson("/api/admin/vehicles/{$vehicle->id}/approve")
            ->assertUnprocessable();
    }
}