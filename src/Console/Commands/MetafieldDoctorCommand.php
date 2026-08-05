<?php

declare(strict_types=1);

namespace Nvl\Metafields\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Metafields\Data\MetafieldDoctorCheckData;
use Nvl\Metafields\Services\MetafieldDoctor;

/**
 * Reports Metafields installation and adoption readiness without mutation.
 */
final class MetafieldDoctorCommand extends Command
{
    protected $signature = 'nvl:metafields:doctor
        {--strict : Treat warnings as failures}
        {--format=text : Output format: text or json}';

    protected $description = 'Inspect Metafields schema, registries, routes, and authorization bindings';

    public function handle(MetafieldDoctor $doctor): int
    {
        $checks = $doctor->inspect();

        if ($this->option('format') === 'json') {
            $this->line((string) json_encode([
                'package' => 'nvl/metafields',
                'healthy' => ! $this->hasFailures($checks),
                'checks' => array_map(
                    static fn (MetafieldDoctorCheckData $check): array => $check->toArray(),
                    $checks,
                ),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(
                ['Check', 'Severity', 'Result', 'Message'],
                array_map(
                    static fn (MetafieldDoctorCheckData $check): array => [
                        $check->key,
                        $check->severity,
                        $check->passed ? 'PASS' : 'FAIL',
                        $check->message,
                    ],
                    $checks,
                ),
            );
        }

        return $this->hasFailures($checks) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<MetafieldDoctorCheckData>  $checks
     */
    private function hasFailures(array $checks): bool
    {
        $strict = (bool) $this->option('strict');

        foreach ($checks as $check) {
            if (! $check->passed && ($check->severity === 'error' || $strict)) {
                return true;
            }
        }

        return false;
    }
}
