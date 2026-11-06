<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function frontendRequest()
    {
        return $this->withHeader(
            'Origin',
            'http://localhost:3000'
        );
    }

    public function test_customer_can_register(): void
    {
        $response = $this->frontendRequest()->postJson('/api/register', [
            'name' => 'Test Customer',
            'email' => 'customer@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Account created successfully.')
            ->assertJsonPath('data.email', 'customer@example.com');

        $this->assertDatabaseHas('users', [
            'email' => 'customer@example.com',
        ]);

        $this->assertAuthenticated('web');
    }

    public function test_customer_cannot_register_with_existing_email(): void
    {
        User::factory()->create([
            'email' => 'customer@example.com',
        ]);

        $response = $this->frontendRequest()->postJson('/api/register', [
            'name' => 'Another Customer',
            'email' => 'customer@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_customer_can_login(): void
    {
        User::factory()->create([
            'email' => 'customer@example.com',
            'password' => 'Password123!',
        ]);

        $response = $this->frontendRequest()->postJson('/api/login', [
            'email' => 'customer@example.com',
            'password' => 'Password123!',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Login successful.');

        $this->assertAuthenticated('web');
    }

    public function test_customer_cannot_login_with_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'customer@example.com',
            'password' => 'Password123!',
        ]);

        $response = $this->frontendRequest()->postJson('/api/login', [
            'email' => 'customer@example.com',
            'password' => 'WrongPassword123!',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertGuest('web');
    }

    public function test_authenticated_customer_can_view_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web');

        $response = $this->frontendRequest()->getJson('/api/user');

        $response->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('email', $user->email);
    }

    public function test_authenticated_customer_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web');

        $response = $this->frontendRequest()->postJson('/api/logout');

        $response->assertOk()
            ->assertJsonPath('message', 'Logout successful.');

        $this->assertGuest('web');
    }

    public function test_guest_cannot_access_user_profile(): void
    {
        $response = $this->frontendRequest()->getJson('/api/user');

        $response->assertUnauthorized();
    }
}