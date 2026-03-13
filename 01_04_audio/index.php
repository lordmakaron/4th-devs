<?php
/**
 * S01E04 — Audio Processing Agent (PHP)
 *
 * CONCEPT
 * -------
 * An autonomous audio processing agent that transcribes, analyzes, queries,
 * and generates speech from audio files. Supports local files (MP3, WAV,
 * AIFF, AAC, OGG, FLAC) and YouTube URLs.
 *
 *   User query → Agent loop → [Tool calls <-> Results] → Final answer
 *
 * AUDIO PROCESSING (Gemini Native API)
 * -------------------------------------
 * Unlike image examples that use OpenRouter, audio operations call the
 * Gemini API directly — OpenRouter doesn't expose Gemini's audio/TTS
 * endpoints. Two models:
 *  - gemini-2.5-flash          — transcription, analysis, queries
 *  - gemini-2.5-flash-preview-tts — text-to-speech generation
 *
 * For files >20MB, the Gemini Files API upload is used (resumable protocol).
 * For smaller files, audio is sent inline as base64.
 *
 * THREE MODELS, THREE ROLES
 * -------------------------
 *  - Orchestrator (gpt-4.1 via OpenRouter)   — plans, reasons, calls tools
 *  - Audio processor (gemini-2.5-flash)      — transcription, analysis, queries
 *  - TTS generator (gemini-2.5-flash-tts)    — speech synthesis
 *
 * TOOLS
 * -----
 *  - transcribe_audio  Speech-to-text with timestamps, speaker diarization,
 *                       emotion detection, translation
 *  - analyze_audio     Content analysis (general, music, speech, sounds)
 *  - query_audio       Ask any custom question about audio content
 *  - generate_audio    Text-to-speech (single or multi-speaker, 30 voices)
 *  - list_directory    List files in workspace folders
 *  - read_file         Read text file contents
 *  - write_file        Write/create text files
 *
 * TEXT-TO-SPEECH
 * --------------
 * 30 voices available (Kore, Puck, Charon, Aoede, Fenrir, etc.).
 * Style is controlled via natural language in the text prompt:
 *  - "Say cheerfully: Hello!"        → happy tone
 *  - "In a whisper: The secret..."   → soft, quiet
 *  - "Speak slowly: The end."        → pacing control
 *
 * Multi-speaker (up to 2): "Speaker1: Hello! Speaker2: Hi there!"
 * Each speaker can have a different voice assigned.
 *
 * Output format: WAV (24kHz, 16-bit, mono PCM).
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
 *  1. Agent lists workspace/input/ to see available audio files
 *  2. Agent calls transcribe_audio or analyze_audio on a file
 *  3. Gemini processes audio → structured JSON result
 *  4. Agent optionally saves results to workspace/output/
 *  5. Agent summarizes and returns findings
 *
 *  For TTS:
 *  1. Agent selects a voice matching the content/mood
 *  2. Agent calls generate_audio with styled text
 *  3. Gemini returns raw PCM → saved as WAV to workspace/output/
 *  4. Agent reports the output path
 */

require __DIR__ . '/../../../lib/init.php';

// =============================================================================
// CONFIG
// =============================================================================

define('WORKSPACE', __DIR__);
define('MAX_STEPS', 50);
define('INLINE_SIZE_LIMIT', 20 * 1024 * 1024); // 20MB

$orModel    = 'openai/gpt-4.1';
$audioModel = 'gemini-2.5-flash';
$ttsModel   = 'gemini-2.5-flash-preview-tts';

$geminiApiKey = $env['GEMINI_API_KEY'] ?? '';

if (!$geminiApiKey)
{
    logMsg('ERROR', 'GEMINI_API_KEY is required for audio processing. Add it to .env', 'danger');
    require __DIR__ . '/../../../lib/footer.php';
    exit;
}

$geminiGenerateEndpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$audioModel}:generateContent";
$geminiTtsEndpoint      = "https://generativelanguage.googleapis.com/v1beta/models/{$ttsModel}:generateContent";
$geminiUploadEndpoint   = 'https://generativelanguage.googleapis.com/upload/v1beta/files';

