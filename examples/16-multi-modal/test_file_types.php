<?php

/**
 * Multi-Modal Test - Testing various file types with Gemini
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\ContentPart;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Provider\Google\GoogleApi;
use WebFiori\Ai\Provider\Google\GoogleApiVersion;


$client = new GoogleClient([
    'credentials' => __DIR__.'/../../webfiori-9d1263770ba1.json',
    'model' => 'gemini-2.5-flash',
]);

echo "=== Multi-Modal File Type Tests ===\n\n";

// Test 1: Image (already verified working)
echo "1. Image (PNG) - ";
$redPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');
$message = new Message('user', [
    ContentPart::text('What color is this 1-pixel image? Answer with one word.'),
    ContentPart::imageBase64(base64_encode($redPng), 'image/png'),
]);
$response = $client->chat([$message]);
echo $response->getMessage()->getContent()."\n";

// Test 2: PDF document
echo "\n2. PDF Document - ";
// Create a minimal PDF
$pdfContent = "%PDF-1.4
1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj
2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj
3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R >> endobj
4 0 obj << /Length 44 >> stream
BT /F1 12 Tf 100 700 Td (Hello PDF World!) Tj ET
endstream endobj
xref
0 5
0000000000 65535 f 
0000000009 00000 n 
0000000058 00000 n 
0000000115 00000 n 
0000000214 00000 n 
trailer << /Size 5 /Root 1 0 R >>
startxref
306
%%EOF";

$message = new Message('user', [
    ContentPart::text('This is a PDF document. What text does it contain? If you can\'t read it, just say "cannot read".'),
    ContentPart::document(base64_encode($pdfContent), 'application/pdf'),
]);

try {
    $response = $client->chat([$message]);
    echo $response->getMessage()->getContent()."\n";
} catch (Exception $e) {
    echo "Error: ".$e->getMessage()."\n";
}

// Test 3: Text/Code file
echo "\n3. Text/Code File - ";
$codeContent = "def greet(name):\n    return f'Hello, {name}!'\n\nprint(greet('World'))";
$message = new Message('user', [
    ContentPart::text('What does this Python code do? Answer briefly.'),
    ContentPart::document(base64_encode($codeContent), 'text/plain'),
]);
$response = $client->chat([$message]);
echo $response->getMessage()->getContent()."\n";

// Test 4: JSON file
echo "\n4. JSON File - ";
$jsonContent = '{"name": "WebFiori AI", "version": "1.0.0", "features": ["chat", "embeddings", "vision"]}';
$message = new Message('user', [
    ContentPart::text('What is the name and version in this JSON? Answer format: "name vX.X.X"'),
    ContentPart::document(base64_encode($jsonContent), 'application/json'),
]);
try {
    $response = $client->chat([$message]);
    echo $response->getMessage()->getContent()."\n";
} catch (Exception $e) {
    echo "Error: ".$e->getMessage()."\n";
}

// Test 5: CSV data
echo "\n5. CSV Data - ";
$csvContent = "Name,Age,City\nAlice,30,New York\nBob,25,Los Angeles\nCharlie,35,Chicago";
$message = new Message('user', [
    ContentPart::text('How many people are in this CSV and what is the average age? Be brief.'),
    ContentPart::document(base64_encode($csvContent), 'text/csv'),
]);
try {
    $response = $client->chat([$message]);
    echo $response->getMessage()->getContent()."\n";
} catch (Exception $e) {
    echo "Error: ".$e->getMessage()."\n";
}

// Test 6: Using file() method with a temp file
echo "\n6. Local File (via file() method) - ";
$tempFile = sys_get_temp_dir().'/test_code_'.uniqid().'.py';
file_put_contents($tempFile, "# Calculator\ndef add(a, b):\n    return a + b\n\nresult = add(5, 3)\nprint(result)");

$message = new Message('user', [
    ContentPart::text('What will this code print? Just the number.'),
    ContentPart::file($tempFile),
]);
try {
    $response = $client->chat([$message]);
    echo $response->getMessage()->getContent()."\n";
} catch (Exception $e) {
    echo "Error: ".$e->getMessage()."\n";
}
unlink($tempFile);

// Test 7: Multiple file types in one message
echo "\n7. Multiple Files - ";
$message = new Message('user', [
    ContentPart::text('I have an image and some code. What color is the image and what does the code do?'),
    ContentPart::imageBase64(base64_encode($redPng), 'image/png'),
    ContentPart::document(base64_encode("console.log('Hello');"), 'text/plain'),
]);
try {
    $response = $client->chat([$message]);
    echo $response->getMessage()->getContent()."\n";
} catch (Exception $e) {
    echo "Error: ".$e->getMessage()."\n";
}

echo "\n=== Tests Complete ===\n";
