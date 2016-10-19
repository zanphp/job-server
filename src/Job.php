<?php

namespace Zan\Framework\Components\JobServer;


class Job
{
    const INIT      = 0;
    const DONE      = 1;
    const RETRY     = 2;
    const ERROR     = 3;

    public $jobKey;

    public $fingerPrint;

    public $body;

    public $raw;

    public $status = self::INIT;

    public $attempts;

    public $createTime;

    public $extra;
}