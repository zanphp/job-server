<?php

namespace Zan\Framework\Components\JobServer;


use Zan\Framework\Components\JobServer\Contract\JobManager;
use Zan\Framework\Components\JobServer\Contract\JobProcessor;
use Zan\Framework\Components\JobServer\Monitor\JobMonitor;
use Zan\Framework\Foundation\Application;
use Zan\Framework\Foundation\Core\Debug;
use Zan\Framework\Network\Server\Monitor\Worker;
use Zan\Framework\Network\Server\Timer\Timer;
use Zan\Framework\Utilities\Types\Time;
use swoole_server as SwooleServer;

class CronJobManager implements JobManager
{
    const LOG_DIR_FMT = "/data/logs/job-%s";
    const DUMP_INTERVAL = 1000;

    protected $swooleServer;

    protected $jobId;

    protected $dumpTickId;

    protected $file;

    /**
     * @var array jobKey => lastRunTs
     */
    protected $lastProcessTime = [];

    /**
     * @var array jobKey => cronConf
     */
    protected $cronConfigs = [];

    /**
     * @var array jobKey => JobProcessor
     */
    protected $jobProcessors = [];

    public function __construct(SwooleServer $swooleServer)
    {
        $this->swooleServer = $swooleServer;
        $this->init();
    }

    protected function init()
    {
        $workerId = $this->swooleServer->worker_id;
        $appName = Application::getInstance()->getName();
        $ymd = date("Y-m-d");

        if (PHP_OS === "Darwin") {
            $logDir = sys_get_temp_dir();
        } else {
            $logDir = sprintf(static::LOG_DIR_FMT, $appName);
            // 避免mkdir竞态
            if ($workerId === 0) {
                if (!file_exists($logDir)) {
                    mkdir($logDir, 0755, true);
                }
            }
        }

        // 按workerId分开存储, 避免对文件加锁, 减少文件体积,
        // 1. cronjob配置的顺序影响
        // 2. worker_num调节影响
        $this->file = "$logDir/$appName#cron#$ymd#$workerId.data";
    }

    public function submit($dst, $body)
    {

    }

    public function done(Job $job)
    {
        if ($job->status !== Job::INIT) {
            return false;
        }

        sys_echo("worker #{$this->swooleServer->worker_id} DONE_CRON_JOB [jobKey=$job->jobKey, fingerPrint=$job->fingerPrint]");

        $job->status = Job::DONE;

        JobMonitor::done($job);

        return true;
    }

    public function error(Job $job, $reason)
    {
        if ($job->status !== Job::INIT) {
            return false;
        }

        if ($reason instanceof \Exception) {
            $reason = get_class($reason) . "::" . $reason->getMessage();
        }
        sys_echo("worker #{$this->swooleServer->worker_id} ERROR_CRON_JOB [jobKey=$job->jobKey, fingerPrint=$job->fingerPrint, attempts=$job->attempts, reason=$reason]");

        $job->status = Job::ERROR;

        JobMonitor::error($job);

        return true;
    }

    public function register($jobKey, JobProcessor $jobProcessor, array $config = [])
    {
        if ($this->jobId && isset($this->jobProcessors[$jobKey])) {
            return false;
        }

        sys_echo("worker #{$this->swooleServer->worker_id} REGISTER_CRON_JOB [jobKey=$jobKey, cron={$config['cron']}]");

        $this->jobProcessors[$jobKey] = $jobProcessor;
        $this->cronConfigs[$jobKey] = $config;
        
        return true;
    }

    public function unRegister($jobKey)
    {
        sys_echo("worker #{$this->swooleServer->worker_id} UN_REGISTER_CRON_JOB [jobKey=$jobKey]");
        unset($this->jobProcessors[$jobKey]);
        unset($this->cronConfigs[$jobKey]);
    }

    public function listJob()
    {
        $list = [];
        foreach ($this->cronConfigs as $jobKey => $conf) {
            if (isset($this->lastProcessTime[$jobKey])) {
                $conf["last_process_time"] = date("Y-m-d H:i:s", $this->lastProcessTime[$jobKey]);
            }
            $list[$jobKey] = $conf;
        }
        return $list;
    }

