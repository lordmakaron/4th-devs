<?php
/**
 * S01E04 — PDF Report Generation Agent (PHP)
 *
 * CONCEPT
 * -------
 * An autonomous document generation agent that creates professional PDF
 * reports from HTML templates with AI-generated images. Uses a dark-themed
 * design system (Lexend font, near-black backgrounds, cell-shaded styling).
 *
 *   User query → Agent loop → [Tool calls <-> Results] → PDF output
 *
 * DESIGN SYSTEM
 * -------------
 * workspace/template.html contains a complete dark-themed CSS framework:
 *  - Colors: #0d0d0d background, #e5e5e5 text, #6b9fff accent
 *  - Fonts: Lexend (body) + IBM Plex Mono (code)
 *  - Layout: .page containers = one printed page each
 *  - Components: metrics cards, note boxes, status indicators, tables
 *
 * workspace/style-guide.md documents the design system rules.
 *
 * THREE MODELS, THREE ROLES
 * -------------------------
 *  - Orchestrator (gpt-4.1)                  — plans, reasons, calls tools
 *  - Image generator (gemini-3.1-flash)      — produces images from prompts
 *  - Vision analyzer (gpt-4.1)               — evaluates image quality
 *
 * TOOLS
 * -----
 *  - read_file        Read text/JSON/HTML files
 *  - write_file       Write/create files (HTML documents, style specs)
 *  - list_directory   List files in workspace folders
 *  - copy_file        Copy template.html to workspace/html/ for editing
 *  - create_image     Generate images via Gemini (OpenRouter)
 *  - analyze_image    Vision model quality check
 *  - html_to_pdf      Convert HTML → PDF using Chrome/Puppeteer CLI
 *
 * AGENT LOOP
 * ----------
 *   while (steps < 50):
 *       response = LLM(messages + tools)
 *       if no tool_calls → return text (done)
 *       for each tool_call: execute → append result to messages
 *       loop back
 *
 * IMAGE CONSISTENCY
 * -----------------
 * Before generating the first image, the agent must define a visual style
 * and write it to workspace/image-style.txt. Every subsequent create_image
 * call includes that style verbatim in the prompt to ensure visual cohesion
 * across all images in the document.
 *
 * TYPICAL EXECUTION FLOW
 * ----------------------
 *  1. Agent reads workspace/template.html and workspace/style-guide.md
 *  2. Agent copies template to workspace/html/{doc_name}.html
 *  3. Agent edits the HTML <body> — adds content, images, tables
 *  4. Agent defines image style → workspace/image-style.txt
 *  5. Agent calls create_image for each illustration needed
 *  6. Agent updates HTML with absolute image paths
 *  7. Agent calls html_to_pdf with print_background: true
 *  8. Agent reports the output PDF path
 */

require __DIR__ . '/../../lib/init.php';

// =============================================================================
// CONFIG
// =============================================================================

const WORKSPACE = __DIR__;
const MAX_STEPS = 50;

$orModel     = 'openai/gpt-4.1';
$visionModel = 'openai/gpt-4.1';
$imageModel  = 'google/gemini-3.1-flash-image-preview';

$systemPrompt = <<<'PROMPT'
You are an autonomous document generation agent.

## GOAL
Create clear, focused PDF reports. Perfection is achieved not when there is nothing more to add, but when there is nothing left to remove.

## RESOURCES
- workspace/template.html  → HTML template with embedded styles
- workspace/style-guide.md → Design system rules and patterns
- workspace/input/         → Available source assets
- workspace/output/        → Generated PDFs and images
- workspace/html/          → Working HTML files

Read template.html and style-guide.md first to understand the design system.

## TOOLS
- File tools: read_file, write_file, list_directory, copy_file
- create_image: generate images via Gemini → saves to workspace/output/
- analyze_image: vision-based quality analysis of generated images
- html_to_pdf: convert HTML to PDF (requires print_background: true for dark theme)

## REASONING

1. CONTENT
   Understand what you're communicating before writing.
   Every element must earn its place — if it doesn't clarify, it clutters.
   Prefer fewer, stronger points over comprehensive coverage.
   Cut redundancy. Merge related sections. Remove filler.

