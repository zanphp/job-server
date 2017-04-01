<?php

namespace Zan\Framework\Components\JobServer\WorkerStart;


use Zan\Framework\Components\JobServer\ConsoleJobManager;
use Zan\Framework\Components\JobServer\Crontab;
use Zan\Framework\Components\JobServer\CronJobManager;
use Zan\Framework\Components\JobServer\JobMode;
use Zan\Framework\Components\JobServer\JobProcessor\HttpJobProcessor;
use Zan\Framework\Components\JobServer\JobProcessor\JobExceptionHandler;
use Zan\Framework\Components\JobServer\Monitor\JobMonitor;
use Zan\Framework\Components\JobServer\MqJobManager;
use Zan\Framework\Contract\Network\Bootable;
use Zan\Framework\Foundation\Container\Di;
use Zan\Framework\Foundation\Core\ConfigLoader;
use Zan\Framework\Foundation\Core\Path;
use Zan\Framework\Foundation\Coroutine\Task;
use Zan\Framework\Network\Http\RequestExceptionHandlerChain;
use swoole_server as SwooleServer;

class InitializeJobServer implements Bootable
{
    const DEFAULT_TIMEOUT = 60000;

    /**
     * @var MqJobManager
     */
    protected $mqJobMgr;

    /**
     * @var CronJobManager
     */
    protected $cronJobMgr;

    /**
     * @var ConsoleJobManager
     */
    protected $consoleJobMgr;

    public function bootstrap($server)
    {
        if (JobMode::isOn()) {
            $swServer = $server->swooleServer;

            // $this->fixMultiThreadSignalBug();

            $this->init($swServer);

            // JobMonitor::start($swServer);
        }
    }

    protected function fixMultiThreadSignalBug()
    {
        pcntl_signal(SIGTERM, function() {});
    }

    protected function init(SwooleServer $swServ)
    {
        sys_echo("worker #{$swServ->worker_id} job server bootstrap .....");

        $this->registerJobExceptionHandler();

        if (JobMode::isCli()) {
            $this->consoleJobMgr = Di::make(ConsoleJobManager::class, [$swServ], true);

            $this->bootConsoleWorker($swServ);
            return;
        }

        if ((JobMode::isOn(JobMode::CRON))) {
            $this->cronJobMgr = Di::make(CronJobManager::class, [$swServ], true);

            $cronConf = ConfigLoader::getInstance()->loadDistinguishBetweenFolderAndFile(Path::getCronPath());
            if ($cronConf) {
                $this->bootCronWorker($swServ, $cronConf);
            }
        }

        if (JobMode::isOn(JobMode::MQ_WORKER)) {
            $this->mqJobMgr = Di::make(MqJobManager::class, [$swServ], true);

            $o = getopt("", [ "mqfile:" ]);
            if (isset($o["mqfile"])) {
                $path = $o["mqfile"];
                $file  = Path::getMqWorkerPath() . $o["mqfile"];
                if (!is_readable($file)) {
                    fprintf(STDERR, "$file not found\n");
                    $swServ->shutdown();
                }
                $mqWorkerConf = ["$path" => require $file];
            } else {
                $mqWorkerConf = ConfigLoader::getInstance()->loadDistinguishBetweenFolderAndFile(Path::getMqWorkerPath());
            }
            if ($mqWorkerConf) {
                $this->bootMqWorker($swServ, $mqWorkerConf);
            }
        }
    }

    protected function registerJobExceptionHandler()
    {
        $exChain = RequestExceptionHandlerChain::getInstance();

        $this->setProperty($exChain, 'handlerChain', function($handlerChain) {
            array_unshift($handlerChain, new JobExceptionHandler());
            return $handlerChain;
        });

        $this->setProperty($exChain, 'handlerMap', function($handlerMap) {
            $handlerMap[JobExceptionHandler::class] = true;
            return $handlerMap;
        });

        // 1. 以 Tcp Server 为载体需要手动开启
        // 2. 需要优先捕获所有异常, 作业失败
        $exChain->init();
    }

