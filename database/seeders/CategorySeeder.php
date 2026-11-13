<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['slug' => 'sports',                   'name' => 'Sports',                   'sort_order' => 10],
            ['slug' => 'politics',                 'name' => 'Politics',                 'sort_order' => 20],
            ['slug' => 'finance',                  'name' => 'Finance',                  'sort_order' => 30],
            ['slug' => 'crypto',                   'name' => 'Crypto',                   'sort_order' => 40],
            ['slug' => 'entertainment',            'name' => 'Entertainment',            'sort_order' => 50],
            ['slug' => 'technology',               'name' => 'Technology',               'sort_order' => 60],
            ['slug' => 'science',                  'name' => 'Science',                  'sort_order' => 70],
            ['slug' => 'economics',                'name' => 'Economics',                'sort_order' => 80],
            ['slug' => 'geopolitics',              'name' => 'Geopolitics',              'sort_order' => 90],
            ['slug' => 'elections',                'name' => 'Elections',                'sort_order' => 100],
            ['slug' => 'business',                 'name' => 'Business',                 'sort_order' => 110],
            ['slug' => 'legal-regulation',         'name' => 'Legal & Regulation',       'sort_order' => 120],
            ['slug' => 'energy',                   'name' => 'Energy',                   'sort_order' => 130],
            ['slug' => 'weather-climate',          'name' => 'Weather & Climate',        'sort_order' => 140],
            ['slug' => 'health-medicine',          'name' => 'Health & Medicine',        'sort_order' => 150],
            ['slug' => 'space',                    'name' => 'Space',                    'sort_order' => 160],
            ['slug' => 'gaming-esports',           'name' => 'Gaming & Esports',         'sort_order' => 170],
            ['slug' => 'environment',              'name' => 'Environment',             'sort_order' => 180],
            ['slug' => 'infrastructure-transport', 'name' => 'Infrastructure & Transport', 'sort_order' => 190],
            ['slug' => 'society-demographics',     'name' => 'Society & Demographics',   'sort_order' => 200],
        ];

        foreach ($categories as $category) {
            DB::table('categories')->updateOrInsert(
                ['slug' => $category['slug']],
                [
                    'name' => $category['name'],
                    'is_active' => true,
                    'sort_order' => $category['sort_order'],
                    'updated_at' => now(),
                ],
                [
                    'created_at' => now(),
                ]
            );
        }
    }
}
