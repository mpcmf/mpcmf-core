<?php

namespace mpcmf\tests\system\net\reactCurl;

/**
 * setSleep()/setMaxRequest() throttling tests, as in khr/php-mcurl-client
 *
 * @author Dmitry Emelyanov <gilberg.vrn@gmail.com>
 */
class reactCurlThrottleTest extends reactCurlTestCase
{
    /**
     * Measure server arrivals grouped into batches
     *
     * @param callable|null $watch gets the loop before it runs
     *
     * @return array [batch sizes, gaps between batch starts]
     */
    private function measure(callable $configure, $count, $sleep, ?callable $watch = null)
    {
        $loop = $this->createLoop();
        $client = $this->createClient($loop, false);
        $configure($client);
        $arrivals = [];
        for ($i = 0; $i < $count; $i++) {
            $client->prepareTask(self::delayUrl(0))->then(function ($result) use (&$arrivals) {
                $arrivals[] = (float)$result->getBody();
            });
        }
        if ($watch !== null) {
            $watch($loop);
        }
        $client->run();
        $this->runLoop($loop, function () use (&$arrivals, $count) {
            return count($arrivals) >= $count;
        }, 20);
        $this->assertCount($count, $arrivals);

        return $this->batches($arrivals, $sleep / 2);
    }

    /**
     * @return array [batch sizes, gaps between batch starts]
     */
    private function batches(array $arrivals, $split)
    {
        sort($arrivals);
        $sizes = [];
        $starts = [];
        $previous = null;
        foreach ($arrivals as $time) {
            if ($previous === null || $time - $previous > $split) {
                $sizes[] = 0;
                $starts[] = $time;
            }
            $sizes[count($sizes) - 1]++;
            $previous = $time;
        }
        $gaps = [];
        for ($i = 1, $n = count($starts); $i < $n; $i++) {
            $gaps[] = round($starts[$i] - $starts[$i - 1], 3);
        }

        return [$sizes, $gaps];
    }

    private function assertGaps(array $gaps, $min, $max)
    {
        foreach ($gaps as $gap) {
            $this->assertGreaterThanOrEqual($min, $gap, 'gaps: ' . json_encode($gaps));
            $this->assertLessThanOrEqual($max, $gap, 'gaps: ' . json_encode($gaps));
        }
    }

    public function testDefaultThrottleIsTenRequestsPerNinetyHundredthsOfSecond()
    {
        list($sizes, $gaps) = $this->measure(function () {
        }, 12, 0.9);
        $this->assertSame([10, 2], $sizes);
        // no throttle ~0, 1 s default >= 1.0
        $this->assertGaps($gaps, 0.85, 0.99);
    }

    public function testSleepAfterEachBatch()
    {
        list($sizes, $gaps) = $this->measure(function ($client) {
            $client->setMaxRequest(4);
            $client->setSleep(1, 0.3, false);
        }, 10, 0.3);
        $this->assertSame([4, 4, 2], $sizes);
        // no sleep gives ~0, the sleep applied twice gives ~0.6
        $this->assertGaps($gaps, 0.27, 0.45);
    }

    /**
     * Blocking mode: only the first batch has a fixed size
     */
    public function testBlockingSleep()
    {
        $stall = 0.0;
        list($sizes, $gaps) = $this->measure(function ($client) {
            $client->setMaxRequest(4);
            $client->setSleep(1, 0.3, true);
        }, 10, 0.3, function ($loop) use (&$stall) {
            $last = microtime(true);
            $loop->addPeriodicTimer(0.005, function () use (&$last, &$stall) {
                $now = microtime(true);
                $stall = max($stall, $now - $last);
                $last = $now;
            });
        });
        $this->assertSame(4, $sizes[0], 'sizes: ' . json_encode($sizes));
        $this->assertSame(10, array_sum($sizes));
        $this->assertLessThanOrEqual(4, max($sizes), 'sizes: ' . json_encode($sizes));
        // ignoring max requests gives a first batch of 10
        $this->assertGaps($gaps, 0.27, 1.25);
        foreach ($gaps as $gap) {
            $this->assertLessThan(0.05, abs($gap / 0.3 - round($gap / 0.3)) * 0.3, 'gap is a multiple of the sleep: ' . json_encode($gaps));
        }
        // blocking mode stalls the loop for ~0.3 s
        $this->assertGreaterThan(0.25, $stall, 'longest loop stall');
    }

