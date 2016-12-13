<?php

namespace Zan\Framework\Components\JobServer\JobProcessor;


use Zan\Framework\Contract\Foundation\ExceptionHandler;
use Zan\Framework\Network\Http\Response\Response;

class JobExceptionHandler implements ExceptionHandler
{
    public function handle(\Exception $ex)
    {
        // 这里异常code不能采用$ex->getCode(),
        // 因为$ex 的code 不一定是合法的http状态码, 如果不合法会在在Response中抛异常
        return new Response($ex->getMessage(), 500);
    }
}