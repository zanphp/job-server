<?php

namespace Zan\Framework\Components\JobServer\ServerStart;


use Zan\Framework\Components\JobServer\JobMode;
use Zan\Framework\Components\JobServer\JobProcessor\JobTerminator;
use Zan\Framework\Contract\Network\Bootable;
use Zan\Framework\Network\Server\Middleware\MiddlewareConfig;
use Zan\Framework\Network\ServerManager\ServerRegisterInitiator;
use ZanPHP\Config\Config;

class InitializeJobServerConfig implements Bootable
{
    public function bootstrap($server)
    {
        if (JobMode::isOn()) {
            ServerRegisterInitiator::getInstance()->disableRegister();
            Config::set("server.monitor.max_concurrency", PHP_INT_MAX);
            $this->fixCookie();
            $this->fixRoute();
        }

        if (JobMode::isCli()) {
            $this->fixConnectionPool();                               // 命令行模式连接池不初始化连接
            $this->fixWorkerNum($server->swooleServer, 1);  // 命令行模式强制只fork一个worker
        }

        $this->registerTerminator();
    }

    protected function registerTerminator()
    {
        $middleware = MiddlewareConfig::getInstance();

        $class = new \ReflectionClass(MiddlewareConfig::class);
        $prop = $class->getProperty("config");
        $prop->setAccessible(true);
        $config = $prop->getValue($middleware);

        if (!isset($config["group"])) {
            $config["group"] = [];
        }
        if (!isset($config["match"])) {
            $config["match"] = [];
        }
        $groupKey = "__job_server_defer";
        $config["match"][] = [".*", $groupKey];
        $config["group"][$groupKey] = [JobTerminator::class];

        $prop->setValue($middleware, $config);
    }

    protected function fixConnectionPool()
    {
        $connectionList = Config::get("connection");
        if (!is_array($connectionList)) {
            return;
        }

        foreach ($connectionList as $name => $connection) {
            if (in_array(strtolower($name), ["nova", "elasticsearch"], true)) {
                continue;
            }
            $this->resetConnection($name);
        }
    }
    
    private function resetConnection($name)
    {
        $names = Config::get("connection.$name");
        if (!is_array($names)) {
            return;
        }

        foreach ($names as $type => $conf) {
            if (Config::get("connection.$name.$type.pool")) {
                Config::set("connection.$name.$type.pool.minimum-connection-count", 0);
                Config::set("connection.$name.$type.pool.init-connection", 0);
            }
        }
    }

    protected function fixTimeout()
    {
        $timeout = intval(getenv("ZAN_JOB_TIMEOUT"));
        $timeout = max(0, $timeout) ?: 60 * 1000;
        Config::set("server.request_timeout", $timeout);
    }

    protected function fixWorkerNum(\swoole_server $swServ, $workerNum = 1)
    {
        Config::set("server.config.worker_num", $workerNum);
        $swServ->set(["worker_num" => $workerNum]);
    }

    protected function fixCookie()
    {
        $cookie = Config::get("cookie");
        if (!$cookie) {
            Config::set("cookie", [
                'expire' => 0,
                'path' => '',
                'domain' => '',
                'secure' => false,
                'httponly' => false,
            ]);
        }
    }
    
    protected function fixRoute()
    {
        $route = Config::get("route");
        if (!$route) {
            Config::set("route", [
                'default_route' => 'index/index/index',
                'default_controller' => 'index',
                'default_action' => 'index',
                'default_format' => 'html',
                'format_whitelist' => ['html', 'json', 'jsonp'],
            ]);
        }
    }
}