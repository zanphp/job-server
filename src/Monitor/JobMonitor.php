<?php

namespace Zan\Framework\Components\JobServer\Monitor;


use Zan\Framework\Components\JobServer\CronJobManager;
use Zan\Framework\Components\JobServer\Job;
use Zan\Framework\Components\JobServer\JobMode;
use Zan\Framework\Components\JobServer\MqJobManager;
use Zan\Framework\Foundation\Container\Di;
use Zan\Framework\Foundation\Core\Config;
use Zan\Framework\Network\Server\Timer\Timer;
use swoole_server as SwooleServer;

class JobMonitor
{
    const STORE_INTERVAL = 1000;
    const LIST_KEY_FMT = "worker#%d_%s_list";

    /**
     * @var SwooleServer
     */
    protected static $swooleServer;

    /**
     * @var array jobMode => jobMgr
     */
    protected static $mgr = [];

    public static function start(SwooleServer $swooleServer)
    {
        if (static::$swooleServer) {
            return;
        }

        if (JobMode::isOn(JobMode::CRON) || JobMode::isOn(JobMode::MQ_WORKER)) {
            static::$swooleServer = $swooleServer;
            static::init();
        }
    }

    protected static function init()
    {
        static::$mgr[JobMode::MQ_WORKER] = Di::make(MqJobManager::class, [], true);
        static::$mgr[JobMode::CRON] = Di::make(CronJobManager::class, [], true);

        $workerId = static::$swooleServer->worker_id;
        
        Timer::after(1 + $workerId * 200, function() {
            Timer::tick(static::STORE_INTERVAL, function() {
                foreach (static::$mgr as $jobMode => $_) {
                    static::storeShareList($jobMode);
                }
            });
        });
    }

    public static function getJobList()
    {
        if (!static::$swooleServer) {
            return [];
        }

        return [
            JobMode::MQ_WORKER => static::getJobListByMode(JobMode::MQ_WORKER),
            JobMode::CRON => static::getJobListByMode(JobMode::CRON),
        ];
    }

    public static function done(Job $job)
    {
        if (!static::$swooleServer) {
            return;
        }

        if ($job->attempts > 1) {
            ShareCounter::decr("$job->jobKey#delay");
        }
        ShareCounter::incr("$job->jobKey#done");
    }

    public static function error(Job $job)
    {
        if (!static::$swooleServer) {
            return;
        }

        ShareCounter::incr("$job->jobKey#error");
    }

    public static function delay(Job $job)
    {
        if (!static::$swooleServer) {
            return;
        }
        ShareCounter::incr("$job->jobKey#delay");
    }

    protected static function getJobListByMode($jobMode)
    {
        $list = [];

        if (PHP_OS === "Darwin") {
            $countStatistics = ShareCounter::statistic();
        }

        $workerNum = static::$swooleServer->setting["worker_num"];
        for ($i = 0; $i < $workerNum; $i++) {

            $subList = static::getShareList($i, $jobMode);

            if (PHP_OS === "Darwin") {

                /** @noinspection PhpUndefinedVariableInspection */
                $countStatistic = $countStatistics[$i];
                foreach ($subList as $jobKey => &$value) {
                    foreach (["done", "error", "delay"] as $type) {
                        $key = "$jobKey#$type";
                        $value["count_$type"] = isset($countStatistic[$key]) ? $countStatistic[$key] : 0;
                    }
                }
                unset($value);
                
            } else {
                foreach ($subList as $jobKey => &$value) {
                    foreach (["done", "error", "delay"] as $type) {
                        $value["count_$type"] = ShareCounter::apcuGet("$jobKey#$type");
                    }
                }
                unset($value);
            }

            $list["worker#$i"] = $subList;
        }
        return $list;
    }

    protected static function storeShareList($jobMode)
    {
        $workerId = static::$swooleServer->worker_id;

        if (JobMode::isOn($jobMode)) {
            $list = static::$mgr[$jobMode]->listJob();
            return apcu_store(sprintf(static::LIST_KEY_FMT, $workerId, $jobMode), json_encode($list));
        }
        return false;
    }

    protected static function getShareList($workerId, $jobMode)
    {
        $key = sprintf(static::LIST_KEY_FMT, $workerId, $jobMode);
        $ret = apcu_fetch($key);
        $list = json_decode($ret, true);
        return $list ?: [];
    }

    public static function getConnectionPoolStatus()
    {
        // TODO 加入NSQ连接状态
        $connStat = [];
        $workerNum = Config::get("server.config.worker_num", 1);
        $connections = Config::get("connection");

        $ex = null;
        $process = new Process;

        try {
            foreach ($connections as $type => $connection) {
                foreach ($connection as $key => $item) {
                    if (isset($item["pool"]) && $item["pool"]) {
                        $connStat["$type.$key"] = (yield static::socketStatus($process, $item["host"], $item["port"]));
                    }
                }
            }
        } catch (\Exception $ex) {}
        
        $process->_exit();

        if ($ex) {
            throw $ex;
        }

        yield [
            "worker_num" => $workerNum,
            "pool_stat" => $connStat,
        ];
    }

    private static function socketStatus(Process $process, $host, $port) {
        if (!ip2long($host)) {
            $host = (yield Dns::lookup($host, 200));
        }

        if (PHP_OS === "Darwin") {
            $states = ["ESTABLISHED", "TIME_WAIT", "CLOSE_WAIT"];
            $cmd = "netstat -an | awk '(\$5 == \"$host.$port\" && \$6 == \"%s\") || NR==2  {print \$0}'"; // $4 src $5 dst $6 stats
        } else {
            $states = ["established", "time-wait", "close-wait"];
            $cmd = "ss state %s dst $host:$port";
        }

        $info = [];
        foreach ($states as $state) {
            $recv = (yield $process->pipeExec(sprintf("$cmd | wc -l", $state)));
            $info[$state] = intval(trim($recv)) - 1;
        }
        yield $info;
    }
}