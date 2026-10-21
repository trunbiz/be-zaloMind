<?php

namespace App\Http\Controllers;

use App\Services\Navigation;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function show(Request $request, Navigation $navigation, string $section): View
    {
        $area = $request->routeIs('admin.*') ? 'admin' : 'user';
        $page = $navigation->page($area, $section, $request->user());
        abort_if(isset($page['route']), 404);

        return view('workspace.section', ['page' => $page, 'isAdmin' => $area === 'admin']);
    }
}
