<?php

function buildDedupePrompt(array $conceptEntries): string
{
    $conceptsJson = json_encode($conceptEntries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return <<<PROMPT
Group concepts only when they are strict paraphrases of the same claim or term.
Do NOT group related-but-distinct ideas (cause/effect, property vs consequence, part/whole, example vs category, metric vs definition).
Only group items with the same category; if categories differ, keep them separate even if similar.
Every id must appear in exactly one group.
Pick a concise canonical label that preserves the full meaning.
aliases must be full alternative labels, not fragments or partial phrases.
If unsure, do not group.
Return JSON only.
<concepts>
{$conceptsJson}
</concepts>

PROMPT;
}
