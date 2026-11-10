<?php

namespace mpcmf\tests\system\net\reactCurl;

/**
 * Characterization of the setSleep()/setMaxRequest() throttling. Scrapers rely on it for ban
 * avoidance, so batch sizes and gaps must stay as in khr/php-mcurl-client.
 *
 * Request arrival times are taken on the server side (accept time, see delayUrl()) and grouped
 * into batches.
 *
 * @author Dmitry Emelyanov <gilberg.vrn@gmail.com>
 */
class reactCurlThrottleTest extends reactCurlTestCase
{
    /**
     * Arrivals are grouped into batches by gaps longer than half of the sleep: requests of one batch
     * arrive within milliseconds (tens under load), the next batch starts a whole sleep later.
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
        // no throttle gives ~0, a 1 s default gives >= 1.0; below 0.9: the first batch connected later than its mark
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
     * Blocking mode usleep()s inside every throttle check made while the sleep is pending, and
     * every such check moves the mark: the gap is a multiple of the sleep (one check per
     * curl_multi_exec round needed to finish the batch, 1x-3x here). How many requests of a batch
     * are finished at a check depends on the machine, so only the first batch has a fixed size.
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
        // the non-blocking mode keeps the loop running (stalls of a few ms), blocking stalls it for ~0.3 s
        $this->assertGreaterThan(0.25, $stall, 'longest loop stall');
    }

    /**
     * Sleep after every third request. With one slot the finished requests are seen one by one,
     * so the batches are exact.
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
     * With next > 1 the modulo arithmetic lets more than `next` requests through before the first
     * sleep - kept as is. In a live run the outcome depends on whether two transfers are seen
     * finished in the same round (6 of 7 when they are, other splits when not), so the arithmetic
     * is fed that sequence directly: 2 slots, sleep after 3, both transfers of a pair finish together.
     */
    public function testSleepAfterEveryThirdRequestLetsSixThroughWhenPairsFinishTogether()
    {
        $this->requireOwnEngine();
        $throttle = new \mpcmf\system\net\curl\sleepThrottle();
        $throttle->configure(3, 1000, false);
        $allowed = [];
        // [free slots, finished]: start, both running, pair done, one restarted, pair done, pair done
        foreach ([[2, 0], [0, 0], [2, 2], [1, 2], [2, 4], [2, 6]] as list($free, $finished)) {
            $allowed[] = max(0, $throttle->allow($free, $finished));
        }
        $this->assertSame([2, 0, 1, 1, 2, 0], $allowed);
        $this->assertSame(6, array_sum($allowed));
    }

    /**
     * While all slots are busy the sleep mark moves forward in steps of `sleep`, so the next
     * batch starts at the first step after the slots were freed: 0.6 s sleep, 0.9 s requests -
     * marks at 0, 0.6 (slots busy), slots freed at 0.9, next batch at 1.2.
     * Measured against the server arrival of the first batch and the client-side completion
     * of its requests (the moment the slots were freed).
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
        $timeline = sprintf('second batch %.3f s after the first, %.3f s after the slots were freed', $secondStart - $firstStart, $secondStart - $freed);
        // counted from the start without moving the mark while busy: ~0.9 (right when freed); counted from the freed slots: ~1.5
        $this->assertGreaterThan(1.1, $secondStart - $firstStart, $timeline);
        $this->assertLessThan(1.4, $secondStart - $firstStart, $timeline);
        // started as soon as the slots were freed: ~0 here; a full sleep after that: ~0.6
        $this->assertGreaterThan(0.1, $secondStart - $freed, $timeline);
        $this->assertLessThan(0.5, $secondStart - $freed, $timeline);
    }

    /**
     * Requests added in one go while the client runs, after the sleep of the default throttle
     * is over, start as one batch as with khr (checked together on the next round, not one by one
     * in add(): the first one would move the sleep mark and hold the rest for 0.9s).
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
        // started one by one in add(): the rest held for the 0.9 s sleep
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
