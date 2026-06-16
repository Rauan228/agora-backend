<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /** Корень редиректит на страницу входа в админку. */
    public function test_root_redirects_to_admin_login(): void
    {
        $this->get('/')->assertRedirect(route('admin.login'));
    }

    /** Гость не может попасть в админку. */
    public function test_guest_cannot_access_admin(): void
    {
        $this->get('/admin/suppliers')->assertRedirect(route('admin.login'));
    }

    /** Публичное API отдаёт список поставщиков. */
    public function test_api_returns_suppliers(): void
    {
        $this->seed(\Database\Seeders\SupplierSeeder::class);

        $this->getJson('/api/suppliers')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'commercial_name', 'inn', 'contact', 'shipping_cities']],
            ]);
    }
}
