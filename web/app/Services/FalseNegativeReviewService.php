<?php

namespace App\Services;

use App\Models\InterestingJob;
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

        $criteria = $this->loadCriteria();
        $titleKeywords = $this->normalizedList(data_get($criteria, 'desired.title_keywords', []));
        $techKeywords = $this->normalizedList(data_get($criteria, 'desired.tech_keywords', []));
        $excludedKeywords = $this->normalizedList(data_get($criteria, 'excluded.keywords', []));

        $titleCandidates = $this->topCandidates(
            $this->extractTitleCandidates($jobs, $titleKeywords),
            6
        );
        $techCandidates = $this->topCandidates(
            $this->extractTechCandidates($jobs, $techKeywords),
            8
        );
        $excludedConflicts = $this->findExcludedConflicts($jobs, $excludedKeywords);

        $scores = $jobs->pluck('ai_score')
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (float) $value)
            ->sort()
            ->values();

        $scoreCount = $scores->count();
        $medianFlaggedScore = $scoreCount > 0 ? $scores->get((int) floor(($scoreCount - 1) / 2)) : null;

        return [
            'flagged_count' => $jobs->count(),
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
     * @param  Collection<int, InterestingJob>  $jobs
     * @param  array<int, string>  $existingKeywords
     * @return array<string, array{count: int, jobs: array<int, string>}>
     */
    private function extractTitleCandidates(Collection $jobs, array $existingKeywords): array
    {
        $candidates = [];

        foreach ($jobs as $job) {
            foreach ($this->titlePhrases((string) $job->title) as $phrase) {
                if (in_array($phrase, $existingKeywords, true)) {
                    continue;
                }

                if (! isset($candidates[$phrase])) {
                    $candidates[$phrase] = ['count' => 0, 'jobs' => []];
                }

                $candidates[$phrase]['count']++;
                $candidates[$phrase]['jobs'][$job->id] = (string) $job->title;
            }
        }

        return $candidates;
    }

    /**
     * @param  Collection<int, InterestingJob>  $jobs
     * @param  array<int, string>  $existingKeywords
     * @return array<string, array{count: int, jobs: array<int, string>}>
     */
    private function extractTechCandidates(Collection $jobs, array $existingKeywords): array
    {
        $candidates = [];

        foreach ($jobs as $job) {
            $haystack = $this->normalizePhrase(
                (string) $job->title.' '.(string) $job->description_snapshot.' '.(string) $job->ai_reason
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
                $candidates[$normalizedTerm]['jobs'][$job->id] = (string) $job->title;
            }
        }

        return $candidates;
    }

    /**
     * @param  Collection<int, InterestingJob>  $jobs
     * @param  array<int, string>  $excludedKeywords
     * @return array<int, array{keyword: string, count: int, jobs: array<int, array{id: int, title: string}>}>
     */
    private function findExcludedConflicts(Collection $jobs, array $excludedKeywords): array
    {
        $conflicts = [];

        foreach ($excludedKeywords as $keyword) {
            $matches = [];

            foreach ($jobs as $job) {
                $haystack = $this->normalizePhrase(
                    (string) $job->title.' '.(string) $job->description_snapshot.' '.(string) $job->ai_reason
                );

                if (! str_contains($haystack, $keyword)) {
                    continue;
                }

                $matches[] = [
                    'id' => $job->id,
                    'title' => (string) $job->title,
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
