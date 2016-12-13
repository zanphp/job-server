<?php

namespace Zan\Framework\Components\JobServer\JobProcessor;

use Zan\Framework\Components\JobServer\Contract\JobManager;
use Zan\Framework\Components\JobServer\Job;
use Zan\Framework\Contract\Network\Request;
use Zan\Framework\Contract\Network\RequestTerminator;
use Zan\Framework\Contract\Network\Response;
use Zan\Framework\Utilities\DesignPattern\Context;

class JobTerminator implements RequestTerminator
{
    /**
     * @param Request $request
     * @param Response $response
     * @param Context $context
     * @return void
     */
    public function terminate(Request $request, Response $response, Context $context)
    {
        /* @var $jobMgr JobManager */
        /* @var $job Job */
        $jobMgr = $context->get("__job_mgr");
        $job = $context->get("__job");
        if ($jobMgr instanceof JobManager && $job instanceof Job) {
            yield $jobMgr->done($job);
        }
        yield null;
    }
}