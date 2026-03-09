<?php

function buildSearchPrompt(array $concept): string
{
    $canonical   = $concept['canonical'] ?? '';
    $searchQuery = $concept['searchQuery'] ?? '';
    $aliases     = $concept['aliases'] ?? [];

    $prompt = "Use web search to verify and expand on this concept.\n";
    $prompt .= "Search thoroughly and provide accurate, factual information.\n";
    $prompt .= "Return JSON only, matching the schema.\n\n";
    $prompt .= "Requirements:\n";
    $prompt .= "- Write a concise summary grounded in search results\n";
    $prompt .= "- Include 2-4 key points with specific facts\n";
    $prompt .= "- List sources with titles and URLs from the search\n\n";
    $prompt .= "Concept: $canonical";

    if ($searchQuery !== '') {
        $prompt .= "\nSearch query: $searchQuery";
    }

    if (!empty($aliases)) {
        $prompt .= "\nAlso known as: " . implode(', ', $aliases);
    }

    return $prompt;
}
