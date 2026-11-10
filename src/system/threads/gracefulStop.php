<?php

namespace mpcmf\system\threads;

use mpcmf\system\helper\service\signalHandler;

/**
 * Graceful stop of the current process on SIGTERM/SIGINT: a stop flag (enable()) or critical sections
 *
 * @author Dmitry Emelyanov <gilberg.vrn@gmail.com>
 */
class gracefulStop
{
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
     * Exit on a repeated stop signal: null - SIGINT only, true - both, false - none
     *
     * @var bool|null
     */
    private static $forceOnRepeat;

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
     * How a signal is deferred in the critical section: 'default' or 'kernel'
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
     * SIGALRM handler state: null - not set up, false - not ours
     *
     * @var bool|null
     */
    private static $alarm;

    /**
     * SIGALRM was SIG_DFL before onAlarm() was added
     *
     * @var bool
     */
    private static $alarmTerminates = false;

    /**
     * SIGTERM and SIGINT only request a stop from now on
     *
     * @param int|null $deadline seconds to the forced exit, null keeps the current one
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
     * Whether a stop was requested, exits when the deadline is over
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
     * Exit at once on a repeated stop signal: null - SIGINT only (default), true - both, false - none
     *
     * @param bool|null $force
     */
    public static function setForceOnRepeat(?bool $force): void
    {
        self::checkProcess();
        self::$forceOnRepeat = $force;
    }

    /**
     * Enter a critical section, a stop signal is deferred until it is left
     */
    public static function enterCritical(): void
    {
        self::checkProcess();
        // a stop deferred by a section left by an exception
        if (self::$depth === 0 && self::$deferred !== null && !self::$enabled) {
            self::terminate(self::$deferred);
        }
        if (self::$depth > 0 || self::$enabled) {
            self::$depth++;

            return;
        }

        // stop signals are blocked while the handlers are switched
        $mask = self::blockSignals();
        self::$depth = 1;
        $signalHandler = signalHandler::getInstance();
        foreach (self::SIGNALS as $signal) {
            if ($signalHandler->isHandling($signal)) {
                // only the terminal action is deferred
                if ($signalHandler->getDefaultHandler($signal) !== null && !$signalHandler->isDefaultHandlerSuppressed($signal)) {
                    self::$deferrable[$signal] = 'default';
                    self::register($signal);
                    $signalHandler->suppressDefaultHandler($signal);
                }
            } elseif (pcntl_signal_get_handler($signal) === SIG_DFL) {
                self::$deferrable[$signal] = 'kernel';
                pcntl_signal($signal, [self::class, 'onSignal']);
            }
        }
        self::unblockSignals($mask);
    }

    /**
     * Leave a critical section, apply a deferred stop
     */
    public static function leaveCritical(): void
    {
        self::leave(true);
    }

    /**
     * Run the callback in a critical section
     *
     * @param callable $callback
     *
     * @return mixed
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
            if (self::$requested && self::$signal !== null && self::exitsOnRepeat($signal)) {
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
                if (self::exitsOnRepeat($signal)) {
                    exit(128 + $signal);
                }

                return;
            }
            self::$deferred = $signal;
            self::$requested = true;

            return;
        }
        // a forked child with nothing set up: deliver it again
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
        // an earlier alarm of the application
        if (self::$alarmTerminates) {
            exit(128 + $signal);
        }
        self::alarm((int)ceil($left));
    }

    /**
     * Leave a critical section
     *
     * @param bool $apply terminate if a stop was deferred
     */
    private static function leave($apply)
    {
        self::checkProcess();
        if (self::$depth === 0) {

            return;
        }
        if (self::$depth > 1) {
            self::$depth--;

            return;
        }
        // stop signals wait until the old actions are back
        $mask = self::$deferrable ? self::blockSignals() : null;
        if (!self::$enabled) {
            // signals not dispatched yet belong to the section
            pcntl_signal_dispatch();
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
        self::$depth = 0;
        self::unblockSignals($mask);
        if ($apply && self::$deferred !== null && !self::$enabled) {
            self::terminate(self::$deferred);
        }
        self::checkDeadline();
    }

    /**
     * Do what the deferred signal would have done
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
     *
     * @return bool
     */
    private static function exitsOnRepeat($signal)
    {
        return self::$forceOnRepeat !== null ? self::$forceOnRepeat : $signal === SIGINT;
    }

    /**
     * Block the stop signals, the kernel keeps them pending
     *
     * @return array|null the previous signal mask
     */
    private static function blockSignals()
    {
        $previous = [];

        return pcntl_sigprocmask(SIG_BLOCK, self::SIGNALS, $previous) ? $previous : null;
    }

    /**
     * Restore the signal mask, pending stop signals are delivered now
     *
     * @param array|null $previous
     */
    private static function unblockSignals($previous)
    {
        if ($previous !== null) {
            pcntl_sigprocmask(SIG_SETMASK, $previous);
        }
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
        self::$forceOnRepeat = null;
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
