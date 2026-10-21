<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        return view('dashboard');
    }

    public function admin(): View
    {
        return view('admin.dashboard', [
            'userCount' => User::count(),
            'adminCount' => User::whereHas('roles', fn ($query) => $query->where('name', 'admin'))->count(),
            'customerCount' => User::whereHas('roles', fn ($query) => $query->where('name', 'user'))->count(),
        ]);
    }
}
