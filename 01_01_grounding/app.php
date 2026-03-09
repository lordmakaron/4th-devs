<?php

require_once __DIR__ . '/src/config.php';
require_once __DIR__ . '/src/utils/file.php';
require_once __DIR__ . '/src/utils/text.php';
require_once __DIR__ . '/src/pipeline/extract.php';
require_once __DIR__ . '/src/pipeline/dedupe.php';
require_once __DIR__ . '/src/pipeline/search.php';
require_once __DIR__ . '/src/pipeline/ground.php';

function confirmRun(): void
{
    echo "\n⚠️  UWAGA: Uruchomienie tego skryptu może zużyć znaczną ilość tokenów.\n";
    echo "   Jeśli nie chcesz go uruchamiać, możesz sprawdzić gotowy wynik w pliku:\n";
    echo "   01_01_grounding/output/grounded_demo.html\n\n";
    echo 'Czy chcesz kontynuować? (yes/y): ';
    $answer = trim(fgets(STDIN));
    $normalized = strtolower($answer);
    if ($normalized !== 'yes' && $normalized !== 'y') {
        echo "Przerwano.\n";
        exit(0);
    }
}

function main(): void
{
    confirmRun();

    $cliConfig  = CLI_CONFIG;
    $sourceFile = resolveMarkdownPath(PATHS['notes'], $cliConfig['inputFile']);
    $markdown   = file_get_contents($sourceFile);
    if ($markdown === false) {
        throw new \RuntimeException("Could not read file: $sourceFile");
    }

    $paragraphs = splitParagraphs($markdown);

    echo "\n📄 Source: $sourceFile\n";
    echo "   Paragraphs: " . count($paragraphs) . "\n\n";

    echo "1. Extracting concepts...\n";
    $conceptsData = extractConcepts($paragraphs, $sourceFile);
    echo "   Total: " . ($conceptsData['conceptCount'] ?? 0) . " concepts\n\n";

    echo "2. Deduplicating concepts...\n";
    $dedupeData = dedupeConcepts($conceptsData);
    echo "   Groups: " . count($dedupeData['groups'] ?? []) . "\n\n";

    echo "3. Web search grounding...\n";
    $searchData = searchConcepts($conceptsData, $dedupeData);
    echo "   Results: " . count($searchData['resultsByCanonical'] ?? []) . "\n\n";

    echo "4. Generating HTML...\n";
    if ($cliConfig['force'] || !file_exists(PATHS['grounded'])) {
        generateAndApplyTemplate($markdown, $conceptsData, $dedupeData, $searchData);
        echo "   Created: " . PATHS['grounded'] . "\n\n";
    } else {
        echo "   Skipped (exists, use --force to regenerate)\n\n";
    }

    echo "✅ Done! Output files:\n";
    echo "   " . PATHS['concepts'] . "\n";
    echo "   " . PATHS['dedupe'] . "\n";
    echo "   " . PATHS['search'] . "\n";
    echo "   " . PATHS['grounded'] . "\n";
}

try {
    main();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
