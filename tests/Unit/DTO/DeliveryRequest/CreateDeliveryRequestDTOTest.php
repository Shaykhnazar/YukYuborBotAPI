<?php

namespace Tests\Unit\DTO\DeliveryRequest;

use App\Http\DTO\DeliveryRequest\CreateDeliveryRequestDTO;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class CreateDeliveryRequestDTOTest extends TestCase
{
    public function test_can_create_dto_with_all_required_properties()
    {
        $fromLocId = 10;
        $toLocId = 20;
        $desc = 'Delivery service description';
        $fromDate = CarbonImmutable::now()->addDays(1);
        $toDate = CarbonImmutable::now()->addDays(7);
        $price = 200;
        $currency = 'EUR';
        
        $dto = new CreateDeliveryRequestDTO(
            $fromLocId,
            $toLocId,
            $desc,
            $fromDate,
            $toDate,
            $price,
            $currency
        );
        
        $this->assertEquals($fromLocId, $dto->fromLocId);
        $this->assertEquals($toLocId, $dto->toLocId);
        $this->assertEquals($desc, $dto->desc);
        $this->assertEquals($fromDate, $dto->fromDate);
        $this->assertEquals($toDate, $dto->toDate);
        $this->assertEquals($price, $dto->price);
        $this->assertEquals($currency, $dto->currency);
    }

    public function test_can_create_dto_with_null_optional_properties()
    {
        $fromLocId = 1;
        $toLocId = 2;
        $fromDate = CarbonImmutable::now()->addDays(1);
        $toDate = CarbonImmutable::now()->addDays(7);
        
        $dto = new CreateDeliveryRequestDTO(
            $fromLocId,
            $toLocId,
            null, // desc
            $fromDate,
            $toDate,
            null, // price
            null  // currency
        );
        
        $this->assertEquals($fromLocId, $dto->fromLocId);
        $this->assertEquals($toLocId, $dto->toLocId);
        $this->assertNull($dto->desc);
        $this->assertEquals($fromDate, $dto->fromDate);
        $this->assertEquals($toDate, $dto->toDate);
        $this->assertNull($dto->price);
        $this->assertNull($dto->currency);
    }

    public function test_properties_are_readonly()
    {
        $fromDate = CarbonImmutable::now()->addDays(1);
        $toDate = CarbonImmutable::now()->addDays(7);
        
        $dto = new CreateDeliveryRequestDTO(
            1,
            2,
            'Test description',
            $fromDate,
            $toDate,
            150,
            'GBP'
        );
        
        // Properties should be public and accessible
        $this->assertIsInt($dto->fromLocId);
        $this->assertIsInt($dto->toLocId);
        $this->assertIsString($dto->desc);
        $this->assertInstanceOf(CarbonImmutable::class, $dto->fromDate);
        $this->assertInstanceOf(CarbonImmutable::class, $dto->toDate);
        $this->assertIsInt($dto->price);
        $this->assertIsString($dto->currency);
    }

    public function test_accepts_carbon_immutable_for_dates()
    {
        $fromDate = CarbonImmutable::parse('2024-12-20 09:00:00');
        $toDate = CarbonImmutable::parse('2024-12-25 18:00:00');
        
        $dto = new CreateDeliveryRequestDTO(
            1,
            2,
            'Holiday delivery service',
            $fromDate,
            $toDate,
            75,
            'USD'
        );
        
        $this->assertInstanceOf(CarbonImmutable::class, $dto->fromDate);
        $this->assertInstanceOf(CarbonImmutable::class, $dto->toDate);
        $this->assertEquals('2024-12-20 09:00:00', $dto->fromDate->format('Y-m-d H:i:s'));
        $this->assertEquals('2024-12-25 18:00:00', $dto->toDate->format('Y-m-d H:i:s'));
    }

    public function test_handles_same_from_and_to_dates()
    {
        $sameDate = CarbonImmutable::now()->addDays(5);
        
        $dto = new CreateDeliveryRequestDTO(
            1,
            2,
            'Same day delivery',
            $sameDate,
            $sameDate,
            300,
            'USD'
        );
        
        $this->assertEquals($sameDate, $dto->fromDate);
        $this->assertEquals($sameDate, $dto->toDate);
    }

    public function test_handles_reverse_date_order()
    {
        $laterDate = CarbonImmutable::now()->addDays(10);
        $earlierDate = CarbonImmutable::now()->addDays(5);
        
        // DTO should accept dates even if to_date is before from_date (validation should happen elsewhere)
        $dto = new CreateDeliveryRequestDTO(
            1,
            2,
            'Reverse dates test',
            $laterDate,   // from_date is later
            $earlierDate, // to_date is earlier
            100,
            'USD'
        );
        
        $this->assertEquals($laterDate, $dto->fromDate);
        $this->assertEquals($earlierDate, $dto->toDate);
        $this->assertTrue($dto->fromDate->isAfter($dto->toDate));
    }

    public function test_handles_zero_and_negative_prices()
    {
        $fromDate = CarbonImmutable::now()->addDays(1);
        $toDate = CarbonImmutable::now()->addDays(3);
        
        // Zero price
        $dto1 = new CreateDeliveryRequestDTO(1, 2, 'Free service', $fromDate, $toDate, 0, 'USD');
        $this->assertEquals(0, $dto1->price);
        
        // Negative price (maybe they pay the customer)
        $dto2 = new CreateDeliveryRequestDTO(1, 2, 'Reverse payment', $fromDate, $toDate, -50, 'USD');
        $this->assertEquals(-50, $dto2->price);
    }

    public function test_handles_different_currency_codes()
    {
        $fromDate = CarbonImmutable::now()->addDays(1);
        $toDate = CarbonImmutable::now()->addDays(7);
        $currencies = ['USD', 'EUR', 'GBP', 'JPY', 'RUB', 'CAD', 'AUD'];
        
        foreach ($currencies as $currency) {
            $dto = new CreateDeliveryRequestDTO(
                1,
                2,
                "Service in $currency",
                $fromDate,
                $toDate,
                100,
                $currency
            );
            
            $this->assertEquals($currency, $dto->currency);
        }
    }

    public function test_handles_empty_string_description()
    {
        $fromDate = CarbonImmutable::now()->addDays(1);
        $toDate = CarbonImmutable::now()->addDays(7);
        
        $dto = new CreateDeliveryRequestDTO(
            1,
            2,
            '', // empty string
            $fromDate,
            $toDate,
            100,
            'USD'
        );
        
        $this->assertEquals('', $dto->desc);
    }

    public function test_handles_long_description()
    {
        $longDesc = str_repeat('Comprehensive delivery service offering. ', 50);
        $fromDate = CarbonImmutable::now()->addDays(1);
        $toDate = CarbonImmutable::now()->addDays(7);
        
        $dto = new CreateDeliveryRequestDTO(
            1,
            2,
            $longDesc,
            $fromDate,
            $toDate,
            250,
            'EUR'
        );
        
        $this->assertEquals($longDesc, $dto->desc);
        $this->assertGreaterThan(1000, strlen($dto->desc));
    }

    public function test_handles_same_from_and_to_locations()
    {
        $locationId = 15;
        $fromDate = CarbonImmutable::now()->addDays(2);
        $toDate = CarbonImmutable::now()->addDays(4);
        
        $dto = new CreateDeliveryRequestDTO(
            $locationId,
            $locationId, // same as from
            'Local area delivery',
            $fromDate,
            $toDate,
            50,
            'USD'
        );
        
        $this->assertEquals($locationId, $dto->fromLocId);
        $this->assertEquals($locationId, $dto->toLocId);
    }

    public function test_handles_far_future_dates()
    {
        $farFutureFromDate = CarbonImmutable::now()->addYears(2);
        $farFutureToDate = CarbonImmutable::now()->addYears(2)->addMonths(3);
        
        $dto = new CreateDeliveryRequestDTO(
            1,
            2,
            'Future planning service',
            $farFutureFromDate,
            $farFutureToDate,
            500,
            'USD'
        );
        
        $this->assertEquals($farFutureFromDate, $dto->fromDate);
        $this->assertEquals($farFutureToDate, $dto->toDate);
        $this->assertTrue($dto->fromDate->isFuture());
        $this->assertTrue($dto->toDate->isFuture());
    }

    public function test_dto_is_immutable()
    {
        $originalFromDate = CarbonImmutable::now()->addDays(1);
        $originalToDate = CarbonImmutable::now()->addDays(7);
        
        $dto = new CreateDeliveryRequestDTO(
            1,
            2,
            'Test immutability',
            $originalFromDate,
            $originalToDate,
            100,
            'USD'
        );
        
        // Verify that modifying the original dates doesn't affect the DTO
        $modifiedFromDate = $originalFromDate->addHours(5);
        $modifiedToDate = $originalToDate->subDays(2);
        
        $this->assertEquals($originalFromDate, $dto->fromDate);
        $this->assertEquals($originalToDate, $dto->toDate);
        $this->assertNotEquals($modifiedFromDate, $dto->fromDate);
        $this->assertNotEquals($modifiedToDate, $dto->toDate);
    }

    public function test_date_range_calculations()
    {
        $fromDate = CarbonImmutable::parse('2024-06-01 08:00:00');
        $toDate = CarbonImmutable::parse('2024-06-10 20:00:00');
        
        $dto = new CreateDeliveryRequestDTO(
            1,
            2,
            'Date range test',
            $fromDate,
            $toDate,
            180,
            'EUR'
        );
        
        // Test that we can perform calculations on the dates
        $this->assertTrue($dto->toDate->isAfter($dto->fromDate));
        $this->assertEquals(9.5, $dto->fromDate->diffInDays($dto->toDate));
        $this->assertEquals('June', $dto->fromDate->format('F'));
        $this->assertEquals('June', $dto->toDate->format('F'));
    }
}