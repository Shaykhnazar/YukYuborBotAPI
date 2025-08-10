<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GoogleSheetsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class GoogleSheetsController extends Controller
{
    protected $sheetsService;

    public function __construct(GoogleSheetsService $sheetsService)
    {
        $this->sheetsService = $sheetsService;
    }

    /**
     * Get worksheet information
     */
    public function getWorksheetInfo(): JsonResponse
    {
        try {
            $info = $this->sheetsService->getWorksheetInfo();
            
            return response()->json([
                'success' => true,
                'data' => $info,
                'message' => 'Worksheet information retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get worksheet info from API: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve worksheet information',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get data from specific worksheet
     */
    public function getWorksheetData(Request $request): JsonResponse
    {
        try {
            $worksheetName = $request->input('worksheet', 'Users');
            
            // Validate worksheet name
            $allowedWorksheets = ['Users', 'Deliver requests', 'Send requests'];
            if (!in_array($worksheetName, $allowedWorksheets)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid worksheet name. Allowed worksheets: ' . implode(', ', $allowedWorksheets)
                ], 400);
            }
            
            $data = $this->sheetsService->getWorksheetData($worksheetName);
            
            return response()->json([
                'success' => true,
                'worksheet' => $worksheetName,
                'data' => $data,
                'count' => count($data),
                'message' => "Data retrieved from {$worksheetName} worksheet successfully"
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get worksheet data from API: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve worksheet data',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Sync all database data to Google Sheets
     */
    public function syncAllData(): JsonResponse
    {
        try {
            $results = $this->sheetsService->syncAllData();
            
            $successful = array_filter($results);
            $failed = array_filter($results, fn($result) => !$result);
            
            return response()->json([
                'success' => count($failed) === 0,
                'message' => count($failed) === 0 
                    ? 'All data synchronized successfully' 
                    : 'Data synchronization completed with some errors',
                'results' => $results,
                'summary' => [
                    'successful' => count($successful),
                    'failed' => count($failed),
                    'total' => count($results)
                ]
            ], count($failed) === 0 ? 200 : 207); // 207 = Multi-Status for partial success
        } catch (\Exception $e) {
            Log::error('Failed to sync all data from API: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to synchronize data',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Export specific data to Google Sheets
     */
    public function exportData(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'worksheet' => 'required|string|max:100',
                'data' => 'required|array',
                'data.*' => 'array' // Each data item should be an array (row)
            ]);

            $success = $this->sheetsService->batchExport(
                $request->input('worksheet'),
                $request->input('data')
            );

            return response()->json([
                'success' => $success,
                'message' => $success 
                    ? 'Data exported successfully' 
                    : 'Failed to export data',
                'worksheet' => $request->input('worksheet'),
                'rows_exported' => count($request->input('data'))
            ], $success ? 200 : 500);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to export data from API: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to export data',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Initialize Google Sheets with proper headers
     */
    public function initializeWorksheets(): JsonResponse
    {
        try {
            $results = $this->sheetsService->initializeWorksheets();
            
            $successful = array_filter($results);
            $failed = array_filter($results, fn($result) => !$result);
            
            return response()->json([
                'success' => count($failed) === 0,
                'message' => count($failed) === 0 
                    ? 'All worksheets initialized successfully' 
                    : 'Worksheet initialization completed with some errors',
                'results' => $results,
                'summary' => [
                    'successful' => count($successful),
                    'failed' => count($failed),
                    'total' => count($results)
                ]
            ], count($failed) === 0 ? 200 : 207);
        } catch (\Exception $e) {
            Log::error('Failed to initialize worksheets from API: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to initialize worksheets',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Test Google Sheets connection
     */
    public function testConnection(): JsonResponse
    {
        try {
            $info = $this->sheetsService->getWorksheetInfo();
            
            $isConnected = !empty($info['names']);
            
            return response()->json([
                'success' => $isConnected,
                'message' => $isConnected 
                    ? 'Google Sheets connection successful' 
                    : 'Google Sheets connection failed',
                'data' => $info,
                'timestamp' => now()->toISOString()
            ], $isConnected ? 200 : 500);
        } catch (\Exception $e) {
            Log::error('Google Sheets connection test failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Google Sheets connection test failed',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
                'timestamp' => now()->toISOString()
            ], 500);
        }
    }

    /**
     * Get statistics about Google Sheets data
     */
    public function getStatistics(): JsonResponse
    {
        try {
            $info = $this->sheetsService->getWorksheetInfo();
            $stats = [
                'worksheets' => $info,
                'data_counts' => []
            ];
            
            // Get row counts for each main worksheet
            $mainWorksheets = ['Users', 'Deliver requests', 'Send requests'];
            foreach ($mainWorksheets as $worksheet) {
                if (in_array($worksheet, $info['names'])) {
                    $data = $this->sheetsService->getWorksheetData($worksheet);
                    $stats['data_counts'][$worksheet] = max(0, count($data) - 1); // Subtract header row
                }
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Statistics retrieved successfully',
                'data' => $stats,
                'timestamp' => now()->toISOString()
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get Google Sheets statistics: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve statistics',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}