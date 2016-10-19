<?php

namespace Zan\Framework\Components\JobServer\Monitor;


use Zan\Framework\Foundation\Contract\Async;
use swoole_process as SwooleProcess;
use Zan\Framework\Network\Server\Timer\Timer;

class Process implements Async
{
    const DEFAULT_TIMEOUT = 1000;

    /**
     * @var SwooleProcess
     */
    protected $process;

    protected $callback;

    /**
     * Async Exec
     * @param $cmd
     * @param int $timeout
     * @return \Generator
     */
    public static function exec($cmd, $timeout = self::DEFAULT_TIMEOUT)
    {
        $self = new static;
        $recv = (yield $self->pipeExec($cmd, $timeout));
        $self->_exit();
        yield $recv;
    }

    public function __construct()
    {
        $this->process = new SwooleProcess($this->loopCmdTask(), false, 2);
        $this->process->start();
    }

    /**
     * 在子进程内顺序异步执行命令
     * worker进程异步处理, 子进程同步阻塞
     * 注意最终调用_exit(), 保证子进程退出
     * @param string $cmd
     * @param int $timeout
     * @return Async
     */
    public function pipeExec($cmd, $timeout = self::DEFAULT_TIMEOUT)
    {
        $overtimeId = Timer::after($timeout, $this->handleTimeout($cmd, $timeout));

        $flag = SWOOLE_EVENT_READ | SWOOLE_EVENT_WRITE;
        swoole_event_add($this->process->pipe, $this->readResult($overtimeId), $this->writeCmd($cmd), $flag);

        yield $this;
    }

    /**
     * block
     */
    public function _exit()
    {
        $this->process->write("exit");
        $this->process = null;
    }

    protected function handleTimeout($cmd, $timeout)
    {
        return function() use($cmd, $timeout) {
            swoole_event_del($this->process->pipe);
            $this->continueTask(null, new \RuntimeException("Exec <$cmd> timeout [{$timeout}ms]"));
        };
    }

    protected function readResult($overtimeId)
    {
        return function($pipe) use($overtimeId) {
            Timer::clearAfterJob($overtimeId);
            $recv = $this->process->read();
            // sys_echo("Exec recv: $recv");
            $recv = json_decode($recv, true);
            if (is_array($recv)) {
                $this->continueTask($recv["output"]);
            } else {
                $this->continueTask(null);
            }
        };
    }

    protected function writeCmd($cmd)
    {
        return function($pipe) use($cmd) {
            $this->process->write($cmd); // check writeN
            swoole_event_set($this->process->pipe, null, null);
        };
    }

    protected function loopCmdTask()
    {
        return function(SwooleProcess $process) {
            // block loop
            while (true) {
                $cmd = $process->read();
                // sys_echo("Exec recv: $cmd");
                if ($cmd === "exit") {
                    $process->exit(0);
                    return;
                }

                $output = "";
                $ret = exec($cmd, $output, $status);
                // sys_echo("Exec ret: $ret");
                $process->write(json_encode([
                    "status" => $status,
                    "output" => implode("\n", $output),
                ]));
            }
        };
    }

    protected function continueTask($response, $exception = null)
    {
        call_user_func($this->callback, $response, $exception);
    }

    public function execute(callable $callback, $task)
    {
        $this->callback = $callback;
    }

    public function __destruct()
    {
        if ($this->process) {
            $this->_exit();
        }
    }
}