<?php

namespace Tests\Feature;

use App\Models\Client;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientHistoricalNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_clients_get_sequential_automatic_historical_numbers(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = \App\Models\User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');

        $first = Client::createWithAutoHistoricalNumber(['first_name' => 'Juan', 'last_name' => 'Perez']);
        $second = Client::createWithAutoHistoricalNumber(['first_name' => 'Maria', 'last_name' => 'Gomez']);

        $this->assertSame(1, $first->historical_number);
        $this->assertSame(2, $second->historical_number);
    }

    public function test_historical_number_continues_after_existing_clients(): void
    {
        Client::create(['first_name' => 'Viejo', 'last_name' => 'Cliente', 'historical_number' => 184]);

        $new = Client::createWithAutoHistoricalNumber(['first_name' => 'Nuevo', 'last_name' => 'Cliente']);

        $this->assertSame(185, $new->historical_number);
    }

    public function test_historical_number_is_unique(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        Client::create(['first_name' => 'A', 'historical_number' => 5]);
        Client::create(['first_name' => 'B', 'historical_number' => 5]);
    }

    public function test_store_endpoint_assigns_historical_number_automatically(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = \App\Models\User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');

        $response = $this->actingAs($user)->post('/clients', [
            'first_name' => 'Ana',
            'last_name' => 'Diaz',
        ]);

        $response->assertSessionHasNoErrors();
        $client = Client::where('first_name', 'Ana')->first();
        $this->assertNotNull($client);
        $this->assertNotNull($client->historical_number);
    }
}