    /**
     * Sleep after every third request with one slot
     */
    public function testSleepAfterEveryThirdRequest()
    {
        list($sizes, $gaps) = $this->measure(function ($client) {
            $client->setMaxRequest(1);
            $client->setSleep(3, 0.5, false);
        }, 7, 0.5);
        $this->assertSame([3, 3, 1], $sizes);
        // no sleep gives ~0, the sleep applied twice gives ~1.0
        $this->assertGaps($gaps, 0.45, 0.75);
    }

    /**
     * Sleep after 3 with 2 slots lets 6 through when pairs finish together
     */
    public function testSleepAfterEveryThirdRequestLetsSixThroughWhenPairsFinishTogether()
    {
        $this->requireOwnEngine();
        $throttle = new \mpcmf\system\net\curl\sleepThrottle();
        $throttle->configure(3, 1000, false);
        $allowed = [];
        // [free slots, finished]
        foreach ([[2, 0], [0, 0], [2, 2], [1, 2], [2, 4], [2, 6]] as list($free, $finished)) {
            $allowed[] = max(0, $throttle->allow($free, $finished));
        }
        $this->assertSame([2, 0, 1, 1, 2, 0], $allowed);
        $this->assertSame(6, array_sum($allowed));
    }

    /**
     * The next batch starts at the first sleep step after the slots are freed
     */
    public function testSleepCountsFromFreedSlotsWhenRequestsAreSlow()
    {
        $loop = $this->createLoop();
        $client = $this->createClient($loop, false);
        $client->setMaxRequest(2);
        $client->setSleep(1, 0.6, false);
        $runs = [];
        for ($i = 0; $i < 4; $i++) {
            $client->prepareTask(self::delayUrl(0.9))->then(function ($result) use (&$runs) {
                $runs[] = [(float)$result->getBody(), microtime(true)];
            });
        }
        $client->run();
        $this->runLoop($loop, function () use (&$runs) {
            return count($runs) >= 4;
        }, 20);
        sort($runs);
        list($sizes) = $this->batches(array_column($runs, 0), 0.3);
        $this->assertSame([2, 2], $sizes, 'arrival, done: ' . json_encode(array_map(function ($run) use ($runs) {
            return [round($run[0] - $runs[0][0], 3), round($run[1] - $runs[0][0], 3)];
        }, $runs)));
        $firstStart = $runs[0][0];
        $freed = max($runs[0][1], $runs[1][1]);
        $secondStart = $runs[2][0];
        $timeline = sprintf(
            'second batch %.3f s after the first, %.3f s after the slots were freed',
            $secondStart - $firstStart,
            $secondStart - $freed
        );
        // ~0.9 or ~1.5 with a wrong mark
        $this->assertGreaterThan(1.1, $secondStart - $firstStart, $timeline);
        $this->assertLessThan(1.4, $secondStart - $firstStart, $timeline);
        // ~0 or ~0.6 with a wrong mark
        $this->assertGreaterThan(0.1, $secondStart - $freed, $timeline);
        $this->assertLessThan(0.5, $secondStart - $freed, $timeline);
    }

    /**
     * Requests added in one go start as one batch
     */
    public function testBatchAddedWhileRunningStartsTogether()
    {
        $loop = $this->createLoop();
        $client = $this->createClient($loop, false);
        $arrivals = [];
        $slowDone = false;
        $client->prepareTask(self::delayUrl(1.5))->then(function () use (&$slowDone) {
            $slowDone = true;
        });
        $client->run();
        $loop->addTimer(1.0, function () use ($client, &$arrivals) {
            for ($i = 0; $i < 5; $i++) {
                $client->prepareTask(self::delayUrl(0, 'i=' . $i))->then(function ($result) use (&$arrivals) {
                    $arrivals[] = (float)$result->getBody();
                });
            }
        });
        $this->runLoop($loop, function () use (&$arrivals, &$slowDone) {
            return count($arrivals) >= 5 && $slowDone;
        }, 5);
        sort($arrivals);
        // one by one in add() holds the rest for 0.9 s
        $this->assertLessThan(0.45, end($arrivals) - $arrivals[0], 'arrivals: ' . json_encode($arrivals));
    }

    public function testZeroSleepDisablesThrottling()
    {
        list($sizes) = $this->measure(function ($client) {
            $client->setMaxRequest(20);
            $client->setSleep(5, 0, false);
        }, 20, 0.3);
        $this->assertSame([20], $sizes);
    }
}
