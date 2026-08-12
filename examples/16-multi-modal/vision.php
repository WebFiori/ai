<?php

/**
 * Multi-Modal Chat Example - Sending images with text to AI models.
 *
 * This example demonstrates how to send images along with text prompts
 * to vision-capable AI models (GPT-4o, Gemini, Claude 3, etc.).
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\ContentPart;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleClient;

// Create the provider with credentials
$client = new GoogleClient([
    'credentials' => __DIR__.'/../../webfiori-9d1263770ba1.json',
    'model' => 'gemini-2.5-flash',
]);

// Example 1: Image from base64 (most reliable method)
echo "=== Example 1: Analyzing a base64 encoded image ===\n\n";

// Create a simple 1x1 red PNG
$redPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');

$message = new Message('user', [
    ContentPart::text('This is a 1 pixel image. What color is it? Answer with just the color name.'),
    ContentPart::imageBase64(base64_encode($redPng), 'image/png'),
]);

$response = $client->chat([$message]);
echo "Response: ".$response->getMessage()->getContent()."\n\n";

// Example 2: Another color test
echo "=== Example 2: Testing with a blue pixel ===\n\n";

// 1x1 blue PNG
$bluePng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPj/HwADBwIAMC5PCAAAAABJRU5ErkJggg==');

$message = new Message('user', [
    ContentPart::text('What color is this single pixel image? Just say the color.'),
    ContentPart::imageBase64(base64_encode($bluePng), 'image/png'),
]);

$response = $client->chat([$message]);
echo "Response: ".$response->getMessage()->getContent()."\n\n";

// Example 3: Multiple images comparison
echo "=== Example 3: Comparing two images ===\n\n";

$message = new Message('user', [
    ContentPart::text('I have two 1-pixel images. Tell me the color of each one, labeled as Image 1 and Image 2.'),
    ContentPart::imageBase64(base64_encode($redPng), 'image/png'),
    ContentPart::imageBase64(base64_encode($bluePng), 'image/png'),
]);

$response = $client->chat([$message]);
echo "Response: ".$response->getMessage()->getContent()."\n\n";

// Example 4: Test backward compatibility (string content)
echo "=== Example 4: Backward compatibility (text-only) ===\n\n";

$message = new Message('user', 'What is 2 + 2? Answer with just the number.');
$response = $client->chat([$message]);
echo "Response: ".$response->getMessage()->getContent()."\n\n";

echo "=== Usage Statistics ===\n";
echo "Model: ".$response->getModel()."\n";

if ($response->getUsage()) {
    echo "Input tokens: ".$response->getUsage()->getPromptTokens()."\n";
    echo "Output tokens: ".$response->getUsage()->getCompletionTokens()."\n";
}
