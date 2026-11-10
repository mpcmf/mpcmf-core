<?php

namespace mpcmf\tests\system\net\reactCurl;

/**
 * Characterization of the setSleep()/setMaxRequest() throttling. Scrapers rely on it for ban
 * avoidance, so batch sizes and gaps must stay as in khr/php-mcurl-client.
 *
 * Request arrival times are taken on the server side (/ts) and grouped into batches.
 */
class reactCurlThrottleTest extends reactCurlTestCase
{
    /**
     * @return array [batch sizes, gaps between batch starts]
     */
    private function measure(callable $configure, $count, $query = '')
    {
        $loop = $this->createLoop();
        $client = $this->createClient($loop, false);
        $configure($client);
        $arrivals = [];
        for ($i = 0; $i < $count; $i++) {
            $client->prepareTask(self::httpBase() . '/ts' . $query)->then(function ($result) use (&$arrivals) {
                $arrivals[] = (float)$result->getBody();
            });
        }
        $client->run();
        $this->runLoop($loop, function () use (&$arrivals, $count) {
            return count($arrivals) >= $count;
        }, 20);
        $this->assertCount($count, $arrivals);
        sort($arrivals);
        $sizes = [];
        $starts = [];
        $previous = null;
        foreach ($arrivals as $time) {
            if ($previous === null || $time - $previous > 0.1) {
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
        }, 12);
        $this->assertSame([10, 2], $sizes);
        $this->assertGaps($gaps, 0.88, 0.96);
    }

    public function testSleepAfterEachBatch()
    {
        list($sizes, $gaps) = $this->measure(function ($client) {
            $client->setMaxRequest(4);
            $client->setSleep(1, 0.3, false);
        }, 10);
        $this->assertSame([4, 4, 2], $sizes);
        $this->assertGaps($gaps, 0.28, 0.36);
    }

    /**
     * Blocking mode usleep()s inside every throttle check made while the sleep is pending, and
     * every such check moves the mark: in practice the gap is a multiple of the sleep (2x-3x here,
     * one check per curl_multi_exec round needed to finish the batch).
     */
    public function testBlockingSleep()
    {
        list($sizes, $gaps) = $this->measure(function ($client) {
            $client->setMaxRequest(4);
            $client->setSleep(1, 0.3, true);
        }, 10);
        $this->assertSame([4, 4, 2], $sizes);
        $this->assertGaps($gaps, 0.58, 0.96);
        foreach ($gaps as $gap) {
            $this->assertLessThan(0.04, abs($gap / 0.3 - round($gap / 0.3)) * 0.3, 'gap is a multiple of the sleep: ' . json_encode($gaps));
        }
    }

    /**
     * With next > 1 the modulo arithmetic lets more than `next` requests through before the
     * first sleep (6 of 7 here) - kept as is.
     */
    public function testSleepAfterEveryThirdRequest()
    {
        list($sizes, $gaps) = $this->measure(function ($client) {
            $client->setMaxRequest(2);
            $client->setSleep(3, 0.3, false);
        }, 7);
        $this->assertSame([6, 1], $sizes);
        $this->assertGaps($gaps, 0.27, 0.36);
    }

    /**
     * While all slots are busy the sleep mark moves forward in steps of `sleep`, so the next
     * batch starts at the first step after the slots were freed (0.6s for 0.5s requests).
     */
    public function testSleepCountsFromFreedSlotsWhenRequestsAreSlow()
    {
        list($sizes, $gaps) = $this->measure(function ($client) {
            $client->setMaxRequest(2);
            $client->setSleep(1, 0.3, false);
        }, 4, '?s=0.5');
        $this->assertSame([2, 2], $sizes);
        $this->assertGaps($gaps, 0.59, 0.67);
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
        $client->prepareTask(self::httpBase() . '/slow?s=1.5')->then(function () use (&$slowDone) {
            $slowDone = true;
        });
        $client->run();
        $loop->addTimer(1.0, function () use ($client, &$arrivals) {
            for ($i = 0; $i < 5; $i++) {
                $client->prepareTask(self::httpBase() . '/ts?i=' . $i)->then(function ($result) use (&$arrivals) {
                    $arrivals[] = (float)$result->getBody();
                });
            }
        });
        $this->runLoop($loop, function () use (&$arrivals, &$slowDone) {
            return count($arrivals) >= 5 && $slowDone;
        }, 5);
        sort($arrivals);
        $this->assertLessThan(0.1, end($arrivals) - $arrivals[0], 'arrivals: ' . json_encode($arrivals));
    }

    public function testZeroSleepDisablesThrottling()
    {
        list($sizes) = $this->measure(function ($client) {
            $client->setMaxRequest(20);
            $client->setSleep(5, 0, false);
        }, 20);
        $this->assertSame([20], $sizes);
    }
}
