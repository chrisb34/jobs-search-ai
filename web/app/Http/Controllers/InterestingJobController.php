<?php

namespace App\Http\Controllers;

use App\Models\InterestingJob;
use App\Services\CoverLetterGenerator;
use App\Services\FalseNegativeReviewService;
use App\Services\ProbableDuplicateFinder;
use Illuminate\Support\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

class InterestingJobController extends Controller
{
    private const SOURCES = [
        'linkedin',
        'wttj',
    ];

    private const STATUSES = [
        'new',
        'reviewing',
        'applied',
        'archived',
        'rejected',
    ];

    private const QUICK_ACTIONS = [
        'not_relevant' => [
            'shortlist_status' => 'archived',
            'notes' => 'not relevant',
            'message' => 'Job marked as not relevant.',
        ],
        'already_applied' => [
            'shortlist_status' => 'applied',
            'notes' => 'duplicate',
            'message' => 'Job marked as already applied.',
        ],
        'reject' => [
            'shortlist_status' => 'rejected',
            'notes' => 'rejected',
            'message' => 'Job marked as rejected.',
        ],
    ];

    public function index(
        Request $request,
        ProbableDuplicateFinder $duplicateFinder,
        FalseNegativeReviewService $falseNegativeReviewService
    ): View
    {
        $falseNegativeReviewService->ensureColumns();

        $defaultStatus = $request->filled('status') ? (string) $request->string('status') : 'new';

        $query = InterestingJob::query()
            ->leftJoin('normalized_jobs as n', function ($join): void {
                $join
                    ->on('n.source', '=', 'interesting_jobs.source')
                    ->on('n.source_job_id', '=', 'interesting_jobs.source_job_id');
            })
            ->select(
                'interesting_jobs.*',
                'n.ai_llm_score',
                'n.ai_llm_reason',
                'n.ai_llm_decision',
                'n.ai_llm_model',
                'n.ai_llm_usage_json',
                'n.ai_llm_scored_at',
            )
            ->orderByDesc('interesting_jobs.ai_score')
            ->orderByDesc('interesting_jobs.updated_at');

        if ($request->filled('decision')) {
            $query->where('interesting_jobs.ai_decision', $request->string('decision'));
        }

        if ($defaultStatus !== '') {
            $query->where('interesting_jobs.shortlist_status', $defaultStatus);
        }

        if ($request->filled('source')) {
            $query->where('interesting_jobs.source', $request->string('source'));
        }

        if ($request->filled('min_score')) {
            $query->where('interesting_jobs.ai_score', '>=', (float) $request->input('min_score'));
        }

        if ($request->boolean('remote_only')) {
            $query->where('interesting_jobs.remote_type', 'remote');
        }

        if ($request->filled('q')) {
            $search = trim((string) $request->string('q'));
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('interesting_jobs.title', 'like', "%{$search}%")
                    ->orWhere('interesting_jobs.company', 'like', "%{$search}%")
                    ->orWhere('interesting_jobs.source_job_id', 'like', "%{$search}%")
                    ->orWhere('interesting_jobs.source', 'like', "%{$search}%")
                    ->orWhere('interesting_jobs.location_raw', 'like', "%{$search}%")
                    ->orWhere('interesting_jobs.notes', 'like', "%{$search}%")
                    ->orWhere('interesting_jobs.description_snapshot', 'like', "%{$search}%");
            });
        }

        $jobs = $query->paginate(25)->withQueryString();
        $duplicateFinder->attachToPage($jobs->getCollection());

        return view('interesting-jobs.index', [
            'jobs' => $jobs,
            'filters' => array_merge(
                $request->only(['decision', 'status', 'source', 'q', 'remote_only', 'min_score']),
                ['status' => $defaultStatus],
            ),
            'statusOptions' => self::STATUSES,
            'sourceOptions' => self::SOURCES,
            'decisionOptions' => ['high', 'maybe', 'reject'],
        ]);
    }

    public function edit(InterestingJob $interestingJob, ProbableDuplicateFinder $duplicateFinder): View
    {
        $llmFields = DB::table('normalized_jobs')
            ->select(
                'ai_llm_score',
                'ai_llm_reason',
                'ai_llm_decision',
                'ai_llm_model',
                'ai_llm_usage_json',
                'ai_llm_scored_at',
            )
            ->where('source', $interestingJob->source)
            ->where('source_job_id', $interestingJob->source_job_id)
            ->first();

        if ($llmFields) {
            $interestingJob->setAttribute('ai_llm_score', $llmFields->ai_llm_score);
            $interestingJob->setAttribute('ai_llm_reason', $llmFields->ai_llm_reason);
            $interestingJob->setAttribute('ai_llm_decision', $llmFields->ai_llm_decision);
            $interestingJob->setAttribute('ai_llm_model', $llmFields->ai_llm_model);
            $interestingJob->setAttribute(
                'ai_llm_usage_json',
                is_string($llmFields->ai_llm_usage_json) ? json_decode($llmFields->ai_llm_usage_json, true) : null
            );
            $interestingJob->setAttribute(
                'ai_llm_scored_at',
                $llmFields->ai_llm_scored_at ? Carbon::parse($llmFields->ai_llm_scored_at) : null
            );
        }

        return view('interesting-jobs.edit', [
            'job' => $interestingJob,
            'statusOptions' => self::STATUSES,
            'probableDuplicates' => $duplicateFinder->findForJob($interestingJob),
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

    public function quickAction(Request $request, InterestingJob $interestingJob): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string'],
        ]);

        $action = self::QUICK_ACTIONS[$validated['action']] ?? null;
        if ($action === null) {
            return redirect()
                ->back()
                ->with('status', 'Unsupported quick action.');
        }

        $interestingJob->fill([
            'shortlist_status' => $action['shortlist_status'],
            'notes' => $action['notes'],
            'updated_at' => now(),
        ]);
        $interestingJob->save();

        return redirect()
            ->back()
            ->with('status', $action['message']);
    }

    public function generateCoverLetter(
        InterestingJob $interestingJob,
        CoverLetterGenerator $generator
    ): RedirectResponse {
        try {
            $result = $generator->generate($interestingJob);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('interesting-jobs.edit', $interestingJob)
                ->with('status', $exception->getMessage());
        }

        $interestingJob->fill([
            'cover_letter_draft' => $result['draft'],
            'cover_letter_generated_at' => now(),
            'cover_letter_model' => $result['model'],
            'cover_letter_usage_json' => $result['usage'],
            'updated_at' => now(),
        ]);
        $interestingJob->save();

        return redirect()
            ->route('interesting-jobs.edit', $interestingJob)
            ->with('status', 'Cover letter draft generated.');
    }
}
