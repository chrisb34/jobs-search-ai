<?php

namespace App\Services;

use App\Models\InterestingJob;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

class FalseNegativeReviewService
{
    private const TITLE_STOP_WORDS = [
        'a', 'an', 'and', 'at', 'de', 'des', 'du', 'for', 'france', 'full', 'hybrid', 'in', 'lead', 'manager',
        'of', 'or', 'remote', 'senior', 'software', 'staff', 'team', 'the', 'to', 'with',
    ];

    private const TECH_LEXICON = [
        'api',
        'automation',
        'aws',
        'backend',
        'developer experience',
        'devops',
        'django',
        'docker',
        'frontend',
        'fullstack',
        'graphql',
        'internal tools',
        'integration',
        'javascript',
        'kubernetes',
        'laravel',
        'node',
        'php',
        'platform',
        'postgres',
        'product',
        'python',
        'react',
        'rest',
        'saas',
        'security',
        'spring',
        'sql',
        'symfony',
        'terraform',
        'typescript',
        'vue',
    ];

    public function ensureColumns(): void
    {
        $columns = collect(DB::select('PRAGMA table_info(interesting_jobs)'))
            ->pluck('name')
            ->all();

        if (! in_array('false_negative', $columns, true)) {
            DB::statement('ALTER TABLE interesting_jobs ADD COLUMN false_negative INTEGER NOT NULL DEFAULT 0');
        }

        if (! in_array('false_negative_reason', $columns, true)) {
            DB::statement('ALTER TABLE interesting_jobs ADD COLUMN false_negative_reason TEXT');
        }

        if (! in_array('false_negative_marked_at', $columns, true)) {
            DB::statement('ALTER TABLE interesting_jobs ADD COLUMN false_negative_marked_at TEXT');
        }

        DB::statement(
            'CREATE TABLE IF NOT EXISTS review_feedback (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source TEXT NOT NULL,
                source_job_id TEXT NOT NULL,
                feedback_type TEXT NOT NULL,
                reason TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE(source, source_job_id, feedback_type)
            )'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSuggestions(): array
    {
        $this->ensureColumns();

        /** @var Collection<int, InterestingJob> $jobs */
        $jobs = InterestingJob::query()
            ->where('false_negative', true)
            ->orderByDesc('false_negative_marked_at')
            ->get();

        $candidateRows = collect(
            DB::table('review_feedback as rf')
                ->leftJoin('raw_jobs as r', function ($join): void {
                    $join
                        ->on('r.source', '=', 'rf.source')
                        ->on('r.source_job_id', '=', 'rf.source_job_id');
                })
                ->leftJoin('normalized_jobs as n', function ($join): void {
                    $join
                        ->on('n.source', '=', 'rf.source')
                        ->on('n.source_job_id', '=', 'rf.source_job_id');
                })
                ->where('rf.feedback_type', 'false_negative')
                ->orderByDesc('rf.updated_at')
                ->get([
                    'rf.source',
                    'rf.source_job_id',
                    'rf.reason as false_negative_reason',
                    'rf.updated_at as false_negative_marked_at',
                    DB::raw("COALESCE(r.title, n.title_normalized) as title"),
                    DB::raw("COALESCE(r.description_raw, '') as description_snapshot"),
                    'n.ai_reason',
                    'n.ai_score',
                ])
        );

        $records = $jobs->map(fn (InterestingJob $job) => [
            'title' => (string) $job->title,
            'description_snapshot' => (string) ($job->description_snapshot ?? ''),
            'ai_reason' => (string) ($job->ai_reason ?? ''),
            'ai_score' => is_numeric($job->ai_score) ? (float) $job->ai_score : null,
        ])->values()->all();

        $candidateRecords = $candidateRows->map(fn ($row) => [
            'title' => (string) ($row->title ?? ''),
            'description_snapshot' => (string) ($row->description_snapshot ?? ''),
            'ai_reason' => (string) ($row->ai_reason ?? ''),
            'ai_score' => is_numeric($row->ai_score) ? (float) $row->ai_score : null,
        ])->values()->all();

        $records = array_merge($records, $candidateRecords);

        $criteria = $this->loadCriteria();
        $titleKeywords = $this->normalizedList(data_get($criteria, 'desired.title_keywords', []));
        $techKeywords = $this->normalizedList(data_get($criteria, 'desired.tech_keywords', []));
        $excludedKeywords = $this->normalizedList(data_get($criteria, 'excluded.keywords', []));

        $titleCandidates = $this->topCandidates(
            $this->extractTitleCandidates($records, $titleKeywords),
            6
        );
        $techCandidates = $this->topCandidates(
            $this->extractTechCandidates($records, $techKeywords),
            8
        );
        $excludedConflicts = $this->findExcludedConflicts($records, $excludedKeywords);

        $scores = collect($records)
            ->pluck('ai_score')
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (float) $value)
            ->sort()
            ->values();

        $scoreCount = $scores->count();
        $medianFlaggedScore = $scoreCount > 0 ? $scores->get((int) floor(($scoreCount - 1) / 2)) : null;

        return [
            'flagged_count' => count($records),
            'flagged_shortlist_count' => $jobs->count(),
            'flagged_candidate_count' => $candidateRows->count(),
            'title_candidates' => $titleCandidates,
            'tech_candidates' => $techCandidates,
            'excluded_conflicts' => $excludedConflicts,
            'thresholds' => [
                'high' => (float) data_get($criteria, 'thresholds.high', 32),
                'maybe' => (float) data_get($criteria, 'thresholds.maybe', 18),
                'min_flagged_score' => $scores->min(),
                'median_flagged_score' => $medianFlaggedScore,
            ],
        ];
    }

