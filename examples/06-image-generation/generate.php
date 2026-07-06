<?php

/**
 * Example 06: Image Generation (CLI)
 *
 * Run: php examples/06-image-generation/generate.php
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\Provider\OpenAI\OpenAIProvider;

$provider = new OpenAIProvider([
    'api_key' => getenv('OPENAI_API_KEY'),
    'model' => 'gpt-4o',
]);

$request = new ImageRequest(
    prompt: 'A serene Japanese garden with a small bridge over a koi pond, watercolor style',
    size: '1024x1024',
    quality: 'hd',
    style: 'natural'
);

echo 'Generating image...'.PHP_EOL;

$response = $provider->generateImage($request);
$image = $response->getImages()[0];

echo 'URL: '.$image->getUrl().PHP_EOL;

if ($image->getRevisedPrompt()) {
    echo 'Revised prompt: '.$image->getRevisedPrompt().PHP_EOL;
}