/**
 * Available TTS voices with their characteristics.
 */
$ttsVoices = [
    'Zephyr' => 'Bright', 'Puck' => 'Upbeat', 'Charon' => 'Informative',
    'Kore' => 'Firm', 'Fenrir' => 'Excitable', 'Leda' => 'Youthful',
    'Orus' => 'Firm', 'Aoede' => 'Breezy', 'Callirrhoe' => 'Easy-going',
    'Autonoe' => 'Bright', 'Enceladus' => 'Breathy', 'Iapetus' => 'Clear',
    'Umbriel' => 'Easy-going', 'Algieba' => 'Smooth', 'Despina' => 'Smooth',
    'Erinome' => 'Clear', 'Algenib' => 'Gravelly', 'Rasalgethi' => 'Informative',
    'Laomedeia' => 'Upbeat', 'Achernar' => 'Soft', 'Alnilam' => 'Firm',
    'Schedar' => 'Even', 'Gacrux' => 'Mature', 'Pulcherrima' => 'Forward',
    'Achird' => 'Friendly', 'Zubenelgenubi' => 'Casual', 'Vindemiatrix' => 'Gentle',
    'Sadachbia' => 'Lively', 'Sadaltager' => 'Knowledgeable', 'Sulafat' => 'Warm',
];

$systemPrompt = <<<'PROMPT'
You are an autonomous audio processing agent.

## GOAL
Process, transcribe, analyze, and generate audio. Handle speech-to-text, audio analysis, and text-to-speech tasks.

## RESOURCES
- workspace/input/   → Source audio files to process
- workspace/output/  → Generated audio, transcriptions, and analysis results

## TOOLS
- File tools: list_directory, read_file, write_file
- transcribe_audio: Convert speech to text with timestamps, speaker detection, emotion detection, translation
- analyze_audio: Analyze audio content (music, speech patterns, sound identification)
- query_audio: Ask any custom question about audio content
- generate_audio: Text-to-speech generation (single or multi-speaker)

## AUDIO INPUT
Supported sources:
- Local files: workspace/input/audio.mp3 (MP3, WAV, AIFF, AAC, OGG, FLAC)
- YouTube URLs: https://www.youtube.com/watch?v=... or https://youtu.be/...

Max length: 9.5 hours for local files, ~1-3 hours for YouTube (context limit)

Transcription features:
- Speaker diarization (identify who is speaking)
- Timestamps (MM:SS format)
- Language detection and translation
- Emotion detection (happy, sad, angry, neutral)

Analysis types:
- general: Comprehensive overview
- music: Genre, tempo, instruments, structure
- speech: Speaker characteristics, clarity, pace
- sounds: Sound source identification

## TEXT-TO-SPEECH
Generate natural speech with controllable style, tone, pace, and accent.

Voices (30 available):
- Kore (Firm), Puck (Upbeat), Charon (Informative), Aoede (Breezy)
- Fenrir (Excitable), Enceladus (Breathy), Sulafat (Warm), etc.

Style control via natural language:
- "Say cheerfully: Hello!" → happy tone
- "In a whisper: The secret..." → soft, quiet
- "Speak slowly and dramatically: The end." → pacing control

Multi-speaker (up to 2):
- Format: "Speaker1: Hello! Speaker2: Hi there!"
- Assign different voices to each speaker

## WORKFLOW

1. UNDERSTAND THE REQUEST
   - Transcription? → transcribe_audio
   - Analysis? → analyze_audio
   - Generate speech? → generate_audio
   - Custom question? → query_audio

2. FOR GENERATION
   - Choose appropriate voice for the content/mood
   - Include style directions in the text prompt
   - For dialogue, use multi-speaker with distinct voices

3. DELIVER RESULTS
   - Save to workspace/output/ when requested
   - Return file paths and summaries

## RULES

1. Check workspace/input/ for available source files
2. Large files (>20MB) use upload API automatically
3. For TTS, match voice personality to content
4. Save outputs with descriptive names
5. Report output paths clearly

