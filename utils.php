<?php
/**
 * User: xiaofeng
 * Date: 2016/5/31
 * Time: 23:58
 */
namespace xiaofeng\utils;
use Closure;

/**
 * 获取当前php内存限制
 * @return int bytes
 * php支持memory_limit规则见:
 *      http://php.net/manual/en/faq.using.php#faq.using.shorthandbytes
 */
function get_memory_limit() {
    $memory_limit = ini_get("memory_limit");
    if (!$memory_limit) {
        return 128 * 1024 * 1024;
    }
    if ($memory_limit == -1) {
        // return PHP_INT_MAX;
        return -1;
    }
    if (is_numeric($memory_limit)) {
        return intval($memory_limit);
    }
    $memory_limit = trim($memory_limit);
    $unit = strtoupper($memory_limit[strlen($memory_limit)-1]);
    $val = substr($memory_limit, 0, -1);
    switch($unit) {
        case 'G':
            return $val * 1024 * 1024 * 1024;
        case 'M':
            return $val * 1024 * 1024;
        case 'K':
            return $val * 1024;
        default:
            return intval($val);
    }
}

/**
 * 按指数关系生成单位格式化函数
 * @param  array  $units 单位由小到大排列
 * @param  int $base 单位之间关系必须一致
 * @return Closure
 * @author xiaofeng
 */
function formatUnits(array $units, $base) {
    /**
     * @param int $numbers 待格式化数字，以$units[0]为单位
     * @param string $prefix
     * @return string
     * 递归闭包必须以引用方式传递自身
     */
    return $iter = function($numbers, $prefix = "") use($units, $base, &$iter) {
        if($numbers == 0) {
            return ltrim($prefix);
        }
        if($numbers < $base) {
            return ltrim("$prefix {$numbers}{$units[0]}");
        }

        $i = intval(floor(log($numbers, $base))); // 计算最大"位"
        $unit = $units[$i]; // 取最大"位"名称
        // 1024 可优化为 1 << ($i * 10);
        $unitBytes = pow($base, $i);  // 计算最大"位"单位值
        $n = floor($numbers / $unitBytes); // 计算最大"位"值
        return $iter($numbers - $n * $unitBytes, "$prefix $n$unit"); // 递归获取剩余数据值
    };
}

/**
 * 格式化字节大小
 * @param float $bytes
 * @return string mixed
 * 单位见:
 *      https://en.wikipedia.org/wiki/Units_of_information
 */
function formatBytes($bytes) {
    static $f;
    if ($f == null) {
        $f = formatUnits(["Byte", "KB", "MB", "GB", "TB", "PB", "EB", "ZB", "YB"], 1024);
    }
    return $f($bytes);
}

/**
 * 格式化时间
 * second s 1 -> millisecond ms 10^-3 -> microsecond μs 10^-6 -> nanosecond  ns 10^-9 ...
 * @param float $millisecond
 * @return string
 * 时间单位见:
 *      https://en.wikipedia.org/wiki/Orders_of_magnitude_(time)
 */
function formatMillisecond($millisecond) {
    static $f;
    if ($f == null) {
        $f = formatUnits(["us", "ms", "s"], 1000); // μs乱码用us代替
    }
    return $f($millisecond);
}

