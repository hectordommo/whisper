<?php

namespace App\Console\Commands;

use App\Models\AudioChunk;
use App\Models\DictationSession;
use App\Models\Transcript;
use App\Services\ClaudeService;
use App\Services\WhisperService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DebugTranscriptionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transcribe:debug
                            {--session= : Specific session ID to debug}
                            {--detailed : Show detailed information}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Debug transcription system and check configuration';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('=== Transcription System Debug ===');
        $this->newLine();

        $this->checkStoragePermissions();
        $this->newLine();

        $this->checkApiKeys();
        $this->newLine();

        $this->checkDatabaseConnection();
        $this->newLine();

        $this->checkQueueStatus();
        $this->newLine();

        if ($sessionId = $this->option('session')) {
            $this->debugSession($sessionId);
        } else {
            $this->checkRecentSessions();
        }

        return self::SUCCESS;
    }

    /**
     * Check storage directory permissions
     */
    protected function checkStoragePermissions(): void
    {
        $this->info('Checking Storage Permissions...');

        $storagePath = storage_path('app');
        $audioChunksPath = storage_path('app/audio-chunks');

        // Check main storage directory
        if (is_writable($storagePath)) {
            $this->line("<fg=green>✓</> Storage directory is writable: {$storagePath}");
        } else {
            $this->line("<fg=red>✗</> Storage directory is NOT writable: {$storagePath}");
            $this->warn('Run: chmod -R 755 ' . $storagePath);
        }

        // Check audio-chunks directory
        if (!is_dir($audioChunksPath)) {
            $this->line("<fg=yellow>!</> Audio chunks directory does not exist: {$audioChunksPath}");
            $this->info('Creating directory...');
            mkdir($audioChunksPath, 0755, true);
            $this->line("<fg=green>✓</> Created audio chunks directory");
        } elseif (is_writable($audioChunksPath)) {
            $this->line("<fg=green>✓</> Audio chunks directory is writable: {$audioChunksPath}");
        } else {
            $this->line("<fg=red>✗</> Audio chunks directory is NOT writable: {$audioChunksPath}");
            $this->warn('Run: chmod -R 755 ' . $audioChunksPath);
        }

        // Check disk space
        $freeSpace = disk_free_space($storagePath);
        $freeSpaceMB = round($freeSpace / 1024 / 1024, 2);
        $this->line("Free disk space: {$freeSpaceMB} MB");

        if ($freeSpaceMB < 100) {
            $this->warn('Low disk space! Less than 100MB available');
        }
    }

    /**
     * Check API keys configuration
     */
    protected function checkApiKeys(): void
    {
        $this->info('Checking API Keys...');

        // Check OpenAI API key
        $openaiKey = config('services.openai.api_key');
        if ($openaiKey) {
            $this->line('<fg=green>✓</> OpenAI API key is configured');

            if ($this->option('detailed')) {
                $maskedKey = substr($openaiKey, 0, 8) . '...' . substr($openaiKey, -4);
                $this->line("  Key: {$maskedKey}");
            }

            // Validate the key
            try {
                $whisperService = new WhisperService($openaiKey);
                if ($whisperService->validateApiKey()) {
                    $this->line('<fg=green>✓</> OpenAI API key is valid');
                } else {
                    $this->line('<fg=red>✗</> OpenAI API key is INVALID');
                }
            } catch (\Exception $e) {
                $this->line("<fg=yellow>!</> Could not validate OpenAI API key: {$e->getMessage()}");
            }
        } else {
            $this->line('<fg=red>✗</> OpenAI API key is NOT configured');
            $this->warn('Set OPENAI_API_KEY in your .env file');
        }

        // Check Anthropic API key
        $anthropicKey = config('services.anthropic.api_key');
        if ($anthropicKey) {
            $this->line('<fg=green>✓</> Anthropic API key is configured');

            if ($this->option('detailed')) {
                $maskedKey = substr($anthropicKey, 0, 8) . '...' . substr($anthropicKey, -4);
                $this->line("  Key: {$maskedKey}");
            }

            // Validate the key
            try {
                $claudeService = new ClaudeService($anthropicKey);
                if ($claudeService->validateApiKey()) {
                    $this->line('<fg=green>✓</> Anthropic API key is valid');
                } else {
                    $this->line('<fg=red>✗</> Anthropic API key is INVALID');
                }
            } catch (\Exception $e) {
                $this->line("<fg=yellow>!</> Could not validate Anthropic API key: {$e->getMessage()}");
            }
        } else {
            $this->line('<fg=red>✗</> Anthropic API key is NOT configured');
            $this->warn('Set ANTHROPIC_API_KEY in your .env file');
        }
    }

    /**
     * Check database connection
     */
    protected function checkDatabaseConnection(): void
    {
        $this->info('Checking Database Connection...');

        try {
            DB::connection()->getPdo();
            $this->line('<fg=green>✓</> Database connection successful');

            // Check tables exist
            $tables = ['dictation_sessions', 'audio_chunks', 'transcripts', 'jobs', 'failed_jobs'];
            foreach ($tables as $table) {
                if (DB::getSchemaBuilder()->hasTable($table)) {
                    $this->line("<fg=green>✓</> Table exists: {$table}");
                } else {
                    $this->line("<fg=red>✗</> Table missing: {$table}");
                    $this->warn('Run: php artisan migrate');
                }
            }
        } catch (\Exception $e) {
            $this->line('<fg=red>✗</> Database connection failed');
            $this->error($e->getMessage());
        }
    }

    /**
     * Check queue worker status
     */
    protected function checkQueueStatus(): void
    {
        $this->info('Checking Queue Status...');

        $pendingJobs = DB::table('jobs')->count();
        $failedJobs = DB::table('failed_jobs')->count();

        $this->line("Pending jobs: {$pendingJobs}");
        $this->line("Failed jobs: {$failedJobs}");

        if ($failedJobs > 0) {
            $this->warn("You have {$failedJobs} failed jobs. Run 'php artisan queue:monitor' for details");
        }

        // Check if queue worker might be running
        $this->newLine();
        $this->comment('To start the queue worker, run:');
        $this->line('  php artisan queue:work');
        $this->comment('Or in watch mode:');
        $this->line('  php artisan queue:work --tries=3');
    }

    /**
     * Check recent sessions
     */
    protected function checkRecentSessions(): void
    {
        $this->info('Recent Sessions (Last 5)...');

        $sessions = DictationSession::with(['audioChunks', 'transcripts'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        if ($sessions->isEmpty()) {
            $this->line('<fg=yellow>No sessions found</>');
            return;
        }

        foreach ($sessions as $session) {
            $chunksCount = $session->audioChunks->count();
            $transcriptsCount = $session->transcripts->count();

            $statusColor = match($session->status) {
                'ready' => 'green',
                'processing' => 'yellow',
                'recording' => 'blue',
                default => 'white',
            };

            $this->newLine();
            $this->line("Session ID: {$session->id}");
            $this->line("  Title: {$session->title}");
            $this->line("  Status: <fg={$statusColor}>{$session->status}</>");
            $this->line("  Audio Chunks: {$chunksCount}");
            $this->line("  Transcripts: {$transcriptsCount}");
            $this->line("  Created: {$session->created_at}");

            // Check for issues
            if ($chunksCount > 0 && $transcriptsCount === 0) {
                $this->warn("  ⚠ Has audio chunks but no transcripts! Run: php artisan transcribe:debug --session={$session->id}");
            }

            if ($this->option('detailed')) {
                // Check if audio files exist
                foreach ($session->audioChunks as $chunk) {
                    $path = storage_path('app/audio-chunks/' . $chunk->filename);
                    if (file_exists($path)) {
                        $size = filesize($path);
                        $this->line("    Chunk {$chunk->id}: ✓ ({$size} bytes)");
                    } else {
                        $this->line("    Chunk {$chunk->id}: <fg=red>✗ File missing</>");
                    }
                }
            }
        }
    }

    /**
     * Debug a specific session
     */
    protected function debugSession(int $sessionId): void
    {
        $this->info("Debugging Session {$sessionId}...");

        $session = DictationSession::with(['audioChunks', 'transcripts'])->find($sessionId);

        if (!$session) {
            $this->error("Session {$sessionId} not found");
            return;
        }

        $this->newLine();
        $this->line("Title: {$session->title}");
        $this->line("Status: {$session->status}");
        $this->line("User ID: {$session->user_id}");
        $this->line("Created: {$session->created_at}");
        $this->line("Updated: {$session->updated_at}");

        $this->newLine();
        $this->info('Audio Chunks:');
        foreach ($session->audioChunks as $chunk) {
            $path = storage_path('app/audio-chunks/' . $chunk->filename);
            $exists = file_exists($path) ? '✓' : '✗';
            $size = file_exists($path) ? filesize($path) : 0;

            $this->line("  [{$exists}] Chunk {$chunk->id}");
            $this->line("      Filename: {$chunk->filename}");
            $this->line("      Time: {$chunk->start_time}s - {$chunk->end_time}s");
            $this->line("      Size: {$size} bytes");
            $this->line("      Uploaded: {$chunk->uploaded_at}");
        }

        $this->newLine();
        $this->info('Transcripts:');
        if ($session->transcripts->isEmpty()) {
            $this->line('  <fg=yellow>No transcripts found</>');

            // Check if jobs are pending
            $pendingJobs = DB::table('jobs')
                ->where('payload', 'like', '%ProcessAudioChunk%')
                ->count();

            if ($pendingJobs > 0) {
                $this->warn("  There are {$pendingJobs} pending transcription jobs. Make sure queue worker is running.");
            }
        } else {
            foreach ($session->transcripts as $transcript) {
                $this->line("  Transcript {$transcript->id}");
                $this->line("      Type: {$transcript->type}");
                $this->line("      Text: " . substr($transcript->text, 0, 100) . '...');
                $this->line("      Created: {$transcript->created_at}");

                if ($this->option('verbose') && !empty($transcript->meta['words'])) {
                    $wordCount = count($transcript->meta['words']);
                    $this->line("      Words: {$wordCount}");
                }
            }
        }
    }
}