    public function candidatePage(string $scope = 'rejected', string $search = '', int $perPage = 20): LengthAwarePaginator
    {
        $this->ensureColumns();

        $query = DB::table('normalized_jobs as n')
            ->leftJoin('raw_jobs as r', function ($join): void {
                $join
                    ->on('r.source', '=', 'n.source')
                    ->on('r.source_job_id', '=', 'n.source_job_id');
            })
            ->leftJoin('interesting_jobs as i', function ($join): void {
                $join
                    ->on('i.source', '=', 'n.source')
                    ->on('i.source_job_id', '=', 'n.source_job_id');
            })
            ->leftJoin('review_feedback as rf', function ($join): void {
                $join
                    ->on('rf.source', '=', 'n.source')
                    ->on('rf.source_job_id', '=', 'n.source_job_id')
                    ->where('rf.feedback_type', '=', 'false_negative');
            })
            ->select([
                'n.source',
                'n.source_job_id',
                'n.ai_score',
                'n.ai_reason',
                'n.ai_decision',
                'n.ai_llm_score',
                'n.ai_llm_reason',
                'n.ai_llm_decision',
                'n.remote_type',
                'n.contract_type',
                'n.language',
                DB::raw('COALESCE(r.title, n.title_normalized) as title'),
                DB::raw('COALESCE(r.company, n.company_normalized) as company'),
                DB::raw('COALESCE(r.location_raw, TRIM(COALESCE(n.city, \'\') || CASE WHEN n.country IS NOT NULL AND n.country != \'\' THEN \', \' || n.country ELSE \'\' END)) as location_raw'),
                DB::raw('COALESCE(r.url, i.url) as url'),
                DB::raw('COALESCE(r.description_raw, \'\') as description_snapshot'),
                'i.id as interesting_job_id',
                'i.shortlist_status',
                'rf.reason as false_negative_reason',
                'rf.updated_at as false_negative_marked_at',
                DB::raw('CASE WHEN rf.id IS NULL THEN 0 ELSE 1 END as false_negative'),
            ])
            ->orderByDesc(DB::raw('CASE WHEN rf.id IS NULL THEN 0 ELSE 1 END'))
            ->orderByDesc('n.ai_score')
            ->orderByDesc('n.updated_at');

        if ($scope === 'flagged') {
            $query->whereNotNull('rf.id');
        } elseif ($scope === 'all') {
            $query->whereNull('i.id');
        } else {
            $query->whereNull('i.id')
                ->where(function ($builder): void {
                    $builder
                        ->where('n.ai_decision', 'reject')
                        ->orWhereNotNull('rf.id');
                });
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('r.title', 'like', "%{$search}%")
                    ->orWhere('r.company', 'like', "%{$search}%")
                    ->orWhere('n.title_normalized', 'like', "%{$search}%")
                    ->orWhere('n.company_normalized', 'like', "%{$search}%")
                    ->orWhere('n.ai_reason', 'like', "%{$search}%")
                    ->orWhere('rf.reason', 'like', "%{$search}%")
                    ->orWhere('r.description_raw', 'like', "%{$search}%");
            });
        }

        return $query->paginate($perPage)->withQueryString();
    }

    public function updateCandidateFeedback(string $source, string $sourceJobId, bool $shouldFlag, ?string $reason): void
    {
        $this->ensureColumns();

        if (! $shouldFlag) {
            DB::table('review_feedback')
                ->where('source', $source)
                ->where('source_job_id', $sourceJobId)
                ->where('feedback_type', 'false_negative')
                ->delete();

            return;
        }

        $now = now()->toIso8601String();
        $existing = DB::table('review_feedback')
            ->where('source', $source)
            ->where('source_job_id', $sourceJobId)
            ->where('feedback_type', 'false_negative')
            ->exists();

        if ($existing) {
            DB::table('review_feedback')
                ->where('source', $source)
                ->where('source_job_id', $sourceJobId)
                ->where('feedback_type', 'false_negative')
                ->update([
                    'reason' => $reason ?: null,
                    'updated_at' => $now,
                ]);

            return;
        }

        DB::table('review_feedback')->insert([
            'source' => $source,
            'source_job_id' => $sourceJobId,
            'feedback_type' => 'false_negative',
            'reason' => $reason ?: null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCriteria(): array
    {
        $projectRoot = realpath(base_path('..')) ?: base_path('..');
        $localPath = $projectRoot.'/config/criteria.local.yaml';
        $defaultPath = $projectRoot.'/config/criteria.yaml';
        $path = file_exists($localPath) ? $localPath : $defaultPath;

        if (! file_exists($path)) {
            return [];
        }

        $parsed = Yaml::parseFile($path);

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    private function normalizedList($value): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(fn ($item) => $this->normalizePhrase((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{title: string, description_snapshot: string, ai_reason: string, ai_score: ?float}>  $jobs
     * @param  array<int, string>  $existingKeywords
     * @return array<string, array{count: int, jobs: array<int, string>}>
     */
    private function extractTitleCandidates(array $jobs, array $existingKeywords): array
    {
        $candidates = [];

        foreach ($jobs as $job) {
            foreach ($this->titlePhrases((string) $job['title']) as $phrase) {
                if (in_array($phrase, $existingKeywords, true)) {
                    continue;
                }

                if (! isset($candidates[$phrase])) {
                    $candidates[$phrase] = ['count' => 0, 'jobs' => []];
                }

                $candidates[$phrase]['count']++;
                $candidates[$phrase]['jobs'][] = (string) $job['title'];
            }
        }

        return $candidates;
    }

    /**
     * @param  array<int, array{title: string, description_snapshot: string, ai_reason: string, ai_score: ?float}>  $jobs
     * @param  array<int, string>  $existingKeywords
     * @return array<string, array{count: int, jobs: array<int, string>}>
     */
    private function extractTechCandidates(array $jobs, array $existingKeywords): array
    {
        $candidates = [];

        foreach ($jobs as $job) {
            $haystack = $this->normalizePhrase(
                (string) $job['title'].' '.(string) $job['description_snapshot'].' '.(string) $job['ai_reason']
            );

            foreach (self::TECH_LEXICON as $term) {
                $normalizedTerm = $this->normalizePhrase($term);
                if ($normalizedTerm === '' || in_array($normalizedTerm, $existingKeywords, true)) {
                    continue;
                }

                if (! str_contains($haystack, $normalizedTerm)) {
                    continue;
                }

                if (! isset($candidates[$normalizedTerm])) {
                    $candidates[$normalizedTerm] = ['count' => 0, 'jobs' => []];
                }

                $candidates[$normalizedTerm]['count']++;
                $candidates[$normalizedTerm]['jobs'][] = (string) $job['title'];
            }
        }

        return $candidates;
    }

    /**
     * @param  array<int, array{title: string, description_snapshot: string, ai_reason: string, ai_score: ?float}>  $jobs
     * @param  array<int, string>  $excludedKeywords
     * @return array<int, array{keyword: string, count: int, jobs: array<int, array{id: int, title: string}>}>
     */
    private function findExcludedConflicts(array $jobs, array $excludedKeywords): array
    {
        $conflicts = [];

        foreach ($excludedKeywords as $keyword) {
            $matches = [];

            foreach ($jobs as $job) {
                $haystack = $this->normalizePhrase(
                    (string) $job['title'].' '.(string) $job['description_snapshot'].' '.(string) $job['ai_reason']
                );

                if (! str_contains($haystack, $keyword)) {
                    continue;
                }

                $matches[] = [
                    'title' => (string) $job['title'],
                ];
            }

            if ($matches === []) {
                continue;
            }

            $conflicts[] = [
                'keyword' => $keyword,
                'count' => count($matches),
                'jobs' => $matches,
            ];
        }

        usort($conflicts, fn (array $left, array $right) => $right['count'] <=> $left['count']);

        return array_slice($conflicts, 0, 5);
    }

    /**
     * @param  array<string, array{count: int, jobs: array<int, string>}>  $candidates
     * @return array<int, array{keyword: string, count: int, example_titles: array<int, string>}>
     */
    private function topCandidates(array $candidates, int $limit): array
    {
        uasort($candidates, function (array $left, array $right): int {
            return $right['count'] <=> $left['count'];
        });

        $results = [];
        foreach (array_slice($candidates, 0, $limit, true) as $keyword => $entry) {
            $results[] = [
                'keyword' => $keyword,
                'count' => $entry['count'],
                'example_titles' => array_slice(array_values($entry['jobs']), 0, 3),
            ];
        }

        return $results;
    }

    /**
     * @return array<int, string>
     */
    private function titlePhrases(string $title): array
    {
        $tokens = collect(preg_split('/[^a-z0-9\+]+/i', $this->normalizePhrase($title)) ?: [])
            ->filter(fn ($token) => strlen((string) $token) >= 3)
            ->reject(fn ($token) => in_array((string) $token, self::TITLE_STOP_WORDS, true))
            ->values()
            ->all();

        $phrases = [];
        $tokenCount = count($tokens);

        for ($start = 0; $start < $tokenCount; $start++) {
            for ($length = 1; $length <= 3; $length++) {
                $slice = array_slice($tokens, $start, $length);
                if (count($slice) !== $length) {
                    continue;
                }

                $phrase = trim(implode(' ', $slice));
                if ($phrase === '') {
                    continue;
                }

                $phrases[$phrase] = $phrase;
            }
        }

        return array_values($phrases);
    }

    private function normalizePhrase(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9\+\#\.\-\s]+/iu', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }
}
