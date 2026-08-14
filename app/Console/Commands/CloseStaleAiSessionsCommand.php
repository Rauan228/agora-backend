<?php

namespace App\Console\Commands;

use App\Services\Ai\AiMatchingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agora:close-stale-sessions {--hours=24}')]
#[Description('Close active AI chats with no messages for N hours')]
class CloseStaleAiSessionsCommand extends Command
{
    public function handle(AiMatchingService $ai): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $n = $ai->closeStaleActive($hours);
        $this->info("Closed {$n} stale active session(s) (idle ≥ {$hours}h).");

        return self::SUCCESS;
    }
}
