TODO:

!!!!!!!!!!
## 连接池修改灰度之后逻辑
1. 修改telnetDebug composer.json
2. 修改async-qiniu-sdk composer.json
3. 修改发布系统发布配置,线上机器
4. 写连接池change log

nova 限制下等待连接的数据
nova 重连没解决

网络地址 无回应,协议进程连接重试情况加, 关闭重启监控, ab
1. FutureConnection bug, task wait, Task Scheduler 循环依赖 内存泄漏  # gc_collect_cycles
2. 重试策略在ab情况加可能有问题 ( Pool & NovaPool )
3. hiredis在ab会把文件描述符用光
4. TcpConnection与SwooleClient 存在循环引用, SwooleClient不能被__destruct

ONCE_EVENT, event的bind必须fire, 否则内存泄漏~


==========================================================================================


TODO:

长时间, 压力测试, 内存占用


或者靠应用打log做监控报警;
Http 接口查询状态 & 监控
一定要做监控, 通过ERROR和DONE方法的调用做监控;
统计
    当前 注册(订阅)的消息个数;
    输出连接池状态


TODO V2
Cron支持手动绑定到特定worker
简化cron: cron 支持 "fix_delay"  => 2000, "fix_rate"  => 2000,
cron 重试策略
Cron 单独起一个进程专门做定时器, 防止定时器延迟误差, robin-round方式往worker通过pipe发送任务信息, worker接受消息, 执行方法
批量作业处理, 添加一个批量处理的controller基类
平滑重启
完善日志记录
# 处理各种乱七八糟的异常情况



# 看一下旧的mqworker实现
# 文档
# 测试jobKey逻辑修改后, 所有的代码;
# 保存 cron status
# 断网测试
# 测试jobExceptionHandler
# 测试超时
# url_decode
# 测试调服务,
# 测试调用redis
# 测试调用mysql
# console 模式支持 curl 参数 -H --header 传递header -X method --data
# split conf #  mq | cron  # mq > a | b | c # cron > a | b | c
# runmode # env
    1. timeout: online: http tcp | offline: mq cron console
    2. connection pool: console 0 | mq cron http tcp
# cron
    1. worker num = 1
    2. restart : worker id 不变
# mq
    1. pool->workers = SwooleG.memory_pool->alloc(SwooleG.memory_pool, worker_num * sizeof(swWorker));






问题:
0. Empty nodes from server 异常触发之后, 之后走缓存,还是没有节点~
1. 因为走的http请求流程,所以需要配置下cooke.php与route.php
2. 继承 job controller
3. 平滑重启;
4. mqwoker 中不调用done或error或dealy会一直阻塞好像
5. mqworker 取消注册实现有问题
6. cron 定时器时间偏差积累 | 重启过程 导致cron pass调某个时间的任务
7. 一切可能导致定时器偏差的问题,比如cpu密集计算




Mq:
构造http request,执行请求










task->run() 内部与外部都必须捕获异常的原因是因为异常发生的时机;
发生在调度器return(挂起)发生异步执行之前的异常,会被外部捕获;
异步回调时候,任务再次被调度,run()执行时,发生的异常会被内部try捕获;
e.g.:

vendor/zanphp/zan/src/Network/Http/RequestHandler.php
public function handle() try catch
handle 中  $coroutine = $requestTask->run();
vendor/zanphp/zan/src/Network/Http/Dispatcher.php
public function run() 中再次捕获
















依赖放需要修改:

zan
1. worker monitor 需要加入 isDenyRequest

nsq需要修改的地方
1.
    nsq-async/src/Message/Msg.php
    Message/Msg.php 加return
    public function attempts()
    {
        return $this->attempts;
    }

    /**
     * @return int
     */
    public function timestamp()
    {
        return $this->timestamp;
    }

2. zanphp/zan/src/Sdk/Queue/NSQ/Queue.php line:79

    public function subscribe($topic, $channel, callable $callback, $timeout = 1800)
    {
        $this->handler = function ($cb) use ($topic, $channel, $callback, $timeout) {
            NSQueue::set(['subTimeout' => $timeout]);
            NSQueue::subscribe($topic, $channel, $callback);

            // 这里是call...array
            call_user_func_array($cb, [false, null]);
        };

        yield $this;
    }

3. nsq-async/src/Piping/Node.php line:706

        public function msgCallback(Msg $msg)
        {
            if ($this->cIsWaitingClosed)
            {
                $msg->retry();
            }
            else
            {
                // 回调参数加入$this
                call_user_func_array($this->callback, [$msg, $this]);
            }
        }













