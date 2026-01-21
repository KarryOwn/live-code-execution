<?php

namespace Database\Seeders;

use App\Models\Problem;
use Illuminate\Database\Seeder;

class ProblemSeeder extends Seeder
{
    public function run(): void
    {
        // Check if it exists first to avoid duplicates if you run seeder twice
        if (Problem::where('title', 'Test Problem')->exists()) {
            return;
        }

        Problem::create([
            // FIXED UUID: Easy to copy-paste for testing
            'id' => '11111111-1111-1111-1111-111111111111', 
            'title' => 'Test Problem',
            'description' => 'Test Description',
            'code_template' => ['python' => 'print("Start")'],
            'time_limit' => 2.0,
        ]);
        
        \App\Models\User::factory()->create([
             'id' => 1,
             'name' => 'Test User',
             'email' => 'test@example.com',
        ]);
    }
}