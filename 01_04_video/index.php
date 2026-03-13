<?php
/**
 * S01E04 — Video Processing Agent (PHP)
 *
 * CONCEPT
 * -------
 * An autonomous video processing agent that analyzes, transcribes, extracts
 * content from, and queries video files. Supports local files (MP4, MPEG,
 * MOV, AVI, FLV, WebM, WMV, 3GP) and YouTube URLs.
 *
 *   User query → Agent loop → [Tool calls <-> Results] → Final answer
 *
 * VIDEO PROCESSING (Gemini Native API)
 * -------------------------------------
 * Like the audio example, video operations call the Gemini API directly —
 * OpenRouter doesn't expose Gemini's video understanding endpoints.
 *
 *  - gemini-2.5-flash — analysis, transcription, extraction, queries
 *
 * For files >20MB, the Gemini Files API upload is used (resumable protocol).
 * For smaller files, video is sent inline as base64.
 * YouTube URLs are passed directly as file_uri (Gemini fetches them).
 *
 * TWO MODELS, TWO ROLES
 * ----------------------
 *  - Orchestrator (gpt-4.1 via OpenRouter)    — plans, reasons, calls tools
 *  - Video processor (gemini-2.5-flash)       — processes video content
 *
 * TOOLS
 * -----
 *  - analyze_video     Content analysis (general, visual, audio, action)
 *  - transcribe_video  Speech-to-text with timestamps, speakers, translation
 *  - extract_video     Extract scenes, keyframes, objects, or on-screen text
 *  - query_video       Ask any custom question about video content
 *  - list_directory    List files in workspace folders
 *  - read_file         Read text file contents
 *  - write_file        Write/create text files
 *
 * VIDEO METADATA
 * --------------
 * All video tools support optional clipping and sampling parameters:
 *  - start_time / end_time — clip to a segment (e.g. "30s", "1m30s")
 *  - fps — frames per second to sample (lower = fewer tokens for long videos)
 *
 * AGENT LOOP
 * ----------
 *   while (steps < 50):
 *       response = LLM(messages + tools)
 *       if no tool_calls → return text (done)
 *       for each tool_call: execute → append result to messages
 *       loop back
 *
 * TYPICAL EXECUTION FLOW
 * ----------------------
 *  1. Agent lists workspace/input/ to see available video files
 *  2. Agent calls analyze_video / transcribe_video on a file or YouTube URL
 *  3. Gemini processes video → structured JSON result
 *  4. Agent optionally saves results to workspace/output/
 *  5. Agent summarizes and returns findings
 */

require __DIR__ . '/../../../lib/init.php';

// =============================================================================
// CONFIG
// =============================================================================

define('WORKSPACE', __DIR__);
define('MAX_STEPS', 50);
define('INLINE_SIZE_LIMIT', 20 * 1024 * 1024); // 20MB

$orModel    = 'openai/gpt-4.1';
$videoModel = 'gemini-2.5-flash';

$geminiApiKey = $env['GEMINI_API_KEY'] ?? '';

if (!$geminiApiKey)
{
    logMsg('ERROR', 'GEMINI_API_KEY is required for video processing. Add it to .env', 'danger');
    require __DIR__ . '/../../../lib/footer.php';
    exit;
}

$geminiGenerateEndpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$videoModel}:generateContent";
$geminiUploadEndpoint   = 'https://generativelanguage.googleapis.com/upload/v1beta/files';

$systemPrompt = <<<'PROMPT'
You are an autonomous video processing agent.

## GOAL
Process, analyze, transcribe, and extract content from videos. Handle visual analysis, speech-to-text, content extraction, and custom queries.

## RESOURCES
- workspace/input/   → Source video files to process
- workspace/output/  → Analysis results, transcriptions, extractions

## TOOLS
- File tools: list_directory, read_file, write_file
- analyze_video: Analyze video content (visual elements, audio, actions, composition)
- transcribe_video: Transcribe speech with timestamps and speaker detection
- extract_video: Extract scenes, keyframes, objects, or on-screen text
- query_video: Ask any custom question about video content