    protected function bootConsoleWorker(SwooleServer $swServ)
    {
        global $argv;

        if ($argv[1] === "--help") {
            $this->usage($swServ);
            return;
        }

        $args = ConsoleJobManager::parseInputArgs();
        if (!$args['uri']) {
            $this->usage($swServ);
            return;
        }

        if ($swServ->worker_id === 0) {
            sys_echo("worker #{$swServ->worker_id} job console worker bootstrap .....");

            try {
                $this->consoleJobMgr->start();
            } catch (\Exception $ex) {
                echo_exception($ex);
                swoole_event_exit();
                $swServ->shutdown();
            }
        }
    }

    protected function usage(SwooleServer $swServ)
    {
        ConsoleJobManager::usage();
        swoole_event_exit();
        $swServ->shutdown();
    }

    protected function bootMqWorker(SwooleServer $swServ, array $conf)
    {
        sys_echo("worker #{$swServ->worker_id} message queue worker bootstrap .....");

        try {
            $task = $this->doBootMqWorker($conf);
            Task::execute($task);
        } catch (\Exception $ex) {
            echo_exception($ex);
            swoole_event_exit();
            $swServ->shutdown();
        }
    }

    protected function bootCronWorker(SwooleServer $swServ, array $conf)
    {
        sys_echo("worker #{$swServ->worker_id} crontab worker bootstrap .....");

        try {
            $this->doBootCronWorker($swServ, $conf);
        } catch (\Exception $ex) {
            echo_exception($ex);
            swoole_event_exit();
            $swServ->shutdown();
        }
    }

    protected function doBootMqWorker(array $conf)
    {
        $options = [
            "timeout" => static::DEFAULT_TIMEOUT,
            "method" => "GET",
            "header" => [],
            "body" => "",
            "coroutine_num" => 1,
        ];

        foreach ($conf as $path => $mqConfs) {
            foreach ($mqConfs as $jobKey => $mqConf) {
                if (!isset($mqConf["uri"]) || !isset($mqConf["topic"]) || !isset($mqConf["channel"])) {
                    continue;
                }
                $mqConf = $mqConf + $options;

                $processor = new HttpJobProcessor($mqConf["method"], $mqConf["uri"], $mqConf["header"], $mqConf["body"]);

                for ($i = 0; $i < $mqConf["coroutine_num"]; $i++) {
                    // fix bug, 不同文件同名jobKey会产生覆盖, 这里将jobkey追加path
                    $this->mqJobMgr->register("{$path}.{$jobKey}_co_$i", $processor, $mqConf);
                }
            }
        }

        yield $this->mqJobMgr->start();
    }

    protected function doBootCronWorker(SwooleServer $swServ, array $conf)
    {

        $mod = $swServ->setting["worker_num"];
        $workerId = $swServ->worker_id;

        $options = [
            "timeout" => static::DEFAULT_TIMEOUT,
            "strict" => false,
            "method" => "GET",
            "header" => [],
            "body" => "",
        ];

        foreach ($conf as $path => $cronConfs) {
            $i = -1;
            foreach ($cronConfs as $jobKey => &$cronConf) {
                $i++;
                if (!isset($cronConf["uri"]) || !isset($cronConf["cron"])) {
                    continue;
                }
                $cronConf = $cronConf + $options;

                $bindWorker = $i % $mod;
                if ($bindWorker !== $workerId) {
                    continue;
                }

                $cronConf["crontab"] = Crontab::parse($cronConf["cron"]);
                $cronConf["bind_worker"] = $bindWorker;
                $processor = new HttpJobProcessor($cronConf["method"], $cronConf["uri"], $cronConf["header"], $cronConf["body"]);
                $this->cronJobMgr->register($jobKey, $processor, $cronConf);
            }
            unset($cronConf);
        }

        $this->cronJobMgr->start();
    }

    private function setProperty($obj, $propName, callable $callback)
    {
        try {
            $clazz = new \ReflectionObject($obj);
            $prop = $clazz->getProperty($propName);
            $isAccess = $prop->isPublic();
            if (!$isAccess) {
                $prop->setAccessible(true);
            }

            $value = $prop->getValue($obj);
            $value = $callback($value);
            $prop->setValue($obj, $value);

            if (!$isAccess) {
                $prop->setAccessible(false);
            }
        } catch (\Exception $ex) {
            echo_exception($ex);
        }
    }
}