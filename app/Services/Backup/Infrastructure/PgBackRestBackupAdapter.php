<?php

namespace App\Services\Backup\Infrastructure;

use App\Services\Backup\BackupStatusService;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use LogicException;
use Throwable;

/**
 * Runs a fixed set of read-only pgBackRest commands as argument vectors (no shell). The binary,
 * stanza, config path and optional run-as account come only from server configuration and are
 * validated before any process starts.
 */
final class PgBackRestBackupAdapter implements BackupInfrastructureAdapter
{
    public const STANZA = '/\A[A-Za-z0-9][A-Za-z0-9_-]{0,62}\z/';

    private const OS_USER = '/\A[a-z_][a-z0-9_-]{0,31}\z/';

    // Keep stdout pure JSON, never write log files as the web account, and put errors on stderr.
    private const LOGGING = ['--log-level-console=off', '--log-level-stderr=warn', '--log-level-file=off'];

    // Inherited variables that processes need to start; everything else (APP_KEY, DB_PASSWORD and any
    // PGBACKREST_* value, which pgBackRest would read as an option) is removed from the child environment.
    private const ENVIRONMENT = ['PATH', 'SYSTEMROOT', 'WINDIR', 'TEMP', 'TMP', 'TMPDIR'];

    public function __construct(private PgBackRestInfoParser $parser) {}

    public function driver(): string
    {
        return 'pgbackrest';
    }

    public function isConfigured(): bool
    {
        return (bool) config('backup.enabled');
    }

    public function version(): ?string
    {
        if ($this->preflight() !== null) {
            return null;
        }
        $result = $this->run(['version'], $this->timeout('timeout_seconds'));

        return $result['reason'] === null ? $this->parseVersion($result['stdout']) : null;
    }

    public function inspect(): InfrastructureSnapshot
    {
        if ($failure = $this->preflight()) {
            return $failure;
        }
        $stanza = (string) config('backup.pgbackrest.stanza');
        $version = $this->run(['version'], $this->timeout('timeout_seconds'));
        if ($version['reason'] !== null || ($number = $this->parseVersion($version['stdout'])) === null) {
            return InfrastructureSnapshot::failure($version['reason'] ?? 'INVALID_COMMAND_OUTPUT', $this->driver(), $stanza, 'FOUND',
                $this->diagnostics('version', $version, $version['reason'] === null ? 'UNRECOGNIZED_VERSION' : 'NOT_RUN'));
        }
        $info = $this->run(['--stanza='.$stanza, '--output=json', ...self::LOGGING, 'info'], $this->timeout('timeout_seconds'));
        if ($info['reason'] !== null) {
            return InfrastructureSnapshot::failure($info['reason'], $this->driver(), $stanza, 'FOUND', $this->diagnostics('info', $info, 'NOT_RUN'));
        }
        try {
            $repositories = $this->parser->parse($info['stdout'], $stanza, BackupStatusService::REPOSITORIES);
        } catch (InvalidCommandOutput $e) {
            return InfrastructureSnapshot::failure('INVALID_COMMAND_OUTPUT', $this->driver(), $stanza, 'FOUND', $this->diagnostics('info', $info, $e->parserResult));
        }

        return new InfrastructureSnapshot(InfrastructureSnapshot::AVAILABLE, null, $this->driver(), $stanza, 'FOUND', $number,
            $repositories, $this->diagnostics('info', $info, 'OK'), time());
    }

    public function check(): array
    {
        if (! app()->runningInConsole()) {
            throw new LogicException('pgBackRest check is an operator action.');
        }
        if ($failure = $this->preflight()) {
            return ['status' => 'NOT_RUN', 'reason_code' => $failure->reasonCode, 'diagnostics' => $failure->diagnostics];
        }
        $result = $this->run(['--stanza='.config('backup.pgbackrest.stanza'), ...self::LOGGING, 'check'], $this->timeout('check_timeout_seconds'));

        return ['status' => $result['reason'] === null ? 'PASSED' : 'FAILED', 'reason_code' => $result['reason'],
            'diagnostics' => $this->diagnostics('check', $result, 'NOT_APPLICABLE')];
    }

