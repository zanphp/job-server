<?php

namespace Zan\Framework\Components\JobServer\Monitor;


use Zan\Framework\Utilities\DesignPattern\Singleton;
use swoole_server as SwooleServer;
use swoole_table as SwooleTable;
use swoole_atomic as SwooleAtomic;

class ShareCounter
{
    use Singleton;
    
    const MAX_JOB_NUM = 4096;

    /**
     * @var SwooleTable
     * i => [key => $key]
     */
    protected static $keyIndexTable;

    /**
     * @var SwooleServer
     */
    protected static $swooleServer;

    /**
     * @var SwooleAtomic
     */
    protected static $incrKey;

    /**
     * @var \SplFixedArray
     */
    protected static $atomicCounterVector;

    /**
     * @var SwooleAtomic[] $key => SwooleAtomic
     * Process Local
     * 此属性 worker 间不共享数据数据, worker 调用 incr 之后填充数据
     */
    protected static $keyCounter = [];

    /**
     * @param SwooleServer $swServer
     * @return bool
     *
     *  master 进程方法
     */
    public static function init(SwooleServer $swServer)
    {
        if (static::$swooleServer) {
            return false;
        }

        static::$swooleServer = $swServer;

        if (PHP_OS === "Darwin") {
            static::initAtomicCounter();
        }

        return true;
    }

    /**
     * swoole_table 自身没有原子性的setN功能, (apcu可以用apcu_add与apcu_incr实现)
     * swoole_table 要实现 如果不存在则set成1, 存在则incr, 必须lock整张表
     * 因为 swoole_atomic 可设置初始值, 所以用table存key, 避免lock
     *
     *  master进程方法
     */
    protected static function initAtomicCounter()
    {
        static::$keyIndexTable = new SwooleTable(static::MAX_JOB_NUM);
        static::$keyIndexTable->column("key", SwooleTable::TYPE_STRING, 40);
        static::$keyIndexTable->column("worker_id", SwooleTable::TYPE_INT, 2);
        static::$keyIndexTable->create();

        static::$incrKey = new SwooleAtomic(0);

        static::$atomicCounterVector = new \SplFixedArray(static::MAX_JOB_NUM);
        for ($i = 0; $i < static::MAX_JOB_NUM; $i++) {
            static::$atomicCounterVector[$i] = new SwooleAtomic(0);
        }
    }

    /**
     * 获取一个不同worker间不冲突的计数器
     * @param $key
     * @return SwooleAtomic
     * 必须在master进程初始化 ::initAtomicCounter
     *
     *  worker 进程方法
     */
    protected static function fetchOneCounter($key)
    {
        $workerId = static::$swooleServer->worker_id;
        $i = static::$incrKey->add(1); // 返回add(1)之前旧值
        static::$keyIndexTable->set($i, ["key" => $key, "worker_id" => $workerId]);
        return static::$atomicCounterVector[$i];
    }

    /**
     * @param $i
     * @return array|bool
     */
    protected static function fetchRow($i)
    {
        $arr = static::$keyIndexTable->get($i);
        if (is_array($arr)) {
            return $arr;
        } else {
            return false;
        }
    }

    /**
     * @param string $key
     * @param int $n
     * @return bool|int
     *
     *  worker 进程方法
     */
    public static function incr($key, $n = 1)
    {
        if (!static::$swooleServer) {
            return false;
        }

        if (PHP_OS === "Darwin") {

            if (!isset(static::$keyCounter[$key])) {
                static::$keyCounter[$key] = static::fetchOneCounter($key);
            }

            /* @var $atomic SwooleAtomic */
            $atomic = static::$keyCounter[$key];

            return $atomic->add($n);
        } else {

            $ok = true;
            $key = static::apcuKey($key);
            if (!apcu_add($key, $n)) {
                apcu_inc($key, $n, $ok);
            }
            return $ok;
        }
    }

    /**
     * @param string $key
     * @param int $n
     * @return bool|int
     *
     *  worker 进程方法
     */
    public static function decr($key, $n = 1)
    {
        if (!static::$swooleServer) {
            return false;
        }

        if (PHP_OS === "Darwin") {

            if (!isset(static::$keyCounter[$key])) {
                return false;
            }

            /* @var $atomic SwooleAtomic */
            $atomic = static::$keyCounter[$key];

            return $atomic->sub($n);

        } else {
            $key = static::apcuKey($key);
            apcu_dec($key, $n, $ok);
            return $ok;
        }
    }

    /**
     * @return array
     *  workerId => [
     *      key => count,
     *      ......
     *  ]
     */
    public static function statistic()
    {
        if (!static::$swooleServer) {
            return [];
        }

        $list = [];

        if (PHP_OS === "Darwin") {

            $workerNum = static::$swooleServer->setting["worker_num"];
            for ($i = 0; $i < $workerNum; $i++) {
                $list[$i] = [];
            }

            for ($i = 0; $i < static::MAX_JOB_NUM; $i++) {
                $atomic = static::$atomicCounterVector[$i];
                if (!($atomic instanceof SwooleAtomic)) {
                    break;
                }

                $row = static::fetchRow($i);
                if ($row !== false) {
                    $workerId = $row["worker_id"];
                    $key = $row["key"];
                    $list[$workerId][$key] = $atomic->get();
                }
            }

        } else {

        }

        return $list;
    }

    protected static function apcuKey($key)
    {
        $workerId = static::$swooleServer->worker_id;
        return sprintf("worker#%d#%s", $workerId, $key);
    }

    public static function apcuGet($key)
    {
        $key = static::apcuKey($key);
        return apcu_fetch($key) ?:0;
    }

    public static function clear()
    {
        if (static::$swooleServer) {
            static::$keyIndexTable->destroy();
        }
    }
}