<?php

namespace WebFiori\Tests\Ai\Cancellation;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Cancellation\CancellationToken;
use WebFiori\Ai\Cancellation\CancellationTokenSource;
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Exception\OperationCancelledException;

/**
 * Unit tests for the self-contained cancellation primitive (issue #177):
 * CancellationTokenSource / CancellationToken pub-sub semantics.
 */
class CancellationTokenTest extends TestCase {
    public function testChatOptionConstant(): void {
        $this->assertSame('cancellation_token', ChatOption::CANCELLATION_TOKEN);
    }

    public function testGetTokenReturnsSameInstance(): void {
        $source = new CancellationTokenSource();

        $this->assertInstanceOf(CancellationToken::class, $source->getToken());
        $this->assertSame($source->getToken(), $source->getToken());
    }

    public function testNotCancelledByDefault(): void {
        $source = new CancellationTokenSource();

        $this->assertFalse($source->isCancelled());
        $this->assertFalse($source->getToken()->isCancelled());
    }

    public function testCancelFlipsIsCancelled(): void {
        $source = new CancellationTokenSource();
        $token = $source->getToken();

        $source->cancel();

        $this->assertTrue($source->isCancelled());
        $this->assertTrue($token->isCancelled());
    }

    public function testThrowIfCancelledWhenNotCancelled(): void {
        $source = new CancellationTokenSource();

        // Should not throw.
        $source->getToken()->throwIfCancelled();
        $this->assertFalse($source->isCancelled());
    }

    public function testThrowIfCancelledThrowsAfterCancel(): void {
        $source = new CancellationTokenSource();
        $source->cancel();

        $this->expectException(OperationCancelledException::class);
        $source->getToken()->throwIfCancelled();
    }

    public function testMultipleSubscribersAllFire(): void {
        $source = new CancellationTokenSource();
        $token = $source->getToken();
        $calls = [];

        $token->onCancel(function () use (&$calls) {
            $calls[] = 'a';
        });
        $token->onCancel(function () use (&$calls) {
            $calls[] = 'b';
        });
        $token->onCancel(function () use (&$calls) {
            $calls[] = 'c';
        });

        $source->cancel();

        $this->assertSame(['a', 'b', 'c'], $calls);
    }

    public function testLateSubscriberFiresImmediately(): void {
        $source = new CancellationTokenSource();
        $token = $source->getToken();
        $source->cancel();

        $fired = false;
        $token->onCancel(function () use (&$fired) {
            $fired = true;
        });

        $this->assertTrue($fired, 'A listener added after cancel() should fire immediately');
    }

    public function testFailingListenerDoesNotStopOthers(): void {
        $source = new CancellationTokenSource();
        $token = $source->getToken();
        $after = false;

        $token->onCancel(function () {
            throw new \RuntimeException('listener boom');
        });
        $token->onCancel(function () use (&$after) {
            $after = true;
        });

        // cancel() must not propagate the listener's exception...
        $source->cancel();

        // ...and the subsequent listener must still have run.
        $this->assertTrue($after, 'A throwing listener must not prevent later listeners from running');
    }

    public function testCancelIsIdempotent(): void {
        $source = new CancellationTokenSource();
        $token = $source->getToken();
        $count = 0;

        $token->onCancel(function () use (&$count) {
            $count++;
        });

        $source->cancel();
        $source->cancel();
        $source->cancel();

        $this->assertSame(1, $count, 'Listeners must fire exactly once across repeated cancel() calls');
    }

    public function testLateSubscriberAfterIdempotentCancelFiresOnce(): void {
        $source = new CancellationTokenSource();
        $token = $source->getToken();
        $source->cancel();
        $source->cancel();

        $count = 0;
        $token->onCancel(function () use (&$count) {
            $count++;
        });

        $this->assertSame(1, $count);
    }

    public function testOperationCancelledExceptionDefaults(): void {
        $e = new OperationCancelledException();

        $this->assertInstanceOf(\WebFiori\Ai\Exception\AiException::class, $e);
        $this->assertSame('The operation was cancelled.', $e->getMessage());
    }
}
