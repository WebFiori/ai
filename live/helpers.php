<?php

/**
 * Live test helpers — shared utilities for all live test scripts.
 */

require_once __DIR__.'/../vendor/autoload.php';

// ─── Credentials ──────────────────────────────────────────────────────────────

define('KEY_PATH', getenv('GCP_KEY_PATH') ?: __DIR__.'/../keys/vertex-ai-key.json');
define('GCP_PROJECT', getenv('GCP_PROJECT_ID') ?: 'webfiori');
define('GCP_LOCATION', getenv('GCP_LOCATION') ?: 'us-central1');
define('GEMINI_2_MODEL', getenv('GEMINI_2_MODEL') ?: 'gemini-2.5-flash');
define('GEMINI_3_MODEL', getenv('GEMINI_3_MODEL') ?: 'gemini-3.5-flash');
define('BEDROCK_REGION', getenv('AWS_REGION') ?: 'us-east-1');
// Note: Update this to a model currently active on the account.
// The test key may be restricted to specific model versions.
define('BEDROCK_MODEL', getenv('BEDROCK_MODEL') ?: 'us.amazon.nova-lite-v1:0');

// ─── Output helpers ───────────────────────────────────────────────────────────

function pass(string $label): void {
    echo "\033[32m  ✅ PASS\033[0m  {$label}\n";
}

function fail(string $label, string $reason = ''): void {
    echo "\033[31m  ❌ FAIL\033[0m  {$label}";

    if ($reason) {
        echo " — {$reason}";
    }
    echo "\n";
}

function section(string $title): void {
    echo "\n\033[1;36m══ {$title} ══\033[0m\n";
}

function run(string $label, callable $test): void {
    try {
        $result = $test();

        if ($result === false) {
            fail($label);
        } else {
            pass($label);
        }
    } catch (Throwable $e) {
        fail($label, get_class($e).': '.$e->getMessage());
    }
}

// ─── Shared config builders ───────────────────────────────────────────────────

use WebFiori\Ai\Provider\Anthropic\AnthropicClient;
use WebFiori\Ai\Provider\Anthropic\AnthropicClientConfig;
use WebFiori\Ai\Provider\Bedrock\BedrockClient;
use WebFiori\Ai\Provider\Bedrock\BedrockClientConfig;
use WebFiori\Ai\Provider\Google\GoogleApi;
use WebFiori\Ai\Provider\Google\GoogleApiVersion;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;

function gemini2Client(): GoogleClient {
    return new GoogleClient(new GoogleClientConfig(
        model: GEMINI_2_MODEL,
        projectId: GCP_PROJECT,
        location: GCP_LOCATION,
        credentials: KEY_PATH,
        api: GoogleApi::VERTEX_AI,
    ));
}

function gemini3Client(): GoogleClient {
    return new GoogleClient(new GoogleClientConfig(
        model: GEMINI_3_MODEL,
        credentials: KEY_PATH,
        api: GoogleApi::GEMINI, // gemini-3.5-flash is on Gemini API, not Vertex yet
        apiVersion: GoogleApiVersion::INTERACTIONS,
    ));
}

function anthropicClient(): AnthropicClient {
    $apiKey = getenv('ANTHROPIC_API_KEY');
    $model = getenv('ANTHROPIC_MODEL') ?: 'claude-haiku-4-5-20251001';

    if (!$apiKey) {
        throw new RuntimeException('ANTHROPIC_API_KEY not set. Run: source keys/env.sh');
    }

    return new AnthropicClient(new AnthropicClientConfig(
        apiKey: $apiKey,
        model: $model,
    ));
}

function claudeOnVertexClient(): GoogleClient {
    return new GoogleClient(new GoogleClientConfig(
        model: 'claude-sonnet-5',
        projectId: GCP_PROJECT,
        location: 'global',
        credentials: KEY_PATH,
        api: GoogleApi::VERTEX_AI,
        publisher: 'anthropic',
    ));
}

function bedrockClient(): BedrockClient {
    $accessKey = getenv('AWS_ACCESS_KEY_ID');
    $secretKey = getenv('AWS_SECRET_ACCESS_KEY');

    if (!$accessKey || !$secretKey) {
        throw new RuntimeException('AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY not set. Run: source keys/env.sh');
    }

    return new BedrockClient(new BedrockClientConfig(
        region: BEDROCK_REGION,
        model: BEDROCK_MODEL,
        accessKey: $accessKey,
        secretKey: $secretKey,
    ));
}
