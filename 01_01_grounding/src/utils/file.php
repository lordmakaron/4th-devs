<?php

function ensureDir(string $dirPath): void
{
    if (!is_dir($dirPath)) {
        mkdir($dirPath, 0777, true);
    }
}

function resolveMarkdownPath(string $notesDir, ?string $inputFile): string
{
    ensureDir($notesDir);

    if ($inputFile !== null) {
        $candidate = str_starts_with($inputFile, '/')
            ? $inputFile
            : $notesDir . '/' . $inputFile;

        if (!str_ends_with($candidate, '.md')) {
            throw new \RuntimeException('Please provide a .md file name.');
        }

        if (!file_exists($candidate)) {
            throw new \RuntimeException("File not found: $candidate");
        }

        return $candidate;
    }

    $entries = scandir($notesDir);
    $mdFiles = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $fullPath = $notesDir . '/' . $entry;
        if (is_file($fullPath) && str_ends_with($entry, '.md')) {
            $mdFiles[] = $entry;
        }
    }

    if (empty($mdFiles)) {
        throw new \RuntimeException("No .md files found in $notesDir. Add a markdown file to process.");
    }

    sort($mdFiles);
    return $notesDir . '/' . $mdFiles[0];
}

function readJsonIfExists(string $filePath): ?array
{
    if (!file_exists($filePath)) {
        return null;
    }
    $text = file_get_contents($filePath);
    if ($text === false) {
        return null;
    }
    $decoded = json_decode($text, true);
    return is_array($decoded) ? $decoded : null;
}

function safeWriteJson(string $filePath, array $data): void
{
    ensureDir(dirname($filePath));
    $tempPath = $filePath . '.tmp';
    file_put_contents($tempPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    rename($tempPath, $filePath);
}
