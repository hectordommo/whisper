<?php

namespace App\Console\Commands;

use App\Jobs\FinalizeTranscript;
use App\Jobs\ProcessAudioChunk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MonitorQueueCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:monitor
                            {--watch : Continuously monitor the queue}
                            {--interval=5 : Refresh interval in seconds when watching}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor queue jobs and their status';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $watch = $this->option('watch');
        $interval = (int) $this->option('interval');

        do {
            if ($watch) {
                // Clear screen for better readability
                $this->output->write("\033[2J\033[;H");
            }

            $this->displayQueueStats();
            $this->newLine();
            $this->displayRecentJobs();
            $this->newLine();
            $this->displayFailedJobs();

            if ($watch) {
                $this->newLine();
                $this->info("Refreshing every {$interval} seconds... (Ctrl+C to stop)");
                sleep($interval);
            }
        } while ($watch);

        return self::SUCCESS;
    }

    /**
     * Display overall queue statistics
     */
    protected function displayQueueStats(): void
    {
        $this->info('=== Queue Statistics ===');

        // Pending jobs
        $pendingJobs = DB::table('jobs')->count();
        $this->line("Pending Jobs: <fg=yellow>{$pendingJobs}</>");

        // Failed jobs
        $failedJobs = DB::table('failed_jobs')->count();
        $failedColor = $failedJobs > 0 ? 'red' : 'green';
        $this->line("Failed Jobs: <fg={$failedColor}>{$failedJobs}</>");

        // Recent job types
        $recentJobTypes = DB::table('jobs')
            ->select(DB::raw('payload, count(*) as count'))
            ->groupBy('payload')
            ->get()
            ->map(function ($job) {
                $payload = json_decode($job->payload, true);
                $displayName = $payload['displayName'] ?? 'Unknown';
                return "{$displayName}: {$job->count}";
            });

        if ($recentJobTypes->isNotEmpty()) {
            $this->newLine();
            $this->line('Job Types in Queue:');
            foreach ($recentJobTypes as $jobType) {
                $this->line("  - {$jobType}");
            }
        }
    }

    /**
     * Display recent jobs from the queue
     */
    protected function displayRecentJobs(): void
    {
        $this->info('=== Recent Jobs (Last 10) ===');

        $jobs = DB::table('jobs')
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get();

        if ($jobs->isEmpty()) {
            $this->line('<fg=green>No jobs in queue</>');
            return;
        }

        $tableData = $jobs->map(function ($job) {
            $payload = json_decode($job->payload, true);
            $displayName = $payload['displayName'] ?? 'Unknown';

            return [
                'ID' => $job->id,
                'Job' => $displayName,
                'Queue' => $job->queue,
                'Attempts' => $job->attempts,
                'Created' => date('Y-m-d H:i:s', $job->created_at),
            ];
        })->toArray();

        $this->table(
            ['ID', 'Job', 'Queue', 'Attempts', 'Created'],
            $tableData
        );
    }

    /**
     * Display failed jobs
     */
    protected function displayFailedJobs(): void
    {
        $this->info('=== Failed Jobs (Last 10) ===');

        $failedJobs = DB::table('failed_jobs')
            ->orderBy('failed_at', 'desc')
            ->limit(10)
            ->get();

        if ($failedJobs->isEmpty()) {
            $this->line('<fg=green>No failed jobs</>');
            return;
        }

        $tableData = $failedJobs->map(function ($job) {
            $payload = json_decode($job->payload, true);
            $displayName = $payload['displayName'] ?? 'Unknown';

            // Truncate exception for display
            $exception = substr($job->exception, 0, 100);
            if (strlen($job->exception) > 100) {
                $exception .= '...';
            }

            return [
                'ID' => $job->id,
                'Job' => $displayName,
                'Queue' => $job->queue,
                'Failed At' => $job->failed_at,
                'Error' => $exception,
            ];
        })->toArray();

        $this->table(
            ['ID', 'Job', 'Queue', 'Failed At', 'Error'],
            $tableData
        );

        $this->newLine();
        $this->comment('Use "php artisan queue:retry {id}" to retry a failed job');
        $this->comment('Use "php artisan queue:retry all" to retry all failed jobs');
        $this->comment('Use "php artisan queue:flush" to clear all failed jobs');
    }
}