2. ASSETS
   Know what's available (workspace/input/) before creating new.
   Track what you generate — reuse, don't duplicate.
   An image should add information text cannot convey efficiently.
   No decorative images. No placeholder content.

3. IMAGE CONSISTENCY
   All generated images in a document must share the same visual style.
   Before generating the first image, define the style explicitly:
   - Medium (sketch, illustration, photo-realistic, diagram, etc.)
   - Line weight and rendering approach
   - Color palette or mono treatment
   - Level of detail and abstraction

   Write the style definition to workspace/image-style.txt for reference.
   Every subsequent create_image call must include this style in the prompt.
   Review generated images — if style drifts, regenerate with stricter prompt.

   Style consistency > individual image quality.
   A cohesive set of simple images beats a mixed set of polished ones.

4. STRUCTURE
   Let content determine structure, not templates.
   Multi-page: each page should have a clear purpose.
   Headings are navigation — if a section has no content worth finding, remove the heading.
   White space is content. Don't fill every gap.

5. ITERATION
   After drafting, review with fresh eyes.
   Ask: "What can I remove without losing meaning?"
   Ask: "Does this serve the reader or just fill space?"
   Simplify until further simplification would harm clarity.

## RULES

1. TEMPLATE
   Read workspace/template.html, copy its contents to workspace/html/{document_name}.html.
   Never edit template.html directly — it's the master reference.
   In the copy: preserve <head> and styles, modify only <body> content.
   Each page wrapped in .page div.

2. IMAGE PATHS
   HTML requires absolute filesystem paths for images.
   Tools return project_root and absolute_path — use these to construct paths.
   Pattern: {project_root}/workspace/output/{filename}
   Verify files exist before referencing.

3. IMAGE STYLE
   If generating multiple images, write style spec to workspace/image-style.txt first.
   Include style spec verbatim in every create_image prompt.

4. OUTPUT
   HTML to workspace/html/, PDF to workspace/output/.
   Always: print_background: true.

Run autonomously. Report the output path when complete.
PROMPT;

// =============================================================================
// TOOL DEFINITIONS
// =============================================================================

