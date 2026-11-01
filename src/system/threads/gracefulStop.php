<?php

namespace mpcmf\system\threads;

use mpcmf\system\helper\service\signalHandler;

/**
 * Graceful stop of the current process on SIGTERM/SIGINT
 *
 * Without enable() a stop signal does what it did before: the default handler of signalHandler runs
 * (console commands exit with 128 + signal) or the kernel terminates the process. Two ways to stop gracefully:
 *
 * enable(): SIGTERM/SIGINT only set the stop flag, the application checks isRequested() between units of work
 * and exits itself. A repeated signal during the stop exits at once with 128 + signal. A deadline forces
 * the exit with 128 + signal that many seconds after the first signal.
 *
 * Critical sections, enterCritical()/leaveCritical() or critical(): without enable() a stop signal outside
 * a critical section terminates the process as usual, inside one it is deferred until the outermost section is left
 * (a repeated signal exits at once). Code that cannot be interrupted, like processing of a queue message
 * up to its ack, is a critical section; waiting for the next message is not. With enable() critical sections change
 * nothing, the stop flag is set in any case.
 *
 * The deadline uses pcntl_alarm(): it is armed at the first stop signal, an earlier alarm of the application is kept
 * and its SIGALRM is processed as before. SIGALRM handlers set by pcntl_signal() directly are not replaced, and
 * an application which calls pcntl_alarm() during the stop moves the alarm: in these cases the deadline is checked
 * only by isRequested(), leaveCritical() and checkpoint(). Signal handlers of PHP run between opcodes, so neither
 * a stop signal nor the deadline interrupts a blocking call which restarts itself on EINTR (ext-amqp consume,
 * some database drivers): the process manager's SIGKILL is the last resort there.
 *
 * State belongs to the process: a forked child starts without the stop flag, enable() and critical sections.
 * Signal handlers only set flags or exit, they may run anywhere.
 */
class gracefulStop
{
    /**
     * Signals requesting a stop
     */
    const SIGNALS = [SIGTERM, SIGINT];

    /**
     * Process the state belongs to
     *
     * @var int|null
     */
    private static $pid;

    /**
     * @var bool
     */
    private static $enabled = false;

    /**
     * Exit on a repeated stop signal
     *
     * @var bool
     */
    private static $forceOnRepeat = true;

    /**
     * Seconds from the first stop signal to the forced exit, null - no deadline
     *
     * @var int|null
     */
    private static $deadline;

    /**
     * @var bool
     */
    private static $requested = false;

    /**
     * The first stop signal, null if the stop was requested by request()
     *
     * @var int|null
     */
    private static $signal;

    /**
     * When the first stop signal was received
     *
     * @var float|null
     */
    private static $signalAt;

    /**
     * Critical section depth
     *
     * @var int
     */
    private static $depth = 0;

    /**
     * Signal deferred by a critical section without enable()
     *
     * @var int|null
     */
    private static $deferred;

    /**
     * How a signal is deferred in the current critical section: 'default' - the default handler of signalHandler
     * is suppressed, 'kernel' - our handler is set instead of SIG_DFL
     *
     * @var string[]
     */
    private static $deferrable = [];

    /**
     * Signals onSignal() is added to signalHandler for
     *
     * @var bool[]
     */
    private static $registered = [];

    /**
     * SIGALRM handler state: null - not set up, true - onAlarm() is added, false - SIGALRM is not ours to handle
     *
     * @var bool|null
     */
    private static $alarm;

    /**
     * SIGALRM was SIG_DFL before onAlarm() was added: an alarm of the application terminated the process
     *
     * @var bool
     */
    private static $alarmTerminates = false;

    /**
     * SIGTERM and SIGINT only request a stop from now on, idempotent
     *
     * A repeated signal during the stop exits with 128 + signal at once. Async signals are turned on, so the flag
     * is set and the deadline is checked where the signal arrives.
     *
     * @param int|null $deadline Seconds from the first stop signal to the forced exit with 128 + signal,
     *                           null keeps the deadline set before (none by default)
     */
    public static function enable(?int $deadline = null): void
    {
        self::checkProcess();
        if ($deadline !== null) {
            self::$deadline = $deadline > 0 ? $deadline : null;
        }
        if (self::$enabled) {

            return;
        }
        self::$enabled = true;
        pcntl_async_signals(true);

        $signalHandler = signalHandler::getInstance();
        foreach (self::SIGNALS as $signal) {
            // set back to SIG_DFL by leaveCritical() otherwise
            unset(self::$deferrable[$signal]);
            self::register($signal);
            $signalHandler->suppressDefaultHandler($signal);
        }
        // a stop deferred by a critical section is a stop request now
        if (self::$deferred !== null) {
            self::$signal = self::$deferred;
            self::$signalAt = microtime(true);
            self::$deferred = null;
            self::armDeadline();
        }
    }

