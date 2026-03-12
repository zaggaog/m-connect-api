<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class ExpoPushService
{
    protected $apiUrl = 'https://exp.host/--/api/v2/push/send';

    /**
     * Validate Expo push token format
     */
    protected function isValidExpoToken($token): bool
    {
        if (!is_string($token) || empty($token)) {
            return false;
        }

        // Old format: ExponentPushToken[xxxxxxxxxxxxxx]
        if (preg_match('/^ExponentPushToken\[[a-zA-Z0-9]+\]$/', $token)) {
            return true;
        }

        // Also accept: ExpoPushToken[xxxxxxxxxxxxxx]
        if (preg_match('/^ExpoPushToken\[[a-zA-Z0-9]+\]$/', $token)) {
            return true;
        }

        // New format (after Expo migration): alphanumeric string
        if (preg_match('/^[a-zA-Z0-9_-]{22,}$/', $token)) {
            return true;
        }

        return false;
    }

    /**
     * Send HTTP request using PHP's curl or file_get_contents
     */
    protected function makeHttpRequest(array $data)
    {
        $payload = json_encode($data);
        
        // Try curl first (most reliable)
        if (function_exists('curl_init')) {
            return $this->makeCurlRequest($payload);
        }
        
        // Fallback to file_get_contents
        return $this->makeFileGetContentsRequest($payload);
    }

    /**
     * Make HTTP request using curl
     */
    protected function makeCurlRequest(string $payload)
    {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Accept-encoding: gzip, deflate',
                'Content-Length: ' . strlen($payload),
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        curl_close($ch);
        
        if ($error) {
            Log::error('CURL error', ['error' => $error]);
            return null;
        }
        
        return [
            'body' => $response,
            'status' => $httpCode
        ];
    }

    /**
     * Make HTTP request using file_get_contents (fallback)
     */
    protected function makeFileGetContentsRequest(string $payload)
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Content-Length: ' . strlen($payload),
                ]),
                'content' => $payload,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ]
        ]);
        
        try {
            $response = file_get_contents($this->apiUrl, false, $context);
            
            // Parse HTTP status code from response headers
            if (isset($http_response_header[0])) {
                preg_match('/HTTP\/\d\.\d\s+(\d+)/', $http_response_header[0], $matches);
                $statusCode = $matches[1] ?? 0;
            } else {
                $statusCode = 0;
            }
            
            return [
                'body' => $response,
                'status' => $statusCode
            ];
        } catch (\Exception $e) {
            Log::error('file_get_contents error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Send push notification to single device
     */
    public function sendToDevice(string $token, string $title, string $body, array $data = []): bool
    {
        if (!$this->isValidExpoToken($token)) {
            Log::warning('Invalid Expo push token format', ['token' => $token]);
            return false;
        }

        try {
            $payload = [
                'to' => $token,
                'title' => $title,
                'body' => $body,
                'sound' => 'default',
                'badge' => 1,
                'data' => $data,
                'channelId' => 'default',
                'priority' => 'high',
            ];

            $response = $this->makeHttpRequest($payload);
            
            if ($response === null) {
                Log::error('HTTP request failed - no response');
                return false;
            }
            
            if ($response['status'] !== 200) {
                Log::error('HTTP request failed', [
                    'status' => $response['status'],
                    'body' => substr($response['body'], 0, 200) . '...'
                ]);
                return false;
            }
            
            $result = json_decode($response['body'], true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Invalid JSON response', [
                    'error' => json_last_error_msg(),
                    'body' => substr($response['body'], 0, 200)
                ]);
                return false;
            }
            
            if (isset($result['data'][0]['status']) && $result['data'][0]['status'] === 'ok') {
                Log::info('Push notification sent successfully', [
                    'token' => substr($token, 0, 30) . '...',
                    'title' => $title,
                    'message_id' => $result['data'][0]['id'] ?? 'unknown'
                ]);
                return true;
            }
            
            if (isset($result['errors'])) {
                Log::error('Expo API returned errors', [
                    'errors' => $result['errors'],
                    'token' => substr($token, 0, 30) . '...'
                ]);
            } else {
                Log::error('Expo push notification failed', [
                    'response' => $result,
                    'token' => substr($token, 0, 30) . '...'
                ]);
            }
            
            return false;

        } catch (\Exception $e) {
            Log::error('Exception sending push notification', [
                'error' => $e->getMessage(),
                'token' => substr($token, 0, 30) . '...'
            ]);
            return false;
        }
    }

    /**
     * Send notification to multiple devices
     */
    public function sendToMultipleDevices(array $tokens, string $title, string $body, array $data = []): array
    {
        $results = [];
        $validTokens = [];

        // Filter and validate tokens
        foreach ($tokens as $token) {
            if ($this->isValidExpoToken($token)) {
                $validTokens[] = $token;
            } else {
                Log::warning('Invalid token skipped', ['token' => $token]);
                $results[$token] = [
                    'success' => false,
                    'error' => 'Invalid token format'
                ];
            }
        }

        if (empty($validTokens)) {
            Log::warning('No valid tokens provided for batch notification');
            return $results;
        }

        try {
            // Prepare messages
            $messages = [];
            foreach ($validTokens as $token) {
                $messages[] = [
                    'to' => $token,
                    'title' => $title,
                    'body' => $body,
                    'sound' => 'default',
                    'badge' => 1,
                    'data' => $data,
                    'channelId' => 'default',
                    'priority' => 'high',
                ];
            }

            $response = $this->makeHttpRequest($messages);
            
            if ($response === null) {
                // Mark all as failed
                foreach ($validTokens as $token) {
                    $results[$token] = [
                        'success' => false,
                        'error' => 'HTTP request failed'
                    ];
                }
                return $results;
            }
            
            if ($response['status'] !== 200) {
                foreach ($validTokens as $token) {
                    $results[$token] = [
                        'success' => false,
                        'error' => 'HTTP ' . $response['status']
                    ];
                }
                return $results;
            }
            
            $result = json_decode($response['body'], true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                foreach ($validTokens as $token) {
                    $results[$token] = [
                        'success' => false,
                        'error' => 'Invalid JSON response'
                    ];
                }
                return $results;
            }
            
            // Process individual responses
            if (isset($result['data']) && is_array($result['data'])) {
                foreach ($result['data'] as $index => $item) {
                    $token = $validTokens[$index] ?? 'unknown';
                    
                    if (isset($item['status']) && $item['status'] === 'ok') {
                        $results[$token] = [
                            'success' => true,
                            'message_id' => $item['id'] ?? null
                        ];
                        Log::info('Batch notification sent', [
                            'token' => substr($token, 0, 30) . '...',
                            'message_id' => $item['id'] ?? 'unknown'
                        ]);
                    } else {
                        $results[$token] = [
                            'success' => false,
                            'error' => $item['message'] ?? 'Unknown error'
                        ];
                        Log::error('Batch notification failed', [
                            'token' => substr($token, 0, 30) . '...',
                            'error' => $item['message'] ?? 'Unknown error'
                        ]);
                    }
                }
            } else {
                foreach ($validTokens as $token) {
                    $results[$token] = [
                        'success' => false,
                        'error' => 'Invalid response format'
                    ];
                }
            }

        } catch (\Exception $e) {
            foreach ($validTokens as $token) {
                $results[$token] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
            Log::error('Exception in batch notification', [
                'error' => $e->getMessage()
            ]);
        }

        return $results;
    }

    /**
     * Notify farmer about new order
     */
    public function notifyFarmerNewOrder($farmerToken, $orderData): bool
    {
        $message = isset($orderData['buyer_name']) && isset($orderData['total_amount'])
            ? "{$orderData['buyer_name']} placed an order for {$orderData['total_amount']} TZS"
            : 'You have received a new order';

        return $this->sendToDevice(
            $farmerToken,
            '🎉 New Order Received!',
            $message,
            [
                'type' => 'new_order',
                'orderId' => $orderData['order_id'] ?? null,
                'screen' => 'SellerOrderDetails',
                'buyerName' => $orderData['buyer_name'] ?? '',
                'totalAmount' => $orderData['total_amount'] ?? 0,
                'timestamp' => now()->toISOString(),
            ]
        );
    }

    /**
     * Notify buyer about order status change
     */
    public function notifyBuyerOrderStatus($buyerToken, $orderData): bool
    {
        $statusMessages = [
            'pending' => '⏳ Your order is pending confirmation',
            'confirmed' => '✅ Your order has been confirmed!',
            'processing' => '🔄 Your order is being processed',
            'shipped' => '🚚 Your order has been shipped',
            'delivered' => '🎉 Your order has been delivered!',
            'completed' => '✅ Your order has been completed!',
            'cancelled' => '❌ Your order has been cancelled',
            'rejected' => '❌ Seller rejected your order',
        ];

        $status = $orderData['status'] ?? 'unknown';
        $message = $statusMessages[$status] ?? "Your order status has been updated to: {$status}";

        return $this->sendToDevice(
            $buyerToken,
            'Order Update',
            $message,
            [
                'type' => 'order_status_update',
                'orderId' => $orderData['order_id'] ?? null,
                'status' => $status,
                'screen' => 'OrderDetails',
                'orderNumber' => $orderData['order_number'] ?? '',
                'updatedAt' => $orderData['updated_at'] ?? now()->toISOString(),
            ]
        );
    }

    /**
     * Send chat notification
     */
    public function notifyNewMessage($recipientToken, $senderName, $message, $chatId): bool
    {
        return $this->sendToDevice(
            $recipientToken,
            "💬 New message from {$senderName}",
            strlen($message) > 100 ? substr($message, 0, 100) . '...' : $message,
            [
                'type' => 'new_message',
                'chatId' => $chatId,
                'senderName' => $senderName,
                'screen' => 'ChatScreen',
                'timestamp' => now()->toISOString(),
            ]
        );
    }

    /**
     * Simple test method to verify service works
     */
    public function testConnection(): bool
    {
        try {
            // Send a test notification to a dummy token
            // Expo will reject it but we can check if the API is reachable
            $testData = ['test' => true];
            $response = $this->makeHttpRequest($testData);
            
            return $response !== null && $response['status'] === 400;
            // Expo returns 400 for invalid requests, which means the API is reachable
        } catch (\Exception $e) {
            return false;
        }
    }
}