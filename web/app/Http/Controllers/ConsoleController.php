<?php

namespace App\Http\Controllers;

use App\Services\JobfinderConsole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConsoleController extends Controller
{
    public function index(Request $request, JobfinderConsole $console): View
    {
        return view('console.index', [
            'actions' => $console->actions(),
            'result' => $request->session()->get('console_result'),
        ]);
    }

    public function run(Request $request, JobfinderConsole $console): RedirectResponse
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ignore_user_abort(true);

        $validated = $request->validate([
            'action' => ['required', 'string'],
            'pages' => ['nullable', 'integer', 'min:1', 'max:10'],
            'search_name' => ['nullable', 'string', 'max:120'],
            'only_unscored' => ['nullable', 'in:1'],
        ]);

        $result = $console->run(
            action: $validated['action'],
            options: [
                'pages' => (int) ($validated['pages'] ?? 1),
                'search_name' => $validated['search_name'] ?? null,
                'only_unscored' => ($validated['only_unscored'] ?? null) === '1',
            ],
        );

        return redirect()
            ->route('console.index')
            ->with('console_result', $result);
    }
}