function getTools(): array
{
    return [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'create_image',
                'description' => 'Generate or edit images using Gemini. If reference_images is empty, generates from prompt. If reference_images provided, edits/combines them based on the prompt.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'prompt' => [
                            'type'        => 'string',
                            'description' => 'Description of image to generate, or instructions for editing reference images. Be specific about style, composition, colors, changes.',
                        ],
                        'output_name' => [
                            'type'        => 'string',
                            'description' => "Base name for the output file (without extension). Will be saved to workspace/output/",
                        ],
                        'reference_images' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string'],
                            'description' => "Optional paths to reference image(s) for editing. Empty array = generate from scratch.",
                        ],
                        'aspect_ratio' => [
                            'type'        => 'string',
                            'enum'        => ['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'],
                            'description' => 'Aspect ratio of the output image. Default is 1:1.',
                        ],
                        'image_size' => [
                            'type'        => 'string',
                            'enum'        => ['1k', '2k', '4k'],
                            'description' => 'Resolution of the output image. Default is 1k.',
                        ],
                    ],
                    'required' => ['prompt', 'output_name', 'reference_images'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'analyze_image',
                'description' => 'Analyze a generated or edited image for quality issues. Checks for prompt adherence, visual artifacts, style consistency, and common AI generation mistakes.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'image_path' => [
                            'type'        => 'string',
                            'description' => 'Path to the image file relative to the project root',
                        ],
                        'original_prompt' => [
                            'type'        => 'string',
                            'description' => 'The original prompt or instructions used to generate/edit the image',
                        ],
                        'check_aspects' => [
                            'type'        => 'array',
                            'items'       => [
                                'type' => 'string',
                                'enum' => ['prompt_adherence', 'visual_artifacts', 'anatomy', 'text_rendering', 'style_consistency', 'composition'],
                            ],
                            'description' => 'Specific aspects to check. If not provided, checks all aspects.',
                        ],
                    ],
                    'required' => ['image_path', 'original_prompt'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'html_to_pdf',
                'description' => 'Convert an HTML file to PDF. The HTML file should already exist in workspace/html/. Images in the HTML must use absolute paths.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'html_path' => [
                            'type'        => 'string',
                            'description' => 'Path to the HTML file relative to project root (e.g., workspace/html/report.html)',
                        ],
                        'output_name' => [
                            'type'        => 'string',
                            'description' => 'Base name for the output PDF file (without extension). Will be saved to workspace/output/',
                        ],
                        'options' => [
                            'type'        => 'object',
                            'description' => 'PDF generation options',
                            'properties'  => [
                                'format' => [
                                    'type' => 'string',
                                    'enum' => ['A4', 'Letter'],
                                    'description' => 'Page format. Default: A4',
                                ],
                                'landscape' => [
                                    'type'        => 'boolean',
                                    'description' => 'Use landscape orientation. Default: false',
                                ],
                                'margin' => [
                                    'type'        => 'object',
                                    'description' => "Page margins in CSS units (e.g., '20mm')",
                                    'properties'  => [
                                        'top'    => ['type' => 'string'],
                                        'right'  => ['type' => 'string'],
                                        'bottom' => ['type' => 'string'],
                                        'left'   => ['type' => 'string'],
                                    ],
                                ],
                                'print_background' => [
                                    'type'        => 'boolean',
                                    'description' => 'Include CSS backgrounds. Default: true',
                                ],
                            ],
                        ],
                    ],
                    'required' => ['html_path', 'output_name'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'list_directory',
                'description' => 'List files and subdirectories at the given path relative to the script directory.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => "Directory path relative to the script directory (e.g. 'workspace/input' or 'workspace/output')",
                        ],
                    ],
                    'required' => ['path'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'read_file',
                'description' => 'Read the text contents of a file relative to the script directory.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => "File path relative to the script directory (e.g. 'workspace/template.html')",
                        ],
                    ],
                    'required' => ['path'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'write_file',
                'description' => 'Write text content to a file relative to the script directory. Creates parent directories automatically.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => "File path relative to the script directory (e.g. 'workspace/html/report.html')",
                        ],
                        'content' => [
                            'type'        => 'string',
                            'description' => 'The text content to write to the file',
                        ],
                    ],
                    'required' => ['path', 'content'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'copy_file',
                'description' => 'Copy a file from source to destination (both relative to the script directory). Creates destination directories automatically.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'source' => [
                            'type'        => 'string',
                            'description' => 'Source file path relative to the script directory',
                        ],
                        'destination' => [
                            'type'        => 'string',
                            'description' => 'Destination file path relative to the script directory',
                        ],
                    ],
                    'required' => ['source', 'destination'],
                ],
            ],
        ],
    ];
}

// =============================================================================
// HELPERS
// =============================================================================

function getMimeType(string $filepath): string
{
    $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

    return match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png'         => 'image/png',
        'gif'         => 'image/gif',
        'webp'        => 'image/webp',
        default       => 'image/png',
    };
}

function getExtension(string $mimeType): string
{
    return match ($mimeType) {
        'image/jpeg' => '.jpg',
        'image/png'  => '.png',
        'image/gif'  => '.gif',
        'image/webp' => '.webp',
        default      => '.png',
    };
}

function generateFilename(string $prefix, string $ext): string
{
    $timestamp = (int)(microtime(true) * 1000);

    return "{$prefix}_{$timestamp}{$ext}";
}

function normalizeImageSize(string $size): string
{
    return str_ends_with($size, 'k') ? strtoupper($size) : $size;
}

// =============================================================================
// TOOL HANDLERS
// =============================================================================

function handleTool(string $name, array $args): array
{
    return match ($name) {
        'create_image'   => toolCreateImage($args),
        'analyze_image'  => toolAnalyzeImage($args),
        'html_to_pdf'    => toolHtmlToPdf($args),
        'list_directory' => toolListDirectory($args),
        'read_file'      => toolReadFile($args),
        'write_file'     => toolWriteFile($args),
        'copy_file'      => toolCopyFile($args),
        default          => ['error' => "Unknown tool: {$name}"],
    };
}

/**
 * Generate or edit an image using Gemini via OpenRouter.
 */
