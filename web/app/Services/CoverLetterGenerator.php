<?php

namespace App\Services;

use App\Models\InterestingJob;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use RuntimeException;

class CoverLetterGenerator
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    public function generate(InterestingJob $job): array
    {
        $apiKey = (string) config('services.openai.key');
        $model = (string) config('services.openai.model');
        $baseUrl = rtrim((string) config('services.openai.base_url'), '/');

        if ($apiKey === '') {
            throw new RuntimeException('Missing OPENAI_API_KEY in web/.env');
        }

        if (! $job->description_snapshot) {
            throw new RuntimeException('This shortlisted job does not have a local description snapshot yet.');
        }

        $payload = [
            'model' => $model,
            'input' => $this->buildPrompt($job),
        ];

        $response = $this->http
            ->withToken($apiKey)
            ->acceptJson()
            ->timeout(90)
            ->retry(2, 1500, throw: false)
            ->post($baseUrl.'/responses', $payload);

        if ($response->failed()) {
            throw new RuntimeException($this->formatApiError($response));
        }

        $response = $response->json();

        $variant = $this->selectVariant($job, config('applicant'));

        return [
            'draft' => $this->extractText($response),
            'model' => $response['model'] ?? $model,
            'usage' => $this->buildUsagePayload($response['usage'] ?? null, $variant),
        ];
    }

    private function buildPrompt(InterestingJob $job): string
    {
        $applicant = config('applicant');
        $variant = $this->selectVariant($job, $applicant);
        $profileData = $this->mergeApplicantVariant($applicant, $variant['config']);
        $profile = $applicant['profile'] ?? [];
        $skills = $this->stringifyList($profileData['core_skills'] ?? []);
        $secondarySkills = $this->stringifyList($profileData['secondary_skills'] ?? []);
        $targetRoles = $this->stringifyList($profileData['target_roles'] ?? []);
        $strengths = $this->stringifyBullets($profileData['strengths'] ?? []);
        $highlightStrengths = $this->stringifyBullets($profileData['highlight_strengths'] ?? []);
        $achievements = $this->stringifyBullets($profileData['achievements'] ?? []);
        $constraints = $this->stringifyBullets($profileData['constraints'] ?? []);
        $customizationRules = $this->stringifyBullets($profileData['customisation_rules'] ?? []);
        $tone = $profileData['tone'] ?? [];
        $preferences = $profileData['preferences'] ?? [];
        $experienceNotes = $profileData['experience_notes'] ?? [];
        $closingExamples = $this->stringifyBullets(($profileData['closing'] ?? [])['examples'] ?? []);
        $emphasis = $this->stringifyList($variant['config']['emphasis'] ?? []);
        $variantReason = $this->stringifyBullets($variant['matched_keywords']);
        $primaryLanguage = (string) ($experienceNotes['primary_language'] ?? 'Not specified');
        $javaExperience = (string) ($experienceNotes['java'] ?? $experienceNotes['secondary_languages'] ?? 'Not specified');
        $pythonExperience = (string) ($experienceNotes['python'] ?? $experienceNotes['secondary_languages'] ?? 'Not specified');
        $positioning = (string) ($experienceNotes['positioning'] ?? 'Not specified');
        $noteContext = $this->extractLetterContext((string) $job->notes);
        $generalNotes = $this->stringifyBullets($noteContext['general_notes']);
        $letterNotes = $this->stringifyBullets($noteContext['letter_notes']);

        return <<<PROMPT
Write a tailored cover letter draft for this job.

Applicant profile
Name: {$profile['name']}
Location: {$profile['location']}
Work authorisation: {$profile['work_authorisation']}
Selected positioning lens: {$variant['label']}
Selection rationale:
{$variantReason}
Summary: {$profileData['summary']}
Core skills: {$skills}
Secondary skills: {$secondarySkills}
Target roles: {$targetRoles}
Preferred scope: {$this->stringifyList($preferences['scope'] ?? [])}
Preferred environment: {$this->stringifyList($preferences['environment'] ?? [])}
Avoided environments/roles: {$this->stringifyList($preferences['avoid'] ?? [])}
Variant emphasis: {$emphasis}
Strengths:
{$strengths}
Priority strengths for this variant:
{$highlightStrengths}
Achievements:
{$achievements}
Experience notes:
- Primary language: {$primaryLanguage}
- Java: {$javaExperience}
- Python: {$pythonExperience}
- Positioning: {$positioning}
Tone style: {$tone['style']}
Tone guidelines:
{$this->stringifyBullets($tone['guidelines'] ?? [])}
Constraints:
{$constraints}
Customisation rules:
{$customizationRules}
Closing examples:
{$closingExamples}

Job context
Title: {$job->title}
Company: {$job->company}
Location: {$job->location_raw}
Remote type: {$job->remote_type}
Contract type: {$job->contract_type}
AI reason: {$job->ai_reason}
General shortlist notes:
{$generalNotes}
Cover letter specific notes:
{$letterNotes}
Salary snapshot: {$job->salary_snapshot}

Job description
{$job->description_snapshot}

Instructions
- Keep the draft to roughly 250-400 words.
- Use concrete alignment with the role and applicant profile.
- Do not invent experience, tools, achievements, or domain expertise.
- Use the selected positioning lens strongly, but keep the applicant clearly hands-on and technically credible.
- If the job emphasizes skills outside the applicant profile, acknowledge adjacent strengths without overstating expertise.
- Treat any cover letter specific notes as high-priority guidance when they are credible and consistent with the applicant profile.
- Do not present the applicant as purely architectural or non-coding.
- Treat Python as secondary tooling experience, not primary production positioning, unless the applicant profile explicitly says otherwise.
- Do not use bullet points.
- End with one of these closings where appropriate:
{$closingExamples}
- Sign off with the applicant name.
PROMPT;
    }

    private function extractText(array $response): string
    {
        $directText = trim((string) ($response['output_text'] ?? ''));
        if ($directText !== '') {
            return $directText;
        }

        $parts = [];
        foreach (($response['output'] ?? []) as $outputItem) {
            foreach (($outputItem['content'] ?? []) as $contentItem) {
                $text = trim((string) ($contentItem['text'] ?? ''));
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }

        $joined = trim(implode("\n\n", $parts));
        if ($joined === '') {
            throw new RuntimeException('OpenAI returned no usable text.');
        }

        return $joined;
    }

    private function formatApiError(Response $response): string
    {
        $message = $response->json('error.message');
        if (is_string($message) && $message !== '') {
            return 'OpenAI request failed: '.$message;
        }

        return 'OpenAI request failed with status '.$response->status().'.';
    }

    private function selectVariant(InterestingJob $job, array $applicant): array
    {
        $variants = $applicant['variants'] ?? [];
        if (! is_array($variants) || $variants === []) {
            return [
                'key' => 'default',
                'label' => 'Default profile',
                'config' => [],
                'matched_keywords' => ['No variant-specific configuration found.'],
            ];
        }

        $selection = is_array($applicant['variant_selection'] ?? null) ? $applicant['variant_selection'] : [];
        $defaultVariant = (string) ($selection['default'] ?? array_key_first($variants) ?? 'default');
        $jobText = $this->jobText($job);

        $bestKey = $defaultVariant;
        $bestScore = -1;
        $bestMatches = [];

        foreach ($variants as $key => $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $score = 0;
            $matches = [];
            foreach (($variant['selection_keywords'] ?? []) as $keyword => $weight) {
                $needle = is_string($keyword) ? trim($keyword) : '';
                $points = is_numeric($weight) ? (int) $weight : 1;
                if ($needle === '') {
                    continue;
                }
                if (str_contains($jobText, mb_strtolower($needle))) {
                    $score += max(1, $points);
                    $matches[] = "{$needle} (+{$points})";
                }
            }

            if ($score > $bestScore) {
                $bestKey = (string) $key;
                $bestScore = $score;
                $bestMatches = $matches;
            }
        }

        $selected = is_array($variants[$bestKey] ?? null) ? $variants[$bestKey] : [];

        return [
            'key' => $bestKey,
            'label' => (string) ($selected['label'] ?? $bestKey),
            'config' => $selected,
            'matched_keywords' => $bestMatches !== [] ? $bestMatches : ['No strong variant keyword matches; used default variant.'],
        ];
    }

    private function mergeApplicantVariant(array $applicant, array $variant): array
    {
        $profileData = $applicant;
        unset($profileData['variants'], $profileData['variant_selection']);

        if ($variant === []) {
            return $profileData;
        }

        return array_replace_recursive($profileData, Arr::except($variant, ['label', 'selection_keywords']));
    }

    private function jobText(InterestingJob $job): string
    {
        return mb_strtolower(implode(' ', array_filter([
            (string) $job->title,
            (string) $job->company,
            (string) $job->location_raw,
            (string) $job->contract_type,
            (string) $job->notes,
            (string) $job->ai_reason,
            (string) $job->description_snapshot,
        ])));
    }

    private function buildUsagePayload(?array $usage, array $variant): ?array
    {
        $payload = is_array($usage) ? $usage : [];
        $payload['variant_key'] = $variant['key'];
        $payload['variant_label'] = $variant['label'];
        $payload['variant_matched_keywords'] = $variant['matched_keywords'];

        return $payload;
    }

    private function extractLetterContext(string $notes): array
    {
        if (trim($notes) === '') {
            return [
                'general_notes' => [],
                'letter_notes' => [],
            ];
        }

        preg_match_all('/<letter>(.*?)<\/letter>/is', $notes, $matches);
        $letterNotes = array_values(array_filter(array_map(
            static fn (string $value): string => trim(strip_tags($value)),
            $matches[1] ?? []
        )));

        $generalNotesText = preg_replace('/<letter>.*?<\/letter>/is', '', $notes) ?? '';
        $generalNotes = array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            preg_split('/\r\n|\r|\n/', $generalNotesText) ?: []
        )));

        return [
            'general_notes' => $generalNotes,
            'letter_notes' => $letterNotes,
        ];
    }

    private function stringifyList(array $values): string
    {
        return implode(', ', array_filter(array_map('strval', $values)));
    }

    private function stringifyBullets(array $values): string
    {
        $items = array_filter(array_map(static fn ($value) => trim((string) $value), $values));

        if ($items === []) {
            return '- None';
        }

        return '- '.implode("\n- ", $items);
    }
}
