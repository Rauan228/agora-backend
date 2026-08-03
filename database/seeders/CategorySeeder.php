<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('agora.categories', []) as $cat) {
            Category::updateOrCreate(
                ['slug' => $cat['slug']],
                [
                    'name' => $cat['name'],
                    'priority' => $cat['priority'] ?? 'medium',
                    'sort_order' => $cat['sort_order'] ?? 0,
                    'is_active' => true,
                ]
            );
        }
    }
}
