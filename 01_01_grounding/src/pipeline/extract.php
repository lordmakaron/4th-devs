<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api.php';
require_once __DIR__ . '/../utils/file.php';
require_once __DIR__ . '/../utils/hash.php';
require_once __DIR__ . '/../utils/text.php';
require_once __DIR__ . '/../schemas/extract.php';
require_once __DIR__ . '/../prompts/extract.php';
require_once __DIR__ . '/concept_filter.php';

const EXTRACT_CONCURRENCY = 5;

function updateConceptCounts(array &$conceptsData): void
{
    $count = 0;
    foreach ($conceptsData['paragraphs'] as $paragraph) {
        $count += count($paragraph['concepts'] ?? []);
    }
    $conceptsData['paragraphCount'] = count($conceptsData['paragraphs']);
    $conceptsData['conceptCount']   = $count;
}

function computeConceptsHash(array $conceptsData): string
{
    $payload = array_map(fn($p) => [
        'index'    => $p['index'],
        'hash'     => $p['hash'],
        'concepts' => array_map(fn($c) => [
            'label'        => $c['label'],
            'category'     => $c['category'],
            'needsSearch'  => $c['needsSearch'],
            'searchQuery'  => $c['searchQuery'],
            'surfaceForms' => $c['surfaceForms'],
        ], $p['concepts']),
    ], $conceptsData['paragraphs']);

    return hashObject($payload);
}

function updateAndPersist(array &$conceptsData, array &$entryByIndex, string $sourceHash, array $currentIndices): void
{
    foreach (array_keys($entryByIndex) as $index) {
        if (!in_array($index, $currentIndices, true)) {
            unset($entryByIndex[$index]);
        }
    }

    ksort($entryByIndex);
    $conceptsData['paragraphs']    = array_values($entryByIndex);
    updateConceptCounts($conceptsData);
    $conceptsData['sourceHash']    = $sourceHash;
    $conceptsData['model']         = MODELS['extract'];
    $conceptsData['conceptsHash']  = computeConceptsHash($conceptsData);
    safeWriteJson(PATHS['concepts'], $conceptsData);
}

function buildConceptEntries(array $conceptsData): array
{
    $entries = [];
    foreach ($conceptsData['paragraphs'] as $paragraph) {
        foreach ($paragraph['concepts'] as $concept) {
            $entries[] = array_merge($concept, ['paragraphIndex' => $paragraph['index']]);
        }
    }
    return $entries;
}

function extractSingleParagraph(array $item, int $total): array
{
    $paragraphType = getParagraphType($item['paragraph']);
    $targetCount   = getTargetCount($paragraphType);

    $input = buildExtractPrompt(
        $item['paragraph'],
        $paragraphType,
        $targetCount,
        $item['index'] + 1,
        $total
    );

    $data = callResponses([
        'model'      => MODELS['extract'],
        'input'      => $input,
        'textFormat' => getExtractSchema(),
        'reasoning'  => ['effort' => 'medium'],
    ]);

    $result   = parseJsonOutput($data, "extract: paragraph " . ($item['index'] + 1));
    $filtered = filterConcepts($result['concepts'] ?? [], $item['paragraph'], $paragraphType);

    return [
        'index'    => $item['index'],
        'hash'     => $item['hash'],
        'text'     => $item['paragraph'],
        'concepts' => $filtered,
        'rawCount' => count($result['concepts'] ?? []),
    ];
}

function extractConcepts(array $paragraphs, string $sourceFile): array
{
    ensureDir(PATHS['output']);

    $sourceHash = hashText(implode("\n\n", $paragraphs));
    $existing   = readJsonIfExists(PATHS['concepts']);
    $cliConfig  = CLI_CONFIG;
    $shouldReuse     = $existing && ($existing['sourceFile'] ?? '') === $sourceFile && !$cliConfig['force'];
    $sameSourceHash  = ($existing['sourceHash'] ?? '') === $sourceHash;
    $sameModel       = ($existing['model'] ?? '')      === MODELS['extract'];

    if ($shouldReuse && $sameSourceHash && $sameModel) {
        return $existing;
    }

    $conceptsData = $shouldReuse
        ? $existing
        : ['sourceFile' => $sourceFile, 'model' => MODELS['extract'], 'paragraphs' => []];

    $entryByIndex = [];
    foreach ($conceptsData['paragraphs'] as $paragraph) {
        $entryByIndex[$paragraph['index']] = $paragraph;
    }

    $pending       = [];
    $total         = count($paragraphs);
    $currentIndices = range(0, $total - 1);

    foreach ($paragraphs as $index => $paragraph) {
        $paragraphHash = hashText($paragraph);
        $cached        = $entryByIndex[$index] ?? null;

        if ($cached && ($cached['hash'] ?? '') === $paragraphHash && !$cliConfig['force']) {
            echo "  [" . ($index + 1) . "/$total] Cached\n";
            continue;
        }

        $pending[] = ['index' => $index, 'paragraph' => $paragraph, 'hash' => $paragraphHash];
    }

    if (empty($pending)) {
        updateAndPersist($conceptsData, $entryByIndex, $sourceHash, $currentIndices);
        return $conceptsData;
    }

    $batches = chunkArray($pending, EXTRACT_CONCURRENCY);
    echo "  Processing " . count($pending) . " paragraphs (" . EXTRACT_CONCURRENCY . " per batch)\n";

    foreach ($batches as $batchIndex => $batch) {
        $indices = implode(', ', array_map(fn($item) => $item['index'] + 1, $batch));
        echo "  [batch " . ($batchIndex + 1) . "/" . count($batches) . "] Paragraphs: $indices\n";

        foreach ($batch as $item) {
            $result  = extractSingleParagraph($item, $total);
            $dropped = $result['rawCount'] - count($result['concepts']);

            $entryByIndex[$result['index']] = [
                'index'    => $result['index'],
                'hash'     => $result['hash'],
                'text'     => $result['text'],
                'concepts' => $result['concepts'],
            ];

            $dropInfo = $dropped > 0 ? " (filtered $dropped)" : '';
            echo "    ✓ [" . ($result['index'] + 1) . "] " . count($result['concepts']) . " concepts$dropInfo\n";
        }

        updateAndPersist($conceptsData, $entryByIndex, $sourceHash, $currentIndices);
    }

    return $conceptsData;
}