    /** Configuration and binary checks that need no process. */
    private function preflight(): ?InfrastructureSnapshot
    {
        $binary = config('backup.pgbackrest.binary');
        $stanza = config('backup.pgbackrest.stanza');
        $config = config('backup.pgbackrest.config');
        $runAs = config('backup.pgbackrest.run_as');
        $sudo = config('backup.pgbackrest.sudo_binary');
        $safeStanza = is_string($stanza) && preg_match(self::STANZA, $stanza) ? $stanza : null;
        $diagnostics = ['platform' => PHP_OS_FAMILY, 'run_as' => $runAs ? 'CONFIGURED' : 'NONE', 'config_file' => $this->configState($config, $runAs)];
        $fail = fn (string $reason, ?string $binaryState, string $problem) => InfrastructureSnapshot::failure($reason, $this->driver(), $safeStanza, $binaryState,
            [...$diagnostics, 'preflight' => $problem]);

        if ($safeStanza === null) {
            return $fail('INVALID_CONFIGURATION', 'NOT_CHECKED', 'INVALID_STANZA');
        }
        if (! $this->isAbsolute($binary) || ($config !== null && $config !== '' && ! $this->isAbsolute($config))) {
            return $fail('INVALID_CONFIGURATION', 'NOT_CHECKED', 'RELATIVE_OR_INVALID_PATH');
        }
        if ($runAs !== null && $runAs !== '' && (! is_string($runAs) || ! preg_match(self::OS_USER, $runAs) || ! $this->isAbsolute($sudo))) {
            return $fail('INVALID_CONFIGURATION', 'NOT_CHECKED', 'INVALID_RUN_AS');
        }
        if (! is_file($binary)) {
            return $fail('COMMAND_NOT_FOUND', 'NOT_FOUND', 'BINARY_NOT_FOUND');
        }
        if ($runAs) {
            if (! is_file($sudo)) {
                return $fail('COMMAND_NOT_FOUND', 'FOUND', 'SUDO_NOT_FOUND');
            }
        } elseif (! is_executable($binary)) {
            return $fail('PERMISSION_DENIED', 'NOT_EXECUTABLE', 'BINARY_NOT_EXECUTABLE');
        }
        if ($diagnostics['config_file'] === 'MISSING') {
            return $fail('CONFIG_NOT_FOUND', 'FOUND', 'CONFIG_NOT_FOUND');
        }
        if ($diagnostics['config_file'] === 'UNREADABLE') {
            return $fail('PERMISSION_DENIED', 'FOUND', 'CONFIG_UNREADABLE');
        }

        return null;
    }

    /** @return array{exit: ?int, stdout: string, stderr: string, reason: ?string} */
    private function run(array $arguments, int $timeout): array
    {
        $binary = (string) config('backup.pgbackrest.binary');
        $config = config('backup.pgbackrest.config');
        $runAs = config('backup.pgbackrest.run_as');
        $command = [$binary, ...($config ? ['--config='.$config] : []), ...$arguments];
        if ($runAs) {
            // sudo -n never prompts; the sudoers rule must allow exactly this argument vector.
            $command = [(string) config('backup.pgbackrest.sudo_binary'), '-n', '-u', $runAs, ...$command];
        }
        try {
            $process = Process::timeout($timeout)->env($this->environment())->run($command);
        } catch (ProcessTimedOutException) {
            return ['exit' => null, 'stdout' => '', 'stderr' => '', 'reason' => 'COMMAND_TIMEOUT'];
        } catch (Throwable) {
            return ['exit' => null, 'stdout' => '', 'stderr' => '', 'reason' => 'COMMAND_NOT_FOUND'];
        }
        $exit = $process->exitCode();

        return ['exit' => $exit, 'stdout' => $process->output(), 'stderr' => $process->errorOutput(),
            'reason' => $exit === 0 ? null : $this->classify((int) $exit, $process->errorOutput())];
    }

