<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think;

use think\Config;
use think\Request;
use think\Route;

class Url
{
    // 生成URL地址的root
    protected static $root;

    /**
     * URL生成 支持路由反射
     * @param string            $url URL表达式，
     * 格式：'[模块/控制器/操作]?参数1=值1&参数2=值2...@域名'
     * @控制器/操作?参数1=值1&参数2=值2...
     * \\命名空间类\\方法?参数1=值1&参数2=值2...
     * @param string|array      $vars 传入的参数，支持数组和字符串
     * @param string|bool       $suffix 伪静态后缀，默认为true表示获取配置值
     * @param boolean|string    $domain 是否显示域名 或者直接传入域名
     * @return string
     */
    public static function build($url = '', $vars = '', $suffix = true, $domain = false)
    {
        if (false === $domain && Config::get('url_domain_deploy')) {
            $domain = true;
        }
        // 解析URL
        if (0 === strpos($url, '[') && $pos = strpos($url, ']')) {
            // [name] 表示使用路由命名标识生成URL
            $name = substr($url, 1, $pos - 1);
            $url  = 'name' . substr($url, $pos + 1);
        }
        $info = parse_url($url);
        $url  = !empty($info['path']) ? $info['path'] : '';
        if (isset($info['fragment'])) {
            // 解析锚点
            $anchor = $info['fragment'];
            if (false !== strpos($anchor, '?')) {
                // 解析参数
                list($anchor, $info['query']) = explode('?', $anchor, 2);
            }
            if (false !== strpos($anchor, '@')) {
                // 解析域名
                list($anchor, $domain) = explode('@', $anchor, 2);
            }
        } elseif (strpos($url, '@')) {
            // 解析域名
            list($url, $domain) = explode('@', $url, 2);
        }

        // 解析参数
        if (is_string($vars)) {
            // aaa=1&bbb=2 转换成数组
            parse_str($vars, $vars);
        }

        if ($url) {
            $rule = Route::name(isset($name) ? $name : $url . (isset($info['query']) ? '?' . $info['query'] : ''));
            if (is_null($rule) && isset($info['query'])) {
                $rule = Route::name($url);
                // 解析地址里面参数 合并到vars
                parse_str($info['query'], $params);
                $vars = array_merge($params, $vars);
                unset($info['query']);
            }
        }
        if (!empty($rule) && $match = self::getRuleUrl($rule, $vars)) {
            // 匹配路由命名标识 快速生成
            $url = $match[0];
            if (!empty($match[1])) {
                $domain = $match[1];
            }
        } elseif (!empty($rule) && isset($name)) {
            throw new \InvalidArgumentException('route name not exists:' . $name);
        } else {
            if (isset($info['query'])) {
                // 解析地址里面参数 合并到vars
                parse_str($info['query'], $params);
                $vars = array_merge($params, $vars);
            }
            // 路由不存在 直接解析
            $url = self::parseUrl($url, $domain);
        }

        // 检测URL绑定
        $type = Route::getBind('type');
        if ($type) {
            $bind = Route::getBind($type);
            if (0 === strpos($url, $bind)) {
                $url = substr($url, strlen($bind) + 1);
            }
        }
        // 还原URL分隔符
        $depr = Config::get('pathinfo_depr');
        $url  = str_replace('/', $depr, $url);

        // URL后缀
        $suffix = in_array($url, ['/', '']) ? '' : self::parseSuffix($suffix);
        // 锚点
        $anchor = !empty($anchor) ? '#' . $anchor : '';
        // 参数组装
        if (!empty($vars)) {
            // 添加参数
            if (Config::get('url_common_param')) {
                $vars = urldecode(http_build_query($vars));
                $url .= $suffix . '?' . $vars . $anchor;
            } else {
                foreach ($vars as $var => $val) {
                    if ('' !== trim($val)) {
                        $url .= $depr . $var . $depr . urlencode($val);
                    }
                }
                $url .= $suffix . $anchor;
            }
        } else {
            $url .= $suffix . $anchor;
        }
        // 检测域名
        $domain = self::parseDomain($url, $domain);
        // URL组装
        $url = $domain . (self::$root ?: Request::instance()->root()) . '/' . ltrim($url, '/');
        return $url;
    }

