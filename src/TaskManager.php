<?php declare(strict_types=1);

namespace PhpPkg\TaskPM;

use Toolkit\Cli\Cli;
use Toolkit\Stdlib\Helper\Assert;
use Toolkit\Stdlib\Obj\Traits\AutoConfigTrait;
use Toolkit\Stdlib\Php;
use Toolkit\Sys\Util\ProcUtil;
use function getmypid;
use const STDERR;
use const STDOUT;

/**
 * class TaskManager - TaskManager  TaskDispatcher
 *
 * @author inhere
 */
class TaskManager
{
    use AutoConfigTrait {
        __construct as supper;
    }

    /**
     * @var string Slave process identifier
     */
    public static string $slaveLabel = '--worker';

    /**
     * @var string Slave process command
     */
    public string $workerCmd = '';

    /**
     * @var string Process name
     */
    public string $name = '';

    /**
     * @var int Process number
     */
    private int $procNum = 2;

    /**
     * @var string Slave command prefix
     */
    public string $prefix = '';

    /**
     * @var array Pool of process
     */
    private array $procPool = [];

    /**
     * Is a worker process
     *
     * @var bool
     */
    private bool $worker = false;

    /**
     * @var int The number of busy
     */
    private int $idleCount = 0;

    /**
     * @var callable Master process handler
     */
    private $masterHandler;

    /**
     * @var callable Slave process handler
     */
    private $slaveHandler;

    /**
     * @param array{name: string, procNum: int} $config
     */
    public function __construct(array $config = [])
    {
        $this->supper([$config]);

        $this->name = $this->name ?: Php::getBinName();

        if (!empty($_SERVER['argv']) && in_array(static::$slaveLabel, $_SERVER['argv'], true)) {
            $this->worker = true;
        }
    }

    /**
     * Execute only in master process
     *
     * @param callable $masterHandler master process callback, which can be call_user_func() execute
     *
     * @return $this
     */
    public function onMaster(callable $masterHandler): self
    {
        if (!$this->worker) {
            $this->masterHandler = $masterHandler;
            $this->createManager($this->procNum);
        }

        return $this;
    }

    /**
     * Create master handler
     *
     * @param int $limit
     */
    private function createManager(int $limit): void
    {
        !$this->workerCmd && $this->workerCmd = $this->currentCmd();

        ProcUtil::setTitle($this->name . ' - master');

        if (is_callable($this->masterHandler)) {
            for ($i = 0; $i < $limit; $i++) {
                $this->procPool[] = $this->createProcess();
            }

            call_user_func($this->masterHandler, $this);
        }
    }

    /**
     * Get current command
     *
     * @return string
     */
    private function currentCmd(): string
    {
        if (empty($this->prefix)) {
            $prefix = !empty($_SERVER['_']) ? realpath($_SERVER['_']) : '/usr/bin/env php';
        } else {
            $prefix = $this->prefix;
        }

        $mixed = array_merge([$prefix, $_SERVER['PHP_SELF']], $_SERVER['argv']);
        $mixed = array_filter($mixed, static fn($item) => !str_starts_with($item, './'));

        return implode(' ', array_unique($mixed));
    }

    /**
     * Create slave process
     *
     * @return array
     */
    private function createProcess(): array
    {
        $desc = [
            ['pipe', 'r'], // std input
            ['pipe', 'w'], // std output
            ['pipe', 'w'], // std error
        ];
        $res  = proc_open($this->workerCmd . ' ' . static::$slaveLabel, $desc, $pipes, getcwd());

        $status = proc_get_status($res);
        if (!isset($status['pid'])) {
            $this->log('process create failed');
            return $this->createProcess();
        }

        $pid     = $status['pid'];
        $process = [
            'res'      => $res,
            'pipes'    => $pipes,
            'idle'     => true, // process is idling
            'pid'      => $pid,
            'callback' => null, // call when the slave process finished
        ];

        // non-blocking
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $this->log('start ' . $pid);

        return $process;
    }

    /**
     * Log
     *
     * @param string $msg
     * @param array $data
     */
    public function log(string $msg, array $data = []): void
    {
        $pid  = getmypid();
        $opts = [
            'writeOpts' => [
                'stream' => $this->worker ? STDERR : STDOUT,
            ],
            '_role'     => $this->getRoleName(),
            'pid'       => $pid
        ];

        Cli::clog($msg, $data, 'info', $opts);
    }

    /**
     * @return string
     */
    protected function getRoleName(): string
    {
        return $this->worker ? 'worker' : 'master';
    }

    /**
     * Execute only in slave process
     *
     * @param callable $slaveHandler slave process callback, which can be call_user_func() execute
     *
     * @return $this
     */
    public function onWorker(callable $slaveHandler): self
    {
        if ($this->worker) {
            $this->slaveHandler = $slaveHandler;
            $this->createSlave();
        }

        return $this;
    }