    /** Maps a failed run to a reason code. The error text is inspected here and never returned. */
    private function classify(int $exit, string $stderr): string
    {
        $text = strtolower($stderr);
        $has = fn (string ...$needles) => array_filter($needles, fn ($needle) => str_contains($text, $needle)) !== [];

        return match (true) {
            $exit === 127 => 'COMMAND_NOT_FOUND',
            $exit === 126, $has('permission denied', 'a password is required', 'not allowed to execute', 'is not in the sudoers', 'may not run sudo') => 'PERMISSION_DENIED',
            $has('stanza-create'), $has('stanza') && $has('does not exist') => 'STANZA_NOT_FOUND',
            $has('unable to open missing file') && $has('.conf') => 'CONFIG_NOT_FOUND',
            in_array($exit, [27, 31, 32, 33, 34, 35, 36, 37, 61], true), $has('invalid option', 'invalid value', 'requires option') => 'INVALID_CONFIGURATION',
            $has('dbname=') => 'DATABASE_UNAVAILABLE',
            $exit === 49, $has('unable to connect', 'could not resolve', 'connection refused', 'no route to host', 'host key verification failed') => 'REPOSITORY_UNAVAILABLE',
            $has('archive') => 'WAL_ARCHIVE_UNHEALTHY',
            default => 'COMMAND_FAILED',
        };
    }

    private function diagnostics(string $command, array $result, string $parser): array
    {
        $summary = 'NONE';
        if (preg_match('/ERROR: \[(\d{3})\]/', $result['stderr'], $match)) {
            $summary = 'PGBACKREST_ERROR_'.$match[1];
        } elseif (str_contains(strtolower($result['stderr']), 'sudo')) {
            $summary = 'SUDO_REFUSED';
        } elseif (trim($result['stderr']) !== '') {
            $summary = 'PRESENT';
        }

        return ['platform' => PHP_OS_FAMILY, 'run_as' => config('backup.pgbackrest.run_as') ? 'CONFIGURED' : 'NONE',
            'config_file' => $this->configState(config('backup.pgbackrest.config'), config('backup.pgbackrest.run_as')),
            'command' => $command, 'exit_code' => $result['exit'], 'stdout_present' => trim($result['stdout']) !== '',
            'stderr_present' => trim($result['stderr']) !== '', 'stderr_summary' => $summary, 'parser' => $parser];
    }

    private function environment(): array
    {
        $environment = [];
        foreach (array_keys(getenv() + $_ENV) as $name) {
            if (! in_array(strtoupper((string) $name), self::ENVIRONMENT, true)) {
                $environment[$name] = false;
            }
        }

        return array_merge($environment, ['LC_ALL' => 'C', 'LANG' => 'C']);
    }

    private function parseVersion(string $stdout): ?string
    {
        return preg_match('/\ApgBackRest (\d+\.\d+(?:\.\d+)?)\S*\s*\z/', $stdout, $match) ? $match[1] : null;
    }

    private function configState(mixed $config, mixed $runAs): string
    {
        return match (true) {
            $config === null || $config === '' => 'NOT_SET',
            (bool) $runAs => 'NOT_CHECKED',
            ! is_string($config) || ! $this->isAbsolute($config) => 'INVALID',
            ! is_file($config) => 'MISSING',
            ! is_readable($config) => 'UNREADABLE',
            default => 'PRESENT',
        };
    }

    private function isAbsolute(mixed $path): bool
    {
        return is_string($path) && (bool) preg_match('#\A(?:/|[A-Za-z]:[\\\\/])[^\x00-\x1f]*\z#', $path);
    }

    private function timeout(string $key): int
    {
        return max(1, (int) config('backup.pgbackrest.'.$key));
    }
}
