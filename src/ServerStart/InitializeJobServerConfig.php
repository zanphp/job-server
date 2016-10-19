<?php

namespace Zan\Framework\Components\JobServer\ServerStart;


use Zan\Framework\Components\JobServer\JobMode;
use Zan\Framework\Contract\Network\Bootable;
use Zan\Framework\Foundation\Core\Config;
use Zan\Framework\Network\ServerManager\ServerRegisterInitiator;
use swoole_server as SwooleServer;

class InitializeJobServerConfig implements Bootable
{
    public function bootstrap($server)
    {
        if (JobMode::isOn()) {
            $this->disabledUnnecessaryComponents();
            $this->fixTimeout();
            $this->fixMonitor();
            $this->fixCookie();     // http server 依赖cookie配置
            $this->fixRoute();      // http server 依赖路由配置
        }

        if (JobMode::isCli()) {
            $this->fixConnectionPool();                     // 命令行模式连接池不初始化连接
            $this->fixWorkerNum($server->swooleServer, 1);  // 命令行模式强制只fork一个worker
        }
    }

    protected function disabledUnnecessaryComponents()
    {
        // 如果跑在TcpServer下,需要关闭服务注册
        ServerRegisterInitiator::getInstance()->disableRegister();
        // ... and so on
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
            Config::set("connection.$name.$type.pool.minimum-connection-count", 1);
            Config::set("connection.$name.$type.pool.init-connection", 1);
        }
    }

    protected function fixTimeout()
    {
        $timeout = intval(getenv("ZAN_JOB_TIMEOUT"));
        $timeout = max(0, $timeout) ?: 60 * 1000;
        Config::set("server.request_timeout", $timeout);
    }

    protected function fixWorkerNum(SwooleServer $swServ, $workerNum = 1)
    {
        Config::set("server.config.worker_num", $workerNum);
        $swServ->set(["worker_num" => $workerNum]);
    }

    protected function fixMonitor()
    {
        Config::set("server.monitor.max_concurrency", PHP_INT_MAX);
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