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
use ReflectionClass;
use WebFiori\Ai\Auth\GoogleAuth;
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Exception\UnsupportedFeatureException;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Rag\GoogleRagConfig;
use WebFiori\Ai\Rag\GoogleRagProvider;

/**
 * Offline tests for GoogleRagProvider using FakeHttpClient.
 *
 * The provider builds its own GoogleAuth internally. To keep the tests fully
 * offline (no token exchange over the network), a pre-configured access token
 * is injected into that GoogleAuth via reflection — GoogleAuth::getAccessToken()
 * returns a pre-configured token before attempting any credential resolution.
 */
class GoogleRagProviderTest extends TestCase {
    private function config(): GoogleRagConfig {
        return new GoogleRagConfig(
            projectId: 'my-project',
            location: 'us-central1',
            corpusId: 'corpus-1',
            credentials: ['type' => 'service_account'],
        );
    }

    private function provider(FakeHttpClient $http): GoogleRagProvider {
        $provider = new GoogleRagProvider($this->config(), $http);
        $this->injectToken($provider, 'ya29.fake-token');

        return $provider;
    }

    /**
     * Replaces the provider's internal GoogleAuth with one carrying a
     * pre-configured token, so getAuthHeaders() never hits the network.
     */
    private function injectToken(GoogleRagProvider $provider, string $token): void {
        $auth = new GoogleAuth(credentials: null, accessToken: $token);

        $ref = new ReflectionClass($provider);
        $prop = $ref->getProperty('auth');
        $prop->setAccessible(true);
        $prop->setValue($provider, $auth);
    }

    // =========================================================================
    // retrieve()
    // =========================================================================

    public function testRetrieve_ParsesAndSortsResults(): void {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(200, [], json_encode([
            'contexts' => [
                'contexts' => [
                    ['text' => 'Lower score.', 'score' => 0.40, 'sourceUri' => 'gs://b/low.txt'],
                    ['text' => 'Higher score.', 'score' => 0.95, 'sourceUri' => 'gs://b/high.txt'],
                ],
            ],
        ])));

        $results = $this->provider($http)->retrieve('question', topK: 5);

        $this->assertCount(2, $results);
        // Sorted by score descending.
        $this->assertSame('Higher score.', $results[0]->getText());
        $this->assertEqualsWithDelta(0.95, $results[0]->getScore(), 0.001);
        $this->assertSame('gs://b/high.txt', $results[0]->getMetadata()['source']);
        $this->assertSame('Lower score.', $results[1]->getText());
    }

    public function testRetrieve_SendsCorrectRequest(): void {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(200, [], json_encode(['contexts' => ['contexts' => []]])));

        $this->provider($http)->retrieve('what is php', topK: 7);

        $request = $http->getLastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringContainsString('us-central1-aiplatform.googleapis.com', $request->getUrl());
        $this->assertStringContainsString(':retrieveContexts', $request->getUrl());
        $this->assertSame('Bearer ya29.fake-token', $request->getHeader('Authorization'));

        $body = json_decode($request->getBody(), true);
        $this->assertSame('what is php', $body['query']['text']);
        $this->assertSame(7, $body['query']['ragRetrievalConfig']['topK']);
        $this->assertStringContainsString(
            'projects/my-project/locations/us-central1/ragCorpora/corpus-1',
            $body['vertexRagStore']['ragResources'][0]['ragCorpus']
        );
    }

    public function testRetrieve_EmptyContexts(): void {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(200, [], json_encode(['contexts' => ['contexts' => []]])));

        $this->assertSame([], $this->provider($http)->retrieve('q'));
    }

    public function testRetrieve_ResultWithoutSourceUriHasNoSourceMetadata(): void {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(200, [], json_encode([
            'contexts' => ['contexts' => [['text' => 'No source.', 'score' => 0.5]]],
        ])));

        $results = $this->provider($http)->retrieve('q');

        $this->assertArrayNotHasKey('source', $results[0]->getMetadata());
    }

    public function testRetrieve_ThrowsOnErrorStatus(): void {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(500, [], 'internal error'));

        $this->expectException(ProviderException::class);
        $this->provider($http)->retrieve('q');
    }

    // =========================================================================
    // delete()
    // =========================================================================

    public function testDelete_SendsDeleteRequest(): void {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(200, [], ''));

        $this->provider($http)->delete('rag-file-9');

        $request = $http->getLastRequest();
        $this->assertSame('DELETE', $request->getMethod());
        $this->assertStringContainsString('ragFiles/rag-file-9', $request->getUrl());
    }

    public function testDelete_ThrowsOnErrorStatus(): void {
        $http = new FakeHttpClient();
        $http->addResponse(new HttpResponse(404, [], 'not found'));

        $this->expectException(ProviderException::class);
        $this->provider($http)->delete('missing');
    }

    // =========================================================================
    // ingest() unsupported + accessors
    // =========================================================================

    public function testIngest_Throws(): void {
        $this->expectException(UnsupportedFeatureException::class);
        $this->provider(new FakeHttpClient())->ingest('text');
    }

    public function testGetConfig_ReturnsConfig(): void {
        $config = $this->config();
        $provider = new GoogleRagProvider($config, new FakeHttpClient());

        $this->assertSame($config, $provider->getConfig());
    }

    public function testSetHttpClient_IsFluent(): void {
        $provider = new GoogleRagProvider($this->config(), new FakeHttpClient());
        $this->assertSame($provider, $provider->setHttpClient(new FakeHttpClient()));
    }
}
