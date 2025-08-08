<?php

namespace Tests\Unit\Services;

use App\Service\TelegramUserService;
use App\Models\TelegramUser;
use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

class TelegramUserServiceTest extends TestCase
{
    use RefreshDatabase;

    protected TelegramUserService $telegramUserService;
    protected User $user;
    protected TelegramUser $telegramUser;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->telegramUserService = new TelegramUserService();
        
        $this->user = User::factory()->create();
        $this->telegramUser = TelegramUser::factory()->create([
            'user_id' => $this->user->id,
            'telegram' => '123456789'
        ]);
    }

    public function test_get_user_by_telegram_id_returns_user_when_found()
    {
        $request = new Request(['telegram_id' => '123456789']);
        
        $result = $this->telegramUserService->getUserByTelegramId($request);
        
        $this->assertInstanceOf(User::class, $result);
        $this->assertEquals($this->user->id, $result->id);
    }

    public function test_get_user_by_telegram_id_returns_null_when_not_found()
    {
        $request = new Request(['telegram_id' => '999999999']);
        
        $result = $this->telegramUserService->getUserByTelegramId($request);
        
        $this->assertNull($result);
    }

    public function test_get_user_by_telegram_id_returns_null_when_no_telegram_id_provided()
    {
        $request = new Request();
        
        $result = $this->telegramUserService->getUserByTelegramId($request);
        
        $this->assertNull($result);
    }

    public function test_get_user_by_telegram_id_handles_string_telegram_id()
    {
        $request = new Request(['telegram_id' => 'string_telegram_id']);
        
        TelegramUser::factory()->create([
            'user_id' => $this->user->id,
            'telegram' => 'string_telegram_id'
        ]);
        
        $result = $this->telegramUserService->getUserByTelegramId($request);
        
        $this->assertInstanceOf(User::class, $result);
        $this->assertEquals($this->user->id, $result->id);
    }

    public function test_get_user_by_telegram_id_returns_correct_user_when_multiple_exist()
    {
        // Create another user with different telegram ID
        $otherUser = User::factory()->create();
        TelegramUser::factory()->create([
            'user_id' => $otherUser->id,
            'telegram' => '987654321'
        ]);
        
        $request = new Request(['telegram_id' => '987654321']);
        
        $result = $this->telegramUserService->getUserByTelegramId($request);
        
        $this->assertInstanceOf(User::class, $result);
        $this->assertEquals($otherUser->id, $result->id);
        $this->assertNotEquals($this->user->id, $result->id);
    }

    public function test_get_user_by_telegram_id_uses_first_match_when_duplicates_exist()
    {
        // Create duplicate telegram user with same telegram ID (shouldn't happen in real scenario)
        $duplicateUser = User::factory()->create();
        TelegramUser::factory()->create([
            'user_id' => $duplicateUser->id,
            'telegram' => '123456789' // Same as setUp telegramUser
        ]);
        
        $request = new Request(['telegram_id' => '123456789']);
        
        $result = $this->telegramUserService->getUserByTelegramId($request);
        
        $this->assertInstanceOf(User::class, $result);
        // Should return the first one found (the one from setUp)
        $this->assertEquals($this->user->id, $result->id);
    }

    public function test_get_user_by_telegram_id_handles_integer_telegram_id()
    {
        $request = new Request(['telegram_id' => 123456789]);
        
        $result = $this->telegramUserService->getUserByTelegramId($request);
        
        $this->assertInstanceOf(User::class, $result);
        $this->assertEquals($this->user->id, $result->id);
    }

    public function test_get_user_by_telegram_id_is_case_sensitive()
    {
        TelegramUser::factory()->create([
            'user_id' => $this->user->id,
            'telegram' => 'CaseSensitiveId'
        ]);
        
        $request1 = new Request(['telegram_id' => 'CaseSensitiveId']);
        $request2 = new Request(['telegram_id' => 'casesensitiveid']);
        
        $result1 = $this->telegramUserService->getUserByTelegramId($request1);
        $result2 = $this->telegramUserService->getUserByTelegramId($request2);
        
        $this->assertInstanceOf(User::class, $result1);
        $this->assertNull($result2);
    }

    public function test_service_can_be_instantiated()
    {
        $service = new TelegramUserService();
        
        $this->assertInstanceOf(TelegramUserService::class, $service);
    }

    public function test_get_user_by_telegram_id_handles_empty_string_telegram_id()
    {
        $request = new Request(['telegram_id' => '']);
        
        $result = $this->telegramUserService->getUserByTelegramId($request);
        
        $this->assertNull($result);
    }

    public function test_get_user_by_telegram_id_handles_zero_telegram_id()
    {
        TelegramUser::factory()->create([
            'user_id' => $this->user->id,
            'telegram' => '0'
        ]);
        
        $request = new Request(['telegram_id' => 0]);
        
        $result = $this->telegramUserService->getUserByTelegramId($request);
        
        $this->assertInstanceOf(User::class, $result);
        $this->assertEquals($this->user->id, $result->id);
    }

    public function test_get_user_by_telegram_id_loads_user_relationship()
    {
        $request = new Request(['telegram_id' => '123456789']);
        
        $result = $this->telegramUserService->getUserByTelegramId($request);
        
        $this->assertInstanceOf(User::class, $result);
        $this->assertTrue($result->exists);
        $this->assertNotNull($result->name);
    }
}