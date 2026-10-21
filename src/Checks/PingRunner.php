<?php

declare(strict_types=1);

namespace Nordvarr\Checks;

use Nordvarr\Time;

/**
 * Kontroly ping přes systémový příkaz ping, až 20 procesů současně.
 * Chybějící nebo nepoužitelný ping dává stav unknown s důvodem tool_missing.
 */
final class PingRunner
{
    public const MAX_PARALLEL = 20;

    public function __construct(private readonly string $binary = 'ping')
    {
    }

    /**
     * @param list<array<string, mixed>> $checks
     * @param callable(string, CheckResult): void $onResult
     */
    public function run(array $checks, callable $onResult, float $deadline = INF): void
    {
        $binary = $this->resolveBinary();
        $queue = $checks;
        /** @var list<array{check: array<string, mixed>, process: resource, pipes: array<int, resource>, started: string, start: float, limit: float, out: string}> $running */
        $running = [];

        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && count($running) < self::MAX_PARALLEL && microtime(true) < $deadline) {
                $check = array_shift($queue);
                $started = Time::toDb(Time::now());
                if ($binary === null) {
                    $onResult((string) $check['id'], new CheckResult(Status::UNKNOWN, Reason::TOOL_MISSING, 'Příkaz ping není k dispozici', null, $started, $started));
                    continue;
                }
                $timeout = max(1, (int) $check['timeout_s']);
                $process = proc_open(
                    [$binary, '-n', '-c', '1', '-W', (string) $timeout, (string) $check['config']['host']],
                    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes
                );
                if (!is_resource($process)) {
                    $onResult((string) $check['id'], new CheckResult(Status::UNKNOWN, Reason::TOOL_MISSING, 'Příkaz ping nelze spustit', null, $started, $started));
                    continue;
                }
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $running[] = ['check' => $check, 'process' => $process, 'pipes' => $pipes, 'started' => $started,
                    'start' => microtime(true), 'limit' => $timeout + 2.0, 'out' => ''];
            }
            if ($running === []) {
                break;
            }
            usleep(20000);
            foreach ($running as $i => &$job) {
                $job['out'] .= (string) stream_get_contents($job['pipes'][1]) . (string) stream_get_contents($job['pipes'][2]);
                $status = proc_get_status($job['process']);
                $elapsed = microtime(true) - $job['start'];
                if ($status['running'] && $elapsed < $job['limit']) {
                    continue;
                }
                if ($status['running']) {
                    proc_terminate($job['process'], 9);
                    $exit = -1;
                } else {
                    $exit = (int) $status['exitcode'];
                }
                $job['out'] .= (string) stream_get_contents($job['pipes'][1]) . (string) stream_get_contents($job['pipes'][2]);
                fclose($job['pipes'][1]);
                fclose($job['pipes'][2]);
                proc_close($job['process']);
                $onResult((string) $job['check']['id'], self::evaluate($exit, $job['out'], $job['started'], Time::toDb(Time::now())));
                unset($running[$i]);
            }
            unset($job);
            $running = array_values($running);
        }
    }

    /**
     * Vyhodnocení výstupu příkazu ping (iputils i busybox).
     */
    public static function evaluate(int $exit, string $output, string $started, string $finished): CheckResult
    {
        if ($exit === 0) {
            $latency = preg_match('/time[=<]\s*([\d.]+)\s*ms/', $output, $m) === 1 ? (int) round((float) $m[1]) : null;

            return CheckResult::healthy($started, $finished, $latency, $latency === null ? 'odpověď přijata' : "odpověď za $latency ms");
        }
        $lower = strtolower($output);
        if (str_contains($lower, 'not permitted') || str_contains($lower, 'permission denied') || str_contains($lower, 'socket:')) {
            return new CheckResult(Status::UNKNOWN, Reason::TOOL_MISSING, 'ping nemá oprávnění: ' . trim($output), null, $started, $finished);
        }
        if (str_contains($lower, 'name or service not known') || str_contains($lower, 'unknown host')
            || str_contains($lower, 'temporary failure in name resolution') || str_contains($lower, 'bad address')) {
            return new CheckResult(Status::UNHEALTHY, Reason::DNS_FAILURE, trim($output), null, $started, $finished);
        }
        if ($exit === 1 || $exit === -1) {
            return new CheckResult(Status::UNHEALTHY, Reason::CHECK_TIMEOUT, 'bez odpovědi', null, $started, $finished);
        }

        return new CheckResult(Status::UNKNOWN, Reason::UNKNOWN_ERROR, 'ping skončil s kódem ' . $exit . ': ' . trim($output), null, $started, $finished);
    }

    private function resolveBinary(): ?string
    {
        if (str_contains($this->binary, '/')) {
            return is_file($this->binary) && is_executable($this->binary) ? $this->binary : null;
        }
        foreach (array_merge(explode(':', (string) getenv('PATH')), ['/usr/bin', '/bin', '/usr/sbin', '/sbin']) as $dir) {
            if ($dir !== '' && is_file($dir . '/' . $this->binary) && is_executable($dir . '/' . $this->binary)) {
                return $dir . '/' . $this->binary;
            }
        }

        return null;
    }
}
