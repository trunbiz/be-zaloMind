<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class AdminLayout extends Component
{
    public function __construct(public string $title = 'Bảng điều khiển') {}

    public function render(): View
    {
        return view('layouts.admin');
    }
}