## VIDEO INPUT
Supported sources:
- Local files: workspace/input/video.mp4 (MP4, MPEG, MOV, AVI, FLV, WebM, WMV, 3GP)
- YouTube URLs: https://www.youtube.com/watch?v=... or https://youtu.be/...

Max length: ~1 hour for local files, depends on context limit for YouTube.

## ANALYSIS TYPES
- general: Comprehensive overview (content, visuals, audio, quality)
- visual: Cinematography, scenes, colors, graphics, transitions
- audio: Speech, music, sound effects, audio quality
- action: Events, movements, interactions, significant moments

## EXTRACTION TYPES
- scenes: Distinct scenes with start/end timestamps, descriptions, mood
- keyframes: Representative moments with timestamps and significance
- objects: People, items, elements with visibility timestamps
- text: On-screen text, titles, captions with timestamps and positions

## TRANSCRIPTION FEATURES
- Speaker diarization (identify who is speaking)
- Timestamps (MM:SS format)
- Language detection and translation
- Non-speech audio annotation (music, effects)

## VIDEO METADATA OPTIONS
- start_time/end_time: Clip to a specific segment (e.g., "30s", "1m30s")
- fps: Frames per second to sample (default ~1). Lower for long videos, higher for fast action.

## TOKENIZATION
- Default: ~300 tokens/second of video
- Low fps (~0.1): ~100 tokens/second — good for long videos
- These are guidelines; adjust fps based on video length and detail needed.

## WORKFLOW

1. UNDERSTAND THE REQUEST
   - Analysis? → analyze_video
   - Transcription? → transcribe_video
   - Extraction? → extract_video
   - Custom question? → query_video

2. CHOOSE PARAMETERS
   - Long video? Lower fps
   - Specific segment? Use start_time/end_time
   - Save results? Provide output_name

3. DELIVER RESULTS
   - Save to workspace/output/ when requested
   - Return file paths and summaries

## RULES

1. Check workspace/input/ for available source files
2. Large files (>20MB) use upload API automatically
3. For long videos, consider using lower fps or time clipping
4. Save outputs with descriptive names
5. Report output paths clearly

