<?php

namespace App\Http\Controllers;

use App\Models\InterestingJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InterestingJobController extends Controller
{
    private const STATUSES = [
        'new',
        'reviewing',
        'applied',
        'archived',
        'rejected',
    ];

    public function index(Request $request): View
    {
        $query = InterestingJob::query()->orderByDesc('ai_score')->orderByDesc('updated_at');

        if ($request->filled('decision')) {
            $query->where('ai_decision', $request->string('decision'));
        }

        if ($request->filled('status')) {
            $query->where('shortlist_status', $request->string('status'));
        }

        if ($request->boolean('remote_only')) {
            $query->where('remote_type', 'remote');
        }

        if ($request->filled('q')) {
            $search = trim((string) $request->string('q'));
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('company', 'like', "%{$search}%")
                    ->orWhere('location_raw', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        return view('interesting-jobs.index', [
            'jobs' => $query->paginate(25)->withQueryString(),
            'filters' => $request->only(['decision', 'status', 'q', 'remote_only']),
            'statusOptions' => self::STATUSES,
            'decisionOptions' => ['high', 'maybe', 'reject'],
        ]);
    }

    public function edit(InterestingJob $interestingJob): View
    {
        return view('interesting-jobs.edit', [
            'job' => $interestingJob,
            'statusOptions' => self::STATUSES,
        ]);
    }

    public function update(Request $request, InterestingJob $interestingJob): RedirectResponse
    {
        $validated = $request->validate([
            'shortlist_status' => ['required', 'in:'.implode(',', self::STATUSES)],
            'notes' => ['nullable', 'string'],
        ]);

        $interestingJob->fill([
            'shortlist_status' => $validated['shortlist_status'],
            'notes' => $validated['notes'] ?? null,
            'updated_at' => now(),
        ]);
        $interestingJob->save();

        return redirect()
            ->route('interesting-jobs.index')
            ->with('status', 'Job updated.');
    }
}
