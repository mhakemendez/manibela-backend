<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_own_profile(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')
            ->getJson('/api/profile');

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', $user->name)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.role', User::ROLE_CUSTOMER);
    }

    public function test_guest_cannot_view_profile(): void
    {
        $this->getJson('/api/profile')
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_update_own_profile(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')
            ->putJson('/api/profile', [
                'name' => 'Updated Customer',
                'email' => 'updated@example.com',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Customer')
            ->assertJsonPath('data.email', 'updated@example.com')
            ->assertJsonPath('data.role', User::ROLE_CUSTOMER);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Customer',
            'email' => 'updated@example.com',
        ]);
    }

    public function test_user_cannot_update_profile_with_existing_email(): void
    {
        $user = User::factory()->create();
        $anotherUser = User::factory()->create();

        $this->actingAs($user, 'web')
            ->putJson('/api/profile', [
                'name' => 'Updated Name',
                'email' => $anotherUser->email,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_user_cannot_change_own_role_through_profile_update(): void
    {
        $user = User::factory()->create();

        $user->forceFill([
            'role' => User::ROLE_VEHICLE_OWNER,
        ])->save();

        $this->actingAs($user, 'web')
            ->putJson('/api/profile', [
                'name' => 'Updated Owner',
                'email' => $user->email,
                'role' => User::ROLE_ADMIN,
            ])
            ->assertOk()
            ->assertJsonPath('data.role', User::ROLE_VEHICLE_OWNER);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => User::ROLE_VEHICLE_OWNER,
        ]);
    }

    public function test_guest_cannot_update_profile(): void
    {
        $this->putJson('/api/profile', [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ])->assertUnauthorized();
    }

    public function test_authenticated_user_can_change_password(): void
    {
        $user = User::factory()->create([
            'password' => 'OldPassword123!',
        ]);

        $response = $this->actingAs($user, 'web')
            ->putJson('/api/profile/password', [
                'current_password' => 'OldPassword123!',
                'password' => 'NewPassword123!',
                'password_confirmation' => 'NewPassword123!',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Password updated successfully.');

        $this->assertTrue(
            \Illuminate\Support\Facades\Hash::check(
                'NewPassword123!',
                $user->fresh()->password
            )
        );
    }

    public function test_user_cannot_change_password_with_incorrect_current_password(): void
    {
        $user = User::factory()->create([
            'password' => 'OldPassword123!',
        ]);

        $this->actingAs($user, 'web')
            ->putJson('/api/profile/password', [
                'current_password' => 'WrongPassword123!',
                'password' => 'NewPassword123!',
                'password_confirmation' => 'NewPassword123!',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);
    }

    public function test_user_must_confirm_new_password(): void
    {
        $user = User::factory()->create([
            'password' => 'OldPassword123!',
        ]);

        $this->actingAs($user, 'web')
            ->putJson('/api/profile/password', [
                'current_password' => 'OldPassword123!',
                'password' => 'NewPassword123!',
                'password_confirmation' => 'DifferentPassword123!',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_guest_cannot_change_password(): void
    {
        $this->putJson('/api/profile/password', [
            'current_password' => 'OldPassword123!',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertUnauthorized();
    }
}