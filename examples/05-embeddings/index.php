<?php

/**
 * Example 05: Semantic Similarity (Web)
 *
 * Start: php -S localhost:8080 -t examples/05-embeddings
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Provider\OpenAI\OpenAIClient;

$similarity = null;
$error = null;
$text1 = '';
$text2 = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['text1']) && !empty($_POST['text2'])) {
    $text1 = trim($_POST['text1']);
    $text2 = trim($_POST['text2']);

    try {
        $provider = new OpenAIClient([
            'api_key' => getenv('OPENAI_API_KEY'),
            'model' => 'gpt-4o',
        ]);

        $response = $provider->embed([$text1, $text2], ['model' => 'text-embedding-3-small']);
        $vectors = $response->getVectors();
        $similarity = cosineSimilarity($vectors[0], $vectors[1]);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function cosineSimilarity(array $a, array $b): float {
    $dotProduct = 0;
    $normA = 0;
    $normB = 0;

    for ($i = 0; $i < count($a); $i++) {
        $dotProduct += $a[$i] * $b[$i];
        $normA += $a[$i] * $a[$i];
        $normB += $b[$i] * $b[$i];
    }

    $denominator = sqrt($normA) * sqrt($normB);

    return $denominator > 0 ? $dotProduct / $denominator : 0.0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Semantic Similarity — WebFiori AI</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; max-width: 700px; margin: 40px auto; padding: 0 20px; color: #333; }
        h1 { margin-bottom: 8px; }
        p.subtitle { color: #666; margin-bottom: 24px; }
        form { margin-bottom: 24px; }
        textarea { width: 100%; padding: 10px 14px; border: 1px solid #ccc; border-radius: 6px; font-size: 15px; font-family: inherit; resize: vertical; min-height: 60px; margin-bottom: 12px; }
        label { font-weight: 500; display: block; margin-bottom: 6px; }
        button { padding: 10px 20px; background: #2563eb; color: #fff; border: none; border-radius: 6px; font-size: 16px; cursor: pointer; }
        button:hover { background: #1d4ed8; }
        .result { text-align: center; padding: 24px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; }
        .score { font-size: 48px; font-weight: bold; color: #2563eb; }
        .label { font-size: 14px; color: #64748b; margin-top: 4px; }
        .error { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 12px; border-radius: 6px; margin-bottom: 16px; }
    </style>
</head>
<body>
    <h1>Semantic Similarity</h1>
    <p class="subtitle">Enter two texts and see how semantically similar they are (0 = unrelated, 1 = identical meaning).</p>

    <?php if ($error) { ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php } ?>

    <form method="POST">
        <label for="text1">Text 1</label>
        <textarea name="text1" id="text1" placeholder="How do I reset my password?"><?= htmlspecialchars($text1) ?></textarea>

        <label for="text2">Text 2</label>
        <textarea name="text2" id="text2" placeholder="I forgot my login credentials"><?= htmlspecialchars($text2) ?></textarea>

        <button type="submit">Compare</button>
    </form>

    <?php if ($similarity !== null) { ?>
        <div class="result">
            <div class="score"><?= number_format($similarity, 3) ?></div>
            <div class="label">Cosine Similarity</div>
        </div>
    <?php } ?>
</body>
</html>
