<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 7, seccion 4 y 15: la busqueda debe encontrar razonablemente por
 * nombre, apellido, nombre+apellido (en cualquier orden), telefono y
 * numero historico.
 */
class ClientSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Client::create(['first_name' => 'Jose', 'last_name' => 'Perez', 'phone' => '11 1234 5678', 'historical_number' => 42]);
        Client::create(['first_name' => 'Ana', 'last_name' => 'Gomez', 'phone' => '11 8765 4321', 'historical_number' => 43]);
    }

    protected function search(string $term): \Illuminate\Support\Collection
    {
        return Client::query()->searchTerm($term)->get()->pluck('first_name');
    }

    public function test_search_by_first_name(): void
    {
        $this->assertTrue($this->search('Jose')->contains('Jose'));
    }

    public function test_search_by_last_name(): void
    {
        $this->assertTrue($this->search('Perez')->contains('Jose'));
    }

    public function test_search_by_full_name_first_then_last(): void
    {
        $this->assertTrue($this->search('Jose Perez')->contains('Jose'));
        $this->assertFalse($this->search('Jose Perez')->contains('Ana'));
    }

    public function test_search_by_full_name_last_then_first(): void
    {
        $this->assertTrue($this->search('Perez Jose')->contains('Jose'));
    }

    public function test_search_by_partial_tokens(): void
    {
        $this->assertTrue($this->search('Jos Per')->contains('Jose'));
    }

    public function test_search_by_phone(): void
    {
        $this->assertTrue($this->search('1234')->contains('Jose'));
    }

    public function test_search_by_historical_number(): void
    {
        $this->assertTrue($this->search('42')->contains('Jose'));
    }

    public function test_empty_search_returns_everyone(): void
    {
        $this->assertCount(2, $this->search(''));
    }
}
