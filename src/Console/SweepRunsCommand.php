<?php

namespace Whilesmart\Runs\Console;

use Illuminate\Console\Command;
use Whilesmart\Runs\Services\RunService;

class SweepRunsCommand extends Command
{
    protected $signature = 'runs:sweep';

    protected $description = 'Expire abandoned run attempts and time out overdue runs';

    public function handle(RunService $runs): int
    {
        $swept = $runs->sweep();
        $this->info("Expired {$swept['expired_attempts']} attempts, resumed {$swept['resumed_runs']} runs, timed out {$swept['timed_out_runs']} runs, dispatched {$swept['dispatched_runs']} runs.");

        return self::SUCCESS;
    }
}
