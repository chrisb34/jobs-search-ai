<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use RuntimeException;

class SetupWizardGenerator
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly SetupWizardFiles $files,
    ) {
    }

    public function generateFromCv(string $cvText, string $extraContext = ''): array
    {
        $apiKey = (string) config('services.openai.key');
        $model = (string) config('services.openai.model');
        $baseUrl = rtrim((string) config('services.openai.base_url'), '/');

        if ($apiKey === '') {
            throw new RuntimeException('Missing OPENAI_API_KEY in web/.env');
        }

        $preparedCvText = $this->prepareCvText($cvText);
        $generated = $this->sendPrompt(
            $baseUrl,
            $apiKey,
            $model,
            $this->buildCombinedPrompt($preparedCvText, $extraContext),
        );

        $decoded = json_decode($this->stripCodeFences($generated), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI did not return valid JSON for the setup wizard.');
        }

        $criteria = trim((string) ($decoded['criteria_yaml'] ?? ''));
        $applicant = trim((string) ($decoded['applicant_php'] ?? ''));
        if ($criteria === '' || $applicant === '') {
            throw new RuntimeException('OpenAI response was missing criteria or applicant content.');
        }

        return [
            'criteria' => $this->stripCodeFences($criteria),
            'applicant' => $this->stripCodeFences($applicant),
        ];
    }

    private function sendPrompt(string $baseUrl, string $apiKey, string $model, string $prompt): string
    {
        $response = $this->http
            ->withToken($apiKey)
            ->acceptJson()
            ->timeout(180)
            ->retry(1, 1200, throw: false)
            ->post($baseUrl.'/responses', [
                'model' => $model,
                'input' => $prompt,
            ]);

        if ($response->failed()) {
            throw new RuntimeException($this->formatApiError($response));
        }

        return $this->extractText($response->json());
    }

    private function buildCombinedPrompt(string $cvText, string $extraContext): string
    {
        $criteriaTemplate = $this->files->criteriaTemplate();
        $applicantTemplate = $this->files->applicantTemplate();

        return <<<PROMPT
Generate both:
1. a `criteria.local.yaml` file for a job-search scoring system
2. a `web/config/applicant.local.php` file for a Laravel app

Requirements:
- Return JSON only, with this exact shape:
{
  "criteria_yaml": "full yaml contents",
  "applicant_php": "full php contents"
}
- `criteria_yaml` must be raw YAML matching the same schema shape as this template:
{$criteriaTemplate}
- `applicant_php` must be valid raw PHP starting with `<?php` and returning an array using the same overall structure as this template:
{$applicantTemplate}
- Infer likely target roles, preferred technologies, seniority, remote preferences, exclusions, and profile positioning from the CV.
- Be conservative and factual. Do not invent technologies, preferences, or achievements not reasonably supported by the CV.
- Keep achievements concrete and defensible.
- For the applicant config, include these top-level keys:
  profile, summary, core_skills, secondary_skills, target_roles, preferences, strengths, achievements, experience_notes, tone, constraints, customisation_rules, variant_selection, variants, closing
- Under `variants`, include at least `fullstack` and `platform`
- Make the two variants genuinely different in emphasis, but still represent the same senior hands-on engineer.
- `variant_selection.default` should be whichever variant is the safer default from the CV.
- Add `selection_keywords` arrays to both variants so the app can auto-select them from job text.
- Do not wrap the YAML or PHP in markdown fences.

Additional context from the user:
{$extraContext}

CV text:
{$cvText}
PROMPT;
    }

    private function prepareCvText(string $cvText): string
    {
        $trimmed = trim($cvText);
        $limit = 18000;

        if (mb_strlen($trimmed) <= $limit) {
            return $trimmed;
        }

        $head = mb_substr($trimmed, 0, 12000);
        $tail = mb_substr($trimmed, -5000);

        return $head."\n\n[CV content truncated for length]\n\n".$tail;
    }

    private function stripCodeFences(string $contents): string
    {
        $trimmed = trim($contents);
        $trimmed = preg_replace('/^```(?:yaml|php)?\s*/i', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;

        return trim($trimmed);
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
}
