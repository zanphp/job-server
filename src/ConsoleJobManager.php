<?php

namespace Zan\Framework\Components\JobServer;

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Zan\Framework\Components\JobServer\Contract\JobManager;
use Zan\Framework\Components\JobServer\Contract\JobProcessor;
use Zan\Framework\Components\JobServer\JobProcessor\HttpJobProcessor;
use Zan\Framework\Utilities\Types\Time;
use swoole_server as SwooleServer;

class ConsoleJobManager implements JobManager
{
    const DEFAULT_TIMEOUT = 60000;

    protected $swooleServer;

    /**
     * @var InputDefinition
     */
    protected static $inputDefinition;

    /**
     * @var $processors JobProcessor[]
     */
    protected $processors = [];

    public function __construct(SwooleServer $swooleServer)
    {
        $this->swooleServer = $swooleServer;
    }

    public function submit($dst, $body)
    {
        
    }

    public function done(Job $job)
    {
        if ($job->status !== Job::INIT) {
            return false;
        }

        sys_echo("DONE_CONSOLE_JOB [jobKey=$job->jobKey, fingerPrint=$job->fingerPrint]");
        $job->status = Job::DONE;

        $this->stop();
        return true;
    }

    public function error(Job $job, $reason)
    {
        if ($job->status !== Job::INIT) {
            return false;
        }

        if ($reason instanceof \Exception) {
            $reason = $reason->getMessage();
        }
        sys_echo("ERROR_CONSOLE_JOB [jobKey=$job->jobKey, fingerPrint=$job->fingerPrint, attempts=$job->attempts, reason=$reason]");
        $job->status = Job::ERROR;

        $this->stop();
        return true;
    }

    public function register($jobKey, JobProcessor $jobProcessor, array $config = [])
    {
        $this->processors[$jobKey] = $jobProcessor;
    }

    public function unRegister($jobKey)
    {
        unset($this->processors[$jobKey]);
    }

    public function listJob()
    {
        return array_keys($this->processors);
    }

    public function start()
    {
        $args = static::parseInputArgs(true);

        if ($args === false) {
            $this->stop();
        }

        $processor = new HttpJobProcessor($args["method"], urldecode($args["uri"]), $args["header"], $args["body"]);

        $jobKey = parse_url($args["uri"], PHP_URL_PATH);
        $this->register($jobKey, $processor);

        $timeout = intval($args["timeout"]);
        $timeout = $timeout < 0 ? static::DEFAULT_TIMEOUT : $timeout;
        $job = $this->makeConsoleJob($jobKey, $args);

        $processor->process($this, $job, $timeout);

        // TODO 如果是非JobController子类, 没有调用stop, 如何stop
        // Timer::after(60 * 1000, function() { $this->stop(); });
    }

    public function stop()
    {
        $this->swooleServer->exit();
        swoole_event_exit();
        $this->swooleServer->shutdown();
        // exit();
    }

    protected function makeConsoleJob($jobKey, array $args)
    {
        $job = new Job;

        $job->fingerPrint   = "$jobKey#" . uniqid(spl_object_hash($job));
        $job->jobKey        = $jobKey;
        $job->body          = $args["body"];
        $job->raw           = $args["body"];
        $job->createTime    = Time::stamp();
        $job->attempts      = 1;
        $job->status        = Job::INIT;
        $job->extra         = $args["header"];

        return $job;
    }

    protected static function getInputDefinition()
    {
        if (!static::$inputDefinition) {
            static::$inputDefinition = new InputDefinition([
                new InputArgument('request-uri', InputArgument::OPTIONAL, "Set the server request_uri", null),

                new InputOption('timeout', 't', InputOption::VALUE_OPTIONAL,
                    "job processing timeout", static::DEFAULT_TIMEOUT),
                new InputOption('header', 'H', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                    "Extra header to include in the request when invoking console command", []),
                new InputOption('request', 'X', InputOption::VALUE_OPTIONAL,
                    "Specifies a custom request method to use when invoking console command", "GET"),
                new InputOption('data', 'd', InputOption::VALUE_OPTIONAL,
                    "Sends the specified data in a POST|PUT|DELETE|PATCH request when invoking console command, also pass the data using the content-type", null),
            ]);
        }

        return static::$inputDefinition;
    }

    public static function usage()
    {
        $usage = static::getInputDefinition()->getSynopsis();
        echo "\nUsage:\n\t$usage\n\n";
    }

    public static function parseInputArgs($flushError = false)
    {
        static $args = null;

        if ($args === null) {
            $argvInput = null;

            try {
                $argvInput = new ArgvInput($_SERVER["argv"], static::getInputDefinition());
                $args = [
                    "uri" => $argvInput->getArgument("request-uri"),
                    "timeout" => $argvInput->getOption("timeout"),
                    "method" => $argvInput->getOption("request"),
                    "header" => $argvInput->getOption("header"),
                    "body" => $argvInput->getOption("data"),
                ];

            } catch (\Exception $ex) {
                if ($flushError) {
                    sys_echo($ex->getMessage());
                    static::usage();
                }
                return false;
            }
        }

        return $args;
    }
}