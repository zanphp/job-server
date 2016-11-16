<?php

namespace Zan\Framework\Components\JobServer;

use Kdt\Iron\NSQ\Message\Msg;
//use Kdt\Iron\NSQ\Piping\Node;
use Zan\Framework\Components\JobServer\Contract\JobProcessor;
use Zan\Framework\Components\JobServer\Contract\JobManager;
use Zan\Framework\Components\JobServer\Monitor\JobMonitor;
use Zan\Framework\Network\Server\Monitor\Worker;
use Zan\Framework\Sdk\Queue\NSQ\Queue;
use swoole_server as SwooleServer;

class MqJobManager implements JobManager
{
    const MAX_ATTEMPTS = 5;

    protected $isRunning = false;

    protected $swooleServer;

    protected $msgQueue;

    /**
     * @var array jobKey => JobProcessor
     */
    protected $processors = [];

    /**
     * @var array jobKey => jobConfig
     */
    protected $jobConfigs = [];

    /**
     * @var array jobKey => Node[]
     */
    // protected $nodes = [];

    public function __construct(Queue $msgQueue, SwooleServer $swooleServer)
    {
        $this->msgQueue = $msgQueue;
        $this->swooleServer = $swooleServer;
    }

    public function submit($dst, $raw)
    {
        sys_echo("worker #{$this->swooleServer->worker_id} SUBMIT_MQ_JOB [jobKey=$dst]");

        $input = $this->jobEncode($raw);
        yield $this->msgQueue->publish($dst, Msg::fromClient($input));
    }

    public function done(Job $job)
    {
        if ($job->status !== Job::INIT) {
            return false;
        }

        sys_echo("worker #{$this->swooleServer->worker_id} DONE_MQ_JOB [jobKey=$job->jobKey, fingerPrint=$job->fingerPrint]");

        /* @var $msg Msg */
        $msg = $job->extra;
        $msg->done();
        $job->status = Job::DONE;

        JobMonitor::done($job);

        return true;
    }

    protected function delay(Job $job, $reason)
    {
        if ($job->status !== Job::INIT) {
            return false;
        }

        /* @var $msg Msg */
        $msg = $job->extra;
        $delay = 2 ** $job->attempts;
        $msg->delay($delay);
        $job->status = Job::RETRY;

        sys_echo("worker #{$this->swooleServer->worker_id} DELAY_MQ_JOB [jobKey=$job->jobKey, fingerPrint=$job->fingerPrint, attempts=$job->attempts, delay={$delay}s, reason=$reason]");

        JobMonitor::delay($job);

        return true;
    }

    public function error(Job $job, $reason)
    {
        if ($reason instanceof \Exception) {
            $reason = $reason->getMessage();
        }
        if ($job->attempts >= static::MAX_ATTEMPTS) {
            sys_echo("worker #{$this->swooleServer->worker_id} ERROR_MQ_JOB [jobKey=$job->jobKey, fingerPrint=$job->fingerPrint, attempts=$job->attempts, reason=$reason]");
            $this->done($job);
        } else {
            $this->delay($job, $reason);
        }

        JobMonitor::error($job);

        return true;
    }

    public function register($jobKey, JobProcessor $jobProcessor, array $config = [])
    {
        if ($this->isRunning || isset($this->processors[$jobKey])) {
            return false;
        }

        sys_echo("worker #{$this->swooleServer->worker_id} REGISTER_MQ_JOB [jobKey=$jobKey]");

        $this->processors[$jobKey] = $jobProcessor;
        $this->jobConfigs[$jobKey] = $config;

        return true;
    }

    // TODO 问题
    // 1. 必须接收到一条消息之后才能取消订阅,
    // 2. 直接关闭连接,可能有数据没发送完
    public function unRegister($jobKey)
    {
//        sys_echo("worker #{$this->swooleServer->worker_id} UN_REGISTER_MQ_JOB [jobKey=$jobKey]");
//
//        if (isset($this->nodes[$jobKey])) {
//            foreach ($this->nodes[$jobKey] as $node) {
//                /* @var $node Node */
//                $node->close();
//            }
//            unset($this->nodes[$jobKey]);
//            unset($this->processors[$jobKey]);
//        }
    }

    public function listJob()
    {
        return $this->jobConfigs;
    }

    public function start()
    {
        $this->isRunning = true;

        foreach ($this->processors as $jobKey => $processor) {
            yield $this->subscribe($jobKey, $processor);
        }
    }

    public function stop()
    {
        $this->isRunning = false;

        foreach ($this->processors as $jobKey => $_) {
            $this->unRegister($jobKey);
        }
    }

    protected function subscribe($jobKey, JobProcessor $jobProcessor)
    {
        $topic = $this->jobConfigs[$jobKey]["topic"];
        $channel = $this->jobConfigs[$jobKey]["channel"];

        $onReceive = $this->onReceive($jobKey, $jobProcessor);
        yield $this->msgQueue->subscribe($topic, $channel, $onReceive, 0);
    }

    public function onReceive($jobKey, JobProcessor $jobProcessor)
    {
        return function(Msg $msg/*, Node $node = null*/) use($jobKey, $jobProcessor) {

            if (Worker::getInstance()->isDenyRequest()) {
                $this->stop();
            }

            if (!$this->isRunning) {
                return;
            }

            // $this->nodes[$jobKey] = $node;

            $timeout = $this->jobConfigs[$jobKey]["timeout"];
            $job = $this->jobDecode($jobKey, $msg);
            
            $jobProcessor->process($this, $job, $timeout);
        };
    }

    protected function jobEncode($source)
    {
        return json_encode($source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function jobDecode($jobKey, Msg $msg)
    {
        $body = json_decode($msg->payload(), true, 512, JSON_BIGINT_AS_STRING);

        $topic = $this->jobConfigs[$jobKey]["topic"];
        $channel = $this->jobConfigs[$jobKey]["channel"];
        $workerId = $this->swooleServer->worker_id;
        $uri = $this->jobConfigs[$jobKey]["uri"];

        $job = new Job;

        $job->jobKey        = $jobKey;
        $job->fingerPrint   = "$workerId#$jobKey#$uri#$topic:$channel#" . $msg->id();
        $job->body          = $body;
        $job->raw           = $msg->payload();
        $job->createTime    = $msg->timestamp() / 1e9;
        $job->attempts      = $msg->attempts() ?: 1;
        $job->status        = Job::INIT;
        $job->extra         = $msg;

        return $job;
    }
}