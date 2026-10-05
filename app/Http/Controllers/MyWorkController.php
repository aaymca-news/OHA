<?php

namespace App\Http\Controllers;

use App\Queries\MyWork;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MyWorkController extends Controller
{
    public function __invoke(Request $request, MyWork $myWork): View
    {
        $groups = $myWork->for($request->user());
        $keys = array_column($groups, 'key');

        // Open the first group with something to act on, unless one was chosen.
        $active = in_array($request->query('group'), $keys, true)
            ? $request->query('group')
            : (collect($groups)->first(fn ($g) => collect($g['items'])->contains('actionable', true))['key'] ?? ($keys[0] ?? null));

        return view('my-work', ['groups' => $groups, 'active' => $active]);
    }
}
