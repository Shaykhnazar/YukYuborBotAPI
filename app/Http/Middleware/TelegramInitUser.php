<?php

namespace App\Http\Middleware;

use App\Models\TelegramUser;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class TelegramInitUser
{
    /**
     * Handle incoming request with improved Telegram data detection
     */
    public function handle(Request $request, Closure $next): Response
    {
        Log::info('TelegramInitUser middleware triggered', [
            'url' => $request->url(),
            'method' => $request->method(),
            'has_user_data' => $request->hasHeader('X-TELEGRAM-USER-DATA'),
            'dev_mode' => $request->header('X-DEV-MODE'),
        ]);

        // Check if this is real Telegram data
        $initData = $request->header(config('auth.guards.tgwebapp.userDataHeaderName', 'X-TELEGRAM-USER-DATA'));
        $devMode = $request->header('X-DEV-MODE') === 'true';

        if ($initData && !$devMode) {
            return $this->handleRealTelegramData($request, $initData, $next);
        } else {
            return $this->handleDevelopmentData($request, $next);
        }
    }

    /**
     * Handle real Telegram Web App data with improved validation
     */
    private function handleRealTelegramData(Request $request, string $initData, Closure $next): Response
    {
        Log::info('Processing REAL Telegram Web App data', [
            'init_data_length' => strlen($initData),
            'init_data_preview' => substr($initData, 0, 100) . '...'
        ]);

        // Check if initData is too short to be valid
        if (strlen($initData) < 10) {
            Log::warning('Telegram initData too short, falling back to development mode', [
                'init_data_length' => strlen($initData),
                'init_data' => $initData
            ]);
            return $this->handleDevelopmentData($request, $next);
        }

        try {
            // Parse Telegram's initData
            parse_str($initData, $params);

            Log::debug('Parsed Telegram initData', [
                'params_keys' => array_keys($params),
                'has_user' => isset($params['user']),
                'has_auth_date' => isset($params['auth_date']),
                'has_hash' => isset($params['hash']),
                'raw_user_param' => $params['user'] ?? 'missing'
            ]);

            if (!isset($params['user'])) {
                Log::warning('No user data in Telegram initData', ['params' => $params]);
                return $this->handleDevelopmentData($request, $next);
            }

            // FIXED: Improved user parameter processing
            $user = $this->parseUserParameter($params['user']);

            if (!$user || !isset($user['id'])) {
                Log::warning('Invalid Telegram user data after parsing', [
                    'user_param_raw' => $params['user'],
                    'parsed_user' => $user,
                    'json_error' => json_last_error_msg()
                ]);
                return $this->handleDevelopmentData($request, $next);
            }

            // Additional validation
            if (!is_numeric($user['id']) || $user['id'] <= 0) {
                Log::warning('Invalid Telegram user ID', ['user_id' => $user['id']]);
                return $this->handleDevelopmentData($request, $next);
            }

            // TODO: Add Telegram hash validation here for production security
            // $this->validateTelegramHash($initData);

            $this->createOrUpdateUser($user, true);
            $request->attributes->set('telegram_id', $user['id']);
            $request->attributes->set('is_real_telegram', true);

            Log::info('Real Telegram user processed successfully', [
                'telegram_id' => $user['id'],
                'username' => $user['username'] ?? null,
                'first_name' => $user['first_name'] ?? null,
                'last_name' => $user['last_name'] ?? null
            ]);

            return $next($request);

        } catch (\Exception $e) {
            Log::error('Error processing real Telegram data, falling back to development mode', [
                'error' => $e->getMessage(),
                'init_data_length' => strlen($initData),
                'init_data_preview' => substr($initData, 0, 50),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->handleDevelopmentData($request, $next);
        }
    }

    /**
     * FIXED: Improved user parameter parsing with multiple fallback strategies
     */
    private function parseUserParameter(string $userParam): ?array
    {
        Log::debug('Parsing user parameter', [
            'original' => $userParam,
            'length' => strlen($userParam)
        ]);

        // Strategy 1: Try direct JSON decode (parse_str already decoded URL encoding)
        $user = json_decode($userParam, true);
        if ($user && isset($user['id'])) {
            Log::debug('Strategy 1 successful: Direct JSON decode');
            return $user;
        }

        // Strategy 2: Try with manual URL decoding
        $decodedParam = urldecode($userParam);
        Log::debug('Strategy 2: Manual URL decode', ['decoded' => $decodedParam]);

        $user = json_decode($decodedParam, true);
        if ($user && isset($user['id'])) {
            Log::debug('Strategy 2 successful: Manual URL decode + JSON');
            return $user;
        }

        // Strategy 3: Handle double encoding (if somehow double-encoded)
        $doubleDecoded = urldecode($decodedParam);
        if ($doubleDecoded !== $decodedParam) {
            Log::debug('Strategy 3: Double URL decode', ['double_decoded' => $doubleDecoded]);

            $user = json_decode($doubleDecoded, true);
            if ($user && isset($user['id'])) {
                Log::debug('Strategy 3 successful: Double URL decode + JSON');
                return $user;
            }
        }

        // Strategy 4: Handle malformed JSON by fixing common issues
        $fixedJson = $this->fixMalformedJson($userParam);
        if ($fixedJson !== $userParam) {
            Log::debug('Strategy 4: Fixed JSON', ['fixed' => $fixedJson]);

            $user = json_decode($fixedJson, true);
            if ($user && isset($user['id'])) {
                Log::debug('Strategy 4 successful: Fixed JSON');
                return $user;
            }
        }

        Log::warning('All user parsing strategies failed', [
            'original' => $userParam,
            'decoded' => $decodedParam ?? 'failed',
            'json_last_error' => json_last_error_msg()
        ]);

        return null;
    }

    /**
     * Fix common JSON malformation issues
     */
    private function fixMalformedJson(string $json): string
    {
        // Remove any leading/trailing whitespace
        $json = trim($json);

        // Fix unescaped forward slashes in URLs (common issue)
        $json = str_replace('https:/', 'https:\/\/', $json);
        $json = str_replace('http:/', 'http:\/\/', $json);

        // Fix any stray backslashes that shouldn't be there
        $json = preg_replace('/([^\\\\])\\\\([^"\/nrtbf])/', '$1$2', $json);

        return $json;
    }

    /**
     * Handle development/testing data - FIXED version
     */
    private function handleDevelopmentData(Request $request, Closure $next): Response
    {
        Log::info('Processing development user data');

        $userDataHead = $request->header('X-TELEGRAM-USER-DATA');

        if ($userDataHead) {
            try {
                // FIXED: Don't double-decode, parse_str handles URL decoding
                parse_str($userDataHead, $userData);

                Log::debug('Development data parsed', [
                    'keys' => array_keys($userData),
                    'has_user' => isset($userData['user'])
                ]);

                if (isset($userData['user'])) {
                    // Use the same improved parsing logic
                    $user = $this->parseUserParameter($userData['user']);

                    if (!$user || !isset($user['id'])) {
                        Log::warning('Invalid development user data', [
                            'user_data' => $userData['user'] ?? 'missing',
                            'parsed_user' => $user
                        ]);
                        return response()->json(['error' => 'Invalid development user data'], 401);
                    }

                    $this->createOrUpdateUser($user, false);
                    $request->attributes->set('telegram_id', $user['id']);
                    $request->attributes->set('is_real_telegram', false);

                    Log::info('Development user processed', [
                        'telegram_id' => $user['id'],
                        'name' => ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')
                    ]);
                    return $next($request);
                }
            } catch (\Exception $e) {
                Log::error('Error processing development data', [
                    'error' => $e->getMessage(),
                    'user_data_head' => $userDataHead
                ]);
            }
        }

        Log::warning('No valid user data found');
        return response()->json(['error' => 'No user data'], 401);
    }

    /**
     * Create or update user with enhanced logging
     */
    private function createOrUpdateUser(array $user, bool $isRealTelegram): void
    {
        Log::info('Creating/updating user', [
            'user_id' => $user['id'],
            'is_real' => $isRealTelegram,
            'first_name' => $user['first_name'] ?? null,
            'last_name' => $user['last_name'] ?? null,
            'username' => $user['username'] ?? null
        ]);

        $telegramUser = TelegramUser::where('telegram', (int) $user['id'])->first();

        if (!$telegramUser) {
            $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
            if (empty($fullName)) {
                $fullName = $user['username'] ?? 'User ' . $user['id'];
            }

            $userModel = new User([
                'name' => $fullName,
                'links_balance' => $isRealTelegram ? 3 : 10  // Real users get 3, dev gets 10
            ]);
            $userModel->save();

            $telegramUser = new TelegramUser([
                'telegram' => $user['id'],
                'username' => $user['username'] ?? null,
                'image' => $user['photo_url'] ?? null,
                'user_id' => $userModel->id
            ]);
            $telegramUser->save();

            Log::info('New user created', [
                'user_id' => $userModel->id,
                'telegram_id' => $user['id'],
                'name' => $userModel->name,
                'links_balance' => $userModel->links_balance,
                'is_real' => $isRealTelegram
            ]);
        } else {
            Log::info('Updating existing user', ['existing_user_id' => $telegramUser->user_id]);

            // Update user info only if changed
            $updates = [];
            if (isset($user['username']) && $telegramUser->username !== $user['username']) {
                $updates['username'] = $user['username'];
            }
            if (isset($user['photo_url']) && $telegramUser->image !== $user['photo_url']) {
                $updates['image'] = $user['photo_url'];
            }

            if (!empty($updates)) {
                $telegramUser->update($updates);
                Log::info('Telegram user updated', $updates);
            }

            // Update name if needed
            $newName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
            if (empty($newName)) {
                $newName = $user['username'] ?? 'User ' . $user['id'];
            }

            if ($newName && $telegramUser->user->name !== $newName) {
                $telegramUser->user->update(['name' => $newName]);
                Log::info('User name updated', ['new_name' => $newName]);
            }

            // Ensure development users always have enough links for testing
            if (!$isRealTelegram && $telegramUser->user->links_balance < 5) {
                $telegramUser->user->update(['links_balance' => 10]);
                Log::info('Refilled development user links', ['new_balance' => 10]);
            }
        }
    }

    /**
     * Validate Telegram hash (implement this for production security)
     */
    private function validateTelegramHash(string $initData): bool
    {
        // TODO: Implement proper Telegram WebApp data validation
        // https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app

        $botToken = env('TELEGRAM_BOT_TOKEN');
        if (!$botToken) {
            Log::warning('TELEGRAM_BOT_TOKEN not set, skipping hash validation');
            return true; // Allow in development
        }

        // Parse initData to extract hash and other parameters
        parse_str($initData, $params);
        $hash = $params['hash'] ?? null;

        if (!$hash) {
            Log::warning('No hash in Telegram initData');
            return false;
        }

        // Remove hash from params for validation
        unset($params['hash']);

        // Sort parameters and create data string
        ksort($params);
        $dataCheckString = urldecode(http_build_query($params, '', "\n"));

        // Create secret key
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);

        // Calculate hash
        $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        $isValid = hash_equals($hash, $calculatedHash);

        if (!$isValid) {
            Log::warning('Telegram hash validation failed', [
                'provided_hash' => $hash,
                'calculated_hash' => $calculatedHash,
                'data_check_string' => $dataCheckString
            ]);
        }

        return $isValid;
    }
}
