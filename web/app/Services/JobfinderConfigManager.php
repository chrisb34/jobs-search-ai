<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class JobfinderConfigManager
{
    /**
     * @return array<string, array{label: string, path: string, contents: string}>
     */
    public function loadAll(): array
    {
        $files = [];

        foreach ($this->definitions() as $key => $definition) {
            $path = $definition['path'];
            $files[$key] = [
                'label' => $definition['label'],
                'path' => $path,
                'contents' => file_exists($path) ? file_get_contents($path) ?: '' : '',
            ];
        }

        return $files;
    }

    public function save(string $fileKey, string $contents): void
    {
        $definition = $this->definitions()[$fileKey] ?? null;
        if ($definition === null) {
            throw new InvalidArgumentException('Unsupported configuration file.');
        }

        $this->validateYaml($contents, $definition['label']);

        $path = $definition['path'];
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create configuration directory.');
        }

        file_put_contents($path, rtrim($contents).PHP_EOL);
    }

    /**
     * @return array<string, array{label: string, path: string}>
     */
    private function definitions(): array
    {
        $projectRoot = realpath(base_path('..')) ?: base_path('..');

        return [
            'sources' => [
                'label' => 'Sources',
                'path' => $projectRoot.'/config/sources.yaml',
            ],
            'criteria' => [
                'label' => 'Criteria (Local Override)',
                'path' => $projectRoot.'/config/criteria.local.yaml',
            ],
        ];
    }

    private function validateYaml(string $contents, string $label): void
    {
        try {
            Yaml::parse($contents);
        } catch (ParseException $exception) {
            throw new InvalidArgumentException($label.' YAML is invalid: '.$exception->getMessage());
        }
    }
}