    public function start()
    {
        $this->doOneCronCheck();
        $this->jobId = Timer::tick(1000, [$this, "doOneCronCheck"]);

        register_shutdown_function([$this, "dumpLastProcessTime"]);
        if (file_exists($this->file)) {
            swoole_async_readfile($this->file, [$this, "loadCallback"]); // 注意4M文件体积限制
        } else {
            $this->dumpTickId = Timer::tick(static::DUMP_INTERVAL, [$this, "dumpLastProcessTime"]);
        }
    }

    public function stop()
    {
        if ($this->jobId) {
            Timer::clearTickJob($this->jobId);
            Timer::clearTickJob($this->dumpTickId);
            $this->dumpLastProcessTime();

            $this->jobId = null;
            $this->dumpTickId = null;
        }
    }

    public function doOneCronCheck()
    {
        if (Worker::getInstance()->isDenyRequest()) {
            $this->stop();
            return;
        }

        $ts = Time::stamp();

        foreach ($this->cronConfigs as $jobKey => $cronConf) {
            /* @var $crontab Crontab */
            $crontab = $cronConf["crontab"];
            if ($crontab->isInTime($ts)) {
                $this->processJob($jobKey, $crontab, $ts);
                $this->lastProcessTime[$jobKey] = $ts;
            }
        }
    }

    protected function processJob($jobKey, Crontab $crontab, $ts)
    {
        $crontab = implode("_", $crontab->getCrontab());
        $job = $this->makeCronJob($crontab, $ts, $jobKey);

        $timeout = $this->cronConfigs[$jobKey]["timeout"];
        /* @var  $processor JobProcessor */
        $processor = $this->jobProcessors[$jobKey];

        $processor->process($this, $job, $timeout);
    }

    public function dumpLastProcessTime()
    {
        if ($this->lastProcessTime) {
            $data = json_encode($this->lastProcessTime);
            swoole_async_writefile($this->file, $data, function($file, $n) {}); // 注意4M文件体积限制
        }
    }

    public function loadCallback($file, $data)
    {
        if (!$data) {
            goto tick_dump;
        }
        $jobStatus = json_decode($data, true);

        if (!is_array($jobStatus)) {
            goto tick_dump;
        }

        $this->processOvertimeJob($jobStatus);

        foreach ($this->cronConfigs as $jobKey => $_) {
            if (isset($jobStatus[$jobKey]) && !isset($this->lastProcessTime[$jobKey])) {
                $this->lastProcessTime[$jobKey] = $jobStatus[$jobKey];
            }
        }

        tick_dump:
        $this->dumpTickId = Timer::tick(static::DUMP_INTERVAL, [$this, "dumpLastProcessTime"]);
    }

    protected function processOvertimeJob(array $jobStatus)
    {
        $nowTs = Time::stamp();

        foreach ($this->cronConfigs as $jobKey => $cronConf) {
            $isStrict = $cronConf["strict"];
            if (!$isStrict) {
                continue;
            }

            /* @var $crontab Crontab */
            $crontab = $cronConf["crontab"];

            $lastRunTs = $jobStatus[$jobKey];
            for ($ts = $lastRunTs; $ts < $nowTs; $ts++) {
                $isInCrontab = $crontab->isInTime($ts);
                if ($isInCrontab) {
                    $this->processJob($jobKey, $crontab, $ts);
                }
            }
        }
    }

    protected function makeCronJob($crontab, $ts, $jobKey)
    {
        $workerId = $this->swooleServer->worker_id;
        $uri = $this->cronConfigs[$jobKey]["uri"];

        $job = new Job;

        $job->jobKey        = $jobKey;
        $job->fingerPrint   = "$workerId#$jobKey#$uri#$crontab#$ts#" . microtime(true) * 1000;
        $job->body          = null;
        $job->raw           = null;
        $job->createTime    = $ts;
        $job->attempts      = 1;
        $job->status        = Job::INIT;
        $job->extra         = $this->cronConfigs[$jobKey];

        return $job;
    }
}