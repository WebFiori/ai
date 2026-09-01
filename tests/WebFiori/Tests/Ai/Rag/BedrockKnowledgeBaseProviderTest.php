<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai\Rag;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Exception\UnsupportedFeatureException;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Rag\BedrockKbConfig;
use WebFiori\Ai\Rag\BedrockKnowledgeBaseProvider;

/**
 * Offline tests for BedrockKnowledgeBaseProvider using FakeHttpClient.
 *
 * Explicit credentials are supplied so the AWS SigV4 signer is built without
 * touching the credential chain or the network.
 */
class BedrockKnowledgeBaseProviderTest extends TestCase {
    private function config(): BedrockKbConfig {
        return new BedrockKbConfig(
            region: 'us-east-1',
            knowledgeBaseId: 'KB12345',
            accessKey: 'AKIA_TEST',
            secretKey: 'SECRET_TEST',
        );
    }

    private function provider(FakeHttpClient $http): BedrockKnowledgeBaseProvider {
        $provider = new BedrockKnowledgeBaseProvider($this->config(), $http);

        return $provider;
    }

    // =========================================================================
    // retrieve()
    // =========================================================================

    public function testRetrieve_ParsesResults(): void {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(200, [], json_encode([
            'retrievalResults' => [
                [
                    'content' => ['text' => 'PHP is a scripting language.'],
                    'score' => 0.92,
                    'location' => ['s3Location' => ['uri' => 's3://bucket/php.txt'], 'type' => 'S3'],
                    'metadata' => ['category' => 'lang'],
                ],
                [
                    'content' => ['text' => 'Composer manages dependencies.'],
                    'score' => 0.81,
                    'location' => ['type' => 'S3'],
                ],
            ],
        ])));

        $results = $this->provider($http)->retrieve('what is php', topK: 5);

        $this->assertCount(2, $results);

        $this->assertSame('PHP is a scripting language.', $results[0]->getText());
        $this->assertEqualsWithDelta(0.92, $results[0]->getScore(), 0.001);
        $this->assertSame('s3://bucket/php.txt', $results[0]->getMetadata()['source']);
        $this->assertSame('lang', $results[0]->getMetadata()['category']);

        // Second result has no s3Location — falls back to location_type.
        $this->assertSame('S3', $results[1]->getMetadata()['location_type']);
    }

    public function testRetrieve_SendsSignedRequestToCorrectEndpoint(): void {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(200, [], json_encode(['retrievalResults' => []])));

        $this->provider($http)->retrieve('q', topK: 3);

        $request = $http->getLastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringContainsString(
            'bedrock-agent-runtime.us-east-1.amazonaws.com/knowledgebases/KB12345/retrieve',
            $request->getUrl()
        );
        // SigV4 signer must have added an Authorization header.
        $this->assertNotNull($request->getHeader('Authorization'));

        $body = json_decode($request->getBody(), true);
        $this->assertSame('q', $body['retrievalQuery']['text']);
        $this->assertSame(3, $body['retrievalConfiguration']['vectorSearchConfiguration']['numberOfResults']);
    }

    public function testRetrieve_AppliesFilterAndSearchTypeOptions(): void {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(200, [], json_encode(['retrievalResults' => []])));

        $this->provider($http)->retrieve('q', topK: 5, options: [
            'filter' => ['equals' => ['key' => 'x', 'value' => 'y']],
            'search_type' => 'HYBRID',
        ]);

        $body = json_decode($http->getLastRequest()->getBody(), true);
        $vsc = $body['retrievalConfiguration']['vectorSearchConfiguration'];
        $this->assertSame(['equals' => ['key' => 'x', 'value' => 'y']], $vsc['filter']);
        $this->assertSame('HYBRID', $vsc['overrideSearchType']);
    }

    public function testRetrieve_EmptyResults(): void {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(200, [], json_encode(['retrievalResults' => []])));

        $this->assertSame([], $this->provider($http)->retrieve('q'));
    }

    public function testRetrieve_ThrowsProviderExceptionOnErrorStatus(): void {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(403, [], json_encode(['message' => 'Access denied'])));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Access denied');

        $this->provider($http)->retrieve('q');
    }

    // =========================================================================
    // Unsupported operations
    // =========================================================================

    public function testIngest_Throws(): void {
        $this->expectException(UnsupportedFeatureException::class);
        $this->provider(new FakeHttpClient())->ingest('content');
    }

    public function testDelete_Throws(): void {
        $this->expectException(UnsupportedFeatureException::class);
        $this->provider(new FakeHttpClient())->delete('id');
    }

    // =========================================================================
    // Config / accessors
    // =========================================================================

    public function testGetConfig_ReturnsConfig(): void {
        $config = $this->config();
        $provider = new BedrockKnowledgeBaseProvider($config, new FakeHttpClient());

        $this->assertSame($config, $provider->getConfig());
    }

    public function testConstructor_ThrowsWhenCredentialsUnresolvable(): void {
        // When no explicit keys are given, the provider falls back to the AWS
        // credential chain. Consult the chain directly: if it can resolve
        // credentials on this machine (env, ~/.aws/credentials, metadata),
        // skip — otherwise the constructor must throw ProviderException.
        $chain = new \WebFiori\Ai\Auth\AwsCredentialChain();

        if ($chain->resolve() !== null) {
            $this->markTestSkipped('AWS credentials resolvable in this environment.');
        }

        $config = new BedrockKbConfig(region: 'us-east-1', knowledgeBaseId: 'KB');

        $this->expectException(ProviderException::class);
        new BedrockKnowledgeBaseProvider($config, new FakeHttpClient());
    }
}
