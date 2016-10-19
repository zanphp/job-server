<?php

namespace Zan\Framework\Components\JobServer;


class Job
{
    const INIT      = 0;
    const DONE      = 1;
    const RETRY     = 2;
    const ERROR     = 3;

    // mp       topic:channel
    // cron     route path  module/controller/action   ?a=1&b=2
    // console  route path  module/controller/action   ?a=1&b=2
    public $jobKey;

    public $fingerPrint;

    public $body;

    public $raw;

    public $status = self::INIT;

    public $attempts;

    public $createTime;

    public $extra;

    public function __toString()
    {
        return json_encode($this, JSON_PRETTY_PRINT);
    }
}