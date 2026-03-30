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

        $criteria = $this->sendPrompt(
            $baseUrl,
            $apiKey,
            $model,
            $this->buildCriteriaPrompt($cvText, $extraContext),
        );

        $applicant = $this->sendPrompt(
            $baseUrl,
            $apiKey,
            $model,
            $this->buildApplicantPrompt($cvText, $extraContext),
        );

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
            ->timeout(120)
            ->retry(2, 1500, throw: false)
            ->post($baseUrl.'/responses', [
                'model' => $model,
                'input' => $prompt,
            ]);

        if ($response->failed()) {
            throw new RuntimeException($this->formatApiError($response));
        }

        return $this->extractText($response->json());
    }

    private function buildCriteriaPrompt(string $cvText, string $extraContext): string
    {
        $template = $this->files->criteriaTemplate();

        return <<<PROMPT
Generate a `criteria.local.yaml` file for a job-search scoring system.

Requirements:
- Output raw YAML only.
- Follow the same schema shape as this template:
{$template}
- Infer likely target roles, preferred technologies, seniority, remote preferences, and exclusions from the CV.
- Be conservative and factual. Do not invent technologies or preferences not reasonably supported by the CV.
- Include language penalties if a likely language preference is implied.
- Prefer a practical, selective configuration rather than a broad one.

Additional context from the user:
{$extraContext}

CV text:
{$cvText}
PROMPT;
    }

    private function buildApplicantPrompt(string $cvText, string $extraContext): string
    {
        return <<<PROMPT
Generate a `web/config/applicant.local.php` file for a Laravel app.

Requirements:
- Output valid raw PHP only.
- Start with `<?php` and return a PHP array using `return [ ... ];`
- Include these top-level keys:
  profile, summary, core_skills, secondary_skills, target_roles, preferences, strengths, achievements, experience_notes, tone, constraints, customisation_rules, variant_selection, variants, closing
- Under `variants`, include at least:
  `fullstack` and `platform`
- Use the CV to infer strong, factual positioning.
- Do not invent experience.
- Keep achievements concrete and defensible.
- Make the two variants genuinely different in emphasis, but still represent the same senior hands-on engineer.
- `variant_selection.default` should be whichever variant is the safer default from the CV.
- Add `selection_keywords` arrays to both variants so the app can auto-select them from job text.

Additional context from the user:
{$extraContext}

CV text:
{$cvText}
PROMPT;
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
