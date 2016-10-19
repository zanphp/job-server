<?php

namespace Zan\Framework\Components\JobServer\Monitor;


use Zan\Framework\Foundation\Contract\Async;
use Zan\Framework\Network\Server\Timer\Timer;

class Dns implements Async
{
    public $callback;

    public static function lookup($domain, $timeout = 1000)
    {
        $self = new static;

        $timeoutId = Timer::after($timeout, function() use($self, $domain) {
            if ($self->callback) {
                call_user_func($self->callback, $domain); // no exception
                unset($self->callback);
            }
        });

        swoole_async_dns_lookup($domain, function($domain, $ip) use($self, $timeoutId) {
            if ($self->callback) {
                Timer::clearAfterJob($timeoutId);
                call_user_func($self->callback, $ip ?: $domain);
                unset($self->callback);
            }
        });

        return $self;
    }

    public function execute(callable $callback, $task)
    {
        $this->callback = $callback;
    }
}