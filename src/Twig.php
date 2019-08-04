<?php
/**
 * yxcms.
 * Author: xcwmoon <wodeipan@outlook.com>
 * Copyright (c) 2019 https://xcwmoon.com All rights reserved.
 * Licensed ( https://xcwmoon.com/licenses/LICENSE-2.0 )
 */
declare (strict_types = 1);
namespace Sclswin;

use think\App;
use think\Config;
use think\contract\TemplateHandlerInterface;
use think\helper\Str;
use think\template\exception\TemplateNotFoundException;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;
use Twig\Loader\LoaderInterface;
use Twig\RuntimeLoader\FactoryRuntimeLoader;
use Twig\TwigFilter;
use think\Template;
use Twig\TwigFunction;

class Twig
{
    private $template;
    /**
     * 模板引擎实例
     * @var object
     */
    public $engine;
    protected $app;
    protected $config = [
        'view_path'           => '',
        'view_suffix'         => 'twig',
        'cache'          => '',
        'strict_variables'    => true,
        'auto_add_function'   => false,
        'base_template_class' => 'Twig_Template',//继承Template
        'functions'           => [],
        'filters'             => [],
        'globals'             => [],
        'runtime'             => [],
        'view_depr'   => DIRECTORY_SEPARATOR,
        // 是否开启模板编译缓存,设为false则每次都会重新编译
        'tpl_cache'   => true,
    ];

    public function __construct(App $app, array $config = [])
    {
        $this->app = $app;
        $this->config = array_merge($this->config, (array) $config);
        if (empty($this->config['view_base'])) {
            $this->config['view_base'] = $app->getRootPath() . 'view' . DIRECTORY_SEPARATOR;
        }
        if (empty($this->config['cache_path'])) {
            $this->config['cache_path'] = $app->getRuntimePath() . 'temp' . DIRECTORY_SEPARATOR;
        }
        $this->config['cache'] = $this->config['cache_path'];
        $this->template = new Template($this->config);
        $this->template->setCache($app->cache);
        $this->template->extend('$Request', function (array $vars) {
            // 获取Request请求对象参数
            $method = array_shift($vars);
            if (!empty($vars)) {
                $params = implode('.', $vars);
                if ('true' != $params) {
                    $params = '\'' . $params . '\'';
                }
            } else {
                $params = '';
            }

            return 'app(\'request\')->' . $method . '(' . $params . ')';
        });
    }


    /**
     * 渲染模板文件
     * @access public
     * @param  string    $template 模板文件
     * @param  array     $data 模板变量
     * @return void
     */
    public function fetch(string $template, array $data = []): void
    {
        if ('' == pathinfo($template, PATHINFO_EXTENSION)) {
            // 获取模板文件名
            $template = $this->parseTemplate($template);
        }
        // 模板不存在 抛出异常
        if (!is_file($template)) {
            throw new TemplateNotFoundException('template not exists:' . $template, $template);
        }
        // 记录视图信息
        $this->app['log']
            ->record('[ VIEW ] ' . $template . ' [ ' . var_export(array_keys($data), true) . ' ]');
        $loader = new FilesystemLoader($this->template->getConfig('view_path'));
        $twig = $this->getTwig($loader);
        $template = str_ireplace($this->template->getConfig('view_path'),'',$template);
        $twig->display($template , $data);
    }

    /**
     * twig配置
     * @return array
     */
    protected function getTwigConfig()
    {
        return [
            'debug'               => $this->app->isDebug(),
            'auto_reload'         => $this->app->isDebug(),
            'charset' =>'utf-8',
            'cache'               => $this->config['cache_path'],
            'strict_variables'    => $this->config['strict_variables'],
            'base_template_class' => $this->config['base_template_class']
        ];
    }

