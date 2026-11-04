<?php

namespace WebFiori\Tests\Ai\Http;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Cancellation\CancellationTokenSource;
use WebFiori\Ai\Exception\HttpException;
use WebFiori\Ai\Exception\OperationCancelledException;
use WebFiori\Ai\Http\CurlHttpClient;
use WebFiori\Ai\Http\HttpRequest;

/**
 * Integration tests for the cancellable (curl_multi) transfer path of
 * CurlHttpClient, against a local PHP built-in server. Skipped when the
 * server cannot be started.
 */
class CurlMultiCancellationTest extends TestCase {
    /** @var resource|null */
    private static $process = null;
    private static string $dir = '';
    private static string $base = '';

    public static function setUpBeforeClass(): void {
        if (!function_exists('curl_init') || !function_exists('proc_open')) {
            return;
        }

        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($probe === false) {
            return;
        }

        $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        self::$dir = sys_get_temp_dir().'/webfiori-ai-curl-'.bin2hex(random_bytes(4));
        mkdir(self::$dir);
        file_put_contents(self::$dir.'/router.php', <<<'PHP'
<?php
$mode = $_GET['p'] ?? 'fast';
if ($mode === 'idle') {
    sleep((int) ($_GET['s'] ?? 6));
    echo 'idle-done';
    return;
}
echo 'fast-ok';
PHP);

        $cmd = [PHP_BINARY, '-S', '127.0.0.1:'.$port, self::$dir.'/router.php'];
        self::$process = proc_open($cmd, [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, null, ['PHP_CLI_SERVER_WORKERS' => '4'] + getenv());

        if (!is_resource(self::$process)) {
            self::$process = null;

            return;
        }

        self::$base = 'http://127.0.0.1:'.$port.'/';

        for ($i = 0; $i < 50; $i++) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);

            if ($conn !== false) {
                fclose($conn);

                return;
            }

            usleep(100000);
        }

        self::tearDownAfterClass();
        self::$base = '';
    }

    public static function tearDownAfterClass(): void {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }

        if (self::$dir !== '' && is_dir(self::$dir)) {
            @unlink(self::$dir.'/router.php');
            @rmdir(self::$dir);
            self::$dir = '';
        }
    }

    protected function setUp(): void {
        if (self::$base === '') {
            $this->markTestSkipped('Local PHP server could not be started.');
        }
    }

    public function testSuccessfulSendWithTokenReturnsBodyAndStatus(): void {
        $source = new CancellationTokenSource();
        $response = (new CurlHttpClient(10, 3, false))->send($this->request('p=fast', $source));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('fast-ok', $response->getBody());
    }

    public function testSuccessfulStreamingWithTokenDeliversBody(): void {
        $source = new CancellationTokenSource();
        $received = '';
        (new CurlHttpClient(10, 3, false))->sendStreaming($this->request('p=fast', $source), function (string $chunk) use (&$received) {
            $received .= $chunk;
        });

        $this->assertSame('fast-ok', $received);
    }

    public function testPollerCancelIsObservedWellBeforeProgressCallbackCadence(): void {
        // The stop becomes true 1.3s into an idle request. Observing it through
        // libcurl's progress callback alone (~1 tick/s) would take until ~2s;
        // the multi loop re-checks every ~100ms.
        $start = hrtime(true);
        $source = new CancellationTokenSource(
            fn (): bool => (hrtime(true) - $start) / 1e9 >= 1.3,
            0
        );

        try {
            (new CurlHttpClient(30, 3, false))->send($this->request('p=idle&s=8', $source));
            $this->fail('expected OperationCancelledException');
        } catch (OperationCancelledException $e) {
            $elapsed = (hrtime(true) - $start) / 1e9;
            $this->assertGreaterThanOrEqual(1.3, $elapsed);
            $this->assertLessThan(1.8, $elapsed, 'cancel must be observed within ~0.5s of the stop');
        }
    }

    public function testStreamingPollerCancelIsObservedPromptly(): void {
        $start = hrtime(true);
        $source = new CancellationTokenSource(
            fn (): bool => (hrtime(true) - $start) / 1e9 >= 1.3,
            0
        );

        try {
            (new CurlHttpClient(30, 3, false))->sendStreaming($this->request('p=idle&s=8', $source), fn () => null);
            $this->fail('expected OperationCancelledException');
        } catch (OperationCancelledException $e) {
            $this->assertLessThan(1.8, (hrtime(true) - $start) / 1e9);
        }
    }

    public function testTimeoutKeepsTransportErrorSemantics(): void {
        $source = new CancellationTokenSource();

        try {
            (new CurlHttpClient(1, 3, false))->send($this->request('p=idle&s=5', $source));
            $this->fail('expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(CURLE_OPERATION_TIMEDOUT, $e->getCode());
            $this->assertStringContainsString('timed out', $e->getMessage());
        }
    }

    public function testConnectionRefusedKeepsTransportErrorSemantics(): void {
        $source = new CancellationTokenSource();
        $request = new HttpRequest('GET', 'http://127.0.0.1:1/', [], null, $source->getToken());

        try {
            (new CurlHttpClient(5, 2, false))->send($request);
            $this->fail('expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(CURLE_COULDNT_CONNECT, $e->getCode());
        }
    }

    public function testReuseEnabledClientStillWorksAfterCancellation(): void {
        $client = (new CurlHttpClient(10, 3, false))->enableConnectionReuse(true);

        $this->assertSame('fast-ok', $client->send($this->request('p=fast', new CancellationTokenSource()))->getBody());

        $cancelled = new CancellationTokenSource();
        $cancelled->cancel();

        try {
            $client->send($this->request('p=idle&s=5', $cancelled));
            $this->fail('expected OperationCancelledException');
        } catch (OperationCancelledException $e) {
            // expected
        }

        $this->assertSame('fast-ok', $client->send($this->request('p=fast', new CancellationTokenSource()))->getBody());
    }

    private function request(string $query, CancellationTokenSource $source): HttpRequest {
        return new HttpRequest('GET', self::$base.'?'.$query, [], null, $source->getToken());
    }
}
