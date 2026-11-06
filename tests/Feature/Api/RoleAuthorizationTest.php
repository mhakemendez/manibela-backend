<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithRole(string $role): User
    {
        $user = User::factory()->create();

        // Assign the role through a trusted test setup.
        $user->forceFill(['role' => $role])->save();

        return $user->refresh();
    }

    public function test_customer_can_access_customer_route(): void
    {
        $user = $this->createUserWithRole(User::ROLE_CUSTOMER);

        $response = $this->actingAs($user, 'web')
            ->getJson('/api/rbac/customer');

        $response->assertOk()
            ->assertJsonPath('role', 'customer');
    }

    public function test_customer_cannot_access_owner_route(): void
    {
        $user = $this->createUserWithRole(User::ROLE_CUSTOMER);

        $this->actingAs($user, 'web')
            ->getJson('/api/rbac/vehicle-owner')
            ->assertForbidden();
    }

    public function test_customer_cannot_access_admin_route(): void
    {
        $user = $this->createUserWithRole(User::ROLE_CUSTOMER);

        $this->actingAs($user, 'web')
            ->getJson('/api/rbac/admin')
            ->assertForbidden();
    }

    public function test_vehicle_owner_can_access_owner_route(): void
    {
        $user = $this->createUserWithRole(User::ROLE_VEHICLE_OWNER);

        $response = $this->actingAs($user, 'web')
            ->getJson('/api/rbac/vehicle-owner');

        $response->assertOk()
            ->assertJsonPath('role', 'vehicle_owner');
    }

    public function test_vehicle_owner_cannot_access_admin_route(): void
    {
        $user = $this->createUserWithRole(User::ROLE_VEHICLE_OWNER);

        $this->actingAs($user, 'web')
            ->getJson('/api/rbac/admin')
            ->assertForbidden();
    }

    public function test_admin_can_access_admin_route(): void
    {
        $user = $this->createUserWithRole(User::ROLE_ADMIN);

        $response = $this->actingAs($user, 'web')
            ->getJson('/api/rbac/admin');

        $response->assertOk()
            ->assertJsonPath('role', 'admin');
    }

    public function test_guest_cannot_access_role_protected_routes(): void
    {
        $routes = [
            '/api/rbac/customer',
            '/api/rbac/vehicle-owner',
            '/api/rbac/admin',
        ];

        foreach ($routes as $route) {
            $this->getJson($route)->assertUnauthorized();
        }
    }

    public function test_public_registration_cannot_assign_privileged_role(): void
    {
        $response = $this->withHeader(
            'Origin',
            'http://localhost:3000'
        )->postJson('/api/register', [
                    'name' => 'Test Customer',
                    'email' => 'role-test@example.com',
                    'password' => 'Password123!',
                    'password_confirmation' => 'Password123!',
                    'role' => 'admin',
                ]);

        $response->assertCreated()
            ->assertJsonPath('data.role', 'customer');

        $this->assertDatabaseHas('users', [
            'email' => 'role-test@example.com',
            'role' => 'customer',
        ]);
    }
}