    /**
     * 自动定位模板文件
     * @access private
     * @param  string $template 模板文件规则
     * @return string
     */
    private function parseTemplate(string $template): string
    {
        // 分析模板文件规则
        $request = $this->app['request'];

        // 获取视图根目录
        if (strpos($template, '@')) {
            // 跨模块调用
            list($app, $template) = explode('@', $template);
        }

        if ($this->config['view_path'] && !isset($app)) {
            $path = $this->config['view_path'];
        } else {
            $app = isset($app) ? $app : $request->app();
            // 基础视图目录
            $path = $this->config['view_base'] . ($app ? $app . DIRECTORY_SEPARATOR : '');

            $this->template->view_path = $path;
        }

        $depr = $this->config['view_depr'];

        if (0 !== strpos($template, '/')) {
            $template   = str_replace(['/', ':'], $depr, $template);
            $controller = $request->controller();

            if (strpos($controller, '.')) {
                $pos        = strrpos($controller, '.');
                $controller = substr($controller, 0, $pos) . '.' . Str::snake(substr($controller, $pos + 1));
            } else {
                $controller = Str::snake($controller);
            }

            if ($controller) {
                if ('' == $template) {
                    // 如果模板文件名为空 按照默认模板渲染规则定位
                    if (2 == $this->config['auto_rule']) {
                        $template = $request->action(true);
                    } elseif (3 == $this->config['auto_rule']) {
                        $template = $request->action();
                    } else {
                        $template = Str::snake($request->action());
                    }

                    $template = str_replace('.', DIRECTORY_SEPARATOR, $controller) . $depr . $template;
                } elseif (false === strpos($template, $depr)) {
                    $template = str_replace('.', DIRECTORY_SEPARATOR, $controller) . $depr . $template;
                }
            }
        } else {
            $template = str_replace(['/', ':'], $depr, substr($template, 1));
        }

        return $path . ltrim($template, '/') . '.' . ltrim($this->config['view_suffix'], '.');
    }


    /**
     * twig loader
     * @param LoaderInterface $loader
     * @return Environment
     */
    protected function getTwig(LoaderInterface $loader)
    {
        $twig = new Environment($loader, $this->getTwigConfig());
        if ($this->config['auto_add_function']) {
            $this->addFunctions($twig);
        }
        if (!empty($this->config['globals'])) {
            foreach ($this->config['globals'] as $name => $global) {
                $twig->addGlobal($name, $global);
            }
        }
        if (!empty($this->config['functions'])) {
            foreach ($this->config['functions'] as $name => $function) {
                if (is_integer($name)) {
                    $twig->addFunction(new TwigFunction($function, $function));
                } else {
                    $twig->addFunction(new TwigFunction($name, $function));
                }
            }
        }
        if (!empty($this->config['filters'])) {
            foreach ($this->config['filters'] as $name => $filter) {
                if (is_integer($name)) {
                    $twig->addFilter(new TwigFilter($filter, $filter));
                } else {
                    $twig->addFilter(new TwigFilter($name, $filter));
                }
            }
        }
        if (!empty($this->config['runtime'])) {
            $twig->addRuntimeLoader(new FactoryRuntimeLoader($this->config['runtime']));
        }
        //$twig->addExtension(new TwigExtension());
        return $twig;
    }

    protected function addFunctions(Environment $twig)
    {
        $twig->registerUndefinedFunctionCallback(function ($name) {
            if (function_exists($name)) {
                return new TwigFunction($name, $name);
            }
            return false;
        });
    }

    /**
     * 渲染模板内容
     * @access public
     * @param  string    $template 模板内容
     * @param  array     $data 模板变量
     * @return void
     */
    public function display(string $template, array $data = []): void
    {
        if ('' == pathinfo($template, PATHINFO_EXTENSION)) {
            // 获取模板文件名
            $template = $this->parseTemplate($template);
        }
        // 模板不存在 抛出异常
        if (!is_file($template)) {
            throw new TemplateNotFoundException('template not exists:' . $template, $template);
        }
        //$_template = str_ireplace($this->template->getConfig('view_path'),'',$template);
        $key    = md5($template);
        $loader = new ArrayLoader([$key => $template]);
        $twig = $this->getTwig($loader);
        $twig->display($key, $data);
    }

}