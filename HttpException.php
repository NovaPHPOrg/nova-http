<?php

namespace nova\plugin\http;

use Exception;

class HttpException extends Exception
{
    public function __construct($message = "")
    {
        parent::__construct($message);
    }
}