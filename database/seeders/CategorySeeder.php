<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['slug' => 'sports',        'name' => 'Sports',        'sort_order' => 10],
            ['slug' => 'politics',      'name' => 'Politics',      'sort_order' => 20],
            ['slug' => 'finance',       'name' => 'Finance',       'sort_order' => 30],
            ['slug' => 'crypto',        'name' => 'Crypto',        'sort_order' => 40],
            ['slug' => 'entertainment', 'name' => 'Entertainment', 'sort_order' => 50],
            ['slug' => 'technology',    'name' => 'Technology',    'sort_order' => 60],
            ['slug' => 'science',       'name' => 'Science',       'sort_order' => 70],
        ];

        foreach ($categories as $category) {
            DB::table('categories')->updateOrInsert(
                ['slug' => $category['slug']],
                [
                    'name' => $category['name'],
                    'is_active' => true,
                    'sort_order' => $category['sort_order'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
