<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\TelegramUser;
use App\Models\DeliveryRequest;
use App\Models\SendRequest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RequestsSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create 20 diverse users first
        $users = User::factory(20)->create()->each(function ($user) {
            // Create telegram user for each user
            TelegramUser::factory()->create([
                'user_id' => $user->id,
                'telegram' => $user->id + 1000000000, // Fake telegram ID
                'username' => 'user_' . $user->id,
                'image' => "https://via.placeholder.com/150/007bff/ffffff?text=U{$user->id}"
            ]);
        });

        echo "✅ Created {$users->count()} users with telegram profiles\n";

        // Create 50 Delivery Requests with variety
        $this->createDeliveryRequests($users);

        // Create 50 Send Requests with variety
        $this->createSendRequests($users);

        echo "🎉 Database seeded successfully with 100 requests for infinite scroll testing!\n";
        $this->printSeederSummary();
    }

    private function createDeliveryRequests($users)
    {
        // 25 Open delivery requests (available for public browsing)
        DeliveryRequest::factory(25)
            ->open()
            ->state(function () use ($users) {
                return ['user_id' => $users->random()->id];
            })
            ->create();

        // 10 Delivery requests with responses
        DeliveryRequest::factory(10)
            ->hasResponses()
            ->state(function () use ($users) {
                return ['user_id' => $users->random()->id];
            })
            ->create();

        // 5 Matched delivery requests
        DeliveryRequest::factory(5)
            ->matched()
            ->state(function () use ($users) {
                return ['user_id' => $users->random()->id];
            })
            ->create();

        // 5 Completed delivery requests
        DeliveryRequest::factory(5)
            ->completed()
            ->state(function () use ($users) {
                return ['user_id' => $users->random()->id];
            })
            ->create();

        // 5 Special delivery requests with variety
        $specialDeliveryStates = [
            ['withPrice', [100000, 'UZS']],
            ['withoutPrice', []],
            ['anyDestination', []],
            ['anySize', []],
            ['frequentTraveler', []]
        ];

        foreach ($specialDeliveryStates as $index => $state) {
            $factory = DeliveryRequest::factory()
                ->open()
                ->state(function () use ($users) {
                    return ['user_id' => $users->random()->id];
                });

            $method = $state[0];
            $params = $state[1];

            if (!empty($params)) {
                $factory = $factory->$method(...$params);
            } else {
                $factory = $factory->$method();
            }

            $factory->create();
        }

        echo "📦 Created 50 delivery requests (25 open, 10 with responses, 5 matched, 5 completed, 5 special)\n";
    }

    private function createSendRequests($users)
    {
        // 25 Open send requests (available for public browsing)
        SendRequest::factory(25)
            ->open()
            ->state(function () use ($users) {
                return ['user_id' => $users->random()->id];
            })
            ->create();

        // 10 Send requests with responses
        SendRequest::factory(10)
            ->hasResponses()
            ->state(function () use ($users) {
                return ['user_id' => $users->random()->id];
            })
            ->create();

        // 5 Matched send requests
        SendRequest::factory(5)
            ->matched()
            ->state(function () use ($users) {
                return ['user_id' => $users->random()->id];
            })
            ->create();

        // 5 Completed send requests
        SendRequest::factory(5)
            ->completed()
            ->state(function () use ($users) {
                return ['user_id' => $users->random()->id];
            })
            ->create();

        // 5 Special send requests with variety
        $specialSendStates = [
            ['withPrice', [200000, 'USD']],
            ['withoutPrice', []],
            ['urgent', []],
            ['withRoute', ['Tashkent', 'Samarkand']],
            ['withRoute', ['Bukhara', 'Fergana']]
        ];

        foreach ($specialSendStates as $index => $state) {
            $factory = SendRequest::factory()
                ->open()
                ->state(function () use ($users) {
                    return ['user_id' => $users->random()->id];
                });

            $method = $state[0];
            $params = $state[1];

            if (!empty($params)) {
                $factory = $factory->$method(...$params);
            } else {
                $factory = $factory->$method();
            }

            $factory->create();
        }

        echo "📮 Created 50 send requests (25 open, 10 with responses, 5 matched, 5 completed, 5 special)\n";
    }

    private function printSeederSummary()
    {
        $deliveryStats = [
            'open' => DeliveryRequest::where('status', 'open')->count(),
            'has_responses' => DeliveryRequest::where('status', 'has_responses')->count(),
            'matched' => DeliveryRequest::where('status', 'matched')->count(),
            'completed' => DeliveryRequest::where('status', 'completed')->count(),
        ];

        $sendStats = [
            'open' => SendRequest::where('status', 'open')->count(),
            'has_responses' => SendRequest::where('status', 'has_responses')->count(),
            'matched' => SendRequest::where('status', 'matched')->count(),
            'completed' => SendRequest::where('status', 'completed')->count(),
        ];

        echo "\n📊 SEEDER SUMMARY:\n";
        echo "==================\n";
        echo "Users: " . User::count() . "\n";
        echo "Telegram Users: " . TelegramUser::count() . "\n\n";

        echo "📦 DELIVERY REQUESTS (" . DeliveryRequest::count() . " total):\n";
        foreach ($deliveryStats as $status => $count) {
            echo "  • {$status}: {$count}\n";
        }

        echo "\n📮 SEND REQUESTS (" . SendRequest::count() . " total):\n";
        foreach ($sendStats as $status => $count) {
            echo "  • {$status}: {$count}\n";
        }

        $publicRequests = DeliveryRequest::whereIn('status', ['open', 'has_responses'])->count() +
            SendRequest::whereIn('status', ['open', 'has_responses'])->count();

        echo "\n🌐 PUBLIC BROWSABLE REQUESTS: {$publicRequests}\n";
        echo "(Perfect for testing infinite scroll pagination!)\n\n";
    }
}
