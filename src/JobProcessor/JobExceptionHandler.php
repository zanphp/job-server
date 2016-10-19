<?php

namespace Zan\Framework\Components\JobServer\JobProcessor;


use Zan\Framework\Contract\Foundation\ExceptionHandler;
use Zan\Framework\Network\Http\Response\Response;

class JobExceptionHandler implements ExceptionHandler
{
    public function handle(\Exception $ex)
    {
        return new Response($ex->getMessage(), $ex->getCode() ?: 500);
    }
}