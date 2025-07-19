<?php

declare(strict_types=1);

/*
 * Copyright (c) 2025. Lorem ipsum dolor sit amet, consectetur adipiscing elit.
 * Morbi non lorem porttitor neque feugiat blandit. Ut vitae ipsum eget quam lacinia accumsan.
 * Etiam sed turpis ac ipsum condimentum fringilla. Maecenas magna.
 * Proin dapibus sapien vel ante. Aliquam erat volutpat. Pellentesque sagittis ligula eget metus.
 * Vestibulum commodo. Ut rhoncus gravida arcu.
 */

namespace nova\plugin\http;

use Exception;

/**
 * HTTP异常类
 *
 * 用于处理HTTP相关操作中发生的异常情况。
 * 继承自PHP标准Exception类，提供HTTP特定的异常处理功能。
 *
 * @package nova\plugin\http
 * @author Nova Framework
 * @since 1.0.0
 */
class HttpException extends Exception
{
    /**
     * 构造函数
     *
     * 创建一个新的HTTP异常实例。
     *
     * @param string         $message  异常消息，默认为空字符串
     * @param int            $code     异常代码，默认为0
     * @param Exception|null $previous 前一个异常，用于异常链
     */
    public function __construct($message = "", $code = 0, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