Run autonomously. Summarize findings clearly.
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
                'name'        => 'analyze_video',
                'description' => 'Analyze video content — visual elements, audio, actions, and overall composition. Supports local files and YouTube URLs. Returns structured analysis with timestamps.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'video_path' => [
                            'type'        => 'string',
                            'description' => 'Path to video file relative to project root (e.g., workspace/input/video.mp4) OR a YouTube URL',
                        ],
                        'analysis_type' => [
                            'type'        => 'string',
                            'enum'        => ['general', 'visual', 'audio', 'action'],
                            'description' => "Type of analysis: 'general' (comprehensive), 'visual' (cinematography, scenes), 'audio' (speech, music, sounds), 'action' (events, movements). Default: general",
                        ],
                        'custom_prompt' => [
                            'type'        => 'string',
                            'description' => 'Optional custom analysis prompt to override the default',
                        ],
                        'start_time' => [
                            'type'        => 'string',
                            'description' => "Optional start time for clipping (e.g., '30s' or '1m30s')",
                        ],
                        'end_time' => [
                            'type'        => 'string',
                            'description' => 'Optional end time for clipping',
                        ],
                        'fps' => [
                            'type'        => 'number',
                            'description' => 'Frames per second to sample (default: 1). Lower for long videos, higher for fast action.',
                        ],
                        'output_name' => [
                            'type'        => 'string',
                            'description' => 'Optional base name for saving analysis JSON to workspace/output/',
                        ],
                    ],
                    'required' => ['video_path'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'transcribe_video',
                'description' => 'Transcribe speech from video with timestamps and speaker detection. Also captures non-speech audio. Supports local files and YouTube URLs.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'video_path' => [
                            'type'        => 'string',
                            'description' => 'Path to video file relative to project root OR a YouTube URL',
                        ],
                        'include_timestamps' => [
                            'type'        => 'boolean',
                            'description' => 'Include timestamps for each segment. Default: true',
                        ],
                        'detect_speakers' => [
                            'type'        => 'boolean',
                            'description' => 'Identify and label different speakers. Default: true',
                        ],
                        'translate_to' => [
                            'type'        => 'string',
                            'description' => "Target language for translation (e.g., 'English', 'Spanish'). If not provided, keeps original language.",
                        ],
                        'start_time' => [
                            'type'        => 'string',
                            'description' => "Optional start time for clipping (e.g., '30s' or '1m30s')",
                        ],
                        'end_time' => [
                            'type'        => 'string',
                            'description' => 'Optional end time for clipping',
                        ],
                        'output_name' => [
                            'type'        => 'string',
                            'description' => 'Optional base name for saving transcription JSON to workspace/output/',
                        ],
                    ],
                    'required' => ['video_path'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'extract_video',
                'description' => 'Extract specific elements from video: scenes, keyframes, objects, or text. Returns structured data with timestamps.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'video_path' => [
                            'type'        => 'string',
                            'description' => 'Path to video file relative to project root OR a YouTube URL',
                        ],
                        'extraction_type' => [
                            'type'        => 'string',
                            'enum'        => ['scenes', 'keyframes', 'objects', 'text'],
                            'description' => "What to extract: 'scenes' (distinct scenes with timestamps), 'keyframes' (representative moments), 'objects' (people/things), 'text' (on-screen text). Default: scenes",
                        ],
                        'start_time' => [
                            'type'        => 'string',
                            'description' => 'Optional start time for clipping',
                        ],
                        'end_time' => [
                            'type'        => 'string',
                            'description' => 'Optional end time for clipping',
                        ],
                        'fps' => [
                            'type'        => 'number',
                            'description' => 'Frames per second to sample. Higher = more detail but more tokens.',
                        ],
                        'output_name' => [
                            'type'        => 'string',
                            'description' => 'Optional base name for saving extraction JSON to workspace/output/',
                        ],
                    ],
                    'required' => ['video_path'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'query_video',
                'description' => "Ask any question about a video. Use for custom queries that don't fit analyze/transcribe/extract patterns. Can reference specific timestamps.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'video_path' => [
                            'type'        => 'string',
                            'description' => 'Path to video file relative to project root OR a YouTube URL',
                        ],
                        'question' => [
                            'type'        => 'string',
                            'description' => "Question or prompt about the video content. Can reference timestamps like 'What happens at 01:30?'",
                        ],
                        'start_time' => [
                            'type'        => 'string',
                            'description' => 'Optional start time to focus on specific segment',
                        ],
                        'end_time' => [
                            'type'        => 'string',
                            'description' => 'Optional end time to focus on specific segment',
                        ],
                    ],
                    'required' => ['video_path', 'question'],
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
                            'description' => "Directory path relative to the script directory (e.g. 'workspace/input')",
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
                            'description' => 'File path relative to the script directory',
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
                            'description' => 'File path relative to the script directory',
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
    ];
}

// =============================================================================
// HELPERS
// =============================================================================

/**
 * Get video MIME type from file extension.
 */
function getVideoMimeType(string $filepath): string
{
    $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

    return match ($ext) {
        'mp4'        => 'video/mp4',
        'mpeg', 'mpg'=> 'video/mpeg',
        'mov'        => 'video/mov',
        'avi'        => 'video/avi',
        'flv'        => 'video/x-flv',
        'webm'       => 'video/webm',
        'wmv'        => 'video/wmv',
        '3gp', '3gpp'=> 'video/3gpp',
        default      => 'video/mp4',
    };
}

/**
 * Check if input is a YouTube URL.
 */
function isYouTubeUrl(string $input): bool
{
    return str_contains($input, 'youtube.com/watch') || str_contains($input, 'youtu.be/');
}

/**
 * Upload a video file to Gemini Files API (for files >20MB).
 * Uses the resumable upload protocol.
 */
