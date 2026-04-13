<?php

namespace App\Http\Controllers;

use App\Models\InterestingJob;
use App\Services\FalseNegativeReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FalseNegativeController extends Controller
{
    public function index(Request $request, FalseNegativeReviewService $reviewService): View
    {
        $reviewService->ensureColumns();

        $scope = $request->filled('scope') ? (string) $request->string('scope') : 'rejected';

        $query = InterestingJob::query()
            ->orderByDesc('false_negative')
            ->orderByDesc('updated_at');

        if ($scope === 'flagged') {
            $query->where('false_negative', true);
        } elseif ($scope === 'all') {
            $query->where(function ($builder): void {
                $builder
                    ->where('interesting_jobs.shortlist_status', 'rejected')
                    ->orWhere('interesting_jobs.ai_decision', 'reject')
                    ->orWhere('interesting_jobs.false_negative', true);
            });
        } else {
            $query->where(function ($builder): void {
                $builder
                    ->where('interesting_jobs.shortlist_status', 'rejected')
                    ->orWhere('interesting_jobs.false_negative', true);
            });
        }

        if ($request->filled('q')) {
            $search = trim((string) $request->string('q'));
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('interesting_jobs.title', 'like', "%{$search}%")
                    ->orWhere('interesting_jobs.company', 'like', "%{$search}%")
                    ->orWhere('interesting_jobs.notes', 'like', "%{$search}%")
                    ->orWhere('interesting_jobs.false_negative_reason', 'like', "%{$search}%")
                    ->orWhere('interesting_jobs.ai_reason', 'like', "%{$search}%")
                    ->orWhere('interesting_jobs.description_snapshot', 'like', "%{$search}%");
            });
        }

        return view('false-negatives.index', [
            'jobs' => $query->paginate(20)->withQueryString(),
            'filters' => [
                'scope' => $scope,
                'q' => (string) $request->string('q'),
            ],
            'suggestions' => $reviewService->buildSuggestions(),
        ]);
    }

    public function update(
        Request $request,
        InterestingJob $interestingJob,
        FalseNegativeReviewService $reviewService
    ): RedirectResponse {
        $reviewService->ensureColumns();

        $validated = $request->validate([
            'false_negative' => ['required', 'boolean'],
            'false_negative_reason' => ['nullable', 'string'],
        ]);

        $shouldFlag = (bool) $validated['false_negative'];

        $interestingJob->fill([
            'false_negative' => $shouldFlag,
            'false_negative_reason' => $shouldFlag ? ($validated['false_negative_reason'] ?: null) : null,
            'false_negative_marked_at' => $shouldFlag ? now() : null,
            'updated_at' => now(),
        ]);
        $interestingJob->save();

        return redirect()
            ->back()
            ->with('status', $shouldFlag ? 'Job marked as a false negative.' : 'False-negative flag cleared.');
    }
}
