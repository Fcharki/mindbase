<?php

namespace Tests\Unit\Services;

use App\Services\AI\AiHttpClient;
use App\Services\AI\QueryRewriteService;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Tests\TestCase;

class QueryRewriteServiceTest extends TestCase
{
    /**
     * @param  callable(string, array<string, mixed>): array<string, mixed>  $responder
     */
    private function client(callable $responder): AiHttpClient
    {
        return new class($responder) extends AiHttpClient
        {
            /** @var callable */
            private $responder;

            public function __construct(callable $responder)
            {
                parent::__construct(1, 0);
                $this->responder = $responder;
            }

            public function post(string $path, array $json, ?int $timeout = null): array
            {
                return ($this->responder)($path, $json);
            }
        };
    }

    public function test_a_rewritten_query_is_returned(): void
    {
        $service = new QueryRewriteService(
            $this->client(fn () => ['response' => 'credential lifetime expiry'])
        );

        $this->assertSame(
            'credential lifetime expiry',
            $service->rewrite('how do tokens expire'),
        );
    }

    public function test_quotes_and_whitespace_are_trimmed(): void
    {
        $service = new QueryRewriteService(
            $this->client(fn () => ['response' => "  \"token expiry\"\n"])
        );

        $this->assertSame('token expiry', $service->rewrite('tokens?'));
    }

    public function test_an_unchanged_rewrite_is_treated_as_no_help(): void
    {
        $service = new QueryRewriteService(
            $this->client(fn () => ['response' => 'How Do Tokens Expire'])
        );

        $this->assertNull($service->rewrite('How Do Tokens Expire'));
    }

    public function test_an_empty_rewrite_is_no_help(): void
    {
        $service = new QueryRewriteService($this->client(fn () => ['response' => '   ']));

        $this->assertNull($service->rewrite('tokens'));
    }

    public function test_a_transport_failure_returns_null_rather_than_throwing(): void
    {
        $service = new QueryRewriteService($this->client(function () {
            throw new ConnectException('Connection refused', new GuzzleRequest('POST', 'rag'));
        }));

        $this->assertNull($service->rewrite('tokens'));
    }
}