    /**
     * @return bool
     */
    public static function isEnabled(): bool
    {
        self::checkProcess();

        return self::$enabled;
    }

    /**
     * Whether a stop was requested, by a signal or request(); exits when the deadline is over
     *
     * @return bool
     */
    public static function isRequested(): bool
    {
        self::checkProcess();
        // signals are dispatched here if async signals are off
        pcntl_signal_dispatch();
        self::checkDeadline();

        return self::$requested;
    }

    /**
     * Request a stop programmatically, the deadline does not apply to it
     */
    public static function request(): void
    {
        self::checkProcess();
        self::$requested = true;
    }

    /**
     * The first stop signal, null if there was none
     *
     * @return int|null
     */
    public static function getSignal(): ?int
    {
        self::checkProcess();

        return self::$signal !== null ? self::$signal : self::$deferred;
    }

    /**
     * Whether a repeated stop signal exits at once (by default), for code that gets the same signal twice
     * by design: a pool master forwards SIGTERM to threads, which may have got it from the process manager already
     *
     * @param bool $force
     */
    public static function setForceOnRepeat(bool $force): void
    {
        self::checkProcess();
        self::$forceOnRepeat = $force;
    }

    /**
     * Enter a critical section: without enable() a stop signal is deferred until the outermost section is left
     */
    public static function enterCritical(): void
    {
        self::checkProcess();
        // a stop deferred by a section left by an exception
        if (self::$depth === 0 && self::$deferred !== null && !self::$enabled) {
            self::terminate(self::$deferred);
        }
        if (self::$depth++ > 0 || self::$enabled) {

            return;
        }

        $signalHandler = signalHandler::getInstance();
        foreach (self::SIGNALS as $signal) {
            if ($signalHandler->isHandling($signal)) {
                // only the terminal action is deferred, handlers of the application run as usual
                if ($signalHandler->getDefaultHandler($signal) !== null && !$signalHandler->isDefaultHandlerSuppressed($signal)) {
                    self::register($signal);
                    $signalHandler->suppressDefaultHandler($signal);
                    self::$deferrable[$signal] = 'default';
                }
            } elseif (pcntl_signal_get_handler($signal) === SIG_DFL) {
                pcntl_signal($signal, [self::class, 'onSignal']);
                self::$deferrable[$signal] = 'kernel';
            }
        }
    }

    /**
     * Leave a critical section: a stop signal deferred by it terminates the process now
     */
    public static function leaveCritical(): void
    {
        self::leave(true);
    }

    /**
     * Run the callback in a critical section
     *
     * If the callback throws, a deferred stop is kept pending: the exception goes up, the stop is applied
     * by the next enterCritical() or checkpoint().
     *
     * @param callable $callback
     *
     * @return mixed what the callback returned
     * @throws \Throwable
     */
    public static function critical(callable $callback)
    {
        self::enterCritical();
        try {
            $result = $callback();
        } catch (\Throwable $exception) {
            self::leave(false);

            throw $exception;
        }
        self::leave(true);

        return $result;
    }

    /**
     * Outside critical sections: apply a deferred stop and the deadline
     */
    public static function checkpoint(): void
    {
        self::checkProcess();
        pcntl_signal_dispatch();
        if (self::$depth === 0 && self::$deferred !== null && !self::$enabled) {
            self::terminate(self::$deferred);
        }
        self::checkDeadline();
    }

    /**
     * Signal handler
     *
     * @param int $signal
     */
    public static function onSignal($signal)
    {
        self::checkProcess();
        if (self::$enabled) {
            if (self::$requested && self::$signal !== null && self::$forceOnRepeat) {
                exit(128 + $signal);
            }
            self::$requested = true;
            if (self::$signal === null) {
                self::$signal = $signal;
                self::$signalAt = microtime(true);
                self::armDeadline();
            }

            return;
        }
        if (self::$depth > 0 && isset(self::$deferrable[$signal])) {
            if (self::$deferred !== null) {
                exit(128 + $signal);
            }
            self::$deferred = $signal;
            self::$requested = true;

            return;
        }
        // a forked child with nothing set up: SIG_DFL is set back by checkProcess(), deliver the signal again
        if (pcntl_signal_get_handler($signal) === SIG_DFL) {
            posix_kill(posix_getpid(), $signal);
        }
    }

