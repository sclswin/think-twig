<?php
/**
 * yxcms.
 * Author: xcwmoon <wodeipan@outlook.com>
 * Copyright (c) 2019 https://xcwmoon.com All rights reserved.
 * Licensed ( https://xcwmoon.com/licenses/LICENSE-2.0 )
 */

namespace Sclswin;

use think\App;
use think\helper\Str;
use think\template\exception\TemplateNotFoundException;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;
use Twig\Loader\LoaderInterface;
use Twig\RuntimeLoader\FactoryRuntimeLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

class Twig
{

    protected $app;
    protected $config = [
        'view_path'           => '',
        'view_suffix'         => 'twig',
        'cache_path'          => '',
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

    public function __construct(App $app,$config = [])
{
    $this->app = $app;
    $this->config($config);
    if (empty($this->config['view_path'])) {
        $this->config['view_path'] = $this->app->getAppPath() . 'view';
    }
    if (empty($this->config['cache_path'])) {
        $this->config['cache_path'] = $this->app->getRuntimePath() . 'temp';
    }
    if (!is_dir($this->config['cache_path'])) {
        if (!mkdir($this->config['cache_path'], 0755, true)) {
            throw new RuntimeException('Can not make the cache dir!');
        }
    }
}
public function getTwigConfig(){
        return [
            'debug'=>$this->config['debug']??$this->app->isDebug(),
            'cache'=>$this->config['cache_path'],
            'charset'=>$this->config['charset'],
            'base_template_class'=>$this->config['base_template_class'],
            'auto_reload'=>$this->config['auto_reload']??$this->app->isDebug(),
            'strict_variables'=>$this->config['strict_variables'],
            'autoescape'=>$this->config['autoescape'],
            'optimizations'=>$this->config['optimizations'],
        ];
}
    /**
     * 检测是否存在模板文件
     * @access public
     * @param  string $template 模板文件或者模板规则
     * @return bool
     */
    public function exists(string $template): bool
    {
        if ('' == pathinfo($template, PATHINFO_EXTENSION)) {
            // 获取模板文件名
            $template = $this->parseTemplate($template);
        }

        return is_file($template);
    }

    /**
     * 自动定位模板文件
     * @access private
     * @param  string $template 模板文件规则
     * @return string
     */
    private function parseTemplate(string $template): string
    {
        if (empty($this->config['view_base'])) {
            $this->config['view_base'] = $this->app->getRootPath() . 'view' . DIRECTORY_SEPARATOR;
        }

        $request = $this->app->request;

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
                    // 如果模板文件名为空 按照默认规则定位
                    if (2 == $this->config['auto_rule']) {
                        $template = $request->action(true);
                    } elseif (3 == $this->config['auto_rule']) {
                        $template = $request->action();
                    } else {
                        $template = App::parseName($request->action());
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
     * @param Environment $twig
     */
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
        $twig->addExtension(new Extension());
        return $twig;
    }

    /**
     * 渲染模板文件
     * @access public
     * @param  string    $template 模板文件
     * @param  array     $data 模板变量
     * @return void
     */
    public function fetch(string $template, array $data = []){
        if ('' == pathinfo($template, PATHINFO_EXTENSION)) {
            // 获取模板文件名
            $template = $this->parseTemplate($template);
        }

        // 模板不存在 抛出异常
        if (!is_file($template)) {
            throw new TemplateNotFoundException('template not exists:' . $template, $template);
        }

        $this->template = $template;

        // 记录视图信息
        $this->app->log
            ->record('[ VIEW ] ' . $template . ' [ ' . var_export(array_keys($data), true) . ' ]');

        extract($data, EXTR_OVERWRITE);

        include $this->template;
    }

    /**
     * 渲染模板内容
     * @access public
     * @param  string    $content 模板内容
     * @param  array     $data 模板变量
     * @return void
     */
    public function display(string $content, array $data = []){
        if ($config) {
            $this->config($config);
        }
        $key    = md5($template);
        $loader = new ArrayLoader([$key => $template]);

        $twig = $this->getTwig($loader);
        $twig->display($key, $data);
    }

    /**
     * 配置模板引擎
     * @access private
     * @param  array  $config 参数
     * @return void
     */
    public function config(array $config) : void{
        if (is_array($name)) {
            $this->config = array_merge($this->config, $name);
        } else {
            $this->config[$name] = $value;
        }
    }

    /**
     * 获取模板引擎配置
     * @access public
     * @param  string  $name 参数名
     * @return void
     */
    public function getConfig(string $name){

    }
}