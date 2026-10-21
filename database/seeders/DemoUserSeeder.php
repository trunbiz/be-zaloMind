<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || ! config('zalomind.demo_accounts')) {
            return;
        }

        foreach ([
            ['Quản trị viên', 'admin@zalomind.test', 'admin'],
            ['Nguyễn Minh Anh', 'user1@zalomind.test', 'user'],
            ['Trần Hoàng Nam', 'user2@zalomind.test', 'user'],
        ] as [$name, $email, $role]) {
            $user = User::firstOrCreate(['email' => $email], [
                'name' => $name,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]);
            $user->syncRoles([$role]);
        }
    }
}