function uploadVideoToGemini(string $videoData, string $mimeType, string $displayName): array
{
    global $geminiApiKey, $geminiUploadEndpoint;

    logMsg('GEMINI', "Uploading video file: {$displayName}", 'info');

    // Step 1: Initialize resumable upload
    $ch = curl_init($geminiUploadEndpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => [
            'x-goog-api-key: ' . $geminiApiKey,
            'X-Goog-Upload-Protocol: resumable',
            'X-Goog-Upload-Command: start',
            'X-Goog-Upload-Header-Content-Length: ' . strlen($videoData),
            'X-Goog-Upload-Header-Content-Type: ' . $mimeType,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode(['file' => ['display_name' => $displayName]]),
        CURLOPT_TIMEOUT        => 120,
    ]);

    $response   = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers    = substr($response, 0, $headerSize);
    curl_close($ch);

    // Extract upload URL from response headers
    $uploadUrl = null;
    foreach (explode("\r\n", $headers) as $header)
    {
        if (stripos($header, 'x-goog-upload-url:') === 0)
        {
            $uploadUrl = trim(substr($header, strlen('x-goog-upload-url:')));
            break;
        }
    }

    if (!$uploadUrl)
    {
        throw new RuntimeException('No upload URL received from Gemini');
    }

    // Step 2: Upload the actual bytes
    $ch = curl_init($uploadUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Length: ' . strlen($videoData),
            'X-Goog-Upload-Offset: 0',
            'X-Goog-Upload-Command: upload, finalize',
        ],
        CURLOPT_POSTFIELDS     => $videoData,
        CURLOPT_TIMEOUT        => 300,
    ]);

    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200)
    {
        throw new RuntimeException("Upload failed (HTTP {$httpCode}): {$body}");
    }

    $fileInfo = json_decode($body, true);

    if (empty($fileInfo['file']['uri']))
    {
        throw new RuntimeException('No file URI in upload response');
    }

    logMsg('GEMINI', "Uploaded: {$fileInfo['file']['name']}", 'success');

    return [
        'fileUri'  => $fileInfo['file']['uri'],
        'name'     => $fileInfo['file']['name'],
        'mimeType' => $fileInfo['file']['mimeType'] ?? $mimeType,
    ];
}

/**
 * Load video and prepare for Gemini.
 * Returns either fileUri (uploaded/YouTube) or videoBase64 (inline).
 */
function loadVideo(string $videoPath): array
{
    if (isYouTubeUrl($videoPath))
    {
        logMsg('VIDEO', 'YouTube URL detected', 'info');
        return ['fileUri' => $videoPath, 'mimeType' => 'video/mp4'];
    }

    $fullPath = WORKSPACE . '/' . $videoPath;

    if (!file_exists($fullPath))
    {
        throw new RuntimeException("Video file not found: {$videoPath}");
    }

    $data        = file_get_contents($fullPath);
    $mimeType    = getVideoMimeType($videoPath);
    $displayName = basename($videoPath);

    if (strlen($data) > INLINE_SIZE_LIMIT)
    {
        logMsg('VIDEO', 'File > 20MB, using upload API...', 'info');
        $uploaded = uploadVideoToGemini($data, $mimeType, $displayName);
        return ['fileUri' => $uploaded['fileUri'], 'mimeType' => $mimeType];
    }

    return ['videoBase64' => base64_encode($data), 'mimeType' => $mimeType];
}

/**
 * Build videoMetadata array from tool args.
 */
function buildVideoMetadata(array $args): ?array
{
    $metadata = [];
    if (!empty($args['start_time'])) $metadata['start_offset'] = $args['start_time'];
    if (!empty($args['end_time']))   $metadata['end_offset']   = $args['end_time'];
    if (!empty($args['fps']))        $metadata['fps']          = $args['fps'];

    return !empty($metadata) ? $metadata : null;
}

/**
 * Call Gemini generateContent with video input.
 *
 * @param array       $video          From loadVideo() — has fileUri or videoBase64 + mimeType
 * @param string      $prompt         Text prompt/instructions
 * @param array|null  $responseSchema Optional JSON schema for structured output
 * @param array|null  $videoMetadata  Optional fps/start_offset/end_offset
 * @return string|array               Text response or parsed JSON if schema provided
 */
