<?php

namespace Zan\Framework\Components\JobServer\Contract;


use Zan\Framework\Components\JobServer\Job;

interface JobManager
{
    /**
     * 提交Job
     * @param string $dst
     * @param string $body
     * @return void
     */
    public function submit($dst, $body);

    /**
     * 标记Job完成
     * @param Job $job
     * @return bool
     */
    public function done(Job $job);

    /**
     * 标记Job出错
     * @param Job $job
     * @param \Exception|string $reason
     * @return bool
     */
    public function error(Job $job, $reason);

    /**
     * 注册Job
     * @param $jobKey
     * @param JobProcessor $jobProcessor
     * @param array $config
     * @return bool
     */
    public function register($jobKey, JobProcessor $jobProcessor, array $config = []);

    /**
     * 取消注册Job
     * @param $jobKey
     * @return void
     */
    public function unRegister($jobKey);

    /**
     * 当前注册Job列表
     * @return array
     */
    public function listJob();

    /**
     * 启动JobServer
     * @return void
     */
    public function start();

    /**
     * 停止JobServer
     * @return void
     */
    public function stop();
}