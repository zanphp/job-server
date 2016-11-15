<?php

namespace Zan\Framework\Components\JobServer;

use Zan\Framework\Components\JobServer\Contract\JobProcessor;
use Zan\Framework\Components\JobServer\Contract\JobManager;
use Zan\Framework\Components\JobServer\Monitor\JobMonitor;
use Zan\Framework\Components\Nsq\Message;
use Zan\Framework\Components\Nsq\SQS;
use Zan\Framework\Network\Server\Monitor\Worker;
use swoole_server as SwooleServer;

class MqJobManager implements JobManager
{
    const MAX_ATTEMPTS = 5;

    protected $isRunning = false;

    protected $swooleServer;

    /**
     * @var array jobKey => JobProcessor
     */
    protected $processors = [];

    /**
     * @var array jobKey => jobConfig
     */
    protected $jobConfigs = [];

    public function __construct(SwooleServer $swooleServer)
    {
        $this->swooleServer = $swooleServer;
    }

    public function submit($dst, $raw)
    {
        sys_echo("worker #{$this->swooleServer->worker_id} SUBMIT_MQ_JOB [jobKey=$dst]");

        $input = $this->jobEncode($raw);
        yield SQS::publish($dst, $input);
    }

    public function done(Job $job)
    {
        if ($job->status !== Job::INIT) {
            return false;
        }

        sys_echo("worker #{$this->swooleServer->worker_id} DONE_MQ_JOB [jobKey=$job->jobKey, fingerPrint=$job->fingerPrint]");

        /* @var $msg Message */
        $msg = $job->extra;
        $msg->finish();
        $job->status = Job::DONE;

        JobMonitor::done($job);

        return true;
    }

    protected function delay(Job $job, $reason)
    {
        if ($job->status !== Job::INIT) {
            return false;
        }

        /* @var $msg Message */
        $msg = $job->extra;
        $delay = 2 ** $job->attempts;
        $msg->requeue($delay);
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

    public function unRegister($jobKey)
    {
        sys_echo("worker #{$this->swooleServer->worker_id} UN_REGISTER_MQ_JOB [jobKey=$jobKey]");

        if (isset($this->jobConfigs[$jobKey])) {
            $topic = $this->jobConfigs[$jobKey]["topic"];
            $channel = $this->jobConfigs[$jobKey]["channel"];
            if (SQS::unSubscribe($topic, $channel)) {
                unset($this->jobConfigs[$jobKey]);
                unset($this->processors[$jobKey]);
                return true;
            }
        }

        return false;
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
        yield SQS::subscribe($topic, $channel, $onReceive);
    }

    public function onReceive($jobKey, JobProcessor $jobProcessor)
    {
        return function(Message $msg) use($jobKey, $jobProcessor) {

            if (Worker::getInstance()->isDenyRequest()) {
                $this->stop();
            }

            if (!$this->isRunning) {
                return;
            }

            $timeout = $this->jobConfigs[$jobKey]["timeout"];
            $job = $this->jobDecode($jobKey, $msg);
            
            $jobProcessor->process($this, $job, $timeout);
        };
    }

    protected function jobEncode($source)
    {
        return json_encode($source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function jobDecode($jobKey, Message $msg)
    {
        $body = json_decode($msg->getBody(), JSON_BIGINT_AS_STRING);

        $topic = $this->jobConfigs[$jobKey]["topic"];
        $channel = $this->jobConfigs[$jobKey]["channel"];
        $workerId = $this->swooleServer->worker_id;
        $uri = $this->jobConfigs[$jobKey]["uri"];

        $job = new Job;

        $job->jobKey        = $jobKey;
        $job->fingerPrint   = "$workerId#$jobKey#$uri#$topic:$channel#" . $msg->getId();
        $job->body          = $body;
        $job->raw           = $msg->getBody();
        $job->createTime    = $msg->getTimestamp() / 1e9;
        $job->attempts      = $msg->getAttempts() ?: 1;
        $job->status        = Job::INIT;
        $job->extra         = $msg;

        return $job;
    }
}