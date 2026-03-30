<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Process\Process;

class SetupWizardFiles
{
    public function projectRoot(): string
    {
        return realpath(base_path('..')) ?: base_path('..');
    }

    public function currentLocalFiles(): array
    {
        return [
            'criteria' => $this->read($this->criteriaLocalPath()),
            'applicant' => $this->read($this->applicantLocalPath()),
        ];
    }

    public function criteriaTemplate(): string
    {
        return $this->read($this->projectRoot().'/config/criteria.yaml');
    }

    public function applicantTemplate(): string
    {
        return $this->read(base_path('config/applicant.php'));
    }

    public function saveCriteria(string $contents): void
    {
        try {
            Yaml::parse($contents);
        } catch (ParseException $exception) {
            throw new RuntimeException('Generated criteria.local.yaml is invalid YAML: '.$exception->getMessage());
        }

        file_put_contents($this->criteriaLocalPath(), rtrim($contents).PHP_EOL);
    }

    public function saveApplicant(string $contents): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'applicant-local-');
        if ($tempFile === false) {
            throw new RuntimeException('Unable to create temporary file for applicant config validation.');
        }

        file_put_contents($tempFile, $contents);
        $process = new Process(['php', '-l', $tempFile]);
        $process->run();
        @unlink($tempFile);

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Generated applicant.local.php is invalid PHP.');
        }

        file_put_contents($this->applicantLocalPath(), rtrim($contents).PHP_EOL);
    }

    public function criteriaLocalPath(): string
    {
        return $this->projectRoot().'/config/criteria.local.yaml';
    }

    public function applicantLocalPath(): string
    {
        return base_path('config/applicant.local.php');
    }

    private function read(string $path): string
    {
        return file_exists($path) ? file_get_contents($path) ?: '' : '';
    }
}
