<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class VehiclePhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_vehicle_can_have_multiple_photos(): void
    {
        $owner = User::factory()->create([
            'role' => User::ROLE_VEHICLE_OWNER,
        ]);

        $vehicle = $owner->vehicles()->create([
            'type' => Vehicle::TYPE_CAR,
            'brand' => 'Toyota',
            'model' => 'Vios',
            'year' => 2022,
            'plate_number' => 'ABC-1234',
            'transmission' => 'automatic',
            'fuel_type' => 'gasoline',
            'seats' => 5,
        ]);

        $vehicle->status = Vehicle::STATUS_PENDING;
        $vehicle->save();

        $photoOne = VehiclePhoto::create([
            'vehicle_id' => $vehicle->id,
            'image_path' => 'vehicles/photo-one.jpg',
            'is_primary' => true,
        ]);

        $photoTwo = VehiclePhoto::create([
            'vehicle_id' => $vehicle->id,
            'image_path' => 'vehicles/photo-two.jpg',
            'is_primary' => false,
        ]);

        $this->assertCount(2, $vehicle->photos);

        $this->assertEquals($vehicle->id, $photoOne->vehicle->id);
        $this->assertEquals($vehicle->id, $photoTwo->vehicle->id);
    }

    public function test_vehicle_owner_can_upload_multiple_photos(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create([
            'role' => User::ROLE_VEHICLE_OWNER,
        ]);

        $vehicle = $owner->vehicles()->create([
            'type' => Vehicle::TYPE_CAR,
            'brand' => 'Toyota',
            'model' => 'Vios',
            'year' => 2022,
            'plate_number' => 'UPLOAD-001',
            'transmission' => 'automatic',
            'fuel_type' => 'gasoline',
            'seats' => 5,
        ]);

        $response = $this->actingAs($owner)->postJson(
            "/api/vehicles/{$vehicle->id}/photos",
            [
                'photos' => [
                    UploadedFile::fake()->image('front.jpg'),
                    UploadedFile::fake()->image('side.jpg'),
                ],
            ]
        );

        $response->assertCreated()
            ->assertJsonCount(2, 'photos');

        $this->assertDatabaseCount('vehicle_photos', 2);

        $this->assertDatabaseHas('vehicle_photos', [
            'vehicle_id' => $vehicle->id,
            'is_primary' => true,
        ]);

        $this->assertDatabaseHas('vehicle_photos', [
            'vehicle_id' => $vehicle->id,
            'is_primary' => false,
        ]);
    }

    public function test_vehicle_owner_cannot_upload_photos_to_another_owners_vehicle(): void
    {
        $owner = User::factory()->create([
            'role' => User::ROLE_VEHICLE_OWNER,
        ]);

        $anotherOwner = User::factory()->create([
            'role' => User::ROLE_VEHICLE_OWNER,
        ]);

        $vehicle = $anotherOwner->vehicles()->create([
            'type' => Vehicle::TYPE_CAR,
            'brand' => 'Toyota',
            'model' => 'Vios',
            'year' => 2022,
            'plate_number' => 'UPLOAD-002',
            'transmission' => 'automatic',
            'fuel_type' => 'gasoline',
            'seats' => 5,
        ]);

        $response = $this->actingAs($owner)->postJson(
            "/api/vehicles/{$vehicle->id}/photos",
            [
                'photos' => [
                    UploadedFile::fake()->image('front.jpg'),
                ],
            ]
        );

        $response->assertForbidden();

        $this->assertDatabaseCount('vehicle_photos', 0);
    }

    public function test_vehicle_owner_cannot_upload_more_than_ten_photos(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create([
            'role' => User::ROLE_VEHICLE_OWNER,
        ]);

        $vehicle = $owner->vehicles()->create([
            'type' => Vehicle::TYPE_CAR,
            'brand' => 'Toyota',
            'model' => 'Vios',
            'year' => 2022,
            'plate_number' => 'UPLOAD-003',
            'transmission' => 'automatic',
            'fuel_type' => 'gasoline',
            'seats' => 5,
        ]);

        $photos = [];

        for ($i = 0; $i < 11; $i++) {
            $photos[] = UploadedFile::fake()->image("photo-{$i}.jpg");
        }

        $response = $this->actingAs($owner)->postJson(
            "/api/vehicles/{$vehicle->id}/photos",
            ['photos' => $photos]
        );

        $response->assertUnprocessable();

        $this->assertDatabaseCount('vehicle_photos', 0);
    }

    public function test_vehicle_owner_can_view_their_vehicle_photos(): void
    {
        $owner = User::factory()->create([
            'role' => User::ROLE_VEHICLE_OWNER,
        ]);

        $vehicle = $owner->vehicles()->create([
            'type' => Vehicle::TYPE_CAR,
            'brand' => 'Toyota',
            'model' => 'Vios',
            'year' => 2022,
            'plate_number' => 'LIST-001',
            'transmission' => 'automatic',
            'fuel_type' => 'gasoline',
            'seats' => 5,
        ]);

        VehiclePhoto::create([
            'vehicle_id' => $vehicle->id,
            'image_path' => 'vehicles/1/front.jpg',
            'is_primary' => true,
        ]);

        VehiclePhoto::create([
            'vehicle_id' => $vehicle->id,
            'image_path' => 'vehicles/1/side.jpg',
            'is_primary' => false,
        ]);

        $response = $this->actingAs($owner)->getJson(
            "/api/vehicles/{$vehicle->id}/photos"
        );

        $response->assertOk()
            ->assertJsonCount(2, 'photos');
    }

    public function test_vehicle_owner_cannot_view_another_owners_vehicle_photos(): void
    {
        $owner = User::factory()->create([
            'role' => User::ROLE_VEHICLE_OWNER,
        ]);

        $anotherOwner = User::factory()->create([
            'role' => User::ROLE_VEHICLE_OWNER,
        ]);

        $vehicle = $anotherOwner->vehicles()->create([
            'type' => Vehicle::TYPE_CAR,
            'brand' => 'Toyota',
            'model' => 'Vios',
            'year' => 2022,
            'plate_number' => 'LIST-002',
            'transmission' => 'automatic',
            'fuel_type' => 'gasoline',
            'seats' => 5,
        ]);

        $response = $this->actingAs($owner)->getJson(
            "/api/vehicles/{$vehicle->id}/photos"
        );

        $response->assertForbidden();
    }

    public function test_vehicle_owner_can_set_a_primary_photo(): void
    {
        $owner = User::factory()->create([
            'role' => User::ROLE_VEHICLE_OWNER,
        ]);

        $vehicle = $owner->vehicles()->create([
            'type' => Vehicle::TYPE_CAR,
            'brand' => 'Toyota',
            'model' => 'Vios',
            'year' => 2022,
            'plate_number' => 'PRIMARY-001',
            'transmission' => 'automatic',
            'fuel_type' => 'gasoline',
            'seats' => 5,
        ]);

        $first = VehiclePhoto::create([
            'vehicle_id' => $vehicle->id,
            'image_path' => 'vehicles/1/first.jpg',
            'is_primary' => true,
        ]);

        $second = VehiclePhoto::create([
            'vehicle_id' => $vehicle->id,
            'image_path' => 'vehicles/1/second.jpg',
            'is_primary' => false,
        ]);

        $response = $this->actingAs($owner)->patchJson(
            "/api/vehicles/{$vehicle->id}/photos/{$second->id}/primary"
        );

        $response->assertOk();

        $this->assertDatabaseHas('vehicle_photos', [
            'id' => $first->id,
            'is_primary' => false,
        ]);

        $this->assertDatabaseHas('vehicle_photos', [
            'id' => $second->id,
            'is_primary' => true,
        ]);
    }

    public function test_vehicle_owner_cannot_set_another_owners_photo_as_primary(): void
    {
        $owner = User::factory()->create([
            'role' => User::ROLE_VEHICLE_OWNER,
        ]);

        $anotherOwner = User::factory()->create([
            'role' => User::ROLE_VEHICLE_OWNER,
        ]);

        $vehicle = $anotherOwner->vehicles()->create([
            'type' => Vehicle::TYPE_CAR,
            'brand' => 'Toyota',
            'model' => 'Vios',
            'year' => 2022,
            'plate_number' => 'PRIMARY-002',
            'transmission' => 'automatic',
            'fuel_type' => 'gasoline',
            'seats' => 5,
        ]);

        $photo = VehiclePhoto::create([
            'vehicle_id' => $vehicle->id,
            'image_path' => 'vehicles/1/photo.jpg',
            'is_primary' => true,
        ]);

        $response = $this->actingAs($owner)->patchJson(
            "/api/vehicles/{$vehicle->id}/photos/{$photo->id}/primary"
        );

        $response->assertForbidden();
    }

    public function test_vehicle_owner_can_delete_a_photo(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create([
            'role' => User::ROLE_VEHICLE_OWNER,
        ]);

        $vehicle = $owner->vehicles()->create([
            'type' => Vehicle::TYPE_CAR,
            'brand' => 'Toyota',
            'model' => 'Vios',
            'year' => 2022,
            'plate_number' => 'DELETE-001',
            'transmission' => 'automatic',
            'fuel_type' => 'gasoline',
            'seats' => 5,
        ]);

        Storage::disk('public')->put(
            "vehicles/{$vehicle->id}/photo.jpg",
            'fake image'
        );

        $photo = VehiclePhoto::create([
            'vehicle_id' => $vehicle->id,
            'image_path' => "vehicles/{$vehicle->id}/photo.jpg",
            'is_primary' => true,
        ]);

        $response = $this->actingAs($owner)->deleteJson(
            "/api/vehicles/{$vehicle->id}/photos/{$photo->id}"
        );

        $response->assertOk();

        $this->assertDatabaseMissing('vehicle_photos', [
            'id' => $photo->id,
        ]);

        Storage::disk('public')->assertMissing($photo->image_path);
    }

    public function test_deleting_primary_photo_promotes_another_photo(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create([
            'role' => User::ROLE_VEHICLE_OWNER,
        ]);

        $vehicle = $owner->vehicles()->create([
            'type' => Vehicle::TYPE_CAR,
            'brand' => 'Toyota',
            'model' => 'Vios',
            'year' => 2022,
            'plate_number' => 'DELETE-002',
            'transmission' => 'automatic',
            'fuel_type' => 'gasoline',
            'seats' => 5,
        ]);

        $primary = VehiclePhoto::create([
            'vehicle_id' => $vehicle->id,
            'image_path' => "vehicles/{$vehicle->id}/primary.jpg",
            'is_primary' => true,
        ]);

        $secondary = VehiclePhoto::create([
            'vehicle_id' => $vehicle->id,
            'image_path' => "vehicles/{$vehicle->id}/secondary.jpg",
            'is_primary' => false,
        ]);

        Storage::disk('public')->put($primary->image_path, 'primary');
        Storage::disk('public')->put($secondary->image_path, 'secondary');

        $response = $this->actingAs($owner)->deleteJson(
            "/api/vehicles/{$vehicle->id}/photos/{$primary->id}"
        );

        $response->assertOk();

        $this->assertDatabaseHas('vehicle_photos', [
            'id' => $secondary->id,
            'is_primary' => true,
        ]);

        Storage::disk('public')->assertMissing($primary->image_path);
    }

    public function test_vehicle_owner_cannot_delete_another_owners_photo(): void
    {
        $owner = User::factory()->create([
            'role' => User::ROLE_VEHICLE_OWNER,
        ]);

        $anotherOwner = User::factory()->create([
            'role' => User::ROLE_VEHICLE_OWNER,
        ]);

        $vehicle = $anotherOwner->vehicles()->create([
            'type' => Vehicle::TYPE_CAR,
            'brand' => 'Toyota',
            'model' => 'Vios',
            'year' => 2022,
            'plate_number' => 'DELETE-003',
            'transmission' => 'automatic',
            'fuel_type' => 'gasoline',
            'seats' => 5,
        ]);

        $photo = VehiclePhoto::create([
            'vehicle_id' => $vehicle->id,
            'image_path' => 'vehicles/1/photo.jpg',
            'is_primary' => true,
        ]);

        $response = $this->actingAs($owner)->deleteJson(
            "/api/vehicles/{$vehicle->id}/photos/{$photo->id}"
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('vehicle_photos', [
            'id' => $photo->id,
        ]);
    }
}