<?php

require_once __DIR__ . '/../../config.php';

$dirname    = __DIR__;
$projectDir = dirname($dirname);
$rootDir    = dirname($projectDir);

define('PATHS', [
    'root'     => $rootDir,
    'project'  => $projectDir,
    'notes'    => $projectDir . '/notes',
    'output'   => $projectDir . '/output',
    'template' => $projectDir . '/template.html',
    'concepts' => $projectDir . '/output/concepts.json',
    'dedupe'   => $projectDir . '/output/dedupe.json',
    'search'   => $projectDir . '/output/search_results.json',
    'grounded' => $projectDir . '/output/grounded.html',
]);

define('MODELS', [
    'extract' => resolveModelForProvider('gpt-5.4'),
    'search'  => resolveModelForProvider('gpt-5.4'),
    'ground'  => resolveModelForProvider('gpt-5.4'),
]);

function getApiConfig(): array
{
    return [
        'endpoint'     => RESPONSES_API_ENDPOINT,
        'timeoutMs'    => 180000,
        'retries'      => 3,
        'retryDelayMs' => 1000,
    ];
}

// Parse CLI arguments
$cliArgs = array_slice($argv ?? [], 1);

function isCliFlag(string $arg): bool
{
    return str_starts_with($arg, '--');
}

function parseCliConfig(array $args): array
{
    $force     = in_array('--force', $args, true);
    $inputFile = null;

    foreach ($args as $arg) {
        if (!isCliFlag($arg)) {
            $inputFile = $arg;
            break;
        }
    }

    // Batch size
    if (in_array('--no-batch', $args, true)) {
        $batchSize = 1;
    } else {
        $batchArg = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--batch=')) {
                $batchArg = $arg;
                break;
            }
        }
        if ($batchArg !== null) {
            $value     = (int) substr($batchArg, strlen('--batch='));
            $batchSize = ($value < 1) ? 3 : min($value, 10);
        } else {
            $batchSize = 3;
        }
    }

    return [
        'force'     => $force,
        'inputFile' => $inputFile,
        'batchSize' => $batchSize,
    ];
}

define('CLI_CONFIG', parseCliConfig($cliArgs));
