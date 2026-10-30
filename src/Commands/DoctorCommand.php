<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Commands;

use Anthropic\Client;
use Anthropic\Contracts\ClientContract;
use Anthropic\Exceptions\ErrorException;
use Anthropic\Exceptions\NotFoundException;
use Anthropic\Laravel\AnthropicManager;
use Anthropic\Laravel\ClientBuilder;
use Anthropic\Laravel\Exceptions\ApiKeyIsMissing;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Checks a connection's setup: its credential, one API call that spends no tokens, the queues' `retry_after` and
 * Horizon's `timeout` against how long a call can take, Livewire streaming under Octane, and the fallback models'
 * lifecycle. Any failed check exits non-zero, so CI can run it.
 */
class DoctorCommand extends Command
{
    protected $signature = 'anthropic:doctor
        {--connection= : The connection to check, the default one without it}
        {--offline : Skip the checks that call the API}';

    protected $description = 'Checks the Anthropic configuration and the connection to the API.';

    private bool $failed = false;

    public function handle(AnthropicManager $manager): int
    {
        // The console keeps one instance, so a second Artisan::call() mustn't inherit the first one's failures.
        $this->failed = false;
        $name = $this->option('connection');
        $name = is_string($name) && $name !== '' ? $name : AnthropicManager::DEFAULT;
        $offline = $this->option('offline') === true;
        $config = $manager->getInstanceConfig($name);

        $this->components->info("Checking the [{$name}] connection.");

        if ($config === null) {
            $this->components->error("The [{$name}] connection isn't in config/anthropic.php.");

            return self::FAILURE;
        }

        $client = $this->client($manager, $name, $config, $offline);

        if ($client === false) {
            return self::FAILURE;
        }

        $reachable = false;

        if ($client !== null) {
            $this->settings($client);
            $reachable = ! $offline && $this->api($client);
        }

        $this->timeouts($config);
        $this->streaming();

        // After a failed API check, each model would only fail the same way.
        if ($client !== null && $reachable) {
            $this->fallbackModels($client, $config);
        }

        $this->newLine();

        if ($this->failed) {
            $this->components->error('Some checks failed.');

            return self::FAILURE;
        }

        $this->components->info('Everything checks out.');

        return self::SUCCESS;
    }

    /**
     * The connection's client; null when offline without a credential, so the checks that need none still run; false
     * when it can't be built.
     *
     * @param  array<array-key, mixed>  $config
     */
    private function client(AnthropicManager $manager, string $name, array $config, bool $offline): ClientContract|false|null
    {
        // An empty `ANTHROPIC_API_KEY=` line is no credential, though the default connection builds without one.
        $settings = $name === AnthropicManager::DEFAULT ? ClientBuilder::withLaravelAi($config) : $config;

        if (! ClientBuilder::hasCredential($settings)) {
            $message = ($name === AnthropicManager::DEFAULT ? ApiKeyIsMissing::create() : ApiKeyIsMissing::forConnection($name))->getMessage();

            if ($offline) {
                $this->warning('Credential', $message);

                return null;
            }

            $this->failure('Credential', $message);

            return false;
        }

        try {
            $client = $manager->connection($name);
        } catch (Throwable $e) {
            // A config value of the wrong type, or a middleware that isn't one.
            $this->failure('Configuration', $e->getMessage());

            return false;
        }

        $this->passed('Credential', 'set');

        return $client;
    }

    /**
     * What the client sends, read back from it.
     */
    private function settings(ClientContract $client): void
    {
        if (! $client instanceof Client) {
            return;
        }

        $settings = $client->settings();

        $this->components->twoColumnDetail('Base URL', $settings->baseUri);
        $this->components->twoColumnDetail('API version', $settings->apiVersion);
        $this->components->twoColumnDetail('Betas', $settings->betas === [] ? 'none' : implode(', ', $settings->betas));
    }

    /**
     * One call that spends no tokens: the first page of the models list.
     */
    private function api(ClientContract $client): bool
    {
        try {
            $client->models()->list(['limit' => 1]);
        } catch (ErrorException $e) {
            $this->failure('API', trim(($e->getErrorType() ?? 'HTTP '.$e->getStatusCode()).': '.$e->getMessage().' '.($e->getRequestId() ?? '')));

            return false;
        } catch (Throwable $e) {
            // A transport failure, or an answer that isn't the API's, such as a gateway's HTML page.
            $this->failure('API', $e->getMessage());

            return false;
        }

        $this->passed('API', 'reachable');

        return true;
    }

