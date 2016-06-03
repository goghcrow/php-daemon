<?php
/**
 * User: xiaofeng
 * Date: 2016/5/29
 * Time: 15:52
 */
namespace xiaofeng\daemon;
require_once __DIR__ . "/env.php";
require_once __DIR__ . "/utils.php";
use Closure;
use RuntimeException;
use xiaofeng\utils;
//use function xiaofeng\utils\formatBytes;
//use function xiaofeng\utils\get_memory_limit;


/**
 * Class Daemon
 * One Double Fork Model Daemon
 * Parent Process → Child Process → Grand Child Process (Daemon Process)
 * @package xiaofeng\process
 */
class Daemon {
    const IORedirection = "> /dev/null 2>&1";


    /* @var Closure $task */
    protected $task;
    protected $isRunning = false;
    protected $loopTimes = 0;
    protected $loop = false;
    protected $single = true;
    protected $restart = false;
    protected $startTime = 0;

    /* @var Pid $pid */
    protected $pid; // for Daemon

    /**
     * loop-run repeatable task with signal supporting
     * @param Closure $task
     */
    public static function loop(Closure $task) {
        (new static($task, true, false))->parentProcess();
    }

    /**
     * loop-run unrepeatable task with signal supporting
     * @param Closure $task
     */
    public static function loopOnce(Closure $task) {
        (new static($task, true, true))->parentProcess();
    }

    /**
     * run repeatable task with signal supporting
     * @param Closure $task
     */
    public static function run(Closure $task) {
        (new static($task, false, false))->parentProcess();
    }

    /**
     * run unrepeatable task with signal supporting
     * @param Closure $task
     */
    public static function runOnce(Closure $task) {
        (new static($task, false, true))->parentProcess();
    }

    /**
     * Daemon constructor.
     * @param Closure $task
     * @param $loop
     * @param bool $single
     * @internal param string $taskName used for distinct task
     * @internal param array $conf
     */
    protected function __construct(Closure $task, $loop, $single = true) {
        $this->task = $task;
        $this->loop = $loop;
        $this->single = $single;

        $this->registerEventHandler(); // register once, and forked threads inherit all handlers
    }

    /**
     * run
     */
    public function parentProcess() {
        logProcessHierarchy("Parent Process Hierarchy");

        clearstatcache();
        // http://stackoverflow.com/questions/12116121/php-umask0-what-is-the-purpose
        umask(0);
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException(__METHOD__ . " ==> pcntl_fork(1) fail");
        }

        // parentProcess
        if ($pid > 0) {
            if (pcntl_waitpid($pid, $status, WUNTRACED) === -1) {
                error_log(__METHOD__ . ": pcntl_waitpid fail ==> " . pcntl_wexitstatus($status));
            }
            return;
        }

        // childProcess
        if ($pid === 0) {
            $this->childProcess();
        }
    }

    /**
     * child exit after fork one grand child process
     */
    final protected function childProcess() {
        logProcessHierarchy("Child Process Hierarchy");

        // child process be session leader
        $sid = posix_setsid();
        if ($sid === -1) {
            throw new RuntimeException(__METHOD__ . " ==> posix_setsid fail");
        }
        logProcessHierarchy("Child Process Hierarchy After SetSid");

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException(__METHOD__ . " ==> pcntl_fork(2) fail");
        }

        // childProcess
        if ($pid > 0) {
            exit(0); // child process exit
        }

        // daemonProcess
        if ($pid === 0) {
            logProcessHierarchy("Grand Child Process Hierarchy");
            $this->daemonProcess();
        }
    }

    protected function daemonProcess() {
        $this->prepareDaemonProcess();

        $this->isRunning = true;
        if ($this->loop) {
            // 1. for tick_callback
            // 2. for signal
            // TODO: tune ticks number
            declare(ticks = 1) {
                while ($this->isRunning) {
                    call_user_func($this->task, ++$this->loopTimes);
                    pcntl_signal_dispatch(); // optional
                    if ($this->loopTimes === PHP_INT_MAX) {
                        $this->loopTimes = 0;
                    }
                }

                if ($this->restart) {
                    $this->restart();
                }
            }
        } else {
            call_user_func($this->task, ++$this->loopTimes);
        }
    }

    protected function prepareDaemonProcess() {
        set_time_limit(0);
        error_reporting(0);
        $this->startTime = time();
        $this->registerDaemonEventHandler();

        $taskName = static::class;
        if (!$this->single) {
            $taskName .= "_" . posix_getpid();
        }
        $this->pid = new Pid($taskName);
        $this->pid->checkAndSet();
    }

    protected function registerDaemonEventHandler() {
        static $once = false;
        if ($once) return;

        register_shutdown_function(function() {
            error_log("daemon shutdown and clean pid file");
            // the pid file has been removed before restarting
            if ($this->pid && !$this->restart) {
                $this->pid->remove();
            }
            $this->isRunning = false;
            $this->restart = false;
        });

        pcntl_signal(SIGTERM, function() { $this->isRunning = false; }); // kill 15
        pcntl_signal(SIGINT, function() { $this->isRunning = false; });  // kill 2  Ctrl-C
        pcntl_signal(SIGQUIT, function() { $this->isRunning = false; }); // kill 3  Ctrl-\
        pcntl_signal(SIGHUP, function() { $this->prepareRestart("SIGHUB Restart"); }); // kill 1

        $once = true;
    }

    protected function registerEventHandler() {
        static $once = false;
        if ($once) return;

        register_tick_function(function() {
            static $memory_limit = null;
            static $time_limit = null;
            if ($memory_limit === null) {
                $memory_limit = \xiaofeng\utils\get_memory_limit();
                $memory_limit = $memory_limit === -1 ? -1 : $memory_limit * 0.9; // * 0.9; // restart memory threshold
                error_log("tick_function: get memory_limit " . \xiaofeng\utils\formatBytes($memory_limit));
            }
            if ($time_limit === null) {
                $time_limit = isset($_SERVER["time_limit"]) ? intval($_SERVER["time_limit"]) : -1;
            }

            $this->time_monitor($time_limit);
            $this->memory_monitor($memory_limit);
        });

        $once = true;
    }

    protected function memory_monitor($memory_limit) {
        if ($memory_limit === -1 || memory_get_usage(true) < $memory_limit) {
            return true;
        }
        $this->prepareRestart("tick_function: prepare to restart ==> memory_get_usage(true) > $memory_limit");
        return false;
    }

    protected function time_monitor($time_limit) {
        if ($time_limit === -1 || ((time() - $this->startTime) < $time_limit)) {
            return true;
        }
        $this->prepareRestart("tick_function: prepare to restart ==> (time() - \$this->startTime) > $time_limit");
        return false;
    }

    protected function prepareRestart($reason) {
        $this->isRunning = false;
        $this->restart = true;
        error_log($reason);
    }

    protected function restart() {
        global $argv;
        $bin_php = $_SERVER["_"]; // http://stackoverflow.com/questions/3595434/server-equivalent-on-windows
        $args =  str_replace(static::IORedirection, " ", implode(" ", $argv));
        $cmd = "$bin_php $args " . static::IORedirection;
        error_log(__METHOD__ . ": cmd ==> $cmd");

        $this->pid->remove(true); // force remove pid file first to pass by single task checking
        $ret = shell_exec($cmd);
        error_log(__METHOD__ . ": result ==> $ret");
    }
}

