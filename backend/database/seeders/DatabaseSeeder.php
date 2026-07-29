<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $password = env('ADMIN_PASSWORD');

        if (! $password) {
            $this->command?->warn('ADMIN_PASSWORD no está definido; no se creó el administrador.');

            return;
        }

        User::query()->updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@sistemaisp.local')],
            [
                'name' => env('ADMIN_NAME', 'Administrador'),
                'password' => $password,
            ],
        );
    }
}