    // 直接解析URL地址
    protected static function parseUrl($url, $domain)
    {
        $request = Request::instance();
        if (0 === strpos($url, '/')) {
            // 直接作为路由地址解析
            $url = substr($url, 1);
        } elseif (false !== strpos($url, '\\')) {
            // 解析到类
            $url = ltrim(str_replace('\\', '/', $url), '/');
        } elseif (0 === strpos($url, '@')) {
            // 解析到控制器
            $url = substr($url, 1);
        } else {
            // 解析到 模块/控制器/操作
            $module  = $request->module();
            $domains = Route::rules('domain');
            if (isset($domains[$domain]['[bind]'][0])) {
                $bindModule = $domains[$domain]['[bind]'][0];
                if ($bindModule && !in_array($bindModule[0], ['\\', '@'])) {
                    $module = '';
                }
            } else {
                $module = $module ? $module . '/' : '';
            }

            $controller = $request->controller();
            if ('' == $url) {
                // 空字符串输出当前的 模块/控制器/操作
                $url = $module . $controller . '/' . $request->action();
            } else {
                $path       = explode('/', $url);
                $action     = Config::get('url_convert') ? strtolower(array_pop($path)) : array_pop($path);
                $controller = empty($path) ? $controller : (Config::get('url_convert') ? Loader::parseName(array_pop($path)) : array_pop($path));
                $module     = empty($path) ? $module : array_pop($path) . '/';
                $url        = $module . $controller . '/' . $action;
            }
        }
        return $url;
    }

    // 检测域名
    protected static function parseDomain(&$url, $domain)
    {
        if (!$domain) {
            return '';
        }
        $request = Request::instance();
        if (true === $domain) {
            // 自动判断域名
            $domain = $request->host();
            if (Config::get('url_domain_deploy')) {
                // 根域名
                $urlDomainRoot = Config::get('url_domain_root');
                $domains       = Route::rules('domain');
                $route_domain  = array_keys($domains);
                foreach ($route_domain as $domain_prefix) {
                    if (0 === strpos($domain_prefix, '*.') && strpos($domain, ltrim($domain_prefix, '*.')) !== false) {
                        foreach ($domains as $key => $rule) {
                            $rule = is_array($rule) ? $rule[0] : $rule;
                            if (is_string($rule) && false === strpos($key, '*') && 0 === strpos($url, $rule)) {
                                $url    = ltrim($url, $rule);
                                $domain = $key;
                                // 生成对应子域名
                                if (!empty($urlDomainRoot)) {
                                    $domain .= $urlDomainRoot;
                                }
                                break;
                            } else if (false !== strpos($key, '*')) {
                                if (!empty($urlDomainRoot)) {
                                    $domain .= $urlDomainRoot;
                                }
                                break;
                            }
                        }
                    }
                }
            }
        } else {
            $domain .= strpos($domain, '.') ? '' : strstr($request->host(), '.');
        }
        $domain = ($request->isSsl() ? 'https://' : 'http://') . $domain;
        return $domain;
    }

    // 解析URL后缀
    protected static function parseSuffix($suffix)
    {
        if ($suffix) {
            $suffix = true === $suffix ? Config::get('url_html_suffix') : $suffix;
            if ($pos = strpos($suffix, '|')) {
                $suffix = substr($suffix, 0, $pos);
            }
        }
        return (empty($suffix) || 0 === strpos($suffix, '.')) ? $suffix : '.' . $suffix;
    }

    // 匹配路由地址
    public static function getRuleUrl($rule, &$vars = [])
    {
        foreach ($rule as $item) {
            list($url, $pattern, $domain) = $item;
            foreach ($pattern as $key => $val) {
                if (isset($vars[$key])) {
                    $url = str_replace(['[:' . $key . ']', '<' . $key . '?>', ':' . $key . '', '<' . $key . '>'], $vars[$key], $url);
                    unset($vars[$key]);
                    return [$url, $domain];
                } elseif (2 == $val) {
                    $url = str_replace(['/[:' . $key . ']', '[:' . $key . ']', '<' . $key . '?>'], '', $url);
                    return [$url, $domain];
                }
            }
        }
        return false;
    }

    // 指定当前生成URL地址的root
    public static function root($root)
    {
        self::$root = $root;
        Request::instance()->root($root);
    }
}
