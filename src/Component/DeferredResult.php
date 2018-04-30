<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-10-25
 * Time: 17:27
 */

namespace Inhere\Server\Component;

/**
 * Class DeferredResult
 * @package Inhere\Server\Component
 */
class DeferredResult
{
    /**
     * @var mixed
     */
    protected $value;

    /**
     * DeferredResult constructor.
     * @param $value
     */
    public function __construct($value)
    {
        $this->value = $value;
    }

    public function get()
    {
        return $this->value;
    }

    /**
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * @param mixed $value
     */
    public function setValue($value)
    {
        $this->value = $value;
    }
}
