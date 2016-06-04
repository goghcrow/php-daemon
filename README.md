PHP-DAEMON

~~~php
<?php
/**
 * User: xiaofeng
 * Date: 2016/5/29
 * Time: 16:48
 */

namespace xiaofeng\daemon;

require_once __DIR__ . "/Daemon.php";

// 定义pid写入路径
define("__PID_DIR__", __DIR__);
// 定义日志文件路径
// define("__LOG_FILE__", sprintf(__LOG_DIR__ . "/%s_%d.log", date("Y-m-d"), posix_getpid()));
define("__LOG_FILE__", sprintf(__DIR__ . "/%s.log", date("Y-m-d")));

// 设置error_log
ini_set("error_log", __LOG_FILE__);
// 加大error_log单条消息长度
ini_set("log_errors_max_len", 4096); // http://php.net/manual/en/function.error-log.php#97546

// 设置内存限制, -1不限制, 否则达到限制90%将会触发重启脚本
ini_set("memory_limit",  -1);
// 设置执行时间, -1不限制, 否则达到执行时间脚本将会重启
$_SERVER["time_limit"] = -1/*second*/;


// 循环执行,同一任务防重
// kill pid 关闭
// kill -1 重启
Daemon::loopOnce(function($n) {
    error_log(time());
    sleep(1);
});

// 循环执行,同一任务可并行执行
// kill pid 关闭
// kill -1 重启
//Daemon::loop(function($n) {
//    error_log(time());
//    sleep(1);
//});

// 同一任务防重
// 不支持信号控制
//Daemon::runOnce(function() {
//    error_log("run once test");
//    sleep(20);
//});

// 同一任务可并行执行
// 不支持信号控制
//Daemon::run(function() {
//    error_log("run test");
//    sleep(20);
//});
~~~
