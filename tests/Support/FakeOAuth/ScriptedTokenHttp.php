<?php

declare(strict_types=1);

namespace Tests\Support\FakeOAuth;

use Closure;
use Whity\Core\Http\HttpClient;

/** Answers token requests from a script, and counts them (WHIT-901). */
final class ScriptedTokenHttp implements HttpClient
{
    /** @var list<array<string, mixed>|null> */
    public array $responses = [];
    public int $calls = 0;

    /** @param (Closure(): void)|null $during Runs while the request is "in flight". */
    public function __construct(private readonly ?Closure $during = null)
    {
    }

    public function getJson(string $url): ?array
    {
        return null;
    }

    public function postForm(string $url, array $params): ?array
    {
        $this->calls++;
        if ($this->during !== null) {
            ($this->during)();
        }

        return array_shift($this->responses);
    }
}