    /**
     * Create slave handler
     */
    private function createSlave(): void
    {
        @cli_set_process_title($this->name . ':' . 'slave');

        while (true) {
            // listen input from master
            $fp   = @fopen('php://stdin', 'rb');
            $recv = @fread($fp, 8); // read content length
            $size = (int)rtrim($recv);
            $data = @fread($fp, $size);
            @fclose($fp);

            if (!empty($data)) {
                if (is_callable($this->slaveHandler)) {
                    $data = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                    $resp = call_user_func($this->slaveHandler, $data, $this);
                    echo json_encode($resp, JSON_THROW_ON_ERROR);
                }
            } else {
                usleep(100000);
            }
        }
    }

    /**
     * Master submit task to slave
     *
     * @param mixed $data the params transmit to slave handler
     * @param callable|null $callback called after the slave process finishes processing
     *
     * @return int
     */
    public function submit(mixed $data, callable $callback = null): int
    {
        if (!$this->worker) {
            $process = &$this->getAvailableProcess();
            // add callback
            $process['callback'] = $callback;

            $data   = json_encode($data, JSON_THROW_ON_ERROR); // transmit by json, compatible all types
            $length = strlen($data);
            $length = str_pad($length . '', 8, ' ', STR_PAD_RIGHT);

            // send to slave process, with length and content
            fwrite($process['pipes'][0], $length . $data);

            return (int)$process['pid'];
        }

        return 0;
    }

    /**
     * Get available process
     *
     * @return array
     * @throws \JsonException
     */
    private function &getAvailableProcess(): array
    {
        while (true) {
            $index = $this->check();

            if (isset($this->procPool[$index])) {
                $this->procPool[$index]['idle'] = false;
                $this->idleCount++;

                return $this->procPool[$index];
            }

            // sleep 50 ms
            usleep(50000);
        }
    }

    /**
     * Check process
     *
     * @return int
     * @throws \JsonException
     */
    private function check(): int
    {
        $index = -1;
        foreach ($this->procPool as $key => &$process) {
            $this->checkProcessAlive($process);

            if (!$process['idle']) {
                echo stream_get_contents($process['pipes'][2]);      // std error

                $result = stream_get_contents($process['pipes'][1]); // std output
                if (!empty($result)) {
                    $process['idle'] = true;
                    $this->idleCount--;

                    if (is_callable($process['callback'])) {
                        $data = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
                        if (json_last_error()) {
                            $data = $result;
                        }
                        $process['callback']($data, $this);
                    }
                }
            }
            if ($process['idle'] && $index < 0) {
                $index = $key;
            }
        }

        return $index;
    }

    /**
     * Check a process is alive
     *
     * @param array $process
     */
    private function checkProcessAlive(array &$process): void
    {
        $status = proc_get_status($process['res']);
        if (!$status['running']) {
            echo stream_get_contents($process['pipes'][2]);

            $this->killProcess($process);
            $this->log('close ' . $process['pid']);
            if (!$process['idle']) {
                $this->idleCount--;
            }
            $process = $this->createProcess();
        }
    }

    /**
     * Kill process
     *
     * @param array $process
     *
     * @return bool
     */
    private function killProcess(array $process): bool
    {
        if (function_exists('proc_terminate')) {
            return @proc_terminate($process['res']);
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($process['pid'], 9);
        }

        if (function_exists('proc_close')) {
            return @proc_close($process['res']) !== -1;
        }

        return false;
    }

    /**
     * Loop condition
     *
     * @param int $sleep (unit: ms)
     *
     * @return bool
     */
    public function loop(int $sleep = 0): bool
    {
        if (!$this->worker) {
            if ($sleep > 0) {
                usleep($sleep * 1000);
            }

            $this->check();

            return true;
        }

        return false;
    }

    /**
     * Wait all process idled or timeout
     *
     * @param int $timeout (unit: ms)
     *                      0:  wait all processes to idle
     *                      >0: timeout, kill all process
     */
    public function wait(int $timeout = 0): void
    {
        $start = microtime(true);

        while (true) {
            $this->check();

            $interval = (microtime(true) - $start) * 1000;

            // timeout or all processes idle
            $outed = $timeout > 0 && $interval >= $timeout;
            if ($outed || $this->idleCount <= 0) {
                $killStatus = $this->killAllProcess();
                if ($killStatus) {
                    $this->log('all slave processes exited(' . ($outed ? 'timeout' : 'idle') . ')');
                    return;
                }
            }

            usleep(10000);
        }
    }

    /**
     * Kill all worker process
     *
     * @return bool
     */
    private function killAllProcess(): bool
    {
        $killStatus = true;

        foreach ($this->procPool as $process) {
            $status = $this->killProcess($process);

            if ($status) {
                $this->log('kill worker success: ' . $process['pid']);
                !$process['idle'] && $this->idleCount--;
            } else {
                $this->log('kill worker failed: ' . $process['pid']);
                $killStatus = false;
            }
        }

        return $killStatus;
    }

    /**
     * @param int $procNum
     *
     * @return TaskManager
     */
    public function setProcNum(int $procNum): self
    {
        Assert::intShouldGt0($procNum);
        $this->procNum = $procNum;
        return $this;
    }

    /**
     * @param string $name
     *
     * @return TaskManager
     */
    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }
}