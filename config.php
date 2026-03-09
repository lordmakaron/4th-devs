<?php

$rootDir = __DIR__;
$rootEnvFile = $rootDir . '/.env';

// Load .env file if it exists
if (file_exists($rootEnvFile)) {
    $lines = file($rootEnvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        // Remove surrounding quotes
        if (preg_match('/^"(.*)"$/s', $value, $m) || preg_match("/^'(.*)'$/s", $value, $m)) {
            $value = $m[1];
        }
        if (getenv($name) === false) {
            putenv("$name=$value");
            $_ENV[$name] = $value;
        }
    }
}

define('RESPONSES_ENDPOINTS', [
    'openai'      => 'https://api.openai.com/v1/responses',
    'openrouter'  => 'https://openrouter.ai/api/v1/responses',
]);

$openaiApiKey     = trim(getenv('OPENAI_API_KEY') ?: '');
$openrouterApiKey = trim(getenv('OPENROUTER_API_KEY') ?: '');
$requestedProvider = strtolower(trim(getenv('AI_PROVIDER') ?: ''));

$hasOpenAIKey      = $openaiApiKey !== '';
$hasOpenRouterKey  = $openrouterApiKey !== '';
$validProviders    = ['openai', 'openrouter'];

if (!$hasOpenAIKey && !$hasOpenRouterKey) {
    fwrite(STDERR, "\033[31mError: API key is not set\033[0m\n");
    fwrite(STDERR, "       Create: $rootEnvFile\n");
    fwrite(STDERR, "       Add one of:\n");
    fwrite(STDERR, "       OPENAI_API_KEY=sk-...\n");
    fwrite(STDERR, "       OPENROUTER_API_KEY=sk-or-v1-...\n");
    exit(1);
}

if ($requestedProvider !== '' && !in_array($requestedProvider, $validProviders, true)) {
    fwrite(STDERR, "\033[31mError: AI_PROVIDER must be one of: openai, openrouter\033[0m\n");
    exit(1);
}

$resolvedProvider = (function () use ($requestedProvider, $hasOpenAIKey, $hasOpenRouterKey) {
    if ($requestedProvider !== '') {
        if ($requestedProvider === 'openai' && !$hasOpenAIKey) {
            fwrite(STDERR, "\033[31mError: AI_PROVIDER=openai requires OPENAI_API_KEY\033[0m\n");
            exit(1);
        }
        if ($requestedProvider === 'openrouter' && !$hasOpenRouterKey) {
            fwrite(STDERR, "\033[31mError: AI_PROVIDER=openrouter requires OPENROUTER_API_KEY\033[0m\n");
            exit(1);
        }
        return $requestedProvider;
    }
    return $hasOpenAIKey ? 'openai' : 'openrouter';
})();

define('AI_PROVIDER', $resolvedProvider);
define('AI_API_KEY', AI_PROVIDER === 'openai' ? $openaiApiKey : $openrouterApiKey);
define('OPENAI_API_KEY', $openaiApiKey);
define('OPENROUTER_API_KEY', $openrouterApiKey);
define('RESPONSES_API_ENDPOINT', RESPONSES_ENDPOINTS[AI_PROVIDER]);

$extraHeaders = [];
if (AI_PROVIDER === 'openrouter') {
    $httpReferer = getenv('OPENROUTER_HTTP_REFERER') ?: '';
    $appName     = getenv('OPENROUTER_APP_NAME') ?: '';
    if ($httpReferer !== '') {
        $extraHeaders['HTTP-Referer'] = $httpReferer;
    }
    if ($appName !== '') {
        $extraHeaders['X-Title'] = $appName;
    }
}
define('EXTRA_API_HEADERS', $extraHeaders);

function resolveModelForProvider(string $model): string
{
    if (trim($model) === '') {
        throw new \InvalidArgumentException('Model must be a non-empty string');
    }
    if (AI_PROVIDER !== 'openrouter' || str_contains($model, '/')) {
        return $model;
    }
    return str_starts_with($model, 'gpt-') ? "openai/{$model}" : $model;
}
