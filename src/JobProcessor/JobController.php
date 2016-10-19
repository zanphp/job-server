<?php

namespace Zan\Framework\Components\JobServer\JobProcessor;


use Zan\Framework\Components\JobServer\Contract\JobManager;
use Zan\Framework\Components\JobServer\Job;
use Zan\Framework\Components\JobServer\MqJobManager;
use Zan\Framework\Foundation\Container\Di;
use Zan\Framework\Foundation\Domain\HttpController;
use Zan\Framework\Network\Http\Response\Response;

class JobController extends HttpController
{
    /**
     * @var \Zan\Framework\Network\Http\Request\Request
     */
    protected $request;

    /**
     * @return Job|null
     */
    public function getJob()
    {
        return $this->request->server(JobRequest::JOB_PARA_KEY);
    }

    /**
     * @return JobManager|null
     */
    public function getJobManager()
    {
        return $this->request->server(JobRequest::JOB_MGR_PARA_KEY);
    }

    public function jobDone()
    {
        $job = $this->getJob();
        if ($job) {
            $this->getJobManager()->done($job);
            yield new Response("", 200);
        } else {
            yield new Response("", 404);
        }
    }

    public function jobError(\Exception $ex = null)
    {
        if ($job = $this->getJob()) {
            $this->getJobManager()->error($job, $ex);
            yield new Response("", 500);
        } else {
            yield new Response("", 404);
        }
    }

    /**
     * MQ 提交任务
     * @param string $topic mq topic
     * @param $task
     * @return \Generator
     */
    public function submit($topic, $task)
    {
        $mqJobMrg = Di::make(MqJobManager::class, [], true);
        yield $mqJobMrg->submit($topic, $task);
    }
}