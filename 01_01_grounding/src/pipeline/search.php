<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api.php';
require_once __DIR__ . '/../utils/file.php';
require_once __DIR__ . '/../utils/text.php';
require_once __DIR__ . '/../schemas/search.php';
require_once __DIR__ . '/../prompts/search.php';
require_once __DIR__ . '/extract.php';

const SEARCH_CONCURRENCY         = 5;
const OPENROUTER_ONLINE_SUFFIX   = ':online';
const OPENAI_SEARCH_INCLUDE      = ['web_search_call.action.sources'];

function resolveSearchModel(): string
{
    $model = MODELS['search'];

    if (AI_PROVIDER !== 'openrouter') {
        return $model;
    }

    return str_ends_with($model, OPENROUTER_ONLINE_SUFFIX)
        ? $model
        : $model . OPENROUTER_ONLINE_SUFFIX;
}

function buildSearchRequest(string $model, string $input): array
{
    if (AI_PROVIDER === 'openrouter') {
        return [
            'model'      => $model,
            'input'      => $input,
            'textFormat' => getSearchSchema(),
        ];
    }

    return [
        'model'      => $model,
        'input'      => $input,
        'tools'      => [['type' => 'web_search']],
        'include'    => OPENAI_SEARCH_INCLUDE,
        'textFormat' => getSearchSchema(),
    ];
}

function searchSingleConcept(array $concept, string $model): array
{
    $input   = buildSearchPrompt($concept);
    $request = buildSearchRequest($model, $input);

    $data    = callResponses($request);
    $result  = parseJsonOutput($data, "search: " . ($concept['canonical'] ?? ''));
    $sources = getWebSources($data);

    return array_merge(
        ['canonical' => $concept['canonical']],
        $result,
        ['rawSources' => $sources]
    );
}

function searchConcepts(array $conceptsData, array $dedupeData): array
{
    $searchModel = resolveSearchModel();
    $cliConfig   = CLI_CONFIG;

    if (AI_PROVIDER === 'openrouter') {
        fwrite(STDERR, "   Using OpenRouter provider with web plugin via model: $searchModel\n");
    }

    $existing   = readJsonIfExists(PATHS['search']);
    $shouldReuse = $existing && ($existing['sourceFile'] ?? '') === ($conceptsData['sourceFile'] ?? '') && !$cliConfig['force'];
    $sameSourceHash = ($existing['sourceHash'] ?? '') === ($conceptsData['sourceHash'] ?? '');
    $sameDedupeHash = ($existing['dedupeHash'] ?? '') === ($dedupeData['dedupeHash'] ?? '');
    $sameModel      = ($existing['model'] ?? '')       === $searchModel;

    $shouldReset = !$sameSourceHash || !$sameDedupeHash || !$sameModel;

    if ($shouldReuse && $shouldReset) {
        echo "   Search cache invalidated (source, dedupe, or model changed)\n";
    }

    $base = ($shouldReuse && !$shouldReset)
        ? $existing
        : [
            'sourceFile'         => $conceptsData['sourceFile'] ?? '',
            'model'              => $searchModel,
            'sourceHash'         => $conceptsData['sourceHash'] ?? '',
            'dedupeHash'         => $dedupeData['dedupeHash'] ?? '',
            'resultsByCanonical' => [],
        ];

    if ($shouldReuse && !$shouldReset) {
        if (empty($base['sourceHash'])) {
            $base['sourceHash'] = $conceptsData['sourceHash'] ?? '';
        }
        if (empty($base['dedupeHash'])) {
            $base['dedupeHash'] = $dedupeData['dedupeHash'] ?? '';
        }
    }

    // Build concept entries indexed by id
    $conceptEntries = [];
    $id = 0;
    foreach (buildConceptEntries($conceptsData) as $concept) {
        if (!empty($concept['needsSearch'])) {
            $conceptEntries[$id] = array_merge(['id' => $id], $concept);
        }
        $id++;
    }

    // Build canonical concepts from dedupe groups
    $canonicalConcepts = [];
    foreach ($dedupeData['groups'] as $group) {
        $memberEntries = [];
        foreach ($group['ids'] as $memberId) {
            if (isset($conceptEntries[$memberId])) {
                $memberEntries[] = $conceptEntries[$memberId];
            }
        }

        $searchQuery = null;
        foreach ($memberEntries as $entry) {
            if (!empty($entry['searchQuery'])) {
                $searchQuery = $entry['searchQuery'];
                break;
            }
        }
        if ($searchQuery === null) {
            $searchQuery = $group['canonical'];
        }

        $surfaceForms = [];
        foreach ($memberEntries as $entry) {
            foreach ($entry['surfaceForms'] ?? [] as $form) {
                $surfaceForms[] = $form;
            }
        }

        $canonicalConcepts[] = [
            'canonical'   => $group['canonical'],
            'aliases'     => $group['aliases'] ?? [],
            'searchQuery' => $searchQuery,
            'surfaceForms' => array_values(array_unique($surfaceForms)),
        ];
    }

    $pending = array_filter(
        $canonicalConcepts,
        fn($concept) => empty($base['resultsByCanonical'][$concept['canonical']])
    );
    $pending = array_values($pending);

    if (empty($pending) && $sameSourceHash && $sameDedupeHash) {
        echo "   Using cached search results\n";
        return $base;
    }

    echo "   " . count($pending) . " concepts to search (" . SEARCH_CONCURRENCY . " per batch)\n";
    $batches = chunkArray($pending, SEARCH_CONCURRENCY);

    foreach ($batches as $batchIndex => $batch) {
        if (empty($batch)) {
            continue;
        }

        $batchLabels = implode(', ', array_map(fn($c) => $c['canonical'], $batch));
        echo "  [batch " . ($batchIndex + 1) . "/" . count($batches) . "] Searching: $batchLabels\n";

        foreach ($batch as $concept) {
            $result = searchSingleConcept($concept, $searchModel);

            $base['resultsByCanonical'][$result['canonical']] = [
                'canonical' => $result['canonical'],
                'summary'   => $result['summary'] ?? '',
                'keyPoints' => $result['keyPoints'] ?? [],
                'sources'   => $result['sources'] ?? [],
            ];

            $sourceCount = count($result['sources'] ?? []);
            echo "    ✓ {$result['canonical']} ($sourceCount sources)\n";
        }

        safeWriteJson(PATHS['search'], $base);
    }

    return $base;
}
