<?php

declare(strict_types=1);

namespace Tests\Unit;

use Bdus\Controller;
use Monolog\Logger;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for Bdus\Controller::returnJson().
 *
 * Before the fix it did `echo json_encode($data)` unchecked: any string in the
 * payload with a malformed UTF-8 byte (routine in data carried over from a
 * legacy Latin-1 v4 database) made json_encode() return false, so the endpoint
 * emitted a 200 with a zero-byte body and no log line, and the frontend failed
 * with "JSON.parse: unexpected end of data at line 1 column 1".
 */
class ControllerReturnJsonTest extends TestCase
{
    private function makeController(?Logger $log = null): Controller
    {
        $ctrl = new class ([], [], []) extends Controller {};
        if ($log !== null) {
            $ctrl->setLog($log);
        }
        return $ctrl;
    }

    /** @return string the raw body written by returnJson() */
    private function capture(Controller $ctrl, array $data): string
    {
        ob_start();
        $ctrl->returnJson($data);
        return (string) ob_get_clean();
    }

    public function testCleanPayloadIsEmittedVerbatim(): void
    {
        $raw = $this->capture($this->makeController(), ['status' => 'success', 'n' => 42]);

        $this->assertSame(['status' => 'success', 'n' => 42], json_decode($raw, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testStatusSuccessIsInjectedWhenAbsent(): void
    {
        $raw = $this->capture($this->makeController(), ['foo' => 'bar']);

        $this->assertSame('success', json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['status']);
    }

    public function testMalformedUtf8StillProducesValidNonEmptyJson(): void
    {
        // 0xB0 alone is the Latin-1 degree sign — invalid as a UTF-8 sequence.
        $raw = $this->capture($this->makeController(), [
            'status' => 'success',
            'core'   => ['direction' => "38\xB0 W of N, skull NW"],
        ]);

        $this->assertNotSame('', $raw, 'returnJson() must never emit an empty body');

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('success', $decoded['status']);
        // The bad byte is substituted with U+FFFD, the rest of the value survives.
        $this->assertStringContainsString('W of N, skull NW', $decoded['core']['direction']);
        $this->assertStringContainsString("\u{FFFD}", $decoded['core']['direction']);
    }

    public function testUnencodablePayloadReturnsErrorEnvelopeAndLogs(): void
    {
        $log = new Logger('test');
        $handler = new TestHandler();
        $log->pushHandler($handler);

        // A recursive structure cannot be encoded even with the substitute flag.
        $data = ['status' => 'success'];
        $data['self'] = &$data;

        $raw = $this->capture($this->makeController($log), $data);

        $this->assertNotSame('', $raw);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('error', $decoded['status']);
        $this->assertSame('json_encode_failed', $decoded['code']);
        $this->assertTrue($handler->hasErrorRecords(), 'the encode failure must leave a log line');
    }
}