function callGeminiVideo(array $video, string $prompt, ?array $responseSchema = null, ?array $videoMetadata = null): string|array
{
    global $geminiApiKey, $geminiGenerateEndpoint;

    logMsg('GEMINI', 'Processing video — ' . substr($prompt, 0, 80) . '...', 'info');

    $parts = [];

    // Add video part first (Gemini best practice: video before text)
    if (!empty($video['fileUri']))
    {
        $filePart = ['file_data' => ['file_uri' => $video['fileUri']]];

        // Only add mime_type if NOT a YouTube URL (Gemini auto-detects YouTube)
        if (!isYouTubeUrl($video['fileUri']))
        {
            $filePart['file_data']['mime_type'] = $video['mimeType'];
        }

        if ($videoMetadata)
        {
            $filePart['video_metadata'] = $videoMetadata;
        }

        $parts[] = $filePart;
    }
    elseif (!empty($video['videoBase64']))
    {
        $inlinePart = [
            'inline_data' => [
                'mime_type' => $video['mimeType'],
                'data'      => $video['videoBase64'],
            ],
        ];

        if ($videoMetadata)
        {
            $inlinePart['video_metadata'] = $videoMetadata;
        }

        $parts[] = $inlinePart;
    }
    else
    {
        throw new RuntimeException('Either fileUri or videoBase64 must be provided');
    }

    // Text prompt after video
    $parts[] = ['text' => $prompt];

    $body = ['contents' => [['parts' => $parts]]];

    if ($responseSchema)
    {
        $body['generation_config'] = [
            'response_mime_type' => 'application/json',
            'response_schema'   => $responseSchema,
        ];
    }

    $ch = curl_init($geminiGenerateEndpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'x-goog-api-key: ' . $geminiApiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_TIMEOUT        => 300,
    ]);

    $responseBody = curl_exec($ch);
    $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error        = curl_error($ch);
    curl_close($ch);

    if ($error || $httpCode !== 200)
    {
        throw new RuntimeException("Gemini video call failed (HTTP {$httpCode}): " . ($error ?: substr($responseBody, 0, 300)));
    }

    $data = json_decode($responseBody, true);

    if (!empty($data['error']))
    {
        throw new RuntimeException($data['error']['message'] ?? json_encode($data['error']));
    }

    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

    if (!$text)
    {
        throw new RuntimeException('No text response from Gemini');
    }

    logMsg('GEMINI', "Processed video (" . strlen($text) . " chars)", 'secondary');

    if ($responseSchema)
    {
        $parsed = json_decode($text, true);
        return $parsed ?? $text;
    }

    return $text;
}

// =============================================================================
// TOOL HANDLERS
// =============================================================================

function handleTool(string $name, array $args): array
{
    return match ($name) {
        'analyze_video'    => toolAnalyzeVideo($args),
        'transcribe_video' => toolTranscribeVideo($args),
        'extract_video'    => toolExtractVideo($args),
        'query_video'      => toolQueryVideo($args),
        'list_directory'   => toolListDirectory($args),
        'read_file'        => toolReadFile($args),
        'write_file'       => toolWriteFile($args),
        default            => ['error' => "Unknown tool: {$name}"],
    };
}

/**
 * Analyze video content (general, visual, audio, action).
 */