Run autonomously. Be creative with voice generation.
PROMPT;

// =============================================================================
// TOOL DEFINITIONS
// =============================================================================

function getTools(): array
{
    global $ttsVoices;

    $voiceList = implode(', ', array_map(
        fn($name, $style) => "{$name} ({$style})",
        array_keys($ttsVoices),
        array_values($ttsVoices)
    ));

    return [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'transcribe_audio',
                'description' => 'Transcribe audio to text with timestamps and speaker detection. Supports local files (MP3, WAV, AIFF, AAC, OGG, FLAC) and YouTube URLs. Can detect speakers, emotions, and translate to other languages.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'audio_path' => [
                            'type'        => 'string',
                            'description' => 'Path to audio file relative to project root (e.g., workspace/input/recording.mp3) OR a YouTube URL',
                        ],
                        'include_timestamps' => [
                            'type'        => 'boolean',
                            'description' => 'Include timestamps for each segment. Default: true',
                        ],
                        'detect_speakers' => [
                            'type'        => 'boolean',
                            'description' => 'Identify and label different speakers. Default: true',
                        ],
                        'detect_emotions' => [
                            'type'        => 'boolean',
                            'description' => 'Detect speaker emotions (happy, sad, angry, neutral). Default: false',
                        ],
                        'translate_to' => [
                            'type'        => 'string',
                            'description' => "Target language for translation (e.g., 'English', 'Spanish'). If not provided, keeps original language.",
                        ],
                        'output_name' => [
                            'type'        => 'string',
                            'description' => 'Optional base name for saving transcription JSON to workspace/output/',
                        ],
                    ],
                    'required' => ['audio_path'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'analyze_audio',
                'description' => 'Analyze audio content - identify sounds, music characteristics, speech patterns, or general audio analysis. Supports local files and YouTube URLs. Does NOT transcribe - use transcribe_audio for that.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'audio_path' => [
                            'type'        => 'string',
                            'description' => 'Path to audio file relative to project root OR a YouTube URL',
                        ],
                        'analysis_type' => [
                            'type'        => 'string',
                            'enum'        => ['general', 'music', 'speech', 'sounds'],
                            'description' => "Type of analysis: 'general' (comprehensive), 'music' (genre, tempo, instruments), 'speech' (speakers, style, clarity), 'sounds' (identify sound sources). Default: general",
                        ],
                        'custom_prompt' => [
                            'type'        => 'string',
                            'description' => 'Optional custom analysis prompt to override the default for the analysis type',
                        ],
                        'output_name' => [
                            'type'        => 'string',
                            'description' => 'Optional base name for saving analysis JSON to workspace/output/',
                        ],
                    ],
                    'required' => ['audio_path'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'query_audio',
                'description' => 'Ask any question about audio content. Supports local files and YouTube URLs. Use for custom queries that don\'t fit transcribe or analyze patterns.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'audio_path' => [
                            'type'        => 'string',
                            'description' => 'Path to audio file relative to project root OR a YouTube URL',
                        ],
                        'question' => [
                            'type'        => 'string',
                            'description' => 'Question or prompt about the audio content',
                        ],
                    ],
                    'required' => ['audio_path', 'question'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'generate_audio',
                'description' => "Generate speech audio from text using Gemini TTS. Supports single-speaker and multi-speaker (up to 2) generation. Style, tone, pace, and accent are controllable via natural language in the text prompt.\n\nAvailable voices: {$voiceList}\n\nFor style control, include directions in the text like: \"Say cheerfully: Hello!\" or \"In a whisper: The secret is...\"\nFor multi-speaker, format as dialogue: \"Speaker1: Hello! Speaker2: Hi there!\"",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'text' => [
                            'type'        => 'string',
                            'description' => "Text to convert to speech. Include style directions for tone/pace control. For multi-speaker, use 'SpeakerName: dialogue' format.",
                        ],
                        'voice' => [
                            'type'        => 'string',
                            'description' => 'Voice name for single-speaker. Options: Kore (Firm), Puck (Upbeat), Charon (Informative), Aoede (Breezy), Fenrir (Excitable), etc. Default: Kore',
                        ],
                        'speakers' => [
                            'type'        => 'array',
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'speaker' => ['type' => 'string', 'description' => 'Speaker name as used in the text'],
                                    'voice'   => ['type' => 'string', 'description' => 'Voice name for this speaker'],
                                ],
                                'required' => ['speaker', 'voice'],
                            ],
                            'description' => 'For multi-speaker: array of {speaker, voice} mappings. Max 2 speakers.',
                        ],
                        'output_name' => [
                            'type'        => 'string',
                            'description' => 'Base name for output WAV file (saved to workspace/output/)',
                        ],
                    ],
                    'required' => ['text', 'output_name'],
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
                            'description' => "File path relative to the script directory",
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
                            'description' => "File path relative to the script directory",
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
 * Get audio MIME type from file extension.
 */
