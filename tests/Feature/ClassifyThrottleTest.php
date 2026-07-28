<?php

namespace Tests\Feature;

use App\Contracts\GarmentClassifier;
use App\Testing\FakeGarmentClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ClassifyThrottleTest extends TestCase
{
    use RefreshDatabase;

    private const APP_KEY = 'test-app-key';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.app.client_key' => self::APP_KEY]);
        $this->app->instance(GarmentClassifier::class, new FakeGarmentClassifier);
    }

    private function appKeyHeader(): array
    {
        return ['X-App-Key' => self::APP_KEY];
    }

    private function postClassify(array $headers): TestResponse
    {
        return $this->postJson('/api/classify', [
            'photo' => UploadedFile::fake()->image('garment.jpg'),
        ], $headers);
    }

    public function test_eleventh_request_from_same_ip_returns_429(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postClassify($this->appKeyHeader())->assertOk();
        }

        $response = $this->postClassify($this->appKeyHeader());

        $response->assertStatus(429);
        $response->assertHeader('Retry-After');
    }

    public function test_global_bucket_returns_429_across_distinct_ips(): void
    {
        // 10 distinct IPs x 10 requests each = 100, exactly saturating the
        // global 'classify-global' bucket without any single IP ever
        // reaching its own 10/min per-IP limit.
        for ($ip = 1; $ip <= 10; $ip++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$ip}"]);

            for ($i = 0; $i < 10; $i++) {
                $this->postClassify($this->appKeyHeader())->assertOk();
            }
        }

        // A fresh 11th IP is well under its own per-IP limit, so a 429 here
        // can only come from the global bucket.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.11']);
        $response = $this->postClassify($this->appKeyHeader());

        $response->assertStatus(429);
    }

    public function test_wrong_app_key_does_not_consume_limiter_budget(): void
    {
        for ($i = 0; $i < 11; $i++) {
            $this->postClassify(['X-App-Key' => 'wrong'])->assertForbidden();
        }

        // Budget must be untouched by the rejected requests: a valid-key
        // request from the same IP still succeeds instead of hitting 429,
        // proving app.key runs before throttle:classify.
        $this->postClassify($this->appKeyHeader())->assertOk();
    }

    public function test_429_response_is_json_with_message(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postClassify($this->appKeyHeader());
        }

        $response = $this->postClassify($this->appKeyHeader());

        $response->assertStatus(429)
            ->assertJsonStructure(['message']);
    }
}