/**
 * Class Pid
 * @access private
 * @package xiaofeng\daemon
 */
class Pid {
    private $isExist;
    protected $fpid;

    public function __construct($clazz) {
        $dir = defined("__LOG_DIR__") ? __LOG_DIR__ : sys_get_temp_dir();
        $taskName = str_replace("\\", "_", $clazz);
        $this->fpid = $dir . "/$taskName.pid";
        error_log(__METHOD__ . ": fpid ==> " . $this->fpid);
        $this->isExist = $this->get();
    }

    public function checkAndSet() {
        $this->isExist = $this->get();
        if ($this->isExist === false) {
            // pid file not exist
            if (false === file_put_contents($this->fpid, posix_getpid(), FILE_APPEND | LOCK_EX)) {
                error_log(__METHOD__ . ": Can Not Write Pid File({$this->fpid})");
                exit(-1);
            }
        } else {
            error_log(__METHOD__ . ": Pid File Exists, Task Is Running, Please Check.");
            exit(-1);
        }
    }

    public function get() {
        if (!file_exists($this->fpid)) {
            return false;
        }
        $pid = intval(file_get_contents($this->fpid));
        return file_exists("/proc/{$pid}") ? $pid : false;
    }

    public function remove($force = false) {
        // prevent shutdown_handler remove pid file
        if ($this->isExist && !$force) {
            return false;
        }

        if (is_writable($this->fpid)) {
           return unlink($this->fpid);
        }
        return false;
    }
}

// http://rango.swoole.com/archives/59
// http://php.net/manual/en/function.pcntl-fork.php
// http://php.net/manual/en/function.posix-setsid.php

// why fork twice ???

// https://www.safaribooksonline.com/library/view/python-cookbook/0596001673/ch06s08.html

# We need to fork twice, terminating each parent process and letting
# only the grandchild of the original process run the daemon’s code.
# This allows us to decouple(分离) the daemon process from the calling
# terminal, so that the daemon process can keep running (typically as
# a server process without further user interaction, like a web server,
# for example) even after the calling terminal is closed.


// http://stackoverflow.com/questions/10932592/why-fork-twice/16655124#16655124

# All right, so now first of all: what is a zombie process?

# It's a process that is dead, but its parent was busy doing some other work,
# hence it could not collect the child's exit status.
# In some cases, the child runs for a very long time, the parent cannot wait for that long,
# and will continue with it's work (note that the parent doesn't die,
# but continues its remaining tasks but doesn't care about the child).
# In this way, a zombie process is created.

# Now let's get down to business. How does forking twice help here?
# The important thing to note is that the grandchild does the work
# which the parent process wants its child to do.
# Now the first time fork is called, the first child simply forks again and exits.
# This way, the parent doesn't have to wait for a long time to collect the child's exit status
# (since the child's only job is to create another child and exit).
# So, the first child doesn't become a zombie.

# As for the grandchild, its parent has already died.
# Hence the grandchild will be adopted by the init process,
# which always collects the exit status of all its child processes.
# So, now the parent doesn't have to wait for very long, and no zombie process will be created.
# There are other ways to avoid a zombie process; this is just a common technique.

// http://stackoverflow.com/questions/881388/what-is-the-reason-for-performing-a-double-fork-when-creating-a-daemon

# Fork a second child and exit immediately to prevent zombies.  This
# causes the second child process to be orphaned, making the init
# process responsible for its cleanup.  And, since the first child is
# a session leader without a controlling terminal, it's possible for
# it to acquire one by opening a terminal in the future (System V-
# based systems).  This second fork guarantees that the child is no
# longer a session leader, preventing the daemon from ever acquiring
# a controlling terminal.

# In Unix every process belongs to a group which in turn belongs to a session. Here is the hierarchy…
# Session (SID) → Process Group (PGID) → Process (PID)

// http://blog.csdn.net/dlutbrucezhang/article/details/8821690