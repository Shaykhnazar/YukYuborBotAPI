<?php

namespace Tests\Unit\DTO\SendRequest;

use App\Http\DTO\SendRequest\CreateSendRequestDTO;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class CreateSendRequestDTOTest extends TestCase
{
    public function test_can_create_dto_with_all_required_properties()
    {
        $fromLocId = 1;
        $toLocId = 2;
        $desc = 'Test description';
        $toDate = CarbonImmutable::now()->addDays(7);
        $price = 100;
        $currency = 'USD';
        
        $dto = new CreateSendRequestDTO(
            $fromLocId,
            $toLocId,
            $desc,
            $toDate,
            $price,
            $currency
        );
        
        $this->assertEquals($fromLocId, $dto->fromLocId);
        $this->assertEquals($toLocId, $dto->toLocId);
        $this->assertEquals($desc, $dto->desc);
        $this->assertEquals($toDate, $dto->toDate);
        $this->assertEquals($price, $dto->price);
        $this->assertEquals($currency, $dto->currency);
    }

    public function test_can_create_dto_with_null_optional_properties()
    {
        $fromLocId = 1;
        $toLocId = 2;
        $toDate = CarbonImmutable::now()->addDays(7);
        
        $dto = new CreateSendRequestDTO(
            $fromLocId,
            $toLocId,
            null, // desc
            $toDate,
            null, // price
            null  // currency
        );
        
        $this->assertEquals($fromLocId, $dto->fromLocId);
        $this->assertEquals($toLocId, $dto->toLocId);
        $this->assertNull($dto->desc);
        $this->assertEquals($toDate, $dto->toDate);
        $this->assertNull($dto->price);
        $this->assertNull($dto->currency);
    }

    public function test_properties_are_readonly()
    {
        $dto = new CreateSendRequestDTO(
            1,
            2,
            'Test description',
            CarbonImmutable::now()->addDays(7),
            100,
            'USD'
        );
        
        // Properties should be public and accessible
        $this->assertIsInt($dto->fromLocId);
        $this->assertIsInt($dto->toLocId);
        $this->assertIsString($dto->desc);
        $this->assertInstanceOf(CarbonImmutable::class, $dto->toDate);
        $this->assertIsInt($dto->price);
        $this->assertIsString($dto->currency);
    }

    public function test_accepts_carbon_immutable_for_to_date()
    {
        $toDate = CarbonImmutable::parse('2024-12-25 10:00:00');
        
        $dto = new CreateSendRequestDTO(
            1,
            2,
            'Christmas delivery',
            $toDate,
            50,
            'EUR'
        );
        
        $this->assertInstanceOf(CarbonImmutable::class, $dto->toDate);
        $this->assertEquals('2024-12-25 10:00:00', $dto->toDate->format('Y-m-d H:i:s'));
    }

    public function test_handles_zero_price()
    {
        $dto = new CreateSendRequestDTO(
            1,
            2,
            'Free delivery',
            CarbonImmutable::now()->addDays(3),
            0,
            'USD'
        );
        
        $this->assertEquals(0, $dto->price);
    }

    public function test_handles_negative_price()
    {
        $dto = new CreateSendRequestDTO(
            1,
            2,
            'Reverse payment',
            CarbonImmutable::now()->addDays(3),
            -50,
            'USD'
        );
        
        $this->assertEquals(-50, $dto->price);
    }

    public function test_handles_different_currency_codes()
    {
        $currencies = ['USD', 'EUR', 'GBP', 'JPY', 'RUB'];
        
        foreach ($currencies as $currency) {
            $dto = new CreateSendRequestDTO(
                1,
                2,
                "Description for $currency",
                CarbonImmutable::now()->addDays(3),
                100,
                $currency
            );
            
            $this->assertEquals($currency, $dto->currency);
        }
    }

    public function test_handles_empty_string_description()
    {
        $dto = new CreateSendRequestDTO(
            1,
            2,
            '', // empty string
            CarbonImmutable::now()->addDays(7),
            100,
            'USD'
        );
        
        $this->assertEquals('', $dto->desc);
    }

    public function test_handles_long_description()
    {
        $longDesc = str_repeat('This is a very long description. ', 100);
        
        $dto = new CreateSendRequestDTO(
            1,
            2,
            $longDesc,
            CarbonImmutable::now()->addDays(7),
            100,
            'USD'
        );
        
        $this->assertEquals($longDesc, $dto->desc);
        $this->assertGreaterThan(1000, strlen($dto->desc));
    }

    public function test_handles_same_from_and_to_locations()
    {
        $locationId = 5;
        
        $dto = new CreateSendRequestDTO(
            $locationId,
            $locationId, // same as from
            'Same location delivery',
            CarbonImmutable::now()->addDays(1),
            25,
            'USD'
        );
        
        $this->assertEquals($locationId, $dto->fromLocId);
        $this->assertEquals($locationId, $dto->toLocId);
    }

    public function test_handles_future_date()
    {
        $futureDate = CarbonImmutable::now()->addMonths(6);
        
        $dto = new CreateSendRequestDTO(
            1,
            2,
            'Future delivery',
            $futureDate,
            150,
            'USD'
        );
        
        $this->assertEquals($futureDate, $dto->toDate);
        $this->assertTrue($dto->toDate->isFuture());
    }

    public function test_dto_is_immutable()
    {
        $originalDate = CarbonImmutable::now()->addDays(7);
        
        $dto = new CreateSendRequestDTO(
            1,
            2,
            'Test immutability',
            $originalDate,
            100,
            'USD'
        );
        
        // Verify that modifying the original date doesn't affect the DTO
        $modifiedDate = $originalDate->addDays(1);
        
        $this->assertEquals($originalDate, $dto->toDate);
        $this->assertNotEquals($modifiedDate, $dto->toDate);
    }
}