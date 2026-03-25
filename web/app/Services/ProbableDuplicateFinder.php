<?php

namespace App\Services;

use App\Models\InterestingJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProbableDuplicateFinder
{
    private const TITLE_STOP_WORDS = [
        'a',
        'an',
        'and',
        'd',
        'for',
        'france',
        'full',
        'hybrid',
        'm',
        'or',
        'remote',
        'the',
        'with',
    ];

    private const TITLE_LEVEL_WORDS = [
        'junior',
        'lead',
        'principal',
        'senior',
        'staff',
    ];

    public function attachToPage(iterable $jobs): void
    {
        $jobCollection = collect($jobs);

        if ($jobCollection->isEmpty()) {
            return;
        }

        $companies = $jobCollection
            ->pluck('company')
            ->filter()
            ->unique()
            ->values();

        $candidates = InterestingJob::query()
            ->whereIn('company', $companies)
            ->get();

        foreach ($jobCollection as $job) {
            $matches = $this->findMatchesInCollection($job, $candidates)->take(3)->values();
            $job->setAttribute('probable_duplicate_matches', $matches);
            $job->setAttribute('probable_duplicate_count', $matches->count());
        }
    }

    public function findForJob(InterestingJob $job, int $limit = 5): Collection
    {
        $candidates = InterestingJob::query()
            ->where('company', $job->company)
            ->where('id', '!=', $job->id)
            ->get();

        return $this->findMatchesInCollection($job, $candidates)->take($limit)->values();
    }

    private function findMatchesInCollection(InterestingJob $job, Collection $candidates): Collection
    {
        return $candidates
            ->map(function (InterestingJob $candidate) use ($job): ?InterestingJob {
                $score = $this->scorePair($job, $candidate);

                if ($score < 65) {
                    return null;
                }

                $candidate->setAttribute('probable_duplicate_score', $score);

                return $candidate;
            })
            ->filter()
            ->sortByDesc('probable_duplicate_score')
            ->values();
    }

    private function scorePair(InterestingJob $left, InterestingJob $right): int
    {
        if ($left->id === $right->id) {
            return 0;
        }

        if (!$this->sameCompany($left, $right)) {
            return 0;
        }

        $score = 35;

        if ($this->sameRemoteType($left, $right)) {
            $score += 10;
        }

        if ($this->similarLocation($left, $right)) {
            $score += 10;
        }

        $sharedCoreTokens = $this->sharedCoreTitleTokens($left, $right);
        $score += min(25, $sharedCoreTokens * 12);

        if ($this->sameTitleFamily($left, $right)) {
            $score += 15;
        }

        if ($this->similarDescription($left, $right)) {
            $score += 20;
        }

        return min(100, $score);
    }

    private function sameCompany(InterestingJob $left, InterestingJob $right): bool
    {
        return $this->normalizeText($left->company) !== ''
            && $this->normalizeText($left->company) === $this->normalizeText($right->company);
    }

    private function sameRemoteType(InterestingJob $left, InterestingJob $right): bool
    {
        return $this->normalizeText($left->remote_type) !== ''
            && $this->normalizeText($left->remote_type) === $this->normalizeText($right->remote_type);
    }

    private function similarLocation(InterestingJob $left, InterestingJob $right): bool
    {
        $leftLocation = $this->normalizeText($left->location_raw);
        $rightLocation = $this->normalizeText($right->location_raw);

        if ($leftLocation === '' || $rightLocation === '') {
            return false;
        }

        return $leftLocation === $rightLocation
            || Str::contains($leftLocation, $rightLocation)
            || Str::contains($rightLocation, $leftLocation);
    }

    private function sharedCoreTitleTokens(InterestingJob $left, InterestingJob $right): int
    {
        return count(array_intersect(
            $this->coreTitleTokens((string) $left->title),
            $this->coreTitleTokens((string) $right->title)
        ));
    }

    private function sameTitleFamily(InterestingJob $left, InterestingJob $right): bool
    {
        $leftTokens = $this->titleFamilyTokens((string) $left->title);
        $rightTokens = $this->titleFamilyTokens((string) $right->title);

        return !empty($leftTokens) && !empty($rightTokens) && $leftTokens === $rightTokens;
    }

    private function similarDescription(InterestingJob $left, InterestingJob $right): bool
    {
        $leftDescription = $this->normalizeText(Str::limit((string) $left->description_snapshot, 800, ''));
        $rightDescription = $this->normalizeText(Str::limit((string) $right->description_snapshot, 800, ''));

        if ($leftDescription === '' || $rightDescription === '') {
            return false;
        }

        similar_text($leftDescription, $rightDescription, $percent);

        return $percent >= 72;
    }

    private function coreTitleTokens(string $title): array
    {
        return array_values(array_unique(array_filter(
            $this->tokenize($title),
            fn (string $token): bool => !in_array($token, self::TITLE_STOP_WORDS, true)
                && !in_array($token, self::TITLE_LEVEL_WORDS, true)
        )));
    }

    private function titleFamilyTokens(string $title): array
    {
        $preferredTokens = ['engineer', 'engineering', 'developer', 'development', 'software'];

        return array_values(array_intersect($this->coreTitleTokens($title), $preferredTokens));
    }

    private function tokenize(string $value): array
    {
        $normalized = $this->normalizeText($value);

        if ($normalized === '') {
            return [];
        }

        return preg_split('/\s+/', $normalized) ?: [];
    }

    private function normalizeText(?string $value): string
    {
        $ascii = Str::of((string) $value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim();

        return (string) $ascii;
    }
}