function getAudioMimeType(string $filepath): string
{
    $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

    return match ($ext) {
        'mp3'         => 'audio/mp3',
        'wav'         => 'audio/wav',
        'aiff'        => 'audio/aiff',
        'aac'         => 'audio/aac',
        'ogg'         => 'audio/ogg',
        'flac'        => 'audio/flac',
        'm4a'         => 'audio/mp4',
        'webm'        => 'audio/webm',
        default       => 'audio/mpeg',
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
 * Upload an audio file to Gemini Files API (for files >20MB).
 *
 * Uses the resumable upload protocol:
 *  1. POST to init endpoint → get upload URL
 *  2. POST binary data to upload URL → get file URI
 */
function uploadAudioToGemini(string $audioData, string $mimeType, string $displayName): array
{
    global $geminiApiKey, $geminiUploadEndpoint;

    logMsg('GEMINI', "Uploading audio file: {$displayName}", 'info');

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
            'X-Goog-Upload-Header-Content-Length: ' . strlen($audioData),
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
            'Content-Length: ' . strlen($audioData),
            'X-Goog-Upload-Offset: 0',
            'X-Goog-Upload-Command: upload, finalize',
        ],
        CURLOPT_POSTFIELDS     => $audioData,
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
 * Load audio and prepare for Gemini.
 * Returns either fileUri (uploaded/YouTube) or audioBase64 (inline).
 */
function loadAudio(string $audioPath): array
{
    if (isYouTubeUrl($audioPath))
    {
        logMsg('AUDIO', 'YouTube URL detected', 'info');
        return ['fileUri' => $audioPath, 'mimeType' => 'video/mp4'];
    }

    $fullPath = WORKSPACE . '/' . $audioPath;

    if (!file_exists($fullPath))
    {
        throw new RuntimeException("Audio file not found: {$audioPath}");
    }

    $data        = file_get_contents($fullPath);
    $mimeType    = getAudioMimeType($audioPath);
    $displayName = basename($audioPath);

    if (strlen($data) > INLINE_SIZE_LIMIT)
    {
        logMsg('AUDIO', 'File > 20MB, using upload API...', 'info');
        $uploaded = uploadAudioToGemini($data, $mimeType, $displayName);
        return ['fileUri' => $uploaded['fileUri'], 'mimeType' => $mimeType];
    }

    return ['audioBase64' => base64_encode($data), 'mimeType' => $mimeType];
}

/**
 * Call Gemini generateContent with audio input.
 *
 * @param array       $audio          From loadAudio() — has fileUri or audioBase64 + mimeType
 * @param string      $prompt         Text prompt/instructions
 * @param array|null  $responseSchema Optional JSON schema for structured output
 * @return string|array               Text response or parsed JSON if schema provided
 */
function callGeminiAudio(array $audio, string $prompt, ?array $responseSchema = null): string|array
{
    global $geminiApiKey, $geminiGenerateEndpoint;

    logMsg('GEMINI', 'Processing audio — ' . substr($prompt, 0, 80) . '...', 'info');

    $parts = [['text' => $prompt]];

    if (!empty($audio['fileUri']))
    {
        $parts[] = [
            'file_data' => [
                'mime_type' => $audio['mimeType'],
                'file_uri'  => $audio['fileUri'],
            ],
        ];
    }
    elseif (!empty($audio['audioBase64']))
    {
        $parts[] = [
            'inline_data' => [
                'mime_type' => $audio['mimeType'],
                'data'      => $audio['audioBase64'],
            ],
        ];
    }
    else
    {
        throw new RuntimeException('Either fileUri or audioBase64 must be provided');
    }

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
        CURLOPT_TIMEOUT        => 240,
    ]);

    $responseBody = curl_exec($ch);
    $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error        = curl_error($ch);
    curl_close($ch);

    if ($error || $httpCode !== 200)
    {
        throw new RuntimeException("Gemini audio call failed (HTTP {$httpCode}): {$error}");
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

    logMsg('GEMINI', "Processed audio (" . strlen($text) . " chars)", 'secondary');

    if ($responseSchema)
    {
        $parsed = json_decode($text, true);
        return $parsed ?? $text;
    }

    return $text;
}

/**
 * Call Gemini TTS endpoint to generate speech.
 *
 * @param string      $text     Text to speak
 * @param string|null $voice    Voice name (single-speaker)
 * @param array|null  $speakers Multi-speaker config [{speaker, voice}, ...]
 * @return string               Raw PCM audio data (24kHz, 16-bit, mono)
 */
function callGeminiTts(string $text, ?string $voice = null, ?array $speakers = null): string
{
    global $geminiApiKey, $geminiTtsEndpoint;

    $isMulti = !empty($speakers);
    $label   = $isMulti
        ? implode(', ', array_map(fn($s) => $s['voice'], $speakers))
        : ($voice ?? 'Kore');

    logMsg('GEMINI', "Generating speech — {$label}: " . substr($text, 0, 60) . '...', 'info');

    if ($isMulti)
    {
        $speakerVoiceConfigs = array_map(fn($s) => [
            'speaker'     => $s['speaker'],
            'voiceConfig' => [
                'prebuiltVoiceConfig' => ['voiceName' => $s['voice']],
            ],
        ], $speakers);

        $speechConfig = [
            'multiSpeakerVoiceConfig' => [
                'speakerVoiceConfigs' => $speakerVoiceConfigs,
            ],
        ];
    }
    else
    {
        $speechConfig = [
            'voiceConfig' => [
                'prebuiltVoiceConfig' => ['voiceName' => $voice ?? 'Kore'],
            ],
        ];
    }

    $body = [
        'contents' => [
            ['parts' => [['text' => $text]]],
        ],
        'generationConfig' => [
            'responseModalities' => ['AUDIO'],
            'speechConfig'       => $speechConfig,
        ],
    ];

    $ch = curl_init($geminiTtsEndpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'x-goog-api-key: ' . $geminiApiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_TIMEOUT        => 240,
    ]);

    $responseBody = curl_exec($ch);
    $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error        = curl_error($ch);
    curl_close($ch);

    if ($error || $httpCode !== 200)
    {
        throw new RuntimeException("Gemini TTS failed (HTTP {$httpCode}): {$error}");
    }

    $data = json_decode($responseBody, true);

    if (!empty($data['error']))
    {
        throw new RuntimeException($data['error']['message'] ?? json_encode($data['error']));
    }

    $audioData = $data['candidates'][0]['content']['parts'][0]['inlineData']['data'] ?? null;

    if (!$audioData)
    {
        throw new RuntimeException('No audio data in TTS response');
    }

    logMsg('GEMINI', "Generated speech with voice: {$label}", 'success');

    return base64_decode($audioData);
}

