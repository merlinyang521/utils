<?php
namespace Kof\Utils;

class Functions
{
	/**
     * 获取客户端IP地址
     *
     * @param integer $type 返回类型 0 返回IP地址 1 返回IPV4地址数字
     * @param boolean $adv 是否进行高级模式获取（有可能被伪装）
     * @return mixed
     */
    public static function getClientIp($type = 0, $adv = null)
    {
        $type = $type ? 1 : 0;

        static $ip = null;
        if ($ip !== null) {
            return $ip[$type];
        }

        if ($adv) {
            if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $arr = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $pos = array_search('unknown', $arr);
                if (false !== $pos) {
                    unset($arr[$pos]);
                }
                $ip = trim($arr[0]);
            } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
                $ip = $_SERVER['HTTP_CLIENT_IP'];
            } elseif (isset($_SERVER['REMOTE_ADDR'])) {
                $ip = $_SERVER['REMOTE_ADDR'];
            }
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        // IP地址合法验证
        $long = sprintf("%u", ip2long($ip));
        $ip = $long ? array(
            $ip,
            $long
        ) : array(
            '0.0.0.0',
            0
        );

        return $ip[$type];
    }

	/**
     * 获取根域名
     * @param string|null $domain
     * @return string
     */
    public static function getRootDomain($domain = null)
    {
        if (!$domain && isset($_SERVER['HTTP_HOST'])) {
            $domain = $_SERVER['HTTP_HOST'];
        }

        if (!$domain) {
            return '';
        }

        static $allDomain = array();
        if (!isset($allDomain[$domain])) {
            $preg = "/(\w+\.(?:com.cn|net.cn|gov.cn|org.cn|com|net|cn|org|asia|tel|mobi|me|tv|biz|cc|name|info|co))$/i";
            if (preg_match($preg, $domain, $matches) && isset($matches[0])) {
                $allDomain[$domain] = $matches[0];
            } else {
                $allDomain[$domain] = $domain;
            }
        }

        return $allDomain[$domain];
    }

    /**
     * 获取随机字符串
     * @param int $length
     * @param string $charlist
     * @return string
     */
    public static function getRandString(
        $length,
        $charlist = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'
    ) {
        return substr(str_shuffle(str_repeat($charlist, 5)), 0, $length);
    }
}
