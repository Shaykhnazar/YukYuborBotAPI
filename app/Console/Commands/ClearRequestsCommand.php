<?php

namespace App\Console\Commands;

use App\Models\Chat;
use App\Models\DeliveryRequest;
use App\Models\Response;
use App\Models\SendRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearRequestsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'requests:clear {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     */
    protected $description = 'Clear all send requests, delivery requests, responses and related chats from the database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (!$this->option('force')) {
            if (!$this->confirm('This will delete ALL send requests, delivery requests, responses and related chats. Are you sure?')) {
                $this->info('Operation cancelled.');
                return 0;
            }
        }

        $this->info('Starting cleanup...');

        try {
            DB::transaction(function () {
                // Get counts before deletion for reporting
                $sendRequestsCount = SendRequest::count();
                $deliveryRequestsCount = DeliveryRequest::count();
                $responsesCount = Response::count();
                $chatsCount = Chat::count();

                $this->info("Found {$sendRequestsCount} send requests");
                $this->info("Found {$deliveryRequestsCount} delivery requests");
                $this->info("Found {$responsesCount} responses");
                $this->info("Found {$chatsCount} chats");

                // Delete in proper order to respect foreign key constraints
                $this->info('Deleting chats...');
                Chat::truncate();

                $this->info('Deleting responses...');
                Response::truncate();

                $this->info('Deleting send requests...');
                SendRequest::truncate();

                $this->info('Deleting delivery requests...');
                DeliveryRequest::truncate();

                $this->info('✅ All data cleared successfully!');
                $this->info("Deleted {$chatsCount} chats");
                $this->info("Deleted {$responsesCount} responses");
                $this->info("Deleted {$sendRequestsCount} send requests");
                $this->info("Deleted {$deliveryRequestsCount} delivery requests");
            });

            return 0;
        } catch (\Exception $e) {
            $this->error('Error during cleanup: ' . $e->getMessage());
            return 1;
        }
    }
}