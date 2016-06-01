<?php
/**
 * User: xiaofeng
 * Date: 2016/5/29
 * Time: 16:48
 */

namespace xiaofeng\daemon;

define("DEBUG", true);
require_once __DIR__ . "/Daemon.php";

// env.php
//define("__LOG_DIR__", __DIR__); // 定义log目录
//define("__LOG_FILE__", sprintf(__LOG_DIR__ . "/%s.log", date("Y-m-d"))); // 定义log文件名
//ini_set("error_log", __LOG_FILE__); // 定义log文件位置
//ini_set("memory_limit",  -1);          // 定义脚本内存阈值(*0.9),达到该值*重启
//$_SERVER["time_limit"] = -1/*second*/; // 定义守护进程执行时间阈值,达到重启

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