<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            ['name' => 'Fashion', 'icon' => 'shirt-outline'],
            ['name' => 'Gadget', 'icon' => 'phone-portrait-outline'],
            ['name' => 'Beauty', 'icon' => 'sparkles-outline'],
            ['name' => 'Lifestyle', 'icon' => 'fitness-outline'],
            ['name' => 'Home', 'icon' => 'home-outline'],
            ['name' => 'Automotive', 'icon' => 'car-outline'],
            ['name' => 'Food', 'icon' => 'fast-food-outline'],
        ];

        foreach ($categories as $category) {
            \App\Models\Category::updateOrCreate(
                ['name' => $category['name']],
                ['icon' => $category['icon']]
            );
        }
    }
}
