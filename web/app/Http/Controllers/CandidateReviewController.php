<?php

namespace App\Http\Controllers;

use App\Services\FalseNegativeReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CandidateReviewController extends Controller
{
    public function index(Request $request, FalseNegativeReviewService $reviewService): View
    {
        $scope = $request->filled('scope') ? (string) $request->string('scope') : 'rejected';
        $search = trim((string) $request->string('q'));

        return view('candidate-review.index', [
            'jobs' => $reviewService->candidatePage($scope, $search),
            'filters' => [
                'scope' => $scope,
                'q' => $search,
            ],
            'suggestions' => $reviewService->buildSuggestions(),
        ]);
    }

    public function update(Request $request, FalseNegativeReviewService $reviewService): RedirectResponse
    {
        $validated = $request->validate([
            'source' => ['required', 'string'],
            'source_job_id' => ['required', 'string'],
            'false_negative' => ['required', 'boolean'],
            'false_negative_reason' => ['nullable', 'string'],
        ]);

        $reviewService->updateCandidateFeedback(
            $validated['source'],
            $validated['source_job_id'],
            (bool) $validated['false_negative'],
            $validated['false_negative_reason'] ?? null
        );

        return redirect()
            ->back()
            ->with('status', (bool) $validated['false_negative']
                ? 'Candidate marked as a false negative.'
                : 'Candidate false-negative flag cleared.');
    }
}
