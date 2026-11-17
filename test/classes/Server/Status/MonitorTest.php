<?php

declare(strict_types=1);

namespace PhpMyAdmin\Tests\Server\Status;

use PhpMyAdmin\Server\Status\Monitor;
use PhpMyAdmin\Tests\AbstractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

use function floor;
use function microtime;

/**
 * @covers \PhpMyAdmin\Server\Status\Monitor
 */
#[CoversClass(Monitor::class)]
class MonitorTest extends AbstractTestCase
{
    public function testGetJsonForChartingDataTimestamp(): void
    {
        $monitor = new Monitor($this->dbi);

        $before = floor(microtime(true) * 1000);
        $data = $monitor->getJsonForChartingData('{}');
        $after = floor(microtime(true) * 1000);

        self::assertArrayHasKey('x', $data);
        self::assertIsFloat($data['x']);
        self::assertSame(floor($data['x']), $data['x']);
        self::assertGreaterThanOrEqual($before, $data['x']);
        self::assertLessThanOrEqual($after, $data['x']);
        $this->assertAllQueriesConsumed();
    }
}
