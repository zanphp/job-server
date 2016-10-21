# Zan Job Server

### [**Demo-Job-Server**](http://gitlab.qima-inc.com/php-lib/job-server-demo)

## 1. 简介

> JobWorker是依赖Zan框架的一个单机任务作业的Package;
> 原理是通过构造request与response对象, 伪造Http请求流程执行三种作业任务;

1. cron: 周期性作业
2. mqworker: NSQ消息队列实时作业
3. cli: 命令行一次性作业

## 2. 快速开始

### 1. 配置composer.json, 加入 php-lib/job-server 依赖, [*参考*](http://gitlab.qima-inc.com/php-lib/job-server-demo/blob/master/composer.json#L71)

### 2. 配置 ServerStart 与 WorkerStart, [*参考*](http://gitlab.qima-inc.com/php-lib/job-server-demo/tree/master/init)

./init/ServerStart/.config.php

```php
<?php
use Zan\Framework\Components\JobServer\ServerStart\InitializeJobServerConfig;

return [
    InitializeJobServerConfig::class,
];
```

./init/WorkerStart/.config.php

```php
<?php
use Zan\Framework\Components\JobServer\WorkerStart\InitializeJobServer;

return [
    InitializeJobServer::class,
];
```

### 3. 加入作业, [*参考*](http://gitlab.qima-inc.com/php-lib/job-server-demo/tree/master/src/Controller/Job)

1. 作业任务放置根路径 ~src/Controller~
2. 作业类需要继承~JobController~, cron,mqworker,cli三种作业模式下通用;
3. 需要在方法结尾或异常处 调用$this->jobDone() 或 $this->jobError()方法来标注作业执行结果;

jobController示例:

```php
<?php
    class TaskController extends JobController
    {
        public function product()
        {
            try {
                yield doSomething();
                yield $this->jobDone();
            } catch (\Exception $ex) {
                yield $this->jobError($ex);
            }
        }
    }
```

### 4. 配置作业, [*参考*](http://gitlab.qima-inc.com/php-lib/job-server-demo/tree/master/resource/config/share)

#### 配置路径结构

```
    config/share/cron/
        foo/
            cron1.php
            cron2.php
        bar/
            cron3.php
            cron4.php

    config/share/mqworker/
        foo/
            mqw1.php
            mqw2.php
        bar/
            mqw3.php
            mqw4.php
```


#### cron配置说明


