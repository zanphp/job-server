<?php

namespace Zan\Framework\Components\JobServer;


use Zan\Framework\Foundation\Core\Config;

final class JobMode
{
    const CLI = "cli";
    const CRON = "cron";
    const MQ_WORKER = "mqworker";

    private static $modes = null;

    public static function isCli()
    {
        return JobMode::isOn(JobMode::CLI) && $_SERVER["argc"] >= 2;
    }

    public static function isOn($mode = null)
    {
        if ($mode === null) {
            return self::contains(self::CLI) || self::contains(self::CRON) || self::contains(self::MQ_WORKER);
        } else {
            return self::contains($mode);
        }
    }

    private static function contains($mode)
    {
        if (self::$modes === null) {
            self::init();
        }

        return in_array($mode, static::$modes, true);
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

    public static function randomListenPort()
    {
        $port = Config::get("server.port", null);
        if ($port) {
            $newPort = self::getRandomPort() ?: $port;
            Config::set("server.port", $newPort);
        }
    }

    private static function getRandomPort()
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $ok = socket_bind($socket, "0.0.0.0", 0);
        if (!$ok) {
            return false;
        }
        $ok = socket_listen($socket);
        if (!$ok) {
            return false;
        }
        $ok = socket_getsockname($socket, $addr, $port);
        if (!$ok) {
            return false;
        }
        socket_close($socket);
        return $port;
    }
}