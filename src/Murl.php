<?php
/**
 * 封装的curl请求
 */
namespace Zatxm\YRequest;

use Zatxm\YRequest\CurlErr;

class Murl
{
    private static $instance = null; //本实例
    private $option = []; //设置cURL并行选项curl_multi_setopt
    private $old = []; //历史数据
    private $async = false; //是否异步
    /**
     * 配置并发选项键值数组['key1'=>['url'=>'https://xxx.xx.xx', ...]]
     * key1为并发标识符
     * 值说明
     * url通信url
     * method请求方式,默认GET
     * header请求头数组
     * cookie请求cookie数组
     * params请求参数数组
     * option额外信息数组
     * @var array
     */
    private $multiOtion = [];

    private function __construct() {}

    /**
     * 单实例初始化类
     * @return this
     */
    public static function boot()
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    /**
     * curl_multi_setopt设置
     * @param  array $option 设置curl_multi_setopt
     * @return this
     */
    public function option($option = [])
    {
        $this->option = $option;
        return $this;
    }

    /**
     * 设置是否异步
     * @param  boolean $async 是否异步
     * @return this
     */
    public function async($async = false)
    {
        $this->async = $async;
        return $this;
    }

    /**
     * 设置并发选项
     * @param  array $multiOtion 并发选项数组
     * @return this
     */
    public function multiOtion(array $multiOtion)
    {
        $this->multiOtion = $multiOtion;
        return $this;
    }

    /**
     * 增加一个并发通信
     * @param array  $option 并发配置数组
     * @param string $tag    并发配置标识
     * @return this
     */
    public function addMultiOption(array $option, $tag = '')
    {
        $this->multiOtion[$tag] = $option;
        return $this;
    }

    /**
     * 请求
     * 返回[
     *         'async'  => 1, //异步返回
     *         'data'   => ['code'=>'状态码', 'msg'=>'内容', 'location'=>'重定向url'],
     *         'cookie' => 'rescookie设置为1时返回响应cookie数组',
     *         'header' => 'resheader设置为1时返回响应header数组'
     *     ]
     * @return array|CurlErr
     */
    public function go()
    {
        set_time_limit(0);

        $mh = curl_multi_init();
        if ($this->option && is_array($this->option)) {
            foreach ($this->option as $k => $v) {
                curl_multi_setopt($mh, $k, $v);
            }
        }

        $keys = $handles = [];
        $ys = (function () use ($mh, &$keys) {
            foreach ($this->multiOtion as $k => $v) {
                if (empty($v['url'])) {
                    continue; //没有请求url
                }
                $ch = curl_init($v['url']);
                /***处理请求各种curl option***/
                // 支持原生配置只需传个p数组，但可能会被以下参数覆盖
                $options = $v['source'] ?? [];

                $options[CURLOPT_RETURNTRANSFER] = true;
                $options[CURLOPT_FAILONERROR] = false;

                // 是否异步请求并设置超时时间
                if ($this->async) {
                    if (!defined('CURLOPT_TIMEOUT_MS')) {
                        define('CURLOPT_TIMEOUT_MS', 155);
                    }
                    $options[CURLOPT_NOSIGNAL] = 1;
                    $options[CURLOPT_TIMEOUT_MS] = 200;
                } else {
                    $options[CURLOPT_TIMEOUT] = !empty($v['timeout']) ? intval($v['timeout']) : 30;
                }

                // https请求
                if (strlen($v['url']) > 5 && strtolower(substr($v['url'], 0, 5)) == 'https') {
                    $options[CURLOPT_SSL_VERIFYPEER] = false;
                    $options[CURLOPT_SSL_VERIFYHOST] = false;
                }

                // 处理请求头
                $reqHeaders = $v['header'] ?? null;
                if ($reqHeaders && is_array($reqHeaders)) {
                    $header = [];
                    foreach ($reqHeaders as $x => $y) {
                        switch ($x) {
                            case 'User-Agent':
                                $options[CURLOPT_USERAGENT] = $y;
                                break;
                            case 'Referer':
                                $options[CURLOPT_REFERER] = $y;
                                break;
                            default:
                                $header[] = "{$x}: {$y}";
                                break;
                        }
                    }
                    if ($header) {
                        $options[CURLOPT_HTTPHEADER] = $header;
                    }
                }

                // 处理参数
                $params = $v['params'] ?? null;
                if (!empty($params)) {
                    if (is_array($params)) {
                        $options[CURLOPT_POST] = true;
                        $options[CURLOPT_POSTFIELDS] = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
                    } elseif(is_string($params)) {
                        $options[CURLOPT_POST] = true;
                        $options[CURLOPT_POSTFIELDS] = $params;
                    }
                }

                // 设置cookie
                $cookies = $v['cookie'] ?? null;
                if ($cookies) {
                    if (is_array($cookies)) {
                        $reqCookies = [];
                        foreach ($cookies as $k => $v) {
                            $reqCookies[] = $k . '=' . $v;
                        }
                        $options[CURLOPT_COOKIE] = implode('; ', $reqCookies);
                    } elseif (is_string($cookies)) {
                        $options[CURLOPT_COOKIE] = $cookies;
                    }
                }

                // 处理数据流
                if (!empty($v['stream'])) {
                    $options[CURLOPT_WRITEFUNCTION] = $v['stream'];
                }

                // 设置代理
                if (!empty($v['proxy'])) {
                    $options[CURLOPT_PROXY] = $v['proxy'];
                }

                // 设置请求方式
                $method = $v['method'] ?? 'GET';
                $options[CURLOPT_CUSTOMREQUEST] = $method;
                /***处理请求各种curl option结束***/

                curl_setopt_array($ch, $options);
                curl_multi_add_handle($mh, $ch);

                $keys[(int) $ch] = $k;

                yield $k => $ch;
            }
        })();
        foreach ($ys as $k => $v) {
            $handles[$k] = $v;
        }

        // 执行cURL Multi句柄
        $data = []; //返回数组
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                // 等待子句柄有活动
                curl_multi_select($mh);
            }
        } while ($active && $status == CURLM_OK);

        while ($done = curl_multi_info_read($mh)) {
            $ch = $done['handle'];
            $key = $keys[(int) $ch];
            if ($done['result'] == CURLE_OK) {
                $data[$key] = [
                    'code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
                    'data' => curl_multi_getcontent($ch)
                ];
            } else {
                $data[$key] = new CurlErr($done['result'], curl_error($ch));
            }
        }

        // 获取每个请求的结果并输出
        foreach ($handles as $v) {
            curl_multi_remove_handle($mh, $v);
            curl_close($v);
        }

        curl_multi_close($mh);
        $this->clear();

        return $data;
    }

    /**
     * 清理请求数据
     * @return this
     */
    public function clear()
    {
        $this->old = [
            'multiOtion' => $this->multiOtion,
            'option'     => $this->option,
            'async'      => $this->async
        ];
        $this->multiOtion = [];
        $this->option = [];
        $this->async = false;
        return $this;
    }
}