crontab 格式, 细节参考[crontab](http://crontab.org/), 注意字段允许值不同;

```
       *      *       *        *        *      *
      sec    min    hour   day/month  month day/week
      0-59   0-59   0-23     1-31     1-12    0-6

```

与php~date()~fmt对应关系:

```
http://php.net/manual/en/function.date.php
s	Seconds, with leading zeros	                                0 through 59    intval(00 through 59)
i	Minutes with leading zeros	                                0 to 59         intval(00 to 59)
G	24-hour format of an hour without leading zeros	            0 through 23
j	Day of the month without leading zeros	                    1 to 31
n	Numeric representation of a month, without leading zeros	1 through 12
w	Numeric representation of the day of the week	            0 (for Sunday) through 6 (for Saturday)
date format : s i G j n w
```


符号说明:

```
* 全部覆盖
- 范围 from-to
/ 步进 /step
```

以秒为例, 其他位置逻辑相同

```
每秒执行一次
* * * * * *

每10秒执行一次
*/10 * * * * *

每分钟的第1,15,20,50秒执行
1,15,20,50 * * * * *

每分钟的1-30秒,每三秒执行一次
1-30/3 * * * * *

每分钟的1-30秒,每三秒执行一次,且第50秒,55秒各执行一次
1-30/3,50,55 * * * * *
```


#### cron配置示例

```php
<?php
    return [
        "作业标识" => [
            "uri" => "job/task/taks1",      // 必填, 作业uri, 匹配http请求路由
            "cron"  => "* * * * * *",       // 必填, 精确到秒的cron表达式 (秒 分 时 天 月 天(周))
            "timeout"=> 60000,              // 选填, 默认60000, 执行超时
            "method" => "GET",              // 选填, 默认GET, 对应http请求
            "header" => [ "x-foo: bar", ],  // 选填, 默认[], 对应http请求
            "body" => "",                   // 选填, 默认"", 对应http请求
            "strict" => false,              // 选填, 默认false, 是否启用严格模式, 严格模式会对服务重启等原因错过的任务进行补偿执行
        ],
        "cronJob2" => [
            "uri" => "job/task/task0",
            "cron"  => "* * * * * *",
        ],
        "cronJob3" => [
            "uri" => "job/task/taks2?kdt_id=1", // 传递GET参数
            "cron"  => "*/2 * * * * *",
            // POST 传递post参数
            "method" => "POST",
            "header" => [['Content-type' => 'application/x-www-form-urlencoded']],
            "body" => "foo=bar",
        ],
        "cronJob4" => [
            "uri" => "job/task/task3?kdt_id=1",
            "cron"  => "*/2 * * * * *",
            "method" => "POST",
            "header" => [['Content-type' => 'application/json']],
            "body" => ""{"foo": "bar"}"",
        ],
    ];
```


#### mqworker配置示例:

```php
    <?php
    return [
        "mqJob1" => [
            "uri" => "job/task/consume",    // 必填, 作业uri, 匹配http请求路由
            "topic" => "taskTopic",         // 必填, nsq topic
            "channel" => "ch2",             // 必填, nsq channel
            "timeout"=> 60000,              // 选填, 默认60000, 执行超时
            "coroutine_num" => 1,           // 选填, 默认1, 单个worker任务处理并发数量, 根据业务需要调整
            "method" => "GET",              // 选填, 默认GET, 对应http请求
            "header" => [ "x-foo: bar", ],  // 选填, 默认[], 对应http请求
            "body" => "",                   // 选填, 默认"", 对应http请求
        ],
        "mqJob2" => [
            "uri" => "job/task/taks2",
            "topic" => "someTopic",
            "channel" => "ch1",
        ],
    ];
```


### 5. 启动, [*参考*](http://gitlab.qima-inc.com/php-lib/job-server-demo/blob/master/bin/jobserv)

在bin目录新建一个启动脚本, 配置环境变量;

通过环境变量标注当前JobServer模式, 逗号分隔, 目前支持三种模式; 例子:

```shell
ZAN_JOB_MODE=cron,mqworker,cli
```

注: 通过命令行执行Job, 将不会启动MqWorker与CronWorker

示例:

```
#!/usr/bin/env php
<?php

// 此处添加三种任务模式
putenv("ZAN_JOB_MODE=cron,mqworker,cli");

/* @var $app \Zan\Framework\Foundation\Application */
$app = require_once __DIR__.'/../init/app.php';

// 可选创建HttpServer或者TcpServer
$server = $app->createHttpServer();
// $server = $app->createTcpServer();

$server->start();
```

### 6. 监控

如果worker的载体为http服务器, 可添加监控的Controller, 通过Web请求响应监控任务执行状态;

[~参考~](http://gitlab.qima-inc.com/php-lib/job-server-demo/blob/master/src/Controller/Job/MonitorController.php)

```php
<?php

namespace Zan\Framework\Components\JobServer\Controller\Job;

use Zan\Framework\Components\JobServer\Monitor\JobMonitor;
use Zan\Framework\Foundation\Domain\HttpController;
use Zan\Framework\Network\Http\Response\JsonResponse;

class MonitorController extends HttpController
{
    // 当前作业列表信息
    public function jobList()
    {
        $ret = (yield JobMonitor::getJobList());
        yield new JsonResponse($ret);
    }

    // 当前作业连接池信息
    public function poolStat()
    {
        $status = (yield JobMonitor::getConnectionPoolStatus());
        yield new JsonResponse($status);
    }
}
```


## 其他

### 1. CronWorker

保证每个cron作业绑定到某个具体Worker;
当前cron作业失败不会重试;

### 2. MqWorker

使用之前先@冬瓜在nsq中添加topic

使用mqworker,需要配置nsq, lookupd

config/env/nsq.php

```php
    return [
        'lookupd' => 'http:nsq-dev.s.qima-inc.com:4161',
    ];
```

每个worker开n个coroutine, 每个coroutine维持一个到mq的长连接, 在onReceive的回调中构造http请求执行作业;

需要在作业方法结尾或异常处 调用$this->jobDone() 或 $this->jobError() 方法来标注任务执行结果;

手动 yield $this->jobError() 或者 调用任务失败之后, 会自动延时重试,

最多重试5次, 每次延时 2 ** 重试次数秒((2s -> 4s -> 8s -> 16s -> 32s))


### 3. CliWorker

通过 命令行参数构造http请求,执行作业;

ZAN_JOB_MODE环境变量含有cli, 且加入命令行参数, 默认启动cliworker

```shell
./<job_server_bin> [-H -X -d -t] uri
-t --timeout    60000 timeout ms
-H --header     header 支持多个
-X --request    request method
-d --data       request body
```

e.g.

```shell
./jobWorker --help
./jobWorker -H "Content-type: application/x-www-form-urlencoded" -X POST --data "foo=bar" -t 10000 job/task/product?kdt_id=1
./jobWorker -H "Content-type: Content-type: application/json" -X POST --data '{"foo": "bar"}' -t 5000 job/task/product?kdt_id=2
```

