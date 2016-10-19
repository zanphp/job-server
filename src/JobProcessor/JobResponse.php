<?php

namespace Zan\Framework\Components\JobServer\JobProcessor;


use Zan\Framework\Components\JobServer\Contract\JobManager;
use Zan\Framework\Components\JobServer\Job;

class JobResponse extends \swoole_http_response
{
    /**
     * @var $jobManager JobManager
     */
    protected $jobManager;

    /**
     * @var $job Job
     */
    protected $job;

    private $code;

    public static function make(JobManager $jobManager, Job $job)
    {
        $self = new static;
        $self->jobManager = $jobManager;
        $self->job = $job;

        return $self;
    }
    
    public function end($html = '')
    {
        $this->jobAck($html);
    }

    public function status($code) {
        $this->code = intval($code);
    }

    private function jobAck($html = '')
    {
        if ($this->job->status === Job::INIT) {
            // TODO mb_strpos($html, "出错了") !== false
            if ($this->code !== 200 || mb_strpos($html, "出错了") !== false) {
                $ex = new JobException($html, $this->code);
                $this->jobManager->error($this->job, $ex);
            } else {
                $this->jobManager->done($this->job);
            }
        }
    }

    public function write($html) { }
    public function sendfile($filename) { }
    public function header($key, $value) { }
    public function gzip($level = 1) { }
    public function cookie($key, $value, $expire = 0, $path = '/', $domain = '', $secure = false, $httponly = false) {}
}