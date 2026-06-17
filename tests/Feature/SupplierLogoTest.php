<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupplierLogoTest extends TestCase
{
    use RefreshDatabase;

    /** Загрузка логотипа сохраняет файл и проставляет logo_path. */
    public function test_logo_upload_stores_file_and_sets_path(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create();

        $response = $this->actingAs($admin)->post('/admin/suppliers', [
            'commercial_name' => 'С логотипом',
            'inn' => '7736010018',
            'is_active' => '1',
            'logo' => UploadedFile::fake()->image('logo.png', 100, 100),
        ]);

        $response->assertRedirect(route('admin.suppliers.index'));

        $supplier = Supplier::where('commercial_name', 'С логотипом')->first();
        $this->assertNotNull($supplier, 'Поставщик должен создаться');
        $this->assertNotNull($supplier->logo_path, 'logo_path должен быть заполнен');

        // Файл реально лежит на диске public
        Storage::disk('public')->assertExists($supplier->logo_path);

        // logo_url отдаёт корректную ссылку на /storage/...
        $this->assertStringContainsString('/storage/'.$supplier->logo_path, $supplier->logo_url);
    }
}
