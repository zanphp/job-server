<?php

namespace Zan\Framework\Components\JobServer\Contract;


use Zan\Framework\Components\JobServer\Job;

interface JobProcessor
{
    public function process(JobManager $jobManager, Job $job);
}