<?php

namespace Tests\Unit\Services;

use App\Models\DeliveryRequest;
use App\Models\Location;
use App\Models\SendRequest;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\GoogleSheetsService;
use Carbon\Carbon;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Mockery;
use Revolution\Google\Sheets\Facades\Sheets;
use Tests\TestCase;

class GoogleSheetsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected GoogleSheetsService $service;
    protected $mockSheets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockSheets = Mockery::mock();
        Sheets::swap($this->mockSheets);

        // Mock config to return a test spreadsheet ID
        Config::set('google.sheets.spreadsheet_id', 'test-spreadsheet-id');

        $this->service = new GoogleSheetsService();
    }

    protected function tearDown(): void
    {
        // Ensure any open transactions are rolled back
        if ($this->app && $this->app->bound('db')) {
            try {
                \DB::rollBack();
            } catch (\Exception $e) {
                // Ignore rollback errors
            }
        }

        Mockery::close();
        parent::tearDown();
    }

    public function test_constructor_sets_spreadsheet_id_from_config()
    {
        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('spreadsheetId');
        $property->setAccessible(true);

        $this->assertEquals('test-spreadsheet-id', $property->getValue($this->service));
    }

    public function test_record_add_user_with_telegram_user_relation()
    {
        $user = User::factory()->create(['name' => 'Test User', 'phone' => '+1234567890', 'city' => 'Test City']);
        $telegramUser = TelegramUser::factory()->create([
            'user_id' => $user->id,
            'username' => 'testuser',
            'telegram' => '123456789'
        ]);

        $user->setRelation('telegramUser', $telegramUser);

        $this->mockSheets->shouldReceive('spreadsheet')
            ->with('test-spreadsheet-id')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('sheet')
            ->with('Users')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('append')
            ->with(Mockery::on(function ($data) use ($user) {
                $row = $data[0];
                return $row[0] === $user->id &&
                       $row[1] === 'Test User' &&
                       $row[2] === '+1234567890' &&
                       $row[3] === 'Test City' &&
                       $row[5] === '@testuser' &&
                       $row[6] === '';
            }))
            ->once();

        $result = $this->service->recordAddUser($user);

        $this->assertTrue($result);
    }

    public function test_record_add_user_loads_telegram_user_if_not_loaded()
    {
        $user = User::factory()->create(['name' => 'Test User']);
        TelegramUser::factory()->create([
            'user_id' => $user->id,
            'username' => 'testuser',
            'telegram' => '123456789'
        ]);

        $this->mockSheets->shouldReceive('spreadsheet')
            ->with('test-spreadsheet-id')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('sheet')
            ->with('Users')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('append')
            ->once();

        $result = $this->service->recordAddUser($user);

        $this->assertTrue($result);
        $this->assertTrue($user->relationLoaded('telegramUser'));
    }

    public function test_record_add_user_handles_missing_spreadsheet_id()
    {
        Config::set('google.sheets.spreadsheet_id', null);
        $service = new GoogleSheetsService();

        $user = User::factory()->create();

        Log::shouldReceive('warning')
            ->with('Google Sheets spreadsheet ID not configured, skipping recordAddUser')
            ->once();

        $result = $service->recordAddUser($user);

        $this->assertTrue($result);
    }

    public function test_record_add_user_handles_exception()
    {
        $user = User::factory()->create();
        TelegramUser::factory()->create(['user_id' => $user->id]);

        $this->mockSheets->shouldReceive('spreadsheet')
            ->with('test-spreadsheet-id')
            ->once()
            ->andThrow(new Exception('API Error'));

        Log::shouldReceive('error')
            ->with('Failed to add user record to Google Sheets', Mockery::type('array'))
            ->once();

        $result = $this->service->recordAddUser($user);

        $this->assertFalse($result);
    }

    public function test_record_add_delivery_request_with_relations()
    {
        $user = User::factory()->create(['name' => 'Test User']);
        $fromLocation = Location::factory()->create(['name' => 'From City']);
        $toLocation = Location::factory()->create(['name' => 'To City']);

        $deliveryRequest = DeliveryRequest::factory()->create([
            'user_id' => $user->id,
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'from_date' => Carbon::parse('2024-01-01 10:00:00'),
            'to_date' => Carbon::parse('2024-01-05 18:00:00'),
            'size_type' => 'Средняя',
            'description' => 'Test delivery',
            'status' => 'open'
        ]);

        $deliveryRequest->setRelation('user', $user);
        $deliveryRequest->setRelation('fromLocation', $fromLocation);
        $deliveryRequest->setRelation('toLocation', $toLocation);

        $this->mockSheets->shouldReceive('spreadsheet')
            ->with('test-spreadsheet-id')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('sheet')
            ->with('Deliver requests')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('append')
            ->with(Mockery::on(function ($data) use ($deliveryRequest, $user, $fromLocation, $toLocation) {
                $row = $data[0];
                return $row[0] === $deliveryRequest->id &&
                       $row[1] === $user->id . '-Test User' &&
                       $row[2] === 'From City' &&
                       $row[3] === 'To City' &&
                       $row[6] === 'Средняя' &&
                       $row[7] === 'Test delivery' &&
                       $row[8] === 'open';
            }))
            ->once();

        $result = $this->service->recordAddDeliveryRequest($deliveryRequest);

        $this->assertTrue($result);
    }

    public function test_record_add_send_request_with_relations()
    {
        $user = User::factory()->create(['name' => 'Test User']);
        $fromLocation = Location::factory()->create(['name' => 'From City']);
        $toLocation = Location::factory()->create(['name' => 'To City']);

        $sendRequest = SendRequest::factory()->create([
            'user_id' => $user->id,
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'size_type' => 'Маленькая',
            'description' => 'Test package',
            'status' => 'open'
        ]);

        $sendRequest->setRelation('user', $user);
        $sendRequest->setRelation('fromLocation', $fromLocation);
        $sendRequest->setRelation('toLocation', $toLocation);

        $this->mockSheets->shouldReceive('spreadsheet')
            ->with('test-spreadsheet-id')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('sheet')
            ->with('Send requests')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('append')
            ->once();

        $result = $this->service->recordAddSendRequest($sendRequest);

        $this->assertTrue($result);
    }

    public function test_update_request_response_received_first_response()
    {
        $worksheetData = [
            [123, 'user-data', 'From', 'To', 'date1', 'date2', 'size', 'desc', 'status', '2024-01-01T10:00:00.000000Z', 'updated', 'не получен', 0]
        ];

        $this->mockSheets->shouldReceive('spreadsheet')
            ->with('test-spreadsheet-id')
            ->times(4)
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('sheet')
            ->with('Send requests')
            ->times(4)
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('all')
            ->once()
            ->andReturn($worksheetData);

        // Mock the range updates
        $this->mockSheets->shouldReceive('range')
            ->with('L1')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('update')
            ->with([["получен"]])
            ->once();

        $this->mockSheets->shouldReceive('range')
            ->with('M1')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('update')
            ->with([[1]])
            ->once();

        $this->mockSheets->shouldReceive('range')
            ->with('N1')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('update')
            ->with(Mockery::type('array'))
            ->once();

        $result = $this->service->updateRequestResponseReceived('send', 123, true);

        $this->assertTrue($result);
    }

    public function test_update_request_response_received_not_first_response()
    {
        $worksheetData = [
            [123, 'user-data', 'From', 'To', 'date1', 'date2', 'size', 'desc', 'status', '2024-01-01T10:00:00.000000Z', 'updated', 'получен', 2]
        ];

        $this->mockSheets->shouldReceive('spreadsheet')
            ->with('test-spreadsheet-id')
            ->times(3)
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('sheet')
            ->with('Send requests')
            ->times(3)
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('all')
            ->once()
            ->andReturn($worksheetData);

        // Mock the range updates (should not update waiting time for non-first response)
        $this->mockSheets->shouldReceive('range')
            ->with('L1')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('update')
            ->with([["получен"]])
            ->once();

        $this->mockSheets->shouldReceive('range')
            ->with('M1')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('update')
            ->with([[3]])
            ->once();

        $result = $this->service->updateRequestResponseReceived('send', 123, false);

        $this->assertTrue($result);
    }

    public function test_update_request_response_accepted()
    {
        $worksheetData = [
            [123, 'user-data', 'From', 'To', 'date1', 'date2', 'size', 'desc', 'open', '2024-01-01T10:00:00.000000Z']
        ];

        $this->mockSheets->shouldReceive('spreadsheet')
            ->with('test-spreadsheet-id')
            ->times(5)
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('sheet')
            ->with('Deliver requests')
            ->times(5)
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('all')
            ->once()
            ->andReturn($worksheetData);

        // Mock all the range updates
        $this->mockSheets->shouldReceive('range')
            ->with('O1')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('update')
            ->with([["принят"]])
            ->once();

        $this->mockSheets->shouldReceive('range')
            ->with('P1')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('update')
            ->with(Mockery::type('array'))
            ->once();

        $this->mockSheets->shouldReceive('range')
            ->with('Q1')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('update')
            ->with(Mockery::type('array'))
            ->once();

        $this->mockSheets->shouldReceive('range')
            ->with('I1')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('update')
            ->with([["matched"]])
            ->once();

        $result = $this->service->updateRequestResponseAccepted('delivery', 123);

        $this->assertTrue($result);
    }

    public function test_record_close_delivery_request()
    {
        $worksheetData = [
            [123, 'user-data', 'From', 'To', 'date1', 'date2', 'size', 'desc', 'open', 'created', 'updated']
        ];

        $this->mockSheets->shouldReceive('spreadsheet')
            ->with('test-spreadsheet-id')
            ->times(3)
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('sheet')
            ->with('Deliver requests')
            ->times(3)
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('all')
            ->once()
            ->andReturn($worksheetData);

        $this->mockSheets->shouldReceive('range')
            ->with('I1')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('update')
            ->with([["closed"]])
            ->once();

        $this->mockSheets->shouldReceive('range')
            ->with('K1')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('update')
            ->with(Mockery::type('array'))
            ->once();

        $result = $this->service->recordCloseDeliveryRequest(123);

        $this->assertTrue($result);
    }

    public function test_record_close_send_request()
    {
        $worksheetData = [
            [123, 'user-data', 'From', 'To', 'date1', 'date2', 'size', 'desc', 'open', 'created', 'updated']
        ];

        $this->mockSheets->shouldReceive('spreadsheet')
            ->with('test-spreadsheet-id')
            ->times(3)
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('sheet')
            ->with('Send requests')
            ->times(3)
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('all')
            ->once()
            ->andReturn($worksheetData);

        $this->mockSheets->shouldReceive('range')
            ->with('I1')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('update')
            ->with([["closed"]])
            ->once();

        $this->mockSheets->shouldReceive('range')
            ->with('K1')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('update')
            ->with(Mockery::type('array'))
            ->once();

        $result = $this->service->recordCloseSendRequest(123);

        $this->assertTrue($result);
    }

    public function test_get_worksheet_data()
    {
        $testData = [
            ['ID', 'Name', 'Status'],
            [1, 'Test Request', 'open'],
            [2, 'Another Request', 'closed']
        ];

        $this->mockSheets->shouldReceive('spreadsheet')
            ->with('test-spreadsheet-id')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('sheet')
            ->with('Test Worksheet')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('all')
            ->once()
            ->andReturn($testData);

        $result = $this->service->getWorksheetData('Test Worksheet');

        $this->assertEquals($testData, $result);
    }

    public function test_batch_export()
    {
        $testData = [
            ['ID', 'Name', 'Status'],
            [1, 'Test Request', 'open'],
            [2, 'Another Request', 'closed']
        ];

        $this->mockSheets->shouldReceive('spreadsheet')
            ->with('test-spreadsheet-id')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('sheet')
            ->with('Test Worksheet')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('clear')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('append')
            ->with($testData)
            ->once();

        $result = $this->service->batchExport('Test Worksheet', $testData);

        $this->assertTrue($result);
    }

    public function test_initialize_worksheets()
    {
        // Mock the three worksheet initializations
        $this->mockSheets->shouldReceive('spreadsheet')
            ->with('test-spreadsheet-id')
            ->times(3)
            ->andReturnSelf();

        $this->mockSheets->shouldReceive('sheet')
            ->with('Users')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('sheet')
            ->with('Deliver requests')
            ->once()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('sheet')
            ->with('Send requests')
            ->once()
            ->andReturnSelf();

        $this->mockSheets->shouldReceive('clear')
            ->times(3)
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('append')
            ->times(3);

        $result = $this->service->initializeWorksheets();

        $this->assertArrayHasKey('users', $result);
        $this->assertArrayHasKey('delivery_requests', $result);
        $this->assertArrayHasKey('send_requests', $result);
        $this->assertTrue($result['users']);
        $this->assertTrue($result['delivery_requests']);
        $this->assertTrue($result['send_requests']);
    }

    public function test_methods_handle_missing_spreadsheet_id_gracefully()
    {
        Config::set('google.sheets.spreadsheet_id', null);
        $service = new GoogleSheetsService();

        Log::shouldReceive('warning')
            ->withAnyArgs()
            ->zeroOrMoreTimes();

        Log::shouldReceive('error')
            ->withAnyArgs()
            ->zeroOrMoreTimes();

        Log::shouldReceive('info')
            ->withAnyArgs()
            ->zeroOrMoreTimes();

        $this->assertTrue($service->recordAddUser(User::factory()->create()));
        $this->assertTrue($service->recordAddDeliveryRequest(DeliveryRequest::factory()->create()));
        $this->assertTrue($service->recordAddSendRequest(SendRequest::factory()->create()));
        $this->assertTrue($service->updateRequestResponseReceived('send', 123));
        $this->assertTrue($service->updateRequestResponseAccepted('send', 123));
        $this->assertTrue($service->recordCloseDeliveryRequest(123));
        $this->assertEquals([], $service->getWorksheetData('Test'));
    }

    public function test_update_methods_handle_missing_request_id()
    {
        $worksheetData = [
            [456, 'different-request', 'From', 'To'] // Different ID than what we're looking for
        ];

        $this->mockSheets->shouldReceive('spreadsheet')
            ->withAnyArgs()
            ->zeroOrMoreTimes()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('sheet')
            ->withAnyArgs()
            ->zeroOrMoreTimes()
            ->andReturnSelf();
        $this->mockSheets->shouldReceive('all')
            ->zeroOrMoreTimes()
            ->andReturn($worksheetData);

        Log::shouldReceive('warning')
            ->withAnyArgs()
            ->zeroOrMoreTimes();

        Log::shouldReceive('error')
            ->withAnyArgs()
            ->zeroOrMoreTimes();

        $result = $this->service->updateRequestResponseReceived('send', 123);

        // The test should complete without throwing exceptions
        $this->assertIsBool($result);
    }

    public function test_update_methods_handle_empty_worksheet()
    {
        // Mock the initial spreadsheet and sheet calls
        $this->mockSheets->shouldReceive('spreadsheet')
            ->with('test-spreadsheet-id')
            ->once()
            ->andReturnSelf();

        $this->mockSheets->shouldReceive('sheet')
            ->with('Send requests')
            ->once()
            ->andReturnSelf();

        $this->mockSheets->shouldReceive('all')
            ->once()
            ->andReturn([]);

        // The method should log a warning and return true for empty worksheet
        Log::shouldReceive('warning')
            ->with('Worksheet is empty', ['worksheet' => 'Send requests'])
            ->once();

        Log::shouldReceive('error')
            ->withAnyArgs()
            ->zeroOrMoreTimes();

        $result = $this->service->updateRequestResponseReceived('send', 123);

        // Should return true for empty worksheet
        $this->assertTrue($result);
    }

    public function test_exception_handling_in_various_methods()
    {
        $user = User::factory()->create();

        $this->mockSheets->shouldReceive('spreadsheet')
            ->with('test-spreadsheet-id')
            ->once()
            ->andThrow(new Exception('Connection error'));

        Log::shouldReceive('error')
            ->once();

        $result = $this->service->recordAddUser($user);

        $this->assertFalse($result);
    }
}
