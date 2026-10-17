<?php

namespace Tests\Unit\Fundamentals;

use App\Services\Fundamentals\SecEdgarClient;
use App\Services\Fundamentals\SecUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesSecEdgar;
use Tests\TestCase;

/**
 * SEC EDGAR client: User-Agent, spacing, bounded retries and failure reasons.
 * Offline (`Http::fake`, fake clock).
 */
class SecEdgarClientTest extends TestCase
{
    use FakesSecEdgar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableSec();
    }

    public function test_every_request_carries_the_user_agent_and_gzip_and_is_spaced(): void
    {
        $sleeper = $this->fakeSleeper();
        $this->fakeSec();
        $client = $this->app->make(SecEdgarClient::class);

        $map = $client->tickers();
        $client->submissions('320193');
        $client->companyFacts('0000320193');

        $this->assertSame('0000320193', $map['AAPL']);
        $this->assertSame('0001067983', $map['BRK-B'], 'SEC writes class shares with a dash');
        $this->assertSame($map['GOOG'], $map['GOOGL'], 'share classes share a CIK');
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('User-Agent', 'Chartiko tests ops@example.com')
            && $request->hasHeader('Accept-Encoding', 'gzip, deflate'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://data.sec.gov/submissions/CIK0000320193.json');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://data.sec.gov/api/xbrl/companyfacts/CIK0000320193.json');
        // 5 requests per second: 0.2 s between consecutive request starts.
        $this->assertSame([0.2, 0.2], $sleeper->sleeps);
    }

    public function test_spacing_follows_the_configured_rate_and_is_capped_below_ten(): void
    {
        config()->set('fundamentals.requests_per_second', 50);
        $sleeper = $this->fakeSleeper();
        $this->fakeSec();
        $client = $this->app->make(SecEdgarClient::class);

        $client->submissions('320193');
        $client->submissions('789019');

        $this->assertSame([round(1 / 9, 6)], $sleeper->sleeps);
    }

    public function test_429_and_5xx_are_retried_with_backoff(): void
    {
        $sleeper = $this->fakeSleeper();
        $this->fakeSec(['data.sec.gov/submissions/*' => Http::sequence()
            ->push('slow down', 429)
            ->push('oops', 503)
            ->push(self::secFixture('submissions_aapl'))]);

        $data = $this->app->make(SecEdgarClient::class)->submissions('320193');

        $this->assertSame('3571', $data['sic']);
        Http::assertSentCount(3);
        // Backoff 2 s then 8 s; the backoff already covers the request spacing.
        $this->assertSame([2.0, 8.0], $sleeper->sleeps);
    }

    public function test_retries_are_bounded_and_timeouts_count(): void
    {
        $sleeper = $this->fakeSleeper();
        $attempts = 0;
        $this->fakeSec(['data.sec.gov/*' => function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('cURL error 28: timed out');
        }]);

        try {
            $this->app->make(SecEdgarClient::class)->companyFacts('320193');
            $this->fail('expected SecUnavailable');
        } catch (SecUnavailable $exception) {
            $this->assertSame('unavailable', $exception->reason);
        }
        $this->assertSame(3, $attempts, 'one request + two retries');
        $this->assertSame([2.0, 8.0], $sleeper->sleeps);
    }

    public function test_server_errors_after_the_retries_are_unavailable(): void
    {
        $this->fakeSleeper();
        $this->fakeSec(['data.sec.gov/*' => Http::response('down', 500)]);

        $this->assertReason('unavailable', fn (SecEdgarClient $client) => $client->submissions('320193'));
        Http::assertSentCount(3);
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function failures(): array
    {
        return [
            'forbidden' => [403, 'forbidden'],
            'not found' => [404, 'not_found'],
            'redirect is never followed' => [301, 'http_error'],
            'bad request' => [400, 'http_error'],
        ];
    }

    #[DataProvider('failures')]
    public function test_client_errors_are_not_retried(int $status, string $reason): void
    {
        $this->fakeSleeper();
        $this->fakeSec(['data.sec.gov/*' => Http::response('', $status, $status === 301 ? ['Location' => 'https://evil.example/x'] : [])]);

        $this->assertReason($reason, fn (SecEdgarClient $client) => $client->companyFacts('320193'));
        Http::assertSentCount(1);
    }

    public function test_non_json_body_is_invalid_response(): void
    {
        $this->fakeSleeper();
        $this->fakeSec(['data.sec.gov/*' => Http::response('<html>maintenance</html>', 200)]);

        $this->assertReason('invalid_response', fn (SecEdgarClient $client) => $client->submissions('320193'));
    }

    /** @return array<string, array{0: mixed, 1: bool}> */
    public static function userAgents(): array
    {
        return [
            'name and email' => ['Chartiko ops@chartiko.com', true],
            'bracketed email' => ['Chartiko Ops <ops@chartiko.com>', true],
            'empty' => ['', false],
            'null' => [null, false],
            'email only' => ['ops@chartiko.com', false],
            'name only' => ['Chartiko', false],
            'library default' => ['GuzzleHttp/7', false],
            'control characters' => ["Chartiko ops@chartiko.com\r\nX-Evil: 1", false],
        ];
    }

    #[DataProvider('userAgents')]
    public function test_user_agent_must_name_the_operator_and_an_email(mixed $value, bool $valid): void
    {
        $this->assertSame($valid, SecEdgarClient::validUserAgent($value));
    }

    public function test_configuration_gates(): void
    {
        $client = $this->app->make(SecEdgarClient::class);
        $this->assertTrue($client->isConfigured());

        config()->set('fundamentals.data_base_url', 'http://127.0.0.1:8211');
        $this->assertTrue($client->isConfigured(), 'local QA fake');
        config()->set('fundamentals.data_base_url', 'http://sec.example.test');
        $this->assertFalse($client->isConfigured(), 'plain http off localhost');
        config()->set('fundamentals.data_base_url', 'https://data.sec.gov');

        config()->set('fundamentals.user_agent', '');
        $this->assertFalse($client->isConfigured());
        config()->set('fundamentals.user_agent', 'Chartiko ops@example.com');
        config()->set('fundamentals.enabled', false);
        $this->assertFalse($client->isConfigured());
    }

    public function test_defaults_ship_disabled_without_a_user_agent(): void
    {
        $config = require base_path('config/fundamentals.php');

        $this->assertFalse($config['enabled']);
        $this->assertSame('', $config['user_agent']);
        $this->assertSame(5, $config['requests_per_second']);
        $this->assertSame(2, $config['retries']);
        $this->assertSame('https://data.sec.gov', $config['data_base_url']);
        $this->assertSame([6000, 6411], $config['financial_sic']);
    }

    /** @param callable(SecEdgarClient): mixed $call */
    private function assertReason(string $reason, callable $call): void
    {
        try {
            $call($this->app->make(SecEdgarClient::class));
            $this->fail('expected SecUnavailable');
        } catch (SecUnavailable $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }
}
