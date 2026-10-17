<?php

namespace Tests\Unit\Services;

use App\Models\EntryChunk;
use App\Services\AI\AiHttpClient;
use App\Services\AI\RerankService;
use App\Services\Search\HybridSearchResult;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Support\Collection;
use Tests\TestCase;

class RerankServiceTest extends TestCase
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

    /**
     * @return Collection<int, HybridSearchResult>
     */
    private function results(string ...$bodies): Collection
    {
        return collect($bodies)->map(function (string $body, int $index) {
            $chunk = new EntryChunk(['body' => $body]);
            $chunk->id = $index + 1;

            return new HybridSearchResult($chunk, 1.0 - ($index * 0.01));
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.rerank_enabled' => true]);
    }

    public function test_the_model_order_is_applied(): void
    {
        $service = new RerankService($this->client(fn () => ['order' => [1, 0]]));

        $reranked = $service->rerank('question', $this->results('a', 'b'));

        $this->assertSame(['b', 'a'], $reranked->pluck('chunk.body')->all());
    }

    public function test_a_transport_failure_keeps_the_fused_order(): void
    {
        $service = new RerankService($this->client(function () {
            throw new ConnectException('Connection refused', new GuzzleRequest('POST', 'rerank'));
        }));

        $reranked = $service->rerank('question', $this->results('a', 'b'));

        $this->assertSame(['a', 'b'], $reranked->pluck('chunk.body')->all());
    }

    public function test_results_the_model_omitted_are_kept_at_the_tail(): void
    {
        $service = new RerankService($this->client(fn () => ['order' => [1]]));

        $reranked = $service->rerank('question', $this->results('a', 'b', 'c'));

        // The model kept only "b"; the rest keep their fused order behind it.
        $this->assertSame(['b', 'a', 'c'], $reranked->pluck('chunk.body')->all());
    }

    public function test_a_non_integer_order_falls_back(): void
    {
        $service = new RerankService($this->client(fn () => ['order' => 'nope']));

        $reranked = $service->rerank('question', $this->results('a', 'b'));

        $this->assertSame(['a', 'b'], $reranked->pluck('chunk.body')->all());
    }

    public function test_an_empty_result_set_is_returned_untouched_without_a_call(): void
    {
        $service = new RerankService($this->client(function () {
            $this->fail('The model should not be called for an empty list.');
        }));

        $this->assertCount(0, $service->rerank('question', collect()));
    }

    public function test_disabling_rerank_skips_the_model(): void
    {
        config(['ai.rerank_enabled' => false]);

        $service = new RerankService($this->client(function () {
            $this->fail('The model should not be called when reranking is off.');
        }));

        $this->assertSame(['a', 'b'], $service->rerank('question', $this->results('a', 'b'))->pluck('chunk.body')->all());
    }
}
