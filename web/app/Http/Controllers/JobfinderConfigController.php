<?php

namespace App\Http\Controllers;

use App\Services\JobfinderConfigManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class JobfinderConfigController extends Controller
{
    public function index(Request $request, JobfinderConfigManager $configManager): View
    {
        return view('config.index', [
            'files' => $configManager->loadAll(),
            'statusMessage' => $request->session()->get('status'),
        ]);
    }

    public function update(Request $request, JobfinderConfigManager $configManager): RedirectResponse
    {
        $validated = $request->validate([
            'file_key' => ['required', 'string'],
            'contents' => ['required', 'string'],
        ]);

        $configManager->save($validated['file_key'], $validated['contents']);

        return redirect()
            ->route('jobfinder-config.index')
            ->with('status', 'Configuration updated.');
    }
}
