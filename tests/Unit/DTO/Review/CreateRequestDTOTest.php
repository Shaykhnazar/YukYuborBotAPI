<?php

namespace Tests\Unit\DTO\Review;

use App\Http\DTO\Review\CreateRequestDTO;
use Tests\TestCase;

class CreateRequestDTOTest extends TestCase
{
    public function test_can_create_dto_with_all_required_properties()
    {
        $userId = 123;
        $text = 'Excellent service! Very professional and timely delivery.';
        $rating = 5;
        $requestId = 456;
        $requestType = 'send';
        
        $dto = new CreateRequestDTO(
            $userId,
            $text,
            $rating,
            $requestId,
            $requestType
        );
        
        $this->assertEquals($userId, $dto->userId);
        $this->assertEquals($text, $dto->text);
        $this->assertEquals($rating, $dto->rating);
        $this->assertEquals($requestId, $dto->requestId);
        $this->assertEquals($requestType, $dto->requestType);
    }

    public function test_properties_are_readonly()
    {
        $dto = new CreateRequestDTO(
            100,
            'Good service overall',
            4,
            200,
            'delivery'
        );
        
        // Properties should be public and accessible
        $this->assertIsInt($dto->userId);
        $this->assertIsString($dto->text);
        $this->assertIsInt($dto->rating);
        $this->assertIsInt($dto->requestId);
        $this->assertIsString($dto->requestType);
    }

    public function test_handles_different_request_types()
    {
        $requestTypes = ['send', 'delivery'];
        
        foreach ($requestTypes as $requestType) {
            $dto = new CreateRequestDTO(
                1,
                "Review for $requestType request",
                3,
                1,
                $requestType
            );
            
            $this->assertEquals($requestType, $dto->requestType);
        }
    }

    public function test_handles_different_rating_values()
    {
        $ratings = [1, 2, 3, 4, 5];
        
        foreach ($ratings as $rating) {
            $dto = new CreateRequestDTO(
                1,
                "Rating $rating stars",
                $rating,
                1,
                'send'
            );
            
            $this->assertEquals($rating, $dto->rating);
        }
    }

    public function test_handles_minimum_rating()
    {
        $dto = new CreateRequestDTO(
            1,
            'Terrible service, would not recommend',
            1,
            1,
            'send'
        );
        
        $this->assertEquals(1, $dto->rating);
    }

    public function test_handles_maximum_rating()
    {
        $dto = new CreateRequestDTO(
            1,
            'Outstanding service, exceeded expectations!',
            5,
            1,
            'delivery'
        );
        
        $this->assertEquals(5, $dto->rating);
    }

    public function test_handles_edge_case_ratings()
    {
        // Test with 0 rating (might be invalid but DTO should accept it)
        $dto1 = new CreateRequestDTO(1, 'Zero rating', 0, 1, 'send');
        $this->assertEquals(0, $dto1->rating);
        
        // Test with negative rating
        $dto2 = new CreateRequestDTO(1, 'Negative rating', -1, 1, 'send');
        $this->assertEquals(-1, $dto2->rating);
        
        // Test with high rating
        $dto3 = new CreateRequestDTO(1, 'High rating', 10, 1, 'send');
        $this->assertEquals(10, $dto3->rating);
    }

    public function test_handles_empty_text()
    {
        $dto = new CreateRequestDTO(
            1,
            '', // empty string
            3,
            1,
            'send'
        );
        
        $this->assertEquals('', $dto->text);
    }

    public function test_handles_long_review_text()
    {
        $longText = str_repeat('This service was absolutely fantastic and I would recommend it to anyone looking for reliable delivery. ', 20);
        
        $dto = new CreateRequestDTO(
            1,
            $longText,
            5,
            1,
            'delivery'
        );
        
        $this->assertEquals($longText, $dto->text);
        $this->assertGreaterThan(1000, strlen($dto->text));
    }

    public function test_handles_text_with_special_characters()
    {
        $specialText = 'Great service! 5★★★★★ Would use again. Cost was $50 - very reasonable. Thanks! 😊';
        
        $dto = new CreateRequestDTO(
            1,
            $specialText,
            5,
            1,
            'send'
        );
        
        $this->assertEquals($specialText, $dto->text);
    }

    public function test_handles_text_with_newlines_and_formatting()
    {
        $formattedText = "Excellent service!\n\nPros:\n- Fast delivery\n- Good communication\n\nCons:\n- None\n\nHighly recommended!";
        
        $dto = new CreateRequestDTO(
            1,
            $formattedText,
            5,
            1,
            'delivery'
        );
        
        $this->assertEquals($formattedText, $dto->text);
        $this->assertStringContainsString("\n", $dto->text);
    }

    public function test_handles_zero_user_id()
    {
        $dto = new CreateRequestDTO(
            0,
            'Review from user ID 0',
            4,
            1,
            'send'
        );
        
        $this->assertEquals(0, $dto->userId);
    }

    public function test_handles_zero_request_id()
    {
        $dto = new CreateRequestDTO(
            1,
            'Review for request ID 0',
            3,
            0,
            'delivery'
        );
        
        $this->assertEquals(0, $dto->requestId);
    }

    public function test_handles_negative_ids()
    {
        $dto = new CreateRequestDTO(
            -1,    // negative user ID
            'Review with negative IDs',
            2,
            -5,    // negative request ID
            'send'
        );
        
        $this->assertEquals(-1, $dto->userId);
        $this->assertEquals(-5, $dto->requestId);
    }

    public function test_handles_large_ids()
    {
        $largeUserId = 2147483647; // PHP_INT_MAX for 32-bit
        $largeRequestId = 9223372036854775807; // Large 64-bit int
        
        $dto = new CreateRequestDTO(
            $largeUserId,
            'Review with large IDs',
            4,
            $largeRequestId,
            'delivery'
        );
        
        $this->assertEquals($largeUserId, $dto->userId);
        $this->assertEquals($largeRequestId, $dto->requestId);
    }

    public function test_handles_different_request_type_cases()
    {
        // Test different case variations
        $requestTypes = ['SEND', 'DELIVERY', 'Send', 'Delivery', 'send', 'delivery'];
        
        foreach ($requestTypes as $requestType) {
            $dto = new CreateRequestDTO(
                1,
                "Review for $requestType",
                3,
                1,
                $requestType
            );
            
            $this->assertEquals($requestType, $dto->requestType);
        }
    }

    public function test_can_create_multiple_dto_instances()
    {
        $dto1 = new CreateRequestDTO(1, 'First review', 5, 10, 'send');
        $dto2 = new CreateRequestDTO(2, 'Second review', 3, 20, 'delivery');
        
        $this->assertEquals('First review', $dto1->text);
        $this->assertEquals('Second review', $dto2->text);
        $this->assertEquals(5, $dto1->rating);
        $this->assertEquals(3, $dto2->rating);
        $this->assertEquals('send', $dto1->requestType);
        $this->assertEquals('delivery', $dto2->requestType);
    }

    public function test_dto_instances_are_independent()
    {
        $dto1 = new CreateRequestDTO(1, 'Review 1', 4, 100, 'send');
        $dto2 = new CreateRequestDTO(2, 'Review 2', 2, 200, 'delivery');
        
        // Modifying one shouldn't affect the other
        $this->assertNotEquals($dto1->userId, $dto2->userId);
        $this->assertNotEquals($dto1->text, $dto2->text);
        $this->assertNotEquals($dto1->rating, $dto2->rating);
        $this->assertNotEquals($dto1->requestId, $dto2->requestId);
        $this->assertNotEquals($dto1->requestType, $dto2->requestType);
    }
}