/**
 * Write raw PCM data as a WAV file.
 * Gemini TTS outputs 24kHz, 16-bit, mono PCM.
 */
function writeWavFile(string $filepath, string $pcmData): void
{
    $sampleRate    = 24000;
    $numChannels   = 1;
    $bitsPerSample = 16;
    $byteRate      = $sampleRate * $numChannels * ($bitsPerSample / 8);
    $blockAlign    = $numChannels * ($bitsPerSample / 8);
    $dataSize      = strlen($pcmData);

    $header = '';
    $header .= 'RIFF';
    $header .= pack('V', 36 + $dataSize);   // file size - 8
    $header .= 'WAVE';

    // fmt chunk
    $header .= 'fmt ';
    $header .= pack('V', 16);               // chunk size
    $header .= pack('v', 1);                // PCM format
    $header .= pack('v', $numChannels);
    $header .= pack('V', $sampleRate);
    $header .= pack('V', $byteRate);
    $header .= pack('v', $blockAlign);
    $header .= pack('v', $bitsPerSample);

    // data chunk
    $header .= 'data';
    $header .= pack('V', $dataSize);

    file_put_contents($filepath, $header . $pcmData);
}

// =============================================================================
// TOOL HANDLERS
// =============================================================================

function handleTool(string $name, array $args): array
{
    return match ($name) {
        'transcribe_audio' => toolTranscribeAudio($args),
        'analyze_audio'    => toolAnalyzeAudio($args),
        'query_audio'      => toolQueryAudio($args),
        'generate_audio'   => toolGenerateAudio($args),
        'list_directory'   => toolListDirectory($args),
        'read_file'        => toolReadFile($args),
        'write_file'       => toolWriteFile($args),
        default            => ['error' => "Unknown tool: {$name}"],
    };
}

