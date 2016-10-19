
1. 简介模式

JobWorker是依赖Zan框架的一个单机任务作业的Package;

通过构造request与response对象, 伪造Http请求流程执行作业任务, 任务默认超时时间60s;

    1. Cron: 周期性任务
    2. Mqworker: NSQ消息队列实时任务
    3. Cli: 命令行一次性任务



## 1. 快速开始

1. composer.json 加入

2. 加入ServerStart 与 WorkerStart

3. 需要在项目src/controller路径写任务逻辑的JobController;
    与 Http Controller不同点:
    1. 需要继承 JobController, JobController 子类的方法在cron,mqworker,cli三种模式下均可以调用
    2. 需要在方法最后调用 jobDone 或 jobError 方法来标注任务执行结果;
    3. 作业抛出异常, 默认会被调用jobError

    controller示例

    ```php
    class TaskController extends JobController
    {
        public function product()
        {
            try {
                yield doSomething();

                yield $this->jobDone(); // !!!
            } catch (\Exception $ex) {
                echo_exception($ex);
                yield $this->jobError($ex); // !!!
            }
        }
    }
    ```

4. 配置

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

    cron.php

    ~~~php
    <?php
    return [
        "cronJob1" => [
            "uri" => "job/task/taks1",     // 必填, 对应http请求request uri
            "cron"  => "* * * * * *",       // 必填, 精确到秒的cron表达式 (秒 分 时 天 月 周)
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
            "method" => "POST",
            "header" => [['Content-type' => 'application/x-www-form-urlencoded']],
            "body" => "foo=bar",            // 传递post参数
        ],
        "cronJob4" => [
            "uri" => "job/task/task3?kdt_id=1",
            "cron"  => "*/2 * * * * *",
            "method" => "POST",
            "header" => [['Content-type' => 'application/json']],
            "body" => ""{"foo": "bar"}"",
        ],
    ];
    ~~~

    mqworker.php
    ~~~
    <?php
    return [
        "mqJob1" => [
            "uri" => "job/task/consume",    // 必填, 对应http请求request uri
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
    ~~~


5. bin目录新建一个启动脚本, 配置环境变量

    通过环境变量标注当前JobServer模式, 逗号分隔, 目前支持三种模式;

    例子:
        ZAN_JOB_MODE=cron,mqworker,cli

    注: 通过命令行执行Job, 将不会启动MqWorker与CronWorker







3. CronWorker
    保证每个cron作业绑定到某个具体Worker;
    CronWorker内部的作业失败不会重试;

4. MqWorker
    每个worker开n个coroutine, 每个coroutine维持一个到mq的长连接, 在onReceive的回调中构造http请求执行作业;
    作业中一定要标注worker Done或者worker Error

    1. 使用之前先@冬瓜在nsq中添加topic

    使用mqworker,需要配置nsq, lookupd
    config/env/nsq.php
    ~~~php
    return [
        'lookupd' => 'http:nsq-dev.s.qima-inc.com:4161',
    ];
    ~~~

    2. yield $this->jobError(); 调用任务失败之后, 会自动延时重试, 最多重试5次, 每次延时 2 ** 重试次数秒((2s -> 4s -> 8s -> 16s -> 32s))


5. CliWorker

    通过 命令行参数构造http请求,执行作业;

    加入启动参数, 且配置了ZAN_JOB_MODE环境变量配置了cli, 默认启动cliworker

./jobWorker [-H -X -d] uri
-t --timeout    60000
-H --header     header 支持多个
-X --request    request method
-d --data       request body

e.g.
./jobWorker --help
./jobWorker -H "Content-type: application/x-www-form-urlencoded" -X POST --data "foo=bar" -t 10000 job/task/product?kdt_id=1
./jobWorker -H "Content-type: Content-type: application/json" -X POST --data '{"foo": "bar"}' -t 5000 job/task/product?kdt_id=2