function toolCreateImage(array $args): array
{
    global $env, $scriptLabel, $imageModel;

    $prompt          = $args['prompt'];
    $outputName      = $args['output_name'];
    $referenceImages = $args['reference_images'] ?? [];
    $aspectRatio     = $args['aspect_ratio'] ?? null;
    $imageSize       = $args['image_size'] ?? null;
    $isEditing       = !empty($referenceImages);
    $mode            = $isEditing ? 'edit' : 'generate';

    logMsg('IMAGE', ($isEditing ? 'Editing' : 'Generating') . ' — ' . substr($prompt, 0, 100) . '...', 'info');

    // Build content: text prompt + optional reference images
    if ($isEditing)
    {
        $content = [['type' => 'text', 'text' => $prompt]];

        foreach ($referenceImages as $imagePath)
        {
            $fullPath = WORKSPACE . '/' . $imagePath;

            if (!file_exists($fullPath))
            {
                return ['error' => "Reference image not found: {$imagePath}"];
            }

            $mimeType = getMimeType($imagePath);
            $base64   = base64_encode(file_get_contents($fullPath));
            $dataUrl  = "data:{$mimeType};base64,{$base64}";

            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]];
        }
    }
    else
    {
        $content = $prompt;
    }

    // Build image config
    $imageConfig = [];
    if ($aspectRatio) $imageConfig['aspect_ratio'] = $aspectRatio;
    if ($imageSize)   $imageConfig['image_size']   = normalizeImageSize($imageSize);

    $payload = [
        'model'      => $imageModel,
        'messages'   => [['role' => 'user', 'content' => $content]],
        'modalities' => ['image', 'text'],
    ];

    if (!empty($imageConfig))
    {
        $payload['image_config'] = $imageConfig;
    }

    $result = curlPost(
        'https://openrouter.ai/api/v1/chat/completions',
        $payload,
        [
            'Authorization: Bearer ' . ($env['OPENROUTER_API_KEY'] ?? ''),
            'HTTP-Referer: ' . APP_REF,
            'X-Title: ' . ($scriptLabel ?? 'unknown'),
        ]
    );

    if ($result['error'] || $result['httpCode'] !== 200)
    {
        return ['error' => "Image {$mode} failed (HTTP {$result['httpCode']}): {$result['error']}"];
    }

    $decoded = json_decode($result['body'], true);

    if (isset($decoded['usage']))
    {
        $u = $decoded['usage'];
        logMsg('GEMINI', "{$u['prompt_tokens']} in → {$u['completion_tokens']} out", 'secondary');
    }

    // Extract generated image
    $images   = $decoded['choices'][0]['message']['images'] ?? [];
    $imageUrl = $images[0]['image_url']['url'] ?? ($images[0]['imageUrl']['url'] ?? null);

    if (!$imageUrl)
    {
        $textContent = $decoded['choices'][0]['message']['content'] ?? 'No response';

        if (is_array($textContent))
        {
            $texts = array_map(fn($p) => is_string($p) ? $p : ($p['text'] ?? ''), $textContent);
            $textContent = implode("\n", $texts);
        }

        return ['error' => "No image output received. Model response: " . substr($textContent, 0, 300)];
    }

    // Parse data URL
    if (!preg_match('/^data:([^;]+);base64,(.+)$/s', $imageUrl, $matches))
    {
        return ['error' => 'Expected image output as a base64 data URL'];
    }

    $outputMimeType = $matches[1];
    $outputData     = $matches[2];

    // Save to workspace/output/
    $outputDir = WORKSPACE . '/workspace/output';
    if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

    $filename   = generateFilename($outputName, getExtension($outputMimeType));
    $outputPath = $outputDir . '/' . $filename;

    file_put_contents($outputPath, base64_decode($outputData));

    $relativePath = "workspace/output/{$filename}";
    $absolutePath = realpath($outputPath) ?: $outputPath;

    logMsg('IMAGE', "Saved: {$relativePath} ({$outputMimeType})", 'success');

    return [
        'success'          => true,
        'mode'             => $mode,
        'output_path'      => $relativePath,
        'absolute_path'    => $absolutePath,
        'project_root'     => WORKSPACE,
        'mime_type'        => $outputMimeType,
        'prompt_used'      => $prompt,
        'reference_images' => $referenceImages,
    ];
}

/**
 * Analyze a generated image for quality using vision model.
 */