In this one we'll create a Work Queuethat will be used to distribute time-consuming tasks among multiple workers.
The main idea behind Work Queues (aka: Task Queues) is to avoid doing a resource-intensive task immediately and having to wait for it to complete. Instead we schedule the task to be done later. We encapsulate a taskas a message and send it to a queue. A worker process running in the background will pop the tasks and eventually execute the job. When you run many workers the tasks will be shared between them.

This concept is especially useful in web applications where it's impossible to handle a complex task during a short HTTP request window.




如果因为某些原因第三方发生故障了，我们可以处理这些故障，在这个代码片中，我们有三种处理逻辑：


1、如果超过了某个尝试次数阀值，我们就将消息丢弃。
2、如果消息已经被处理成功了，我们就结束消息。
3、如果发生了错误，我们将需要传递的消息重新进行排队。


正如你所看到的，NSQ队列的行为既简单又明确。


在我们的案例中，我们在丢弃消息之前将容忍MAX_DELIVERY_ATTEMPTS * BACKOFF_TIME分钟的故障。


在Segment系统中，我们统计消息尝试的次数、消息丢弃数、消息重新排队数等等，然后结束某些消息以保证我们有一个好的服务质量。如果消息丢弃数超过了我们设置的阀值，我们将在任何时候对服务发出警报。




http://www.jointforce.com/jfperiodical/article/1949?hmsr=toutiao.io


msg := <- ch

if msg.Attempts > MAX_DELIVERY_ATTEMPTS {

  // we discard the message if it's more than the max delivery attempts

  // normally this is handled by the library

  msg.Finish()

  return

}

 

err, _ := request(msg)

if err != nil {

  log.Errorf("error making request %v\n", err)

  msg.Requeue(BACKOFF_TIME) // an error occurred, requeue... (╯°□°)╯︵ ┻━┻

  return

}

 

msg.Finish() // everything worked... (？■_■)

























# PHP CronExpression

### 表达式说明:

```
       *      *       *        *        *      *
      sec    min    hour   day/month  month day/week
      0-59   0-59   0-23     1-31     1-12    0-6
```

1. 添加 秒 字段, 定时器精确到 秒
2. 表达式说明与字段取值范围
3. 字段填充容器非c array而是hashtable, 所以修改为从offset开始填充

### Cron表达式parse参考

1. https://git.busybox.net/busybox/tree/miscutils/crond.c?h=1_25_stable
2. http://crontab.org/
3. man 5 crontab


### 参考文档 man 5 crontab

1. man 5 crontab (CentOS release 6.8 (Final))

```
              field          allowed values
              -----          --------------
              minute         0-59
              hour           0-23
              day of month   1-31
              month          1-12 (or names, see below)
              day of week    0-7 (0 or 7 is Sun, or use names)
```


2. man 5 crontab (OSX)

```
           field         allowed values
           -----         --------------
           minute        0-59
           hour          0-23
           day of month  1-31
           month         1-12 (or names, see below)
           day of week   0-7 (0 or 7 is Sun, or use names)
```

3. crontab.org

```
The time and date fields are:

        field          allowed values
        -----          --------------
        minute         0-59
        hour           0-23
        day of month   0-31
        month          0-12 (or names, see below)
        day of week    0-7 (0 or 7 is Sun, or use names)

 A  field  may  be an asterisk (*), which always stands for
 ``first-last''.

 Ranges of numbers are allowed.   Ranges  are  two  numbers
 separated  with  a  hyphen.  The specified range is inclu-
 sive.  For example, 8-11 for an ``hours'' entry  specifies
 execution at hours 8, 9, 10 and 11.

 Lists are allowed.  A list is a set of numbers (or ranges)
 separated by commas.  Examples: ``1,2,5,9'', ``0-4,8-12''.

 Step  values can be used in conjunction with ranges.  Fol-
 lowing a range with ``/<number>'' specifies skips  of  the
 number's value through the range.  For example, ``0-23/2''
 can be used in the hours field to specify  command  execu-
 tion  every other hour (the alternative in the V7 standard
 is ``0,2,4,6,8,10,12,14,16,18,20,22'').   Steps  are  also
 permitted after an asterisk, so if you want to say ``every
 two hours'', just use ``*/2''.

 Names can also be used for  the  ``month''  and  ``day  of
 week'' fields.  Use the first three letters of the partic-
 ular day or month (case doesn't matter).  Ranges or  lists
 of names are not allowed.


Note: The day of a command's execution can be specified by
two  fields  --  day  of  month, and day of week.  If both
fields are restricted (ie, aren't *), the command will  be
run when either field matches the current time.  For exam-
ple,
``30 4 1,15 * 5'' would cause a command to be run at  4:30
am on the 1st and 15th of each month, plus every Friday.
```