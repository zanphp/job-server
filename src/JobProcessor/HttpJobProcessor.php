<?php

namespace Zan\Framework\Components\JobServer\JobProcessor;


use Zan\Framework\Components\JobServer\Contract\JobManager;
use Zan\Framework\Components\JobServer\Contract\JobProcessor;
use Zan\Framework\Components\JobServer\Job;
use Zan\Framework\Network\Http\RequestHandler;

class HttpJobProcessor implements JobProcessor
{
    public $header;

    public $requestUri;

    public $method;

    public $body;

    public function __construct($method = "GET", $requestUri = "/", array $header = [], $body = "") {
        $this->method = $method;
        $this->requestUri = $requestUri;
        $this->header = $header;
        $this->body = $body;
    }

    public function process(JobManager $jobManager, Job $job)
    {
        $request = JobRequest::make($jobManager, $job, $this);
        $response = JobResponse::make($jobManager, $job);

        (new RequestHandler())->handle($request, $response);
    }
}