<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api.php';
require_once __DIR__ . '/../utils/file.php';
require_once __DIR__ . '/../utils/text.php';
require_once __DIR__ . '/../schemas/ground.php';
require_once __DIR__ . '/../prompts/ground.php';
require_once __DIR__ . '/extract.php';

const GROUND_CONCURRENCY = 5;

function escapeAttribute(string $value): string
{
    return str_replace(
        ['&',    '"',      "'",     '<',    '>'],
        ['&amp;', '&quot;', '&#39;', '&lt;', '&gt;'],
        $value
    );
}

function escapeHtml(string $text): string
{
    return str_replace(
        ['&',    '<',   '>'],
        ['&amp;', '&lt;', '&gt;'],
        $text
    );
}

function buildGroundingItems(array $conceptsData, array $dedupeData, array $searchData): array
{
    $conceptEntries = [];
    $id = 0;
    foreach (buildConceptEntries($conceptsData) as $concept) {
        if (!empty($concept['needsSearch'])) {
            $conceptEntries[$id] = array_merge(['id' => $id], $concept);
        }
        $id++;
    }

    $items = [];

    foreach ($dedupeData['groups'] as $group) {
        $members = [];
        foreach ($group['ids'] as $memberId) {
            if (isset($conceptEntries[$memberId])) {
                $members[] = $conceptEntries[$memberId];
            }
        }

        $surfaceForms = [];
        foreach ($members as $member) {
            foreach ($member['surfaceForms'] ?? [] as $form) {
                $surfaceForms[] = $form;
            }
        }

        $paragraphIndices = [];
        foreach ($members as $member) {
            $paragraphIndices[$member['paragraphIndex']] = true;
        }
        $paragraphIndices = array_keys($paragraphIndices);

        $searchResult = $searchData['resultsByCanonical'][$group['canonical']] ?? null;

        $sources = [];
        foreach ($searchResult['sources'] ?? [] as $source) {
            if (!empty($source['url'])) {
                $sources[] = [
                    'title' => $source['title'] ?? null,
                    'url'   => $source['url'],
                ];
            }
        }

        $summary  = truncateText($searchResult['summary'] ?? '', 420);
        $dataAttr = escapeAttribute(json_encode(['summary' => $summary, 'sources' => $sources]));

        $uniqueSurfaces = array_values(array_unique($surfaceForms));
        usort($uniqueSurfaces, fn($a, $b) => mb_strlen($b) - mb_strlen($a));

        $items[] = [
            'label'            => $group['canonical'],
            'surfaceForms'     => $uniqueSurfaces,
            'paragraphIndices' => $paragraphIndices,
            'dataAttr'         => $dataAttr,
        ];
    }

    return $items;
}

function convertToBasicHtml(string $paragraph): string
{
    $trimmed = trim($paragraph);

    if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $m)) {
        $level = strlen($m[1]);
        $text  = $m[2];
        return "<h{$level}>" . escapeHtml($text) . "</h{$level}>";
    }

    if (preg_match('/^[-*]\s+/m', $trimmed)) {
        $lines = explode("\n", $trimmed);
        $items = [];
        foreach ($lines as $line) {
            if (preg_match('/^[-*]\s+/', $line)) {
                $itemText = preg_replace('/^[-*]\s+/', '', $line);
                $items[]  = '<li>' . escapeHtml($itemText) . '</li>';
            }
        }
        return "<ul>\n" . implode("\n", $items) . "\n</ul>";
    }

    return '<p>' . escapeHtml($trimmed) . '</p>';
}

function groundSingleParagraph(string $paragraph, array $relevantItems, int $index, int $total): string
{
    if (empty($relevantItems)) {
        return convertToBasicHtml($paragraph);
    }

    $groundingItemsForPrompt = array_map(fn($item) => [
        'label'        => $item['label'],
        'surfaceForms' => $item['surfaceForms'],
        'dataAttr'     => $item['dataAttr'],
    ], $relevantItems);

    $input = buildGroundPrompt($paragraph, $groundingItemsForPrompt, $index + 1, $total);

    $data   = callResponses([
        'model'      => MODELS['ground'],
        'input'      => $input,
        'textFormat' => getGroundSchema(),
        'reasoning'  => ['effort' => 'medium'],
    ]);

    $result = parseJsonOutput($data, "ground: paragraph " . ($index + 1));
    return $result['html'] ?? '';
}

function generateAndApplyTemplate(string $markdown, array $conceptsData, array $dedupeData, array $searchData): string
{
    $groundingItems = buildGroundingItems($conceptsData, $dedupeData, $searchData);
    $paragraphs     = splitParagraphs($markdown);
    $total          = count($paragraphs);

    echo "   Processing $total paragraphs (" . GROUND_CONCURRENCY . " per batch)\n";

    $batches  = chunkArray(
        array_map(fn($p, $i) => ['paragraph' => $p, 'index' => $i], $paragraphs, array_keys($paragraphs)),
        GROUND_CONCURRENCY
    );

    $htmlParts = array_fill(0, $total, '');

    foreach ($batches as $batchIndex => $batch) {
        $indices = implode(', ', array_map(fn($item) => $item['index'] + 1, $batch));
        echo "  [batch " . ($batchIndex + 1) . "/" . count($batches) . "] Paragraphs: $indices\n";

        foreach ($batch as $item) {
            $relevantItems = array_values(array_filter(
                $groundingItems,
                fn($gi) => in_array($item['index'], $gi['paragraphIndices'], true)
            ));

            $html = groundSingleParagraph($item['paragraph'], $relevantItems, $item['index'], $total);
            $htmlParts[$item['index']] = $html;

            echo "    ✓ [" . ($item['index'] + 1) . "] grounded\n";
        }
    }

    $htmlChunk = implode("\n\n", $htmlParts);

    $template = file_get_contents(PATHS['template']);
    if ($template === false) {
        throw new \RuntimeException('Could not read template file: ' . PATHS['template']);
    }

    if (!str_contains($template, '<!--CONTENT-->')) {
        throw new \RuntimeException('Template is missing <!--CONTENT--> placeholder.');
    }

    $filled = str_replace('<!--CONTENT-->', $htmlChunk, $template);

    ensureDir(PATHS['output']);
    file_put_contents(PATHS['grounded'], $filled);

    return PATHS['grounded'];
}