    /**
     * SIGALRM handler: the deadline, or an alarm of the application
     *
     * @param int $signal
     */
    public static function onAlarm($signal)
    {
        self::checkProcess();
        if (self::$signalAt === null || self::$deadline === null) {

            return;
        }
        $left = self::$signalAt + self::$deadline - microtime(true);
        // the alarm may come a bit before the time it was set for
        if ($left < 0.1) {
            exit(128 + self::$signal);
        }
        // an earlier alarm of the application: it terminated the process, or its handlers run, then ours again
        if (self::$alarmTerminates) {
            exit(128 + $signal);
        }
        self::alarm((int)ceil($left));
    }

    /**
     * @param bool $apply terminate if a stop was deferred
     */
    private static function leave($apply)
    {
        self::checkProcess();
        if (self::$depth === 0) {

            return;
        }
        if (self::$depth === 1 && !self::$enabled) {
            // signals are dispatched here if async signals are off
            pcntl_signal_dispatch();
        }
        if (--self::$depth > 0) {

            return;
        }
        $signalHandler = signalHandler::getInstance();
        foreach (self::$deferrable as $signal => $how) {
            if ($how === 'default') {
                $signalHandler->suppressDefaultHandler($signal, false);
            } elseif (pcntl_signal_get_handler($signal) === [self::class, 'onSignal']) {
                pcntl_signal($signal, SIG_DFL);
            }
        }
        self::$deferrable = [];
        if ($apply && self::$deferred !== null && !self::$enabled) {
            self::terminate(self::$deferred);
        }
        self::checkDeadline();
    }

    /**
     * Do what the deferred signal would have done: the default handler, or termination by the kernel
     *
     * @param int $signal
     */
    private static function terminate($signal)
    {
        self::$deferred = null;
        self::$requested = false;
        $default = signalHandler::getInstance()->getDefaultHandler($signal);
        if ($default !== null) {
            // the default handler may decide not to exit
            call_user_func($default, $signal);

            return;
        }
        if (pcntl_signal_get_handler($signal) === SIG_DFL) {
            posix_kill(posix_getpid(), $signal);
        }
        exit(128 + $signal);
    }

    /**
     * @param int $signal
     */
    private static function register($signal)
    {
        if (!isset(self::$registered[$signal])) {
            signalHandler::getInstance()->addHandler($signal, [self::class, 'onSignal']);
            self::$registered[$signal] = true;
        }
    }

    /**
     * Arm the deadline alarm at the first stop signal
     */
    private static function armDeadline()
    {
        if (self::$deadline === null) {

            return;
        }
        if (self::$alarm === null) {
            $signalHandler = signalHandler::getInstance();
            $current = pcntl_signal_get_handler(SIGALRM);
            self::$alarm = $current === SIG_DFL || $signalHandler->isHandling(SIGALRM);
            if (self::$alarm) {
                self::$alarmTerminates = $current === SIG_DFL;
                $signalHandler->addHandler(SIGALRM, [self::class, 'onAlarm']);
            }
        }
        if (self::$alarm) {
            self::alarm(self::$deadline);
        }
    }

    /**
     * Schedule SIGALRM in $seconds, an earlier alarm of the application is kept
     *
     * @param int $seconds
     */
    private static function alarm($seconds)
    {
        $previous = pcntl_alarm(max(1, $seconds));
        if ($previous > 0 && $previous < $seconds) {
            pcntl_alarm($previous);
        }
    }

    /**
     * Exit when the deadline is over
     */
    private static function checkDeadline()
    {
        if (self::$enabled && self::$signalAt !== null && self::$deadline !== null
            && microtime(true) - self::$signalAt >= self::$deadline) {
            exit(128 + self::$signal);
        }
    }

    /**
     * The state belongs to the process: drop it in a forked child
     */
    private static function checkProcess()
    {
        $pid = posix_getpid();
        if (self::$pid === $pid) {

            return;
        }
        if (self::$pid !== null) {
            // set by a critical section the fork happened in
            foreach (self::$deferrable as $signal => $how) {
                if ($how === 'kernel' && pcntl_signal_get_handler($signal) === [self::class, 'onSignal']) {
                    pcntl_signal($signal, SIG_DFL);
                }
            }
        }
        self::$pid = $pid;
        self::$enabled = false;
        self::$forceOnRepeat = true;
        self::$deadline = null;
        self::$requested = false;
        self::$signal = null;
        self::$signalAt = null;
        self::$depth = 0;
        self::$deferred = null;
        self::$deferrable = [];
        self::$registered = [];
        self::$alarm = null;
        self::$alarmTerminates = false;
    }
}
