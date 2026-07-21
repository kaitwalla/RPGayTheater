<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CampaignAuthoringResetService;
use Illuminate\Console\Command;

class ResetCampaignAuthoring extends Command
{
    protected $signature = 'campaigns:reset-authoring {--force : Confirm that all campaign content and sessions must be permanently removed}';

    protected $description = 'Permanently remove all campaign authoring data, sessions, and campaign media while preserving users and application configuration';

    public function handle(CampaignAuthoringResetService $reset): int
    {
        if (! $this->option('force')) {
            $this->components->error('Refusing to remove campaign data without --force. Take verified database and object-storage backups first.');

            return self::FAILURE;
        }
        if (! $this->confirm('This permanently deletes every campaign, revision, live session, and campaign media object. Continue?')) {
            $this->components->warn('Campaign authoring reset cancelled.');

            return self::FAILURE;
        }

        $result = $reset->reset();
        $this->components->info("Removed {$result['campaigns']} campaigns and {$result['assets']} media records.");
        if ($result['failed_objects'] > 0) {
            $this->components->warn("{$result['failed_objects']} media objects could not be removed and need storage cleanup.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
