<?php

namespace mpcmf\system\threads;

use mpcmf\system\helper\io\log;

/**
 * Addressable thread task for the forkserver and spawn start methods
 *
 * A child of these start methods does not share memory with the master, so the task must be addressable by name:
 * 'class::method' or [class, 'method'] (public static method) or an invokable class name. Arguments are serialized.
 */
class threadTask
{
    use log;

    /**
     * Validate a task and bring it to [class, method|null]
     *
     * @param mixed $task
     *
     * @return array
     * @throws \InvalidArgumentException
     */
    public static function normalize($task)
    {
        if (is_object($task)) {
            throw new \InvalidArgumentException('Closures and objects cannot be passed to a forkserver/spawn child, use "class::method", [class::class, "method"] (public static method) or an invokable class name');
        }
        if (is_string($task) && strpos($task, '::') !== false) {
            $task = explode('::', $task, 2);
        }
        if (is_string($task)) {
            if (!class_exists($task) || !method_exists($task, '__invoke')) {
                throw new \InvalidArgumentException("Thread task {$task} is not an invokable class");
            }
            $constructor = (new \ReflectionClass($task))->getConstructor();
            if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                throw new \InvalidArgumentException("Invokable thread task {$task} must be constructible without arguments");
            }

            return [$task, null];
        }
        if (is_array($task) && count($task) === 2 && is_string(reset($task)) && is_string(end($task))) {
            [$class, $method] = array_values($task);
            if (!class_exists($class) || !method_exists($class, $method)) {
                throw new \InvalidArgumentException("Thread task {$class}::{$method} does not exist");
            }
            $reflection = new \ReflectionMethod($class, $method);
            if (!$reflection->isStatic() || !$reflection->isPublic()) {
                throw new \InvalidArgumentException("Thread task {$class}::{$method} must be a public static method");
            }

            return [$class, $method];
        }

        throw new \InvalidArgumentException('Thread task must be "class::method", [class::class, "method"] or an invokable class name');
    }

    /**
     * Validate the task and serialize it with its arguments
     *
     * @param mixed $task
     * @param mixed $arguments
     *
     * @return string
     * @throws \InvalidArgumentException
     */
    public static function encode($task, $arguments)
    {
        $task = self::normalize($task);
        try {
            return serialize(['task' => $task, 'args' => $arguments]);
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException("Thread arguments must be serializable: {$exception->getMessage()}", 0, $exception);
        }
    }

    /**
     * Autoload the task class, so forked children do not load it again
     *
     * @param string $payload
     */
    public static function preload($payload)
    {
        $data = @unserialize($payload);
        if (isset($data['task'][0])) {
            class_exists($data['task'][0]);
        }
    }

    /**
     * Child side: run the task, never returns
     *
     * @param array $message payload, graceful, signals, title, async
     */
    public static function run(array $message)
    {
        // async signals as the master had them when it started the thread, like a forked child inherits them:
        // signal handlers of the application are called without pcntl_signal_dispatch()
        pcntl_async_signals(!empty($message['async']));
        if (!empty($message['title'])) {
            cli_set_process_title($message['title'] . thread::CHILD_POSTFIX);
        }
        try {
            $data = unserialize($message['payload']);
            [$class, $method] = $data['task'];
            $callable = $method === null ? new $class() : [$class, $method];
            if (!is_callable($callable)) {
                throw new \RuntimeException("Thread task {$class}::{$method} is not callable");
            }
        } catch (\Throwable $exception) {
            self::log()->addError("[ERROR] Cannot start thread task: {$exception->getMessage()}");
            MPCMF_DEBUG && error_log("[ERROR] Cannot start thread task: {$exception->getMessage()}");

            exit(1);
        }

        $thread = new thread($callable);
        $thread->setGracefulStop(!empty($message['graceful']));
        $thread->setDefaultSignals(isset($message['signals']) ? $message['signals'] : []);
        $thread->runInChild([$data['args']], false);
    }
}
