<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithRole(string $role): User
    {
        $user = User::factory()->create();

        $user->forceFill(['role' => $role])->save();

        return $user->refresh();
    }

    private function createCustomer(): User
    {
        return $this->createUserWithRole(User::ROLE_CUSTOMER);
    }

    private function createVehicleOwner(): User
    {
        return $this->createUserWithRole(User::ROLE_VEHICLE_OWNER);
    }


    private function createApprovedVehicle(User $owner, array $attributes = []): Vehicle
    {
        $status = $attributes['status'] ?? Vehicle::STATUS_APPROVED;

        unset($attributes['status']);

        $vehicle = $owner->vehicles()->create(array_merge([
            'type' => 'car',
            'brand' => 'Toyota',
            'model' => 'Vios',
            'year' => 2024,
            'plate_number' => 'TEST-' . fake()->unique()->numerify('####'),
            'transmission' => 'automatic',
            'fuel_type' => 'gasoline',
            'seats' => 5,
            'description' => 'Test vehicle.',
            'daily_rate' => 1500,
        ], $attributes));

        $vehicle->forceFill([
            'status' => $status,
        ])->save();

        return $vehicle;
    }


    private function bookingData(Vehicle $vehicle, array $attributes = []): array
    {
        return array_merge([
            'vehicle_id' => $vehicle->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
        ], $attributes);
    }

    private function createBooking(
        User $customer,
        Vehicle $vehicle,
        array $attributes = []
    ): Booking {
        return Booking::create(array_merge([
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'daily_rate' => 1500,
            'total_days' => 2,
            'total_amount' => 3000,
            'status' => Booking::STATUS_PENDING,
        ], $attributes));
    }

    // CUSTOMER BOOKING CREATION

    public function test_customer_can_create_booking(): void
    {
        $customer = $this->createCustomer();
        $vehicle = $this->createApprovedVehicle($this->createVehicleOwner());

        $this->actingAs($customer, 'web')
            ->postJson('/api/bookings', $this->bookingData($vehicle))
            ->assertCreated()
            ->assertJsonPath('message', 'Booking request submitted successfully.')
            ->assertJsonPath('data.status', Booking::STATUS_PENDING)
            ->assertJsonPath('data.total_days', 2)
            ->assertJsonPath('data.daily_rate', '1500.00')
            ->assertJsonPath('data.total_amount', '3000.00');

        $this->assertDatabaseHas('bookings', [
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => Booking::STATUS_PENDING,
            'total_days' => 2,
            'total_amount' => 3000,
        ]);
    }

    public function test_customer_cannot_book_unapproved_vehicle(): void
    {
        $customer = $this->createCustomer();

        $vehicle = $this->createApprovedVehicle(
            $this->createVehicleOwner()
        );

        $vehicle = $this->createApprovedVehicle(
            $this->createVehicleOwner(),
            ['status' => Vehicle::STATUS_PENDING]
        );

        $this->actingAs($customer, 'web')
            ->postJson('/api/bookings', $this->bookingData($vehicle))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vehicle_id']);
    }

    public function test_customer_cannot_book_own_vehicle(): void
    {
        $customer = $this->createCustomer();

        $vehicle = $this->createApprovedVehicle($customer);

        $this->actingAs($customer, 'web')
            ->postJson('/api/bookings', $this->bookingData($vehicle))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vehicle_id']);
    }

    public function test_customer_cannot_book_with_invalid_dates(): void
    {
        $customer = $this->createCustomer();

        $vehicle = $this->createApprovedVehicle(
            $this->createVehicleOwner()
        );

        $this->actingAs($customer, 'web')
            ->postJson('/api/bookings', $this->bookingData($vehicle, [
                'start_date' => now()->subDay()->toDateString(),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date']);
    }

    public function test_booking_end_date_must_be_after_start_date(): void
    {
        $customer = $this->createCustomer();

        $vehicle = $this->createApprovedVehicle(
            $this->createVehicleOwner()
        );

        $date = now()->addDays(5)->toDateString();

        $this->actingAs($customer, 'web')
            ->postJson('/api/bookings', $this->bookingData($vehicle, [
                'start_date' => $date,
                'end_date' => $date,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_date']);
    }

    public function test_customer_cannot_book_blocked_dates(): void
    {
        $customer = $this->createCustomer();

        $vehicle = $this->createApprovedVehicle(
            $this->createVehicleOwner()
        );

        $startDate = now()->addDays(5)->toDateString();
        $endDate = now()->addDays(7)->toDateString();

        $vehicle->availabilities()->create([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'reason' => 'Maintenance',
        ]);

        $this->actingAs($customer, 'web')
            ->postJson('/api/bookings', $this->bookingData($vehicle, [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date']);
    }

    public function test_customer_cannot_book_dates_with_existing_pending_booking(): void
    {
        $customer = $this->createCustomer();
        $anotherCustomer = $this->createCustomer();

        $vehicle = $this->createApprovedVehicle(
            $this->createVehicleOwner()
        );

        $this->createBooking($anotherCustomer, $vehicle);

        $this->actingAs($customer, 'web')
            ->postJson('/api/bookings', $this->bookingData($vehicle))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date']);
    }

    public function test_customer_can_book_dates_after_cancelled_booking(): void
    {
        $customer = $this->createCustomer();
        $anotherCustomer = $this->createCustomer();

        $vehicle = $this->createApprovedVehicle(
            $this->createVehicleOwner()
        );

        $this->createBooking($anotherCustomer, $vehicle, [
            'status' => Booking::STATUS_CANCELLED,
        ]);

        $this->actingAs($customer, 'web')
            ->postJson('/api/bookings', $this->bookingData($vehicle))
            ->assertCreated();
    }

    public function test_booking_keeps_original_price_snapshot(): void
    {
        $customer = $this->createCustomer();

        $vehicle = $this->createApprovedVehicle(
            $this->createVehicleOwner()
        );

        $this->actingAs($customer, 'web')
            ->postJson('/api/bookings', $this->bookingData($vehicle))
            ->assertCreated();

        $vehicle->update([
            'daily_rate' => 2500,
        ]);

        $this->assertDatabaseHas('bookings', [
            'vehicle_id' => $vehicle->id,
            'daily_rate' => 1500,
            'total_amount' => 3000,
        ]);
    }

    // CUSTOMER BOOKING ACCESS

    public function test_customer_can_view_own_bookings(): void
    {
        $customer = $this->createCustomer();

        $vehicle = $this->createApprovedVehicle(
            $this->createVehicleOwner()
        );

        $booking = $this->createBooking($customer, $vehicle);

        $this->actingAs($customer, 'web')
            ->getJson('/api/bookings')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $booking->id,
            ]);
    }

    public function test_customer_cannot_view_another_customers_booking(): void
    {
        $customer = $this->createCustomer();
        $anotherCustomer = $this->createCustomer();

        $vehicle = $this->createApprovedVehicle(
            $this->createVehicleOwner()
        );

        $booking = $this->createBooking($anotherCustomer, $vehicle);

        $this->actingAs($customer, 'web')
            ->getJson("/api/bookings/{$booking->id}")
            ->assertForbidden();
    }

    public function test_customer_can_cancel_own_booking(): void
    {
        $customer = $this->createCustomer();

        $vehicle = $this->createApprovedVehicle(
            $this->createVehicleOwner()
        );

        $booking = $this->createBooking($customer, $vehicle);

        $this->actingAs($customer, 'web')
            ->patchJson("/api/bookings/{$booking->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', Booking::STATUS_CANCELLED);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => Booking::STATUS_CANCELLED,
        ]);
    }

    // OWNER BOOKING MANAGEMENT

    public function test_vehicle_owner_can_view_own_vehicle_bookings(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createVehicleOwner();

        $vehicle = $this->createApprovedVehicle($owner);

        $booking = $this->createBooking($customer, $vehicle);

        $this->actingAs($owner, 'web')
            ->getJson('/api/owner/bookings')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $booking->id,
            ]);
    }

    public function test_vehicle_owner_can_confirm_booking(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createVehicleOwner();

        $vehicle = $this->createApprovedVehicle($owner);

        $booking = $this->createBooking($customer, $vehicle);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/owner/bookings/{$booking->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', Booking::STATUS_CONFIRMED);
    }

    public function test_vehicle_owner_can_reject_booking(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createVehicleOwner();

        $vehicle = $this->createApprovedVehicle($owner);

        $booking = $this->createBooking($customer, $vehicle);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/owner/bookings/{$booking->id}/reject", [
                'rejection_reason' => 'Vehicle is unavailable.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Booking::STATUS_REJECTED)
            ->assertJsonPath('data.rejection_reason', 'Vehicle is unavailable.');
    }

    public function test_vehicle_owner_can_complete_confirmed_booking(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createVehicleOwner();

        $vehicle = $this->createApprovedVehicle($owner);

        $booking = $this->createBooking($customer, $vehicle, [
            'status' => Booking::STATUS_CONFIRMED,
        ]);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/owner/bookings/{$booking->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', Booking::STATUS_COMPLETED);
    }

    public function test_vehicle_owner_cannot_manage_another_owners_booking(): void
    {
        $customer = $this->createCustomer();

        $vehicle = $this->createApprovedVehicle(
            $this->createVehicleOwner()
        );

        $booking = $this->createBooking($customer, $vehicle);

        $anotherOwner = $this->createVehicleOwner();

        $this->actingAs($anotherOwner, 'web')
            ->patchJson("/api/owner/bookings/{$booking->id}/confirm")
            ->assertForbidden();
    }

    public function test_guest_cannot_access_booking_endpoints(): void
    {
        $this->getJson('/api/bookings')->assertUnauthorized();

        $this->postJson('/api/bookings')->assertUnauthorized();

        $this->getJson('/api/owner/bookings')->assertUnauthorized();
    }


    public function test_confirmed_booking_blocks_overlapping_dates(): void
    {
        $customer = $this->createCustomer();
        $anotherCustomer = $this->createCustomer();

        $vehicle = $this->createApprovedVehicle(
            $this->createVehicleOwner()
        );

        $this->createBooking($anotherCustomer, $vehicle, [
            'status' => Booking::STATUS_CONFIRMED,
        ]);

        $this->actingAs($customer, 'web')
            ->postJson('/api/bookings', $this->bookingData($vehicle))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date']);
    }

    public function test_rejected_booking_releases_reserved_dates(): void
    {
        $customer = $this->createCustomer();
        $anotherCustomer = $this->createCustomer();

        $vehicle = $this->createApprovedVehicle(
            $this->createVehicleOwner()
        );

        $this->createBooking($anotherCustomer, $vehicle, [
            'status' => Booking::STATUS_REJECTED,
        ]);

        $this->actingAs($customer, 'web')
            ->postJson('/api/bookings', $this->bookingData($vehicle))
            ->assertCreated();
    }

    public function test_customer_cannot_cancel_another_customers_booking(): void
    {
        $customer = $this->createCustomer();
        $anotherCustomer = $this->createCustomer();

        $vehicle = $this->createApprovedVehicle(
            $this->createVehicleOwner()
        );

        $booking = $this->createBooking($anotherCustomer, $vehicle);

        $this->actingAs($customer, 'web')
            ->patchJson("/api/bookings/{$booking->id}/cancel")
            ->assertForbidden();

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => Booking::STATUS_PENDING,
        ]);
    }

    public function test_owner_cannot_confirm_rejected_booking(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createVehicleOwner();

        $vehicle = $this->createApprovedVehicle($owner);

        $booking = $this->createBooking($customer, $vehicle, [
            'status' => Booking::STATUS_REJECTED,
        ]);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/owner/bookings/{$booking->id}/confirm")
            ->assertUnprocessable();
    }

    public function test_customer_cannot_access_owner_booking_endpoints(): void
    {
        $customer = $this->createCustomer();

        $this->actingAs($customer, 'web')
            ->getJson('/api/owner/bookings')
            ->assertForbidden();
    }

    public function test_vehicle_without_valid_daily_rate_cannot_be_booked(): void
    {
        $customer = $this->createCustomer();

        $vehicle = $this->createApprovedVehicle(
            $this->createVehicleOwner(),
            ['daily_rate' => 0]
        );

        $this->actingAs($customer, 'web')
            ->postJson('/api/bookings', $this->bookingData($vehicle))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vehicle_id']);
    }

}
