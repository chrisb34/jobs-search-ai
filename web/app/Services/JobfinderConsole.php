<?php

namespace App\Services;

use InvalidArgumentException;
use Symfony\Component\Process\Process;

class JobfinderConsole
{
    private string $projectRoot;

    public function __construct()
    {
        $this->projectRoot = realpath(base_path('..')) ?: base_path('..');
    }

    public function actions(): array
    {
        return [
            'run_saved_searches' => [
                'label' => 'Run Saved Searches',
                'description' => 'Scrape configured searches into SQLite.',
            ],
            'score_jobs' => [
                'label' => 'Score Jobs',
                'description' => 'Apply rule-based scoring to active jobs.',
            ],
            'promote_shortlist' => [
                'label' => 'Promote Shortlist',
                'description' => 'Promote high/maybe jobs into interesting_jobs.',
            ],
            'diagnose_shortlist' => [
                'label' => 'Diagnose Shortlist',
                'description' => 'Report shortlist gaps, duplicates, and stale rows.',
            ],
        ];
    }

    public function run(string $action, array $options = []): array
    {
        $command = $this->buildCommand($action, $options);
        $process = new Process($command, $this->projectRoot);
        $process->setTimeout(600);
        $process->run();

        return [
            'action' => $action,
            'command' => $process->getCommandLine(),
            'successful' => $process->isSuccessful(),
            'exit_code' => $process->getExitCode(),
            'output' => trim($process->getOutput()),
            'error_output' => trim($process->getErrorOutput()),
            'ran_at' => now()->toDateTimeString(),
        ];
    }

    private function buildCommand(string $action, array $options): array
    {
        $pages = max(1, (int) ($options['pages'] ?? 1));
        $searchName = trim((string) ($options['search_name'] ?? ''));
        $onlyUnscored = (bool) ($options['only_unscored'] ?? false);

        return match ($action) {
            'run_saved_searches' => $this->buildRunSavedSearchesCommand($pages, $searchName),
            'score_jobs' => $this->buildScoreJobsCommand($onlyUnscored),
            'promote_shortlist' => [
                'python3',
                '-m',
                'jobfinder.runs.promote_shortlist',
                '--db-path',
                'data/jobs.db',
                '--decisions',
                'high',
                'maybe',
            ],
            'diagnose_shortlist' => [
                'python3',
                '-m',
                'jobfinder.runs.diagnose_shortlist',
                '--db-path',
                'data/jobs.db',
                '--limit',
                '50',
            ],
            default => throw new InvalidArgumentException('Unsupported console action.'),
        };
    }

    private function buildRunSavedSearchesCommand(int $pages, string $searchName): array
    {
        $command = [
            'python3',
            '-m',
            'jobfinder.runs.run_saved_searches',
            '--config',
            'config/sources.yaml',
            '--db-path',
            'data/jobs.db',
            '--pages',
            (string) $pages,
            '--auto-pages',
            '--delay-seconds',
            '0.2',
        ];

        if ($searchName !== '') {
            $command[] = '--search-name';
            $command[] = $searchName;
        }

        return $command;
    }

    private function buildScoreJobsCommand(bool $onlyUnscored): array
    {
        $command = [
            'python3',
            '-m',
            'jobfinder.runs.score_jobs',
            '--criteria',
            'config/criteria.yaml',
            '--db-path',
            'data/jobs.db',
        ];

        if ($onlyUnscored) {
            $command[] = '--only-unscored';
        }

        return $command;
    }
}
