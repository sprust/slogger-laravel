<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\HttpClient;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use SLoggerLaravel\Guzzle\GuzzleHandlerFactory;
use SLoggerLaravel\RequestPreparer\Masks;
use SLoggerLaravel\RequestPreparer\RequestDataFormatter;
use SLoggerLaravel\RequestPreparer\RequestDataFormatters;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Watchers\Children\HttpClientWatcher;

/**
 * A client is configured where it is built, so its masks are too: what one partner
 * calls an account number is a plain identifier everywhere else.
 */
class HttpClientMasksTest extends BaseWatcherTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerWatcher(HttpClientWatcher::class, null);
    }

    public function testTheMasksOfOneClientReachBothDirections(): void
    {
        $this->callThrough(
            formatters: (new RequestDataFormatters())->add(
                new RequestDataFormatter(
                    urlPatterns: ['*partner.test/*'],
                    requestHeaders: ['x-partner-signature'],
                    requestParameters: new Masks(fullKeys: ['pan'], partialKeys: ['holder']),
                    responseHeaders: ['x-partner-token'],
                    responseFields: new Masks(fullKeys: ['account_number'])
                )
            ),
            url: 'https://partner.test/payments'
        );

        $data = $this->getCallData();

        self::assertSame('********', $data['request']['headers']['x-partner-signature'] ?? null);
        self::assertSame('application/json', $data['request']['headers']['Content-Type'] ?? null);

        self::assertSame('********', $data['request']['payload']['pan'] ?? null);
        self::assertSame('al*****er', $data['request']['payload']['holder'] ?? null);
        self::assertSame(100, $data['request']['payload']['amount'] ?? null);

        self::assertSame('********', $data['response']['headers']['x-partner-token'] ?? null);
        self::assertSame('********', $data['response']['body']['account_number'] ?? null);
        self::assertSame('ok', $data['response']['body']['status'] ?? null);
    }

    public function testAnotherClientsCallIsLeftAlone(): void
    {
        $this->callThrough(
            formatters: (new RequestDataFormatters())->add(
                new RequestDataFormatter(
                    urlPatterns: ['*partner.test/*'],
                    requestParameters: new Masks(fullKeys: ['pan']),
                    responseFields: new Masks(fullKeys: ['account_number'])
                )
            ),
            url: 'https://example.test/payments'
        );

        $data = $this->getCallData();

        // the global lists mask what has to be masked everywhere; this is the url
        // that formatter was never given
        self::assertSame('4111111111111111', $data['request']['payload']['pan'] ?? null);
        self::assertSame('40817810099910004312', $data['response']['body']['account_number'] ?? null);
    }

    private function callThrough(RequestDataFormatters $formatters, string $url): void
    {
        $handlerStack = app(GuzzleHandlerFactory::class)->prepareHandler(
            formatters: $formatters,
            handlerStack: HandlerStack::create(
                new MockHandler([
                    new Response(
                        status: 200,
                        headers: [
                            'Content-Type'    => 'application/json',
                            'x-partner-token' => 'tok-secret',
                        ],
                        body: (string) json_encode([
                            'status'         => 'ok',
                            'account_number' => '40817810099910004312',
                        ])
                    ),
                ])
            )
        );

        $client = new Client([
            'handler'     => $handlerStack,
            'http_errors' => false,
        ]);

        $client->request('post', $url, [
            'headers' => [
                'Content-Type'        => 'application/json',
                'x-partner-signature' => 'sig-secret',
            ],
            'body' => (string) json_encode([
                'pan'    => '4111111111111111',
                'holder' => 'alexander',
                'amount' => 100,
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function getCallData(): array
    {
        $creating = $this->dispatcher->findCreating(type: 'http-client');

        self::assertCount(1, $creating);

        $updating = $this->dispatcher->findUpdating(traceId: $creating[0]->traceId);

        self::assertCount(1, $updating);

        return $updating[0]->data ?? [];
    }
}
