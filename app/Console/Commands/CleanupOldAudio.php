<?php

namespace App\Console\Commands;

use App\Models\AudioChunk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupOldAudio extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audio:cleanup {--hours=24 : Delete audio chunks older than this many hours}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete audio chunks older than 24 hours (default) to free up storage space';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $cutoffTime = now()->subHours($hours);

        $this->info("Cleaning up audio chunks older than {$hours} hours (before {$cutoffTime})...");

        $chunks = AudioChunk::where('uploaded_at', '<', $cutoffTime)->get();

        if ($chunks->isEmpty()) {
            $this->info('No old audio chunks found to delete.');
            return Command::SUCCESS;
        }

        $deletedCount = 0;
        $failedCount = 0;
        $freedSpace = 0;

        foreach ($chunks as $chunk) {
            $filePath = 'audio-chunks/' . $chunk->filename;

            try {
                // Check file size before deleting
                if (Storage::exists($filePath)) {
                    $fileSize = Storage::size($filePath);
                    Storage::delete($filePath);
                    $freedSpace += $fileSize;
                    $deletedCount++;
                } else {
                    $this->warn("File not found: {$filePath}");
                }

                // Delete the database record
                $chunk->delete();
            } catch (\Exception $e) {
                $this->error("Failed to delete chunk {$chunk->id}: {$e->getMessage()}");
                $failedCount++;
            }
        }

        $freedSpaceMB = round($freedSpace / 1024 / 1024, 2);

        $this->info("Cleanup complete:");
        $this->info("  - Deleted: {$deletedCount} audio chunks");
        $this->info("  - Failed: {$failedCount} chunks");
        $this->info("  - Freed space: {$freedSpaceMB} MB");

        return Command::SUCCESS;
    }
}