function toolAnalyzeVideo(array $args): array
{
    $videoPath    = $args['video_path'];
    $analysisType = $args['analysis_type'] ?? 'general';
    $customPrompt = $args['custom_prompt'] ?? null;
    $outputName   = $args['output_name'] ?? null;

    logMsg('TOOL', "analyze_video — {$videoPath} ({$analysisType})", 'info');

    try
    {
        $video         = loadVideo($videoPath);
        $videoMetadata = buildVideoMetadata($args);

        $prompts = [
            'general' => "Analyze this video comprehensively. Describe:\n- Type of video content (tutorial, vlog, presentation, movie clip, etc.)\n- Main subject and topics covered\n- Key visual elements and scenes\n- Audio content (speech, music, sound effects)\n- Overall quality and production value\n- Notable moments with timestamps (MM:SS format)",
            'visual'  => "Analyze the visual elements of this video. Describe:\n- Scene composition and cinematography\n- Color palette and lighting\n- Text overlays, graphics, or animations\n- Objects and people visible\n- Visual transitions and effects\n- Key visual moments with timestamps",
            'audio'   => "Analyze the audio content of this video. Describe:\n- Speech content and speakers\n- Background music (genre, mood)\n- Sound effects\n- Audio quality\n- Key audio moments with timestamps",
            'action'  => "Analyze the actions and events in this video. Describe:\n- Sequence of events with timestamps\n- Key actions performed\n- Interactions between subjects\n- Important transitions or changes\n- Climactic or significant moments",
        ];

        $prompt = $customPrompt ?? ($prompts[$analysisType] ?? $prompts['general']);

        $schema = [
            'type'       => 'OBJECT',
            'properties' => [
                'video_type'         => ['type' => 'STRING', 'description' => 'Type of video content'],
                'summary'            => ['type' => 'STRING', 'description' => 'Brief summary of the video'],
                'duration_estimate'  => ['type' => 'STRING', 'description' => 'Estimated duration of the video'],
                'key_moments'        => [
                    'type'  => 'ARRAY',
                    'items' => [
                        'type'       => 'OBJECT',
                        'properties' => [
                            'timestamp'   => ['type' => 'STRING', 'description' => 'Timestamp in MM:SS format'],
                            'description' => ['type' => 'STRING', 'description' => 'What happens at this moment'],
                        ],
                    ],
                    'description' => 'Key moments with timestamps',
                ],
                'visual_elements'    => [
                    'type'        => 'ARRAY',
                    'items'       => ['type' => 'STRING'],
                    'description' => 'Notable visual elements',
                ],
                'audio_elements'     => [
                    'type'        => 'ARRAY',
                    'items'       => ['type' => 'STRING'],
                    'description' => 'Notable audio elements',
                ],
                'quality_assessment' => ['type' => 'STRING', 'description' => 'Video quality assessment'],
            ],
            'required' => ['video_type', 'summary'],
        ];

        $result = callGeminiVideo($video, $prompt, $schema, $videoMetadata);

        if ($outputName)
        {
            $outputDir = WORKSPACE . '/workspace/output';
            if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

            $timestamp  = (int)(microtime(true) * 1000);
            $outputFile = "{$outputName}_{$timestamp}.json";
            file_put_contents($outputDir . '/' . $outputFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            logMsg('RESULT', "Analysis saved: workspace/output/{$outputFile}", 'success');

            return ['success' => true, 'video_path' => $videoPath, 'analysis_type' => $analysisType, 'output_path' => "workspace/output/{$outputFile}", 'analysis' => $result];
        }

        $videoType = is_array($result) ? ($result['video_type'] ?? 'unknown') : 'unknown';
        logMsg('RESULT', "Analyzed: {$videoType}", 'success');

        return ['success' => true, 'video_path' => $videoPath, 'analysis_type' => $analysisType, 'analysis' => $result];
    }
    catch (Throwable $e)
    {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Transcribe speech from video with timestamps and speaker detection.
 */
function toolTranscribeVideo(array $args): array
{
    $videoPath         = $args['video_path'];
    $includeTimestamps = $args['include_timestamps'] ?? true;
    $detectSpeakers    = $args['detect_speakers'] ?? true;
    $translateTo       = $args['translate_to'] ?? null;
    $outputName        = $args['output_name'] ?? null;

    logMsg('TOOL', "transcribe_video — {$videoPath}", 'info');

    try
    {
        $video         = loadVideo($videoPath);
        $videoMetadata = buildVideoMetadata($args);

        $prompt = "Transcribe all speech from this video.\n\nRequirements:\n";

        if ($detectSpeakers)
            $prompt .= "- Identify distinct speakers (e.g., Speaker 1, Speaker 2, or names if visible/mentioned).\n";
        if ($includeTimestamps)
            $prompt .= "- Provide accurate timestamps for each segment (Format: MM:SS).\n";

        $prompt .= "- Detect the primary language.\n";

        if ($translateTo)
            $prompt .= "- Translate all segments to {$translateTo}.\n";

        $prompt .= "- Note any significant non-speech audio (music, sound effects) with timestamps.\n";
        $prompt .= "- Provide a brief summary at the beginning.";

        // Build segment properties
        $segmentProperties = [
            'content'  => ['type' => 'STRING'],
        ];

        if ($detectSpeakers)    $segmentProperties['speaker']     = ['type' => 'STRING'];
        if ($includeTimestamps) $segmentProperties['timestamp']   = ['type' => 'STRING'];
        if ($translateTo)       $segmentProperties['translation'] = ['type' => 'STRING'];

        $required = ['content'];
        if ($includeTimestamps) $required[] = 'timestamp';
        if ($detectSpeakers)    $required[] = 'speaker';

        $schema = [
            'type'       => 'OBJECT',
            'properties' => [
                'summary'           => ['type' => 'STRING', 'description' => 'A concise summary of the spoken content.'],
                'duration_estimate' => ['type' => 'STRING', 'description' => 'Estimated duration of the video.'],
                'primary_language'  => ['type' => 'STRING', 'description' => 'Primary language detected.'],
                'segments'          => [
                    'type'        => 'ARRAY',
                    'description' => 'List of transcribed segments.',
                    'items'       => [
                        'type'       => 'OBJECT',
                        'properties' => $segmentProperties,
                        'required'   => $required,
                    ],
                ],
                'non_speech_audio' => [
                    'type'  => 'ARRAY',
                    'items' => [
                        'type'       => 'OBJECT',
                        'properties' => [
                            'timestamp'   => ['type' => 'STRING'],
                            'description' => ['type' => 'STRING'],
                        ],
                    ],
                    'description' => 'Notable non-speech audio moments',
                ],
            ],
            'required' => ['summary', 'segments'],
        ];

        $result = callGeminiVideo($video, $prompt, $schema, $videoMetadata);

        if ($outputName)
        {
            $outputDir = WORKSPACE . '/workspace/output';
            if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

            $timestamp  = (int)(microtime(true) * 1000);
            $outputFile = "{$outputName}_{$timestamp}.json";
            file_put_contents($outputDir . '/' . $outputFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            logMsg('RESULT', "Transcription saved: workspace/output/{$outputFile}", 'success');

            return ['success' => true, 'video_path' => $videoPath, 'output_path' => "workspace/output/{$outputFile}", 'transcription' => $result];
        }

        $segCount = is_array($result) ? count($result['segments'] ?? []) : 0;
        logMsg('RESULT', "Transcribed: {$segCount} segments", 'success');

        return ['success' => true, 'video_path' => $videoPath, 'transcription' => $result];
    }
    catch (Throwable $e)
    {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Extract scenes, keyframes, objects, or text from video.
 */
function toolExtractVideo(array $args): array
{
    $videoPath      = $args['video_path'];
    $extractionType = $args['extraction_type'] ?? 'scenes';
    $outputName     = $args['output_name'] ?? null;

    logMsg('TOOL', "extract_video — {$videoPath} ({$extractionType})", 'info');

    try
    {
        $video         = loadVideo($videoPath);
        $videoMetadata = buildVideoMetadata($args);

        $prompts = [
            'scenes'    => "Identify and describe all distinct scenes in this video.\nFor each scene provide:\n- Start timestamp (MM:SS)\n- End timestamp (MM:SS)\n- Description of the scene\n- Key visual elements\n- Mood/tone",
            'keyframes' => "Identify the key frames in this video — moments that best represent the content.\nFor each keyframe provide:\n- Timestamp (MM:SS)\n- Description of what's shown\n- Why this frame is significant",
            'objects'   => "Identify all notable objects, people, and elements visible in this video.\nFor each item provide:\n- What it is\n- Timestamps when visible (MM:SS)\n- Context/relevance to the video",
            'text'      => "Extract all text visible in this video (on-screen text, titles, captions, signs, etc.)\nFor each text element provide:\n- The text content\n- Timestamp when visible (MM:SS)\n- Location on screen\n- Purpose (title, caption, sign, etc.)",
        ];

        $schemas = [
            'scenes' => [
                'type'       => 'OBJECT',
                'properties' => [
                    'total_scenes' => ['type' => 'INTEGER'],
                    'scenes'       => [
                        'type'  => 'ARRAY',
                        'items' => [
                            'type'       => 'OBJECT',
                            'properties' => [
                                'scene_number'    => ['type' => 'INTEGER'],
                                'start_time'      => ['type' => 'STRING'],
                                'end_time'        => ['type' => 'STRING'],
                                'description'     => ['type' => 'STRING'],
                                'visual_elements' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                                'mood'            => ['type' => 'STRING'],
                            ],
                        ],
                    ],
                ],
            ],
            'keyframes' => [
                'type'       => 'OBJECT',
                'properties' => [
                    'total_keyframes' => ['type' => 'INTEGER'],
                    'keyframes'       => [
                        'type'  => 'ARRAY',
                        'items' => [
                            'type'       => 'OBJECT',
                            'properties' => [
                                'timestamp'    => ['type' => 'STRING'],
                                'description'  => ['type' => 'STRING'],
                                'significance' => ['type' => 'STRING'],
                            ],
                        ],
                    ],
                ],
            ],
            'objects' => [
                'type'       => 'OBJECT',
                'properties' => [
                    'objects' => [
                        'type'  => 'ARRAY',
                        'items' => [
                            'type'       => 'OBJECT',
                            'properties' => [
                                'name'       => ['type' => 'STRING'],
                                'timestamps' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                                'context'    => ['type' => 'STRING'],
                            ],
                        ],
                    ],
                ],
            ],
            'text' => [
                'type'       => 'OBJECT',
                'properties' => [
                    'text_elements' => [
                        'type'  => 'ARRAY',
                        'items' => [
                            'type'       => 'OBJECT',
                            'properties' => [
                                'content'   => ['type' => 'STRING'],
                                'timestamp' => ['type' => 'STRING'],
                                'location'  => ['type' => 'STRING'],
                                'purpose'   => ['type' => 'STRING'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $prompt = $prompts[$extractionType] ?? $prompts['scenes'];
        $schema = $schemas[$extractionType] ?? $schemas['scenes'];

        $result = callGeminiVideo($video, $prompt, $schema, $videoMetadata);

        if ($outputName)
        {
            $outputDir = WORKSPACE . '/workspace/output';
            if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

            $timestamp  = (int)(microtime(true) * 1000);
            $outputFile = "{$outputName}_{$timestamp}.json";
            file_put_contents($outputDir . '/' . $outputFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            logMsg('RESULT', "Extraction saved: workspace/output/{$outputFile}", 'success');

            return ['success' => true, 'video_path' => $videoPath, 'extraction_type' => $extractionType, 'output_path' => "workspace/output/{$outputFile}", 'extraction' => $result];
        }

        logMsg('RESULT', "Extracted {$extractionType}", 'success');

        return ['success' => true, 'video_path' => $videoPath, 'extraction_type' => $extractionType, 'extraction' => $result];
    }
    catch (Throwable $e)
    {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Ask a custom question about video content.
 */
function toolQueryVideo(array $args): array
{
    $videoPath = $args['video_path'];
    $question  = $args['question'];

    logMsg('TOOL', "query_video — " . substr($question, 0, 50) . '...', 'info');

    try
    {
        $video         = loadVideo($videoPath);
        $videoMetadata = buildVideoMetadata($args);
        $result        = callGeminiVideo($video, $question, null, $videoMetadata);

        logMsg('RESULT', "Query answered (" . strlen($result) . " chars)", 'success');

        return ['success' => true, 'video_path' => $videoPath, 'question' => $question, 'answer' => $result];
    }
    catch (Throwable $e)
    {
        return ['error' => $e->getMessage()];
    }
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

    return ['path' => $path, 'entries' => array_values(array_diff(scandir($fullPath), ['.', '..']))];
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

        if (empty($toolCalls))
        {
            return $message['content'] ?? 'No response';
        }

        $messages[] = $message;

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

logSection('S01E04 — Video Processing Agent');
logMsg('MODEL', $orModel, 'secondary');
logMsg('VIDEO', $videoModel, 'secondary');

$tools = getTools();
logMsg('TOOLS', implode(', ', array_map(fn($t) => $t['function']['name'], $tools)), 'secondary');

$query = "Analyze the video https://www.youtube.com/watch?v=Iar4yweKGoI and list 4 big claims";

logMsg('QUERY', $query, 'primary');

try
{
    $finalAnswer = runAgent($query, $tools);

    logSection('Video processing complete');
    logMsg('DONE', $finalAnswer, 'success');
}
catch (Throwable $e)
{
    logMsg('ERROR', $e->getMessage(), 'danger');
}

require __DIR__ . '/../../../lib/footer.php';
