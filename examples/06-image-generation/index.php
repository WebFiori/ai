<?php

/**
 * Example 06: Image Generation (Web)
 *
 * Start: php -S localhost:8080 -t examples/06-image-generation
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\Provider\OpenAI\OpenAIProvider;

$imageUrl = null;
$revisedPrompt = null;
$error = null;
$prompt = '';
$size = '1024x1024';
$quality = 'standard';
$style = 'vivid';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['prompt'])) {
    $prompt = trim($_POST['prompt']);
    $size = $_POST['size'] ?? '1024x1024';
    $quality = $_POST['quality'] ?? 'standard';
    $style = $_POST['style'] ?? 'vivid';

    try {
        $provider = new OpenAIProvider([
            'api_key' => getenv('OPENAI_API_KEY'),
            'model' => 'gpt-4o',
        ]);

        $request = new ImageRequest(
            prompt: $prompt,
            size: $size,
            quality: $quality,
            style: $style
        );

        $response = $provider->generateImage($request);
        $image = $response->getImages()[0];
        $imageUrl = $image->getUrl();
        $revisedPrompt = $image->getRevisedPrompt();
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Image Generation — WebFiori AI</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; max-width: 800px; margin: 40px auto; padding: 0 20px; color: #333; }
        h1 { margin-bottom: 8px; }
        p.subtitle { color: #666; margin-bottom: 24px; }
        form { margin-bottom: 24px; }
        label { font-weight: 500; display: block; margin-bottom: 6px; margin-top: 12px; }
        textarea { width: 100%; padding: 10px 14px; border: 1px solid #ccc; border-radius: 6px; font-size: 15px; font-family: inherit; resize: vertical; min-height: 80px; }
        select { padding: 8px 12px; border: 1px solid #ccc; border-radius: 6px; font-size: 15px; }
        .options { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 16px; }
        .options > div { flex: 1; min-width: 150px; }
        button { padding: 10px 20px; background: #2563eb; color: #fff; border: none; border-radius: 6px; font-size: 16px; cursor: pointer; margin-top: 16px; }
        button:hover { background: #1d4ed8; }
        .result { margin-top: 24px; }
        .result img { width: 100%; border-radius: 8px; border: 1px solid #e2e8f0; }
        .revised { font-size: 13px; color: #64748b; margin-top: 8px; font-style: italic; }
        .error { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 12px; border-radius: 6px; margin-bottom: 16px; }
    </style>
</head>
<body>
    <h1>Image Generation</h1>
    <p class="subtitle">Generate images from text prompts using DALL-E 3.</p>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <label for="prompt">Prompt</label>
        <textarea name="prompt" id="prompt" placeholder="A serene Japanese garden with a small bridge over a koi pond..."><?= htmlspecialchars($prompt) ?></textarea>

        <div class="options">
            <div>
                <label for="size">Size</label>
                <select name="size" id="size">
                    <option value="1024x1024" <?= $size === '1024x1024' ? 'selected' : '' ?>>1024×1024</option>
                    <option value="1792x1024" <?= $size === '1792x1024' ? 'selected' : '' ?>>1792×1024 (wide)</option>
                    <option value="1024x1792" <?= $size === '1024x1792' ? 'selected' : '' ?>>1024×1792 (tall)</option>
                </select>
            </div>
            <div>
                <label for="quality">Quality</label>
                <select name="quality" id="quality">
                    <option value="standard" <?= $quality === 'standard' ? 'selected' : '' ?>>Standard</option>
                    <option value="hd" <?= $quality === 'hd' ? 'selected' : '' ?>>HD</option>
                </select>
            </div>
            <div>
                <label for="style">Style</label>
                <select name="style" id="style">
                    <option value="vivid" <?= $style === 'vivid' ? 'selected' : '' ?>>Vivid</option>
                    <option value="natural" <?= $style === 'natural' ? 'selected' : '' ?>>Natural</option>
                </select>
            </div>
        </div>

        <button type="submit">Generate Image</button>
    </form>

    <?php if ($imageUrl): ?>
        <div class="result">
            <img src="<?= htmlspecialchars($imageUrl) ?>" alt="Generated image">
            <?php if ($revisedPrompt): ?>
                <p class="revised">Revised prompt: <?= htmlspecialchars($revisedPrompt) ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</body>
</html>
