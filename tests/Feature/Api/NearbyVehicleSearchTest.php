<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NearbyVehicleSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
        ]);

        $this->owner = User::factory()->create([
            'role' => User::ROLE_VEHICLE_OWNER,
        ]);

        $this->actingAs($this->customer);
    }

    private function createVehicle(array $overrides = []): Vehicle
    {
        $data = array_merge([
            'type' => Vehicle::TYPE_CAR,
            'brand' => 'Toyota',
            'model' => 'Vios',
            'year' => 2024,
            'plate_number' => 'TEST-' . Str::upper(Str::random(8)),
            'transmission' => 'automatic',
            'fuel_type' => 'gasoline',
            'seats' => 5,
            'pickup_latitude' => 14.5995,
            'pickup_longitude' => 120.9842,
        ], $overrides);

        $status = $data['status'] ?? Vehicle::STATUS_APPROVED;
        unset($data['status']);

        $vehicle = $this->owner->vehicles()->create($data);

        $vehicle->status = $status;
        $vehicle->save();

        return $vehicle;
    }

    private function searchParams(array $overrides = []): array
    {
        return array_merge([
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'radius' => 10,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'per_page' => 10,
        ], $overrides);
    }

    private function search(array $overrides = [])
    {
        return $this->getJson(
            '/api/vehicles/nearby?' .
            http_build_query($this->searchParams($overrides))
        );
    }

    public function test_customer_can_search_nearby_approved_vehicles(): void
    {
        $vehicle = $this->createVehicle();

        $response = $this->search();

        $response->assertOk()
            ->assertJsonPath('data.0.id', $vehicle->id)
            ->assertJsonPath('data.0.distance_km', 0);

        $this->assertCount(1, $response->json('data'));
    }

    public function test_nearby_search_excludes_unapproved_vehicles(): void
    {
        $approved = $this->createVehicle();

        $this->createVehicle([
            'status' => Vehicle::STATUS_PENDING,
        ]);

        $this->createVehicle([
            'status' => Vehicle::STATUS_REJECTED,
        ]);

        $response = $this->search();

        $response->assertOk()
            ->assertJsonPath('data.0.id', $approved->id);

        $this->assertCount(1, $response->json('data'));
    }

    public function test_nearby_search_excludes_vehicles_without_coordinates(): void
    {
        $this->createVehicle([
            'pickup_latitude' => null,
            'pickup_longitude' => null,
        ]);

        $response = $this->search();

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_nearby_search_excludes_vehicles_outside_radius(): void
    {
        $this->createVehicle([
            'pickup_latitude' => 14.7000,
            'pickup_longitude' => 121.1000,
        ]);

        $response = $this->search([
            'radius' => 1,
        ]);

        $response->assertOk();

        dump($response->json('data'));
    }

    public function test_customer_can_filter_nearby_vehicles_by_type(): void
    {
        $motorcycle = $this->createVehicle([
            'type' => Vehicle::TYPE_MOTORCYCLE,
        ]);

        $this->createVehicle([
            'type' => Vehicle::TYPE_CAR,
        ]);

        $response = $this->search([
            'type' => Vehicle::TYPE_MOTORCYCLE,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.0.id', $motorcycle->id);

        $this->assertCount(1, $response->json('data'));
    }

    public function test_nearby_search_excludes_vehicles_with_overlapping_blocked_dates(): void
    {
        $vehicle = $this->createVehicle();

        $vehicle->availabilities()->create([
            'start_date' => now()->addDays(6)->toDateString(),
            'end_date' => now()->addDays(8)->toDateString(),
            'reason' => 'Personal use',
        ]);

        $response = $this->search();

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_nearby_search_includes_vehicles_without_overlapping_blocked_dates(): void
    {
        $vehicle = $this->createVehicle();

        $vehicle->availabilities()->create([
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(12)->toDateString(),
            'reason' => 'Personal use',
        ]);

        $response = $this->search();

        $response->assertOk()
            ->assertJsonPath('data.0.id', $vehicle->id);
    }

    public function test_nearby_search_returns_nearest_vehicles_first(): void
    {
        $farVehicle = $this->createVehicle([
            'pickup_latitude' => 14.6200,
            'pickup_longitude' => 120.9842,
        ]);

        $nearVehicle = $this->createVehicle([
            'pickup_latitude' => 14.6000,
            'pickup_longitude' => 120.9842,
        ]);

        $response = $this->search();

        $response->assertOk();

        $vehicleIds = array_column(
            $response->json('data'),
            'id'
        );

        $this->assertSame([
            $nearVehicle->id,
            $farVehicle->id,
        ], $vehicleIds);
    }

    public function test_nearby_search_supports_pagination(): void
    {
        $this->createVehicle();
        $this->createVehicle([
            'pickup_latitude' => 14.6000,
        ]);
        $this->createVehicle([
            'pickup_latitude' => 14.6010,
        ]);

        $response = $this->search([
            'per_page' => 2,
        ]);

        $response->assertOk()
            ->assertJsonPath('per_page', 2)
            ->assertJsonPath('total', 3)
            ->assertJsonCount(2, 'data');
    }

    public function test_nearby_search_returns_vehicle_photos(): void
    {
        $this->createVehicle();

        $response = $this->search();

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'distance_km',
                        'photos',
                    ],
                ],
            ]);
    }

    public function test_nearby_search_validates_coordinates_radius_and_dates(): void
    {
        $this->search([
            'latitude' => 91,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude']);

        $this->search([
            'longitude' => 181,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['longitude']);

        $this->search([
            'radius' => 51,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['radius']);

        $this->search([
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['end_date']);
    }

    public function test_nearby_search_requires_authentication(): void
    {
        auth()->logout();

        $this->search()->assertUnauthorized();
    }

    public function test_nearby_search_is_only_available_to_customers(): void
    {
        $this->actingAs($this->owner);

        $this->search()->assertForbidden();
    }
}
