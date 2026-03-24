<?php

namespace App\Services;

use App\Models\InterestingJob;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
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

        return [
            'draft' => $this->extractText($response),
            'model' => $response['model'] ?? $model,
            'usage' => $response['usage'] ?? null,
        ];
    }

    private function buildPrompt(InterestingJob $job): string
    {
        $applicant = config('applicant');
        $profile = $applicant['profile'] ?? [];
        $skills = $this->stringifyList($applicant['core_skills'] ?? []);
        $secondarySkills = $this->stringifyList($applicant['secondary_skills'] ?? []);
        $targetRoles = $this->stringifyList($applicant['target_roles'] ?? []);
        $strengths = $this->stringifyBullets($applicant['strengths'] ?? []);
        $achievements = $this->stringifyBullets($applicant['achievements'] ?? []);
        $constraints = $this->stringifyBullets($applicant['constraints'] ?? []);
        $customizationRules = $this->stringifyBullets($applicant['customisation_rules'] ?? []);
        $tone = $applicant['tone'] ?? [];
        $preferences = $applicant['preferences'] ?? [];
        $experienceNotes = $applicant['experience_notes'] ?? [];
        $closingExamples = $this->stringifyBullets(($applicant['closing'] ?? [])['examples'] ?? []);

        return <<<PROMPT
Write a tailored cover letter draft for this job.

Applicant profile
Name: {$profile['name']}
Location: {$profile['location']}
Work authorisation: {$profile['work_authorisation']}
Summary: {$applicant['summary']}
Core skills: {$skills}
Secondary skills: {$secondarySkills}
Target roles: {$targetRoles}
Preferred scope: {$this->stringifyList($preferences['scope'] ?? [])}
Preferred environment: {$this->stringifyList($preferences['environment'] ?? [])}
Avoided environments/roles: {$this->stringifyList($preferences['avoid'] ?? [])}
Strengths:
{$strengths}
Achievements:
{$achievements}
Experience notes:
- Primary language: {$experienceNotes['primary_language']}
- Python: {$experienceNotes['python']}
- Positioning: {$experienceNotes['positioning']}
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
Shortlist notes: {$job->notes}
Salary snapshot: {$job->salary_snapshot}

Job description
{$job->description_snapshot}

Instructions
- Keep the draft to roughly 250-400 words.
- Use concrete alignment with the role and applicant profile.
- Do not invent experience, tools, achievements, or domain expertise.
- If the job emphasizes skills outside the applicant profile, acknowledge adjacent strengths without overstating expertise.
- Treat Python as secondary tooling experience, not primary production positioning.
- Prioritise Java, backend, API, integration, platform, and technical leadership strengths where relevant.
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
