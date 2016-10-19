<?php

namespace Zan\Framework\Components\JobServer\JobProcessor;


use Zan\Framework\Components\JobServer\Contract\JobManager;
use swoole_http_request as SwooleHttpRequest;
use Zan\Framework\Components\JobServer\Job;

class JobRequest extends SwooleHttpRequest
{
    const JOB_PARA_KEY = "__JOB__";
    const JOB_MGR_PARA_KEY = "__JOB_MGR__";

    public $get = [];

    public $post = [];

    public $header = [];

    public $server = [];

    public $cookie = [];

    public $files = [];

    public $fd = 0;

    /**
     * @var $jobManager JobManager
     */
    protected $jobManager;

    /**
     * @var $job Job
     */
    protected $job;

    /**
     * @var $httpJobProcessor HttpJobProcessor
     */
    protected $httpJobProcessor;

    public function rawContent()
    {
        return $this->httpJobProcessor->body;
    }

    public static function make(JobManager $jobManager, Job $job, HttpJobProcessor $httpJobProcessor)
    {
        $self = new static;

        $self->jobManager = $jobManager;
        $self->job = $job;
        $self->httpJobProcessor = $httpJobProcessor;

        $query = parse_url($httpJobProcessor->requestUri, PHP_URL_QUERY);
        parse_str($query, $self->get);

        $self->header = static::parseHeaders($httpJobProcessor->header);

        $self->server[static::JOB_PARA_KEY] = $job;
        $self->server[static::JOB_MGR_PARA_KEY] = $jobManager;

        $self->server["request_method"] = $httpJobProcessor->method;
        $self->server["request_uri"] = $httpJobProcessor->requestUri;

        return $self;
    }

    protected static function parseHeaders(array $headerArr)
    {
        $header = [];

        foreach ($headerArr as $line)
        {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $kv = explode(':', $line, 2);
            $keys = explode('-', $kv[0]);
            $keys = array_map("ucfirst", $keys);
            $key = implode('-', $keys);
            $value = isset($kv[1]) ? $kv[1] : '';
            $header[trim($key)] = trim($value);
        }

        return $header;
    }
}