    /**
     * The queues' `retry_after` and Horizon's `timeout` against the longest a non-streamed call can take: every
     * attempt and every fallback hop gets the whole request timeout, and the client backs off between attempts. A job
     * that outlives `retry_after` goes to a second worker and the call is paid for twice. A streamed call's timeout is
     * the longest gap between chunks instead, so only the job's own `$timeout` bounds it.
     *
     * @param  array<array-key, mixed>  $config
     */
    private function timeouts(array $config): void
    {
        $timeout = ClientBuilder::requestTimeout($config);

        if ($timeout <= 0) {
            $this->failure('Request timeout', '0, so a call never times out and no retry_after is safe: set ANTHROPIC_REQUEST_TIMEOUT');

            return;
        }

        $retries = is_numeric($config['max_retries'] ?? null) ? max(0, (int) $config['max_retries']) : 0;
        $models = 1 + count(ClientBuilder::fallbackModels($config));
        // The client's backoff, without the API's own retry-after, which has no cap.
        $backoff = array_sum(array_map(fn (int $retry): float => min(0.5 * 2 ** $retry, 8.0), $retries > 0 ? range(0, $retries - 1) : []));
        $longest = $timeout * ($retries + 1) * $models + $backoff * $models;
        $parts = array_filter([$retries > 0 ? ($retries + 1).' attempts' : null, $models > 1 ? "{$models} models" : null]);
        $detail = $this->seconds($longest);

        if ($parts !== []) {
            $detail .= ' ('.implode(' × ', $parts)." of {$this->seconds($timeout)}".($backoff > 0 ? ', with the waits between them' : '').')';
        }

        $this->components->twoColumnDetail('Longest non-streamed call', $detail);

        $supervisors = $this->horizonSupervisors();

        $this->queues($longest, $supervisors);

        foreach ($supervisors as $supervisor => $settings) {
            $label = "Horizon [{$supervisor}] timeout";
            $supervisorTimeout = is_numeric($settings['timeout'] ?? null) ? (float) $settings['timeout'] : 60.0;

            // A job's own $timeout wins over the supervisor's, so a short one only warns.
            $supervisorTimeout > $longest
                ? $this->passed($label, $this->seconds($supervisorTimeout))
                : $this->warning($label, "{$this->seconds($supervisorTimeout)}: below {$this->seconds($longest)}, so a job that calls Claude needs its own \$timeout above that, or Horizon kills it mid-call");
        }
    }

    /**
     * Each queue connection's `retry_after`. The default connection and those Horizon's supervisors use fail when it's
     * too short; the others, which the app may not use, only warn.
     *
     * @param  array<string, array<array-key, mixed>>  $supervisors
     */
    private function queues(float $longest, array $supervisors): void
    {
        $connections = config('queue.connections', []);
        $connections = is_array($connections) ? $connections : [];
        $default = config('queue.default');
        // A failover connection's members take its jobs.
        $failover = is_string($default) ? ($connections[$default] ?? null) : null;
        $members = is_array($failover) && is_array($failover['connections'] ?? null) ? $failover['connections'] : [];
        $used = [$default, ...array_values($members), ...array_map(fn (array $settings): mixed => $settings['connection'] ?? 'redis', array_values($supervisors))];

        foreach ($connections as $queue => $settings) {
            $settings = is_array($settings) ? $settings : [];
            $driver = $settings['driver'] ?? null;

            if ($driver === 'sqs') {
                if (in_array($queue, $used, true)) {
                    $this->warning("Queue [{$queue}]", "SQS's visibility timeout plays retry_after's part: keep it above {$this->seconds($longest)}");
                }

                continue;
            }

            // The redis and database queues release a job after 60 seconds without a retry_after.
            $retryAfter = $settings['retry_after'] ?? (in_array($driver, ['redis', 'database'], true) ? 60 : null);

            if (! is_numeric($retryAfter)) {
                continue;
            }

            $label = "Queue [{$queue}] retry_after";
            $seconds = $this->seconds((float) $retryAfter);

            match (true) {
                (float) $retryAfter > $longest => $this->passed($label, $seconds),
                in_array($queue, $used, true) => $this->failure($label, "{$seconds}: raise it above {$this->seconds($longest)}, or a job still waiting on Claude runs again and pays twice"),
                default => $this->warning($label, "{$seconds}: below {$this->seconds($longest)}, if a job that calls Claude runs on it"),
            };
        }
    }

