<?php
/**
 * User: xiaofeng
 * Date: 2016/5/31
 * Time: 11:11
 */
namespace xiaofeng\daemon;

use RuntimeException;
if (!function_exists("\\pcntl_fork")) {
    throw new RuntimeException("PCNTL extension needed");
}
if (!function_exists("\\posix_getpid")) {
    throw new RuntimeException("POSIX extension needed");
}
date_default_timezone_set("Asia/Shanghai");


defined("DEBUG") or define("DEBUG", false);
define("__LOG_DIR__", __DIR__);
error_reporting(E_ALL);

// define("__LOG_FILE__", sprintf(__LOG_DIR__ . "/%s_%d.log", date("Y-m-d"), posix_getpid()));
define("__LOG_FILE__", sprintf(__LOG_DIR__ . "/%s.log", date("Y-m-d")));
ini_set("error_log", __LOG_FILE__);
ini_set("log_errors_max_len", 4096); // http://php.net/manual/en/function.error-log.php#97546
ini_set("memory_limit",  -1);
$_SERVER["time_limit"] = -1/*second*/; // when run time reach time_limit, daemon will restart



/**
 * rewrite in namespace
 * @param $message
 *
 * ini_set("error_log", $log_file);
 */
function error_log($message) {
    $pid = posix_getpid();
    $time = date("Y-m-d H:i:s");
    $msg = "[PID:$pid $time] $message";
    \error_log($msg . "\n", 3, __LOG_FILE__);
}

/**
 * @param string $desc
 * @return string
 */
function logProcessHierarchy($desc) {
    $pid = posix_getpid();
    $pgid = posix_getpgid($pid);
    $sid = posix_getsid($pid);
    error_log(($desc ? "$desc: " : "") . "Session (SID: $sid) → Process Group (PGID: $pgid) → Process (PID: $pid)");
}

/**
 * log all exception and error
 * set_error_handler
 * @access private
 * @param $type
 * @param $message
 * @param $file
 * @param $line
 */
function _log_error($type, $message, $file, $line) {
    ob_start();
    debug_print_backtrace();
    $trace = ob_get_clean();

    error_log("\nType: Error[$type];\nFile: $file; Line: $line;\nMessage: $message;");
    error_log("BackTrace:\n$trace");
}

/**
 * log exception
 * set_exception_handler
 * @access private
 * @param \Exception $e
 */
function _log_exception(\Exception $e) {
    // error_log("\n" . $e->getTraceAsString());
    _log_error(get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
}

/**
 * pcntl_signal
 * @access private
 * @param $signo
 */
function _signal_handler($signo) {
    if ($signo == SIGALRM) {
        return;
    }
    error_log(__METHOD__ . ": Signaled($signo)"); // exit by signal
    // exit(-1);
}

/**
 * register_shutdown_function
 * @access private
 */
function _shutdown_handler() {
    error_log("shutdown and log error_get_last");
    if ($err = error_get_last()) {
        _log_error($err["type"], $err["message"], $err["file"], $err["line"]);
    }
}

register_shutdown_function(__NAMESPACE__ . "\\_shutdown_handler");
set_error_handler(__NAMESPACE__ . "\\_log_error");
set_exception_handler(__NAMESPACE__ . "\\_log_exception");
foreach([SIGTERM, SIGHUP, SIGINT, SIGQUIT, SIGILL, SIGPIPE, SIGALRM] as $sig) {
    pcntl_signal($sig, __NAMESPACE__ . "\\_signal_handler");
}

# https://en.wikipedia.org/wiki/Unix_signal
# SIGTERM 15 (kill default)
# SIGINT 2 | Ctrl-C
# SIGQUIT 3 Terminal quit signal. | Ctrl-\
# SIGKILL 9 terminate immediately
# SIGHUP 1 controlling terminal is closed
# SIGALRM 14 alarm
# SIGILL execute illegal
# SIGPIPE write to a pipe without a process connected to the other end

[
//    SIG_IGN,
//    SIG_DFL,
//    SIG_ERR,
//    SIGIOT,
//    SIGCLD,
//    SIGIO,
    SIGHUP,
    SIGINT,
    SIGQUIT,
    SIGILL,
    SIGTRAP,
    SIGABRT,
    SIGBUS,
    SIGFPE,
    SIGKILL,
    SIGUSR1,
    SIGSEGV,
    SIGUSR2,
    SIGPIPE,
    SIGALRM,
    SIGTERM,
    SIGSTKFLT,
    SIGCHLD,
    SIGCONT,
    SIGSTOP,
    SIGTSTP,
    SIGTTIN,
    SIGTTOU,
    SIGURG,
    SIGXCPU,
    SIGXFSZ,
    SIGVTALRM,
    SIGPROF,
    SIGWINCH,
    SIGPOLL,
    SIGPWR,
    SIGSYS,
    SIGBABY,
];

