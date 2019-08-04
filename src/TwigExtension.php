<?php
/**
 * yxcms.
 * Author: xcwmoon <wodeipan@outlook.com>
 * Copyright (c) 2019 https://xcwmoon.com All rights reserved.
 * Licensed ( https://xcwmoon.com/licenses/LICENSE-2.0 )
 */
namespace Sclswin;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

class TwigExtension extends  AbstractExtension implements GlobalsInterface
{
    /**
     * 注册全局变量
     * @return array
     */
    public function getGlobals()
    {
        return [
            //'text' => new Text(),
        ];
    }
    public function getNodeVisitors()
    {
        return [

        ];
    }
}