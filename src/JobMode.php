<?php

namespace Zan\Framework\Components\JobServer;


final class JobMode
{
    const CLI = "cli";
    const CRON = "cron";
    const MQ_WORKER = "mqworker";

    private static $modes = null;
    
    /**
     * @return array
     */
    public static function get()
    {
        if (self::$modes === null) {
            self::init();
        }

        return static::$modes;
    }

    public static function isOn($mode = null)
    {
        if ($mode === null) {
            return self::contains(self::CLI) || self::contains(self::CRON) || self::contains(self::MQ_WORKER);
        } else {
            return self::contains($mode);
        }
    }

    public static function isCli()
    {
        return JobMode::isOn(JobMode::CLI) && $_SERVER["argc"] >= 2;
    }

    private static function init()
    {
        $modes = getenv("ZAN_JOB_MODE");
        if ($modes) {
            self::$modes = array_map("strtolower", array_filter(array_map("trim", explode(",", $modes))));
        } else {
            self::$modes = [];
        }
    }

    private static function contains($mode)
    {
        return in_array($mode, static::get(), true);
    }
}