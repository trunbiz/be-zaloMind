<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class RegisterUser
{
    public function handle(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);
            if (! empty($data['referral_code'])) {
                $referrer = User::where('referral_code', $data['referral_code'])->where('status', 'active')->first();
                if ($referrer && $referrer->id !== $user->id) {
                    $user->referred_by = $referrer->id;
                    $user->save();
                }
            }
            $user->assignRole(Role::findOrCreate('user', 'web'));

            return $user;
        });
    }
}
