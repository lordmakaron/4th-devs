<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api.php';
require_once __DIR__ . '/../utils/file.php';
require_once __DIR__ . '/../utils/hash.php';
require_once __DIR__ . '/../schemas/dedupe.php';
require_once __DIR__ . '/../prompts/dedupe.php';
require_once __DIR__ . '/extract.php';

function dedupeConcepts(array $conceptsData): array
{
    $existing  = readJsonIfExists(PATHS['dedupe']);
    $cliConfig = CLI_CONFIG;

    $sameSource      = $existing && ($existing['sourceFile'] ?? '') === ($conceptsData['sourceFile'] ?? '');
    $sameCounts      = ($existing['paragraphCount'] ?? -1) === ($conceptsData['paragraphCount'] ?? 0)
                    && ($existing['conceptCount'] ?? -1)   === ($conceptsData['conceptCount'] ?? 0);
    $sameSourceHash  = ($existing['sourceHash'] ?? '')    === ($conceptsData['sourceHash'] ?? '');
    $sameConceptsHash = ($existing['conceptsHash'] ?? '') === ($conceptsData['conceptsHash'] ?? '');

    if ($sameSource && $sameCounts && $sameSourceHash && $sameConceptsHash && !$cliConfig['force']) {
        echo "   Using cached dedupe data\n";
        if (empty($existing['dedupeHash'])) {
            $existing['dedupeHash'] = hashObject($existing['groups'] ?? []);
            safeWriteJson(PATHS['dedupe'], $existing);
        }
        return $existing;
    }

    $conceptEntries = [];
    $id             = 0;
    foreach (buildConceptEntries($conceptsData) as $concept) {
        if (!empty($concept['needsSearch'])) {
            $conceptEntries[] = array_merge(['id' => $id], $concept);
        }
        $id++;
    }

    if (empty($conceptEntries)) {
        $empty = [
            'sourceFile'     => $conceptsData['sourceFile'] ?? '',
            'model'          => MODELS['extract'],
            'sourceHash'     => $conceptsData['sourceHash'] ?? '',
            'conceptsHash'   => $conceptsData['conceptsHash'] ?? '',
            'paragraphCount' => $conceptsData['paragraphCount'] ?? 0,
            'conceptCount'   => $conceptsData['conceptCount'] ?? 0,
            'dedupeHash'     => hashObject([]),
            'groups'         => [],
        ];
        safeWriteJson(PATHS['dedupe'], $empty);
        return $empty;
    }

    $input = buildDedupePrompt($conceptEntries);

    $data = callResponses([
        'model'      => MODELS['extract'],
        'input'      => $input,
        'textFormat' => getDedupeSchema(),
        'reasoning'  => ['effort' => 'medium'],
    ]);

    $result = parseJsonOutput($data, 'concept dedupe');

    $dedupeData = [
        'sourceFile'     => $conceptsData['sourceFile'] ?? '',
        'model'          => MODELS['extract'],
        'sourceHash'     => $conceptsData['sourceHash'] ?? '',
        'conceptsHash'   => $conceptsData['conceptsHash'] ?? '',
        'paragraphCount' => $conceptsData['paragraphCount'] ?? 0,
        'conceptCount'   => $conceptsData['conceptCount'] ?? 0,
        'dedupeHash'     => hashObject($result['groups']),
        'groups'         => $result['groups'],
    ];

    safeWriteJson(PATHS['dedupe'], $dedupeData);
    return $dedupeData;
}