    /**
     * The supervisors Horizon runs here, picked as Horizon picks them: the first entry of `environments`, in config
     * order, whose name matches its environment (`horizon.env`, else the app's), over `defaults`. None when no entry
     * matches, without Horizon, or for a supervisor with no processes. `--env` checks another environment's.
     *
     * @return array<string, array<array-key, mixed>>
     */
    private function horizonSupervisors(): array
    {
        if (! class_exists('Laravel\Horizon\Horizon')) {
            return [];
        }

        $defaults = config('horizon.defaults', []);
        $defaults = is_array($defaults) ? $defaults : [];
        $environments = config('horizon.environments', []);
        $environment = config('horizon.env');
        $environment = is_string($environment) ? $environment : app()->environment();
        $current = [];

        foreach (is_array($environments) ? $environments : [] as $pattern => $supervisors) {
            if (Str::is((string) $pattern, $environment)) {
                $current = is_array($supervisors) ? $supervisors : [];

                break;
            }
        }

        $running = [];

        foreach ($current as $supervisor => $settings) {
            $settings = array_replace(is_array($defaults[$supervisor] ?? null) ? $defaults[$supervisor] : [], is_array($settings) ? $settings : []);
            $processes = $settings['maxProcesses'] ?? $settings['processes'] ?? 1;

            if (! is_numeric($processes) || (int) $processes > 0) {
                $running[(string) $supervisor] = $settings;
            }
        }

        return $running;
    }

    /**
     * Livewire's `wire:stream` doesn't work under Octane, which Livewire's docs warn about.
     */
    private function streaming(): void
    {
        if (class_exists('Livewire\Livewire') && class_exists('Laravel\Octane\Octane')) {
            $this->warning('Livewire streaming', "wire:stream doesn't work under Octane: stream from a controller instead");
        }
    }

    /**
     * Each fallback model must still answer: a retired or unknown one fails the check, a deprecated one warns with its
     * date, and says so when that date has passed.
     *
     * @param  array<array-key, mixed>  $config
     */
    private function fallbackModels(ClientContract $client, array $config): void
    {
        foreach (ClientBuilder::fallbackModels($config) as $model) {
            $label = "Fallback model [{$model}]";

            try {
                $retrieved = $client->models()->retrieve($model);
            } catch (NotFoundException) {
                $this->failure($label, 'not a model the API knows');

                continue;
            } catch (Throwable $e) {
                $this->failure($label, $e->getMessage());

                continue;
            }

            $date = $retrieved->retirementDate()?->format('Y-m-d');

            match (true) {
                $retrieved->isRetired() => $this->failure($label, 'retired'),
                $retrieved->isRetirementOverdue() => $this->warning($label, "deprecated, overdue to retire since {$date}: it may stop answering at any time"),
                $retrieved->isRetiring() => $this->warning($label, 'deprecated'.($date === null ? '' : ", retires on {$date}")),
                default => $this->passed($label, $retrieved->lifecycle ?? 'available'),
            };
        }
    }

    private function passed(string $label, string $detail): void
    {
        $this->components->twoColumnDetail($label, "<fg=green;options=bold>OK</> {$detail}");
    }

    private function warning(string $label, string $detail): void
    {
        $this->components->twoColumnDetail($label, "<fg=yellow;options=bold>WARN</> {$detail}");
    }

    private function failure(string $label, string $detail): void
    {
        $this->failed = true;

        $this->components->twoColumnDetail($label, "<fg=red;options=bold>FAIL</> {$detail}");
    }

    private function seconds(float $seconds): string
    {
        return rtrim(rtrim(number_format($seconds, 2, '.', ''), '0'), '.').'s';
    }
}