/**
 * Transcribe audio with timestamps, speaker detection, emotions, translation.
 */
function toolTranscribeAudio(array $args): array
{
    $audioPath         = $args['audio_path'];
    $includeTimestamps = $args['include_timestamps'] ?? true;
    $detectSpeakers    = $args['detect_speakers'] ?? true;
    $detectEmotions    = $args['detect_emotions'] ?? false;
    $translateTo       = $args['translate_to'] ?? null;
    $outputName        = $args['output_name'] ?? null;

    logMsg('TOOL', "transcribe_audio — {$audioPath}", 'info');

    try
    {
        $audio = loadAudio($audioPath);

        // Build transcription prompt
        $prompt = "Process this audio file and generate a detailed transcription.\n\nRequirements:\n";

        if ($detectSpeakers)
            $prompt .= "- Identify distinct speakers (e.g., Speaker 1, Speaker 2, or names if context allows).\n";
        if ($includeTimestamps)
            $prompt .= "- Provide accurate timestamps for each segment (Format: MM:SS).\n";

        $prompt .= "- Detect the primary language of each segment.\n";

        if ($translateTo)
            $prompt .= "- Translate all segments to {$translateTo}.\n";
        if ($detectEmotions)
            $prompt .= "- Identify the primary emotion of the speaker. Choose exactly one: happy, sad, angry, neutral.\n";

        $prompt .= "- Provide a brief summary of the entire audio at the beginning.";

        // Build response schema
        $segmentProperties = [
            'content'  => ['type' => 'STRING'],
            'language' => ['type' => 'STRING'],
        ];

        if ($includeTimestamps) $segmentProperties['timestamp'] = ['type' => 'STRING'];
        if ($detectSpeakers)    $segmentProperties['speaker']   = ['type' => 'STRING'];
        if ($translateTo)       $segmentProperties['translation'] = ['type' => 'STRING'];
        if ($detectEmotions)    $segmentProperties['emotion'] = [
            'type' => 'STRING',
            'enum' => ['happy', 'sad', 'angry', 'neutral'],
        ];

        $required = ['content'];
        if ($includeTimestamps) $required[] = 'timestamp';
        if ($detectSpeakers)    $required[] = 'speaker';

        $schema = [
            'type'       => 'OBJECT',
            'properties' => [
                'summary'           => ['type' => 'STRING', 'description' => 'A concise summary of the audio content.'],
                'duration_estimate' => ['type' => 'STRING', 'description' => 'Estimated duration of the audio.'],
                'primary_language'  => ['type' => 'STRING', 'description' => 'Primary language detected in the audio.'],
                'segments'          => [
                    'type'        => 'ARRAY',
                    'description' => 'List of transcribed segments.',
                    'items'       => [
                        'type'       => 'OBJECT',
                        'properties' => $segmentProperties,
                        'required'   => $required,
                    ],
                ],
            ],
            'required' => ['summary', 'segments'],
        ];

        $result = callGeminiAudio($audio, $prompt, $schema);

        // Save to file if output_name provided
        if ($outputName)
        {
            $outputDir = WORKSPACE . '/workspace/output';
            if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

            $timestamp  = (int)(microtime(true) * 1000);
            $outputFile = "{$outputName}_{$timestamp}.json";
            $outputPath = $outputDir . '/' . $outputFile;

            file_put_contents($outputPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            logMsg('RESULT', "Transcription saved: workspace/output/{$outputFile}", 'success');

            return [
                'success'       => true,
                'audio_path'    => $audioPath,
                'output_path'   => "workspace/output/{$outputFile}",
                'transcription' => $result,
            ];
        }

        $segCount = is_array($result) ? count($result['segments'] ?? []) : 0;
        logMsg('RESULT', "Transcribed: {$segCount} segments", 'success');

        return [
            'success'       => true,
            'audio_path'    => $audioPath,
            'transcription' => $result,
        ];
    }
    catch (Throwable $e)
    {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Analyze audio content (general, music, speech, sounds).
 */
function toolAnalyzeAudio(array $args): array
{
    $audioPath    = $args['audio_path'];
    $analysisType = $args['analysis_type'] ?? 'general';
    $customPrompt = $args['custom_prompt'] ?? null;
    $outputName   = $args['output_name'] ?? null;

    logMsg('TOOL', "analyze_audio — {$audioPath} ({$analysisType})", 'info');

    try
    {
        $audio = loadAudio($audioPath);

        $prompts = [
            'general' => "Analyze this audio file comprehensively. Describe:\n- Type of audio (speech, music, ambient sounds, mixed)\n- Main content and topics discussed (if speech)\n- Notable sounds or instruments (if music/sounds)\n- Audio quality assessment\n- Any notable characteristics or anomalies",
            'music'   => "Analyze this music audio. Describe:\n- Genre and style\n- Tempo (BPM estimate) and time signature\n- Key and mood\n- Instruments identified\n- Song structure (verse, chorus, bridge, etc.)\n- Vocals (if any): gender, style, language\n- Production quality assessment",
            'speech'  => "Analyze the speech in this audio. Describe:\n- Number of speakers and their characteristics\n- Speaking style (formal, casual, emotional)\n- Speech clarity and pace\n- Background noise assessment\n- Language and accent identification\n- Key topics and themes discussed",
            'sounds'  => "Analyze the sounds in this audio. Identify:\n- All distinct sound sources\n- Environmental context (indoor, outdoor, etc.)\n- Temporal patterns (continuous, intermittent)\n- Sound quality and recording conditions\n- Any notable or unusual sounds",
        ];

        $prompt = $customPrompt ?? ($prompts[$analysisType] ?? $prompts['general']);

        $schema = [
            'type'       => 'OBJECT',
            'properties' => [
                'audio_type'         => ['type' => 'STRING', 'description' => 'Primary type of audio content'],
                'summary'            => ['type' => 'STRING', 'description' => 'Brief summary of the audio'],
                'details'            => ['type' => 'STRING', 'description' => 'Detailed analysis results as text'],
                'quality_assessment' => ['type' => 'STRING', 'description' => 'Audio quality assessment'],
                'notable_elements'   => [
                    'type'        => 'ARRAY',
                    'items'       => ['type' => 'STRING'],
                    'description' => 'Notable elements or characteristics',
                ],
            ],
            'required' => ['audio_type', 'summary'],
        ];

        $result = callGeminiAudio($audio, $prompt, $schema);

        if ($outputName)
        {
            $outputDir = WORKSPACE . '/workspace/output';
            if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

            $timestamp  = (int)(microtime(true) * 1000);
            $outputFile = "{$outputName}_{$timestamp}.json";
            $outputPath = $outputDir . '/' . $outputFile;

            file_put_contents($outputPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            logMsg('RESULT', "Analysis saved: workspace/output/{$outputFile}", 'success');

            return [
                'success'       => true,
                'audio_path'    => $audioPath,
                'analysis_type' => $analysisType,
                'output_path'   => "workspace/output/{$outputFile}",
                'analysis'      => $result,
            ];
        }

        $audioType = is_array($result) ? ($result['audio_type'] ?? 'unknown') : 'unknown';
        logMsg('RESULT', "Analyzed: {$audioType}", 'success');

        return [
            'success'       => true,
            'audio_path'    => $audioPath,
            'analysis_type' => $analysisType,
            'analysis'      => $result,
        ];
    }
    catch (Throwable $e)
    {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Ask a custom question about audio content.
 */
function toolQueryAudio(array $args): array
{
    $audioPath = $args['audio_path'];
    $question  = $args['question'];

    logMsg('TOOL', "query_audio — " . substr($question, 0, 50) . '...', 'info');

    try
    {
        $audio  = loadAudio($audioPath);
        $result = callGeminiAudio($audio, $question);

        logMsg('RESULT', "Query answered (" . strlen($result) . " chars)", 'success');

        return [
            'success'    => true,
            'audio_path' => $audioPath,
            'question'   => $question,
            'answer'     => $result,
        ];
    }
    catch (Throwable $e)
    {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Generate speech audio from text (single or multi-speaker).
 */
function toolGenerateAudio(array $args): array
{
    global $ttsVoices;

    $text       = $args['text'];
    $voice      = $args['voice'] ?? 'Kore';
    $speakers   = $args['speakers'] ?? [];
    $outputName = $args['output_name'];
    $isMulti    = !empty($speakers);

    logMsg('TOOL', 'generate_audio — ' . ($isMulti ? 'multi-speaker' : "voice: {$voice}"), 'info');

    try
    {
        if ($isMulti)
        {
            if (count($speakers) > 2)
            {
                return ['error' => 'Maximum 2 speakers supported for multi-speaker TTS'];
            }

            $pcmData = callGeminiTts($text, null, $speakers);
        }
        else
        {
            if (!isset($ttsVoices[$voice]))
            {
                $validVoices = implode(', ', array_keys($ttsVoices));
                return ['error' => "Invalid voice \"{$voice}\". Valid options: {$validVoices}"];
            }

            $pcmData = callGeminiTts($text, $voice);
        }

        // Save as WAV
        $outputDir = WORKSPACE . '/workspace/output';
        if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

        $timestamp = (int)(microtime(true) * 1000);
        $filename  = "{$outputName}_{$timestamp}.wav";
        $fullPath  = $outputDir . '/' . $filename;

        writeWavFile($fullPath, $pcmData);

        $relativePath = "workspace/output/{$filename}";
        logMsg('AUDIO', "Saved: {$relativePath}", 'success');

        return [
            'success'      => true,
            'mode'         => $isMulti ? 'multi-speaker' : 'single-speaker',
            'output_path'  => $relativePath,
            'absolute_path'=> realpath($fullPath) ?: $fullPath,
            'project_root' => WORKSPACE,
            'voice'        => $isMulti ? $speakers : $voice,
            'text_length'  => strlen($text),
            'format'       => 'WAV (24kHz, 16-bit, mono)',
        ];
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

logSection('S01E04 — Audio Processing Agent');
logMsg('MODEL', $orModel, 'secondary');
logMsg('AUDIO', $audioModel, 'secondary');
logMsg('TTS', $ttsModel, 'secondary');

$tools = getTools();
logMsg('TOOLS', implode(', ', array_map(fn($t) => $t['function']['name'], $tools)), 'secondary');

$query = "Transcribe the audio file workspace/input/tech_briefing.wav with speaker detection and timestamps";

logMsg('QUERY', $query, 'primary');

try
{
    $finalAnswer = runAgent($query, $tools);

    logSection('Audio processing complete');
    logMsg('DONE', $finalAnswer, 'success');
}
catch (Throwable $e)
{
    logMsg('ERROR', $e->getMessage(), 'danger');
}

require __DIR__ . '/../../../lib/footer.php';
