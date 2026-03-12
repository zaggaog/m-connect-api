<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TracingService
{
    protected static $enabled = false;
    protected static $serviceName = 'm-connect-api';
    protected static $endpoint = 'http://localhost:4318/v1/traces';
    protected static $traces = [];
    protected static $currentSpan = null;

    public static function init()
    {
        self::$enabled = config('app.tracing_enabled', false);
        self::$serviceName = config('app.name', 'm-connect-api');
        self::$endpoint = config('app.otlp_endpoint', 'http://localhost:4318/v1/traces');
    }

    public static function startSpan(string $name, array $attributes = []): ?string
    {
        if (!self::$enabled) {
            return null;
        }

        $spanId = self::generateSpanId();
        $traceId = self::$currentSpan ? self::$traces[self::$currentSpan]['traceId'] : self::generateTraceId();

        self::$traces[$spanId] = [
            'spanId' => $spanId,
            'traceId' => $traceId,
            'name' => $name,
            'startTime' => microtime(true),
            'attributes' => $attributes,
            'parentSpanId' => self::$currentSpan,
        ];

        self::$currentSpan = $spanId;

        return $spanId;
    }

    public static function endSpan(?string $spanId, array $additionalAttributes = [])
    {
        if (!self::$enabled || !$spanId || !isset(self::$traces[$spanId])) {
            return;
        }

        self::$traces[$spanId]['endTime'] = microtime(true);
        self::$traces[$spanId]['duration'] = self::$traces[$spanId]['endTime'] - self::$traces[$spanId]['startTime'];
        self::$traces[$spanId]['attributes'] = array_merge(
            self::$traces[$spanId]['attributes'],
            $additionalAttributes
        );

        // Send to OTLP endpoint
        self::exportSpan(self::$traces[$spanId]);

        // Reset current span to parent
        self::$currentSpan = self::$traces[$spanId]['parentSpanId'];
    }

    public static function addEvent(string $name, array $attributes = [])
    {
        if (!self::$enabled || !self::$currentSpan) {
            return;
        }

        if (!isset(self::$traces[self::$currentSpan]['events'])) {
            self::$traces[self::$currentSpan]['events'] = [];
        }

        self::$traces[self::$currentSpan]['events'][] = [
            'name' => $name,
            'timestamp' => microtime(true),
            'attributes' => $attributes,
        ];
    }

    public static function recordException(\Throwable $exception)
    {
        if (!self::$enabled || !self::$currentSpan) {
            return;
        }

        self::addEvent('exception', [
            'exception.type' => get_class($exception),
            'exception.message' => $exception->getMessage(),
            'exception.stacktrace' => $exception->getTraceAsString(),
        ]);
    }

    protected static function exportSpan(array $span)
    {
        try {
            $payload = self::formatOTLPPayload($span);
            
            Http::timeout(1)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post(self::$endpoint, $payload);
        } catch (\Exception $e) {
            // Silently fail to avoid disrupting the application
            logger()->debug('Failed to export trace: ' . $e->getMessage());
        }
    }

    protected static function formatOTLPPayload(array $span): array
    {
        $startTimeNanos = (int)($span['startTime'] * 1_000_000_000);
        $endTimeNanos = (int)($span['endTime'] * 1_000_000_000);

        $attributes = [];
        foreach ($span['attributes'] as $key => $value) {
            $attributes[] = [
                'key' => $key,
                'value' => ['stringValue' => (string)$value],
            ];
        }

        return [
            'resourceSpans' => [
                [
                    'resource' => [
                        'attributes' => [
                            ['key' => 'service.name', 'value' => ['stringValue' => self::$serviceName]],
                            ['key' => 'service.version', 'value' => ['stringValue' => '1.0.0']],
                        ],
                    ],
                    'scopeSpans' => [
                        [
                            'scope' => [
                                'name' => self::$serviceName,
                                'version' => '1.0.0',
                            ],
                            'spans' => [
                                [
                                    'traceId' => $span['traceId'],
                                    'spanId' => $span['spanId'],
                                    'parentSpanId' => $span['parentSpanId'] ?? '',
                                    'name' => $span['name'],
                                    'kind' => 1, // SPAN_KIND_INTERNAL
                                    'startTimeUnixNano' => $startTimeNanos,
                                    'endTimeUnixNano' => $endTimeNanos,
                                    'attributes' => $attributes,
                                    'status' => ['code' => 1], // STATUS_CODE_OK
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    protected static function generateTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    protected static function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }
}