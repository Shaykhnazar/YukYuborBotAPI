<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TestUsersController extends Controller
{
    /**
     * Get test users for development environment only
     */
    public function index(Request $request)
    {
        // Only allow in development/local environments
        if (!app()->environment(['local', 'development'])) {
            Log::warning('Test users endpoint accessed in production environment', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'environment' => app()->environment()
            ]);

            return response()->json([
                'error' => 'Not available in production'
            ], 403);
        }

        // Optional: Add simple security check
        if (!$request->header('X-DEV-MODE')) {
            return response()->json([
                'error' => 'Development mode required'
            ], 403);
        }

        try {
            $testUsers = $this->getTestUsers();

            Log::info('Test users requested', [
                'count' => count($testUsers),
                'environment' => app()->environment()
            ]);

            return response()->json($testUsers);

        } catch (\Exception $e) {
            Log::error('Error loading test users', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to load test users'
            ], 500);
        }
    }

    /**
     * Get test users from storage or environment
     */
    private function getTestUsers(): array
    {
        // Try to load from storage file first
        $storageFile = storage_path('app/test-users.json');

        if (file_exists($storageFile)) {
            $content = file_get_contents($storageFile);
            $users = json_decode($content, true);

            if ($users && is_array($users)) {
                return $this->processUsers($users);
            }
        }

        // Fallback to environment variables or hardcoded defaults
        return $this->getDefaultTestUsers();
    }

    /**
     * Process users array - replace environment variables
     */
    private function processUsers(array $users): array
    {
        return array_map(function ($user) {
            // Replace environment variables in all string fields
            foreach ($user as $key => $value) {
                if (is_string($value)) {
                    $user[$key] = $this->replaceEnvVars($value);
                }
            }

            // Ensure ID is integer
            if (isset($user['id'])) {
                $user['id'] = (int) $user['id'];
            }

            return $user;
        }, $users);
    }

    /**
     * Replace ${VAR_NAME} with environment variable values
     */
    private function replaceEnvVars(string $str): string
    {
        return preg_replace_callback('/\$\{([^}]+)\}/', function ($matches) {
            $varName = $matches[1];
            $value = env($varName);
            return $value !== null ? $value : $matches[0]; // Keep placeholder if no value
        }, $str);
    }

    /**
     * Get default test users from environment or hardcoded
     */
    private function getDefaultTestUsers(): array
    {
        $users = [];

        // Try to build users from environment variables
        for ($i = 1; $i <= 3; $i++) {
            $id = env("DEV_USER_{$i}_ID");
            $firstName = env("DEV_USER_{$i}_FIRST_NAME");

            if ($id && $firstName) {
                $users[] = [
                    'id' => (int) $id,
                    'first_name' => $firstName,
                    'last_name' => env("DEV_USER_{$i}_LAST_NAME", ''),
                    'username' => env("DEV_USER_{$i}_USERNAME", "user_{$i}"),
                    'language_code' => 'ru',
                    'allows_write_to_pm' => true,
                    'photo_url' => "https://via.placeholder.com/150/007bff/ffffff?text=" . substr($firstName, 0, 1)
                ];
            }
        }

        // If no environment users found, return hardcoded safe defaults
        if (empty($users)) {
            $users = [
                [
                    'id' => 123456789,
                    'first_name' => 'Test',
                    'last_name' => 'User',
                    'username' => 'test_user',
                    'language_code' => 'en',
                    'allows_write_to_pm' => true,
                    'photo_url' => 'https://via.placeholder.com/150/007bff/ffffff?text=TU'
                ]
            ];
        }

        return $users;
    }
}