function toolAnalyzeImage(array $args): array
{
    global $env, $scriptLabel, $visionModel;

    $imagePath      = $args['image_path'];
    $originalPrompt = $args['original_prompt'];
    $checkAspects   = $args['check_aspects'] ?? [
        'prompt_adherence', 'visual_artifacts', 'anatomy',
        'text_rendering', 'style_consistency', 'composition',
    ];

    $fullPath = WORKSPACE . '/' . $imagePath;

    if (!file_exists($fullPath))
    {
        return ['error' => "File not found: {$imagePath}"];
    }

    $mimeType = getMimeType($imagePath);
    $base64   = base64_encode(file_get_contents($fullPath));
    $dataUrl  = "data:{$mimeType};base64,{$base64}";

    // Build analysis prompt
    $aspectChecks = '';
    $n = 1;
    if (in_array('prompt_adherence', $checkAspects))
        $aspectChecks .= "{$n}. PROMPT ADHERENCE: Does the image accurately represent what was requested? What elements match or are missing?\n" and $n++;
    if (in_array('visual_artifacts', $checkAspects))
        $aspectChecks .= "{$n}. VISUAL ARTIFACTS: Are there any glitches, distortions, blur, noise, or unnatural patterns?\n" and $n++;
    if (in_array('anatomy', $checkAspects))
        $aspectChecks .= "{$n}. ANATOMY: If there are people/animals, check for correct proportions, especially hands, fingers, faces, and limbs.\n" and $n++;
    if (in_array('text_rendering', $checkAspects))
        $aspectChecks .= "{$n}. TEXT RENDERING: If text was requested, is it readable and correctly spelled?\n" and $n++;
    if (in_array('style_consistency', $checkAspects))
        $aspectChecks .= "{$n}. STYLE CONSISTENCY: Is the visual style coherent throughout the image?\n" and $n++;
    if (in_array('composition', $checkAspects))
        $aspectChecks .= "{$n}. COMPOSITION: Is the framing and layout balanced and appropriate?\n" and $n++;

    $analysisPrompt = <<<ANALYSIS
Analyze this AI-generated image for quality issues. The original prompt was:
"{$originalPrompt}"

Please evaluate the following aspects and provide a detailed assessment:

{$aspectChecks}
Provide:
- An overall quality score (1-10)
- List of specific issues found
- Whether the image is acceptable or needs regeneration
- Suggestions for improving the prompt if regeneration is needed
ANALYSIS;

    logMsg('VISION', "Analyzing: {$imagePath}", 'info');

    $payload = [
        'model'    => $visionModel,
        'messages' => [
            [
                'role'    => 'user',
                'content' => [
                    ['type' => 'text',      'text'      => $analysisPrompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                ],
            ],
        ],
    ];

    $result = curlPost(
        'https://openrouter.ai/api/v1/chat/completions',
        $payload,
        [
            'Authorization: Bearer ' . ($env['OPENROUTER_API_KEY'] ?? ''),
            'HTTP-Referer: ' . APP_REF,
            'X-Title: ' . ($scriptLabel ?? 'unknown'),
        ]
    );

    if ($result['error'] || $result['httpCode'] !== 200)
    {
        return ['error' => "Vision call failed (HTTP {$result['httpCode']}): {$result['error']}"];
    }

    $decoded  = json_decode($result['body'], true);
    $analysis = $decoded['choices'][0]['message']['content'] ?? 'No response';

    if (isset($decoded['usage']))
    {
        $u = $decoded['usage'];
        logMsg('VISION', "{$u['prompt_tokens']} in → {$u['completion_tokens']} out", 'secondary');
    }

    logMsg('VISION', substr($analysis, 0, 150) . '...', 'secondary');

    return [
        'success'         => true,
        'image_path'      => $imagePath,
        'original_prompt' => $originalPrompt,
        'aspects_checked' => $checkAspects,
        'analysis'        => $analysis,
    ];
}

/**
 * Convert an HTML file to PDF using Chrome headless.
 *
 * Uses Chrome's built-in --print-to-pdf flag (no Puppeteer dependency needed).
 * Falls back to checking common Chrome/Chromium binary locations.
 */
function toolHtmlToPdf(array $args): array
{
    $htmlPath   = $args['html_path'];
    $outputName = $args['output_name'];
    $options    = $args['options'] ?? [];

    $fullHtmlPath = WORKSPACE . '/' . $htmlPath;

    if (!file_exists($fullHtmlPath))
    {
        return ['error' => "HTML file not found: {$htmlPath}"];
    }

    // Ensure output directory exists
    $outputDir = WORKSPACE . '/workspace/output';
    if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

    $timestamp      = (int)(microtime(true) * 1000);
    $outputFilename = "{$outputName}_{$timestamp}.pdf";
    $outputPath     = $outputDir . '/' . $outputFilename;

    // Find Chrome/Chromium binary
    $chromeBinaries = [
        'google-chrome',
        'google-chrome-stable',
        'chromium',
        'chromium-browser',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
    ];

    $chromeBin = null;
    foreach ($chromeBinaries as $bin)
    {
        $which = trim(shell_exec("which {$bin} 2>/dev/null") ?? '');
        if ($which !== '')
        {
            $chromeBin = $which;
            break;
        }
    }

    if (!$chromeBin)
    {
        return ['error' => 'Chrome/Chromium not found. Install google-chrome or chromium to enable PDF generation.'];
    }

    logMsg('PDF', "Converting {$htmlPath} → {$outputFilename}", 'info');
    logMsg('PDF', "Using: {$chromeBin}", 'secondary');

    // Build Chrome headless command
    $printBackground = ($options['print_background'] ?? true) ? '--print-background' : '';
    $landscape       = ($options['landscape'] ?? false) ? '--landscape' : '';

    // Margins (default 20mm if not specified)
    $margins = $options['margin'] ?? ['top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0'];

    $fileUrl = 'file://' . realpath($fullHtmlPath);

    $cmd = sprintf(
        '%s --headless --disable-gpu --no-sandbox --disable-setuid-sandbox '
        . '--run-all-compositor-stages-before-draw '
        . '%s %s '
        . '--print-to-pdf=%s '
        . '--no-pdf-header-footer '
        . '%s 2>&1',
        escapeshellarg($chromeBin),
        $printBackground,
        $landscape,
        escapeshellarg($outputPath),
        escapeshellarg($fileUrl)
    );

    $output = shell_exec($cmd);

    if (!file_exists($outputPath))
    {
        return ['error' => "PDF generation failed. Chrome output: " . substr($output ?? '', 0, 500)];
    }

    $relativePath = "workspace/output/{$outputFilename}";
    $absolutePath = realpath($outputPath) ?: $outputPath;

    logMsg('PDF', "Created: {$relativePath}", 'success');

    return [
        'success'      => true,
        'output_path'  => $relativePath,
        'absolute_path'=> $absolutePath,
        'project_root' => WORKSPACE,
        'html_source'  => $htmlPath,
    ];
}

/**
 * List directory entries relative to WORKSPACE.
 */
function toolListDirectory(array $args): array
{
    $path     = $args['path'];
    $fullPath = WORKSPACE . '/' . $path;

    if (!is_dir($fullPath))
    {
        return ['error' => "Directory not found: {$path}"];
    }

    $entries = array_values(array_diff(scandir($fullPath), ['.', '..']));

    return ['path' => $path, 'entries' => $entries];
}

/**
 * Read a text file relative to WORKSPACE.
 */
function toolReadFile(array $args): array
{
    $path     = $args['path'];
    $fullPath = WORKSPACE . '/' . $path;

    if (!file_exists($fullPath))
    {
        return ['error' => "File not found: {$path}"];
    }

    return ['path' => $path, 'content' => file_get_contents($fullPath)];
}

/**
 * Write content to a file relative to WORKSPACE.
 */
function toolWriteFile(array $args): array
{
    $path     = $args['path'];
    $content  = $args['content'];
    $fullPath = WORKSPACE . '/' . $path;

    $dir = dirname($fullPath);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    if (file_put_contents($fullPath, $content) !== false)
    {
        return ['success' => true, 'path' => $path];
    }

    return ['error' => "Failed to write: {$path}"];
}

/**
 * Copy a file, creating destination directories as needed.
 */
function toolCopyFile(array $args): array
{
    $source  = $args['source'];
    $dest    = $args['destination'];
    $srcFull = WORKSPACE . '/' . $source;
    $dstFull = WORKSPACE . '/' . $dest;

    if (!file_exists($srcFull))
    {
        return ['error' => "Source not found: {$source}"];
    }

    $dstDir = dirname($dstFull);
    if (!is_dir($dstDir)) mkdir($dstDir, 0755, true);

    if (copy($srcFull, $dstFull))
    {
        return ['success' => true, 'source' => $source, 'destination' => $dest];
    }

    return ['error' => "Failed to copy {$source} to {$dest}"];
}

// =============================================================================
// AGENT LOOP
// =============================================================================

function callAgentLLM(array $messages, array $tools): array
{
    global $env, $scriptLabel, $orModel;

    $payload = [
        'model'       => $orModel,
        'messages'    => $messages,
        'tools'       => $tools,
        'tool_choice' => 'auto',
    ];

    $result = curlPost(
        'https://openrouter.ai/api/v1/chat/completions',
        $payload,
        [
            'Authorization: Bearer ' . ($env['OPENROUTER_API_KEY'] ?? ''),
            'HTTP-Referer: ' . APP_REF,
            'X-Title: ' . ($scriptLabel ?? 'unknown'),
        ]
    );

    if ($result['error'] || $result['httpCode'] !== 200)
    {
        throw new RuntimeException("Agent LLM call failed (HTTP {$result['httpCode']}): {$result['error']}");
    }

    return json_decode($result['body'], true);
}

function runAgent(string $userMessage, array $tools): string
{
    global $systemPrompt;

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => $userMessage],
    ];

    logMsg('AGENT', "Starting — query: {$userMessage}", 'primary');

    for ($step = 1; $step <= MAX_STEPS; $step++)
    {
        logSection("Step {$step} (" . count($messages) . " messages)");

        $response = callAgentLLM($messages, $tools);

        if (isset($response['usage']))
        {
            $u = $response['usage'];
            logMsg('TOKENS', "{$u['prompt_tokens']} in → {$u['completion_tokens']} out", 'secondary');
        }

        $message   = $response['choices'][0]['message'] ?? [];
        $toolCalls = $message['tool_calls'] ?? [];

        // No tool calls → final answer
        if (empty($toolCalls))
        {
            return $message['content'] ?? 'No response';
        }

        // Append assistant message (with tool_calls) before tool results
        $messages[] = $message;

        // Execute each tool call
        foreach ($toolCalls as $tc)
        {
            $name   = $tc['function']['name'];
            $args   = json_decode($tc['function']['arguments'], true) ?? [];
            $callId = $tc['id'];

            logMsg('TOOL', "{$name} — " . json_encode($args), 'info');

            try
            {
                $result = handleTool($name, $args);
                $output = json_encode($result);
                logMsg('RESULT', substr($output, 0, 200), 'secondary');
            }
            catch (Throwable $e)
            {
                $output = json_encode(['error' => $e->getMessage()]);
                logMsg('RESULT', $e->getMessage(), 'danger');
            }

            $messages[] = [
                'role'         => 'tool',
                'tool_call_id' => $callId,
                'content'      => $output,
            ];
        }
    }

    throw new RuntimeException('Max steps (' . MAX_STEPS . ') reached without a final answer.');
}

// =============================================================================
// MAIN
// =============================================================================

logSection('S01E04 — PDF Report Generation Agent');
logMsg('MODEL', $orModel, 'secondary');
logMsg('IMAGE', $imageModel, 'secondary');
logMsg('VISION', $visionModel, 'secondary');

$tools = getTools();
logMsg('TOOLS', implode(', ', array_map(fn($t) => $t['function']['name'], $tools)), 'secondary');

$query = $argv[1] ?? "Create a professional one-page report about AI trends in 2026 with key metrics and one illustration";

logMsg('QUERY', $query, 'primary');

try
{
    $finalAnswer = runAgent($query, $tools);

    logSection('Report generation complete');
    logMsg('DONE', $finalAnswer, 'success');
}
catch (Throwable $e)
{
    logMsg('ERROR', $e->getMessage(), 'danger');
}

require __DIR__ . '/../../lib/footer.php';
