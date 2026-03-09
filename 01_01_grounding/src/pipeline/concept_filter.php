<?php

require_once __DIR__ . '/../schemas/categories.php';

const MAX_BODY   = 5;
const MAX_HEADER = 1;
const MAX_SURFACE_FORM_LENGTH = 100;

function stripMarkdownSyntax(string $text): string
{
    return trim(preg_replace('/^#{1,6}\s+/', '', $text));
}

function normalizeSurfaceForms(mixed $surfaceForms, string $paragraph): array
{
    if (!is_array($surfaceForms)) {
        return [];
    }

    $cleanParagraph = stripMarkdownSyntax($paragraph);
    $unique         = [];

    foreach ($surfaceForms as $form) {
        if (!is_string($form)) {
            continue;
        }
        $trimmed = trim($form);
        if ($trimmed === '') {
            continue;
        }
        $trimmed = stripMarkdownSyntax($trimmed);
        if ($trimmed === '') {
            continue;
        }
        if (mb_strlen($trimmed) > MAX_SURFACE_FORM_LENGTH) {
            continue;
        }
        if (!str_contains($paragraph, $trimmed) && !str_contains($cleanParagraph, $trimmed)) {
            continue;
        }
        $unique[$trimmed] = true;
    }

    return array_keys($unique);
}

function normalizeConcept(mixed $concept, string $paragraph): ?array
{
    if (!is_array($concept)) {
        return null;
    }

    $label = isset($concept['label']) && is_string($concept['label']) ? trim($concept['label']) : '';
    if ($label === '') {
        return null;
    }

    $category = isset($concept['category']) && is_string($concept['category'])
        ? strtolower(trim($concept['category']))
        : 'concept';

    $normalizedCategory = in_array($category, CONCEPT_CATEGORIES, true) ? $category : 'concept';

    $needsSearch = !empty($concept['needsSearch']);
    $searchQuery = isset($concept['searchQuery']) && is_string($concept['searchQuery'])
        ? trim($concept['searchQuery'])
        : null;

    if (!$needsSearch) {
        $searchQuery = null;
    } elseif ($searchQuery === null || $searchQuery === '') {
        $searchQuery = $label;
    }

    $reason      = isset($concept['reason']) && is_string($concept['reason']) ? trim($concept['reason']) : '';
    $surfaceForms = normalizeSurfaceForms($concept['surfaceForms'] ?? [], $paragraph);

    if (empty($surfaceForms)) {
        return null;
    }

    return [
        'label'        => $label,
        'category'     => $normalizedCategory,
        'needsSearch'  => $needsSearch,
        'searchQuery'  => $searchQuery,
        'reason'       => $reason,
        'surfaceForms' => $surfaceForms,
    ];
}

function filterConcepts(array $concepts, string $paragraph, string $paragraphType): array
{
    $maxCount = $paragraphType === 'header' ? MAX_HEADER : MAX_BODY;

    if (!is_array($concepts)) {
        return [];
    }

    $normalized = [];
    foreach ($concepts as $concept) {
        $norm = normalizeConcept($concept, $paragraph);
        if ($norm !== null) {
            $normalized[] = $norm;
        }
    }

    // Dedupe by label, keep first occurrence
    $deduped = [];
    foreach ($normalized as $concept) {
        if (!isset($deduped[$concept['label']])) {
            $deduped[$concept['label']] = $concept;
        }
    }

    $sorted = array_values($deduped);
    usort($sorted, fn($a, $b) => mb_strlen($b['label']) - mb_strlen($a['label']));

    return array_slice($sorted, 0, $maxCount);
}
