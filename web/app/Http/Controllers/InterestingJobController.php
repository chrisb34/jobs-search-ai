<?php

namespace App\Http\Controllers;

use App\Models\InterestingJob;
use App\Services\CoverLetterGenerator;
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

    public function index(Request $request, ProbableDuplicateFinder $duplicateFinder): View
    {
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
            $query->where('ai_decision', $request->string('decision'));
        }

        if ($request->filled('status')) {
            $query->where('shortlist_status', $request->string('status'));
        }

        if ($request->filled('source')) {
            $query->where('source', $request->string('source'));
        }

        if ($request->filled('min_score')) {
            $query->where('ai_score', '>=', (float) $request->input('min_score'));
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
                    ->orWhere('source_job_id', 'like', "%{$search}%")
                    ->orWhere('source', 'like', "%{$search}%")
                    ->orWhere('location_raw', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhere('description_snapshot', 'like', "%{$search}%");
            });
        }

        $jobs = $query->paginate(25)->withQueryString();
        $duplicateFinder->attachToPage($jobs->getCollection());

        return view('interesting-jobs.index', [
            'jobs' => $jobs,
            'filters' => $request->only(['decision', 'status', 'source', 'q', 'remote_only', 'min_score']),
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
