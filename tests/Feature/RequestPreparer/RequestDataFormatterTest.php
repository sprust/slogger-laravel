<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\RequestPreparer;

use SLoggerLaravel\DataResolver;
use SLoggerLaravel\RequestPreparer\Masks;
use SLoggerLaravel\RequestPreparer\RequestDataFormatter;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

/**
 * The formatter hides and reshapes at runtime, and masks what only the urls it
 * matches need. The global lists are the dispatcher job's business.
 */
class RequestDataFormatterTest extends BaseTestCase
{
    public function testPrepareRequestHeadersNoMatch(): void
    {
        $formatter = new RequestDataFormatter(['/api/*']);

        $headers = [
            'Authorization' => 'secret',
            'X-Test'        => ['a', 'b'],
        ];

        // an unmatched url is left exactly as it came in, not even reshaped
        self::assertSame(
            $headers,
            $formatter->prepareRequestHeaders('/other/path', $headers)
        );
    }

    public function testPrepareRequestHeadersJoinsValues(): void
    {
        $formatter = new RequestDataFormatter(['/api/*']);

        $prepared = $formatter->prepareRequestHeaders(
            '/api/users',
            [
                'Authorization' => ['Bearer secret', 'extra'],
                'X-Test'        => ['a', 'b'],
            ]
        );

        self::assertSame('Bearer secret, extra', $prepared['Authorization'] ?? null);
        self::assertSame('a, b', $prepared['X-Test'] ?? null);
    }

    public function testPrepareRequestParametersNoMatch(): void
    {
        $formatter = new RequestDataFormatter(['/api/*'], hideAllRequestParameters: true);

        $parameters = [
            'token' => 'abc',
            'name'  => 'test',
        ];

        self::assertSame(
            $parameters,
            $formatter->prepareRequestParameters('/other/path', $parameters)
        );
    }

    public function testPrepareRequestParametersHideAll(): void
    {
        $formatter = new RequestDataFormatter(['/api/*'], hideAllRequestParameters: true);

        self::assertSame(
            ['__cleaned' => null],
            $formatter->prepareRequestParameters(
                '/api/users',
                [
                    'token' => 'abc',
                    'name'  => 'test',
                ]
            )
        );
    }

    public function testPrepareRequestParametersAreKeptAsIs(): void
    {
        $formatter = new RequestDataFormatter(['/api/*']);

        $parameters = [
            'user' => [
                'name'     => 'Bob',
                'password' => 'secret',
            ],
            'token' => 'abc',
        ];

        self::assertSame(
            $parameters,
            $formatter->prepareRequestParameters('/api/users', $parameters)
        );
    }

    public function testPrepareResponseHeadersJoinsValues(): void
    {
        $formatter = new RequestDataFormatter(['/api/*']);

        $prepared = $formatter->prepareResponseHeaders(
            '/api/users',
            [
                'X-Token' => ['secret', 'extra'],
                'X-Test'  => ['a', 'b'],
            ]
        );

        self::assertSame('secret, extra', $prepared['X-Token'] ?? null);
        self::assertSame('a, b', $prepared['X-Test'] ?? null);
    }

    public function testPrepareResponseDataNoMatch(): void
    {
        $formatter = new RequestDataFormatter(['/api/*'], hideAllResponseData: true);

        $dataResolver = new DataResolver(
            static fn() => [
                'token' => 'abc',
                'name'  => 'test',
            ]
        );

        self::assertTrue($formatter->prepareResponseData('/other/path', $dataResolver));

        self::assertSame(
            [
                'token' => 'abc',
                'name'  => 'test',
            ],
            $dataResolver->getData()
        );
    }

    public function testPrepareResponseDataHideAll(): void
    {
        $formatter = new RequestDataFormatter(['/api/*'], hideAllResponseData: true);

        $dataResolver = new DataResolver(
            static fn() => [
                'token' => 'abc',
                'name'  => 'test',
            ]
        );

        self::assertFalse($formatter->prepareResponseData('/api/users', $dataResolver));

        self::assertSame(
            ['__cleaned' => null],
            $dataResolver->getData()
        );
    }

    public function testPrepareResponseDataIsKeptAsIs(): void
    {
        $formatter = new RequestDataFormatter(['/api/*']);

        $data = [
            'user' => [
                'name'     => 'Bob',
                'password' => 'secret',
            ],
            'token' => 'abc',
        ];

        $dataResolver = new DataResolver(static fn() => $data);

        self::assertTrue($formatter->prepareResponseData('/api/users', $dataResolver));

        self::assertSame($data, $dataResolver->getData());
    }

    public function testHideFlagsAndUrlPatternTrim(): void
    {
        $formatter = new RequestDataFormatter(['/api/*/']);

        self::assertFalse($formatter->isHideAllRequestParameters());
        self::assertFalse($formatter->isHideAllResponseData());

        $formatter
            ->setHideAllRequestParameters(true)
            ->setHideAllResponseData(true);

        self::assertTrue($formatter->isHideAllRequestParameters());
        self::assertTrue($formatter->isHideAllResponseData());

        $headers = [
            'X-Test' => 'value',
        ];

        self::assertSame(
            $headers,
            $formatter->prepareRequestHeaders('/api/users', $headers)
        );
    }

    public function testRequestHeadersAreMaskedForAMatchingUrlOnly(): void
    {
        $formatter = new RequestDataFormatter(
            urlPatterns: ['/api/*'],
            requestHeaders: ['authorization']
        );

        $headers = [
            'Authorization' => ['Bearer secret'],
            'X-Test'        => ['a', 'b'],
        ];

        $prepared = $formatter->prepareRequestHeaders('/api/users', $headers);

        self::assertSame('********', $prepared['Authorization'] ?? null);
        self::assertSame('a, b', $prepared['X-Test'] ?? null);

        // a url this formatter was never given is not its business
        self::assertSame($headers, $formatter->prepareRequestHeaders('/other/path', $headers));
    }

    public function testRequestParametersTakeAllThreeLists(): void
    {
        $formatter = new RequestDataFormatter(
            urlPatterns: ['/api/*'],
            requestParameters: [
                'full_keys'      => ['ticket'],
                'partial_keys'   => ['holder'],
                'value_patterns' => ['/\d{16}/'],
            ]
        );

        self::assertSame(
            [
                'ticket' => '********',
                'holder' => 'al*****er',
                'note'   => 'card 41************11',
                'page'   => 2,
            ],
            $formatter->prepareRequestParameters(
                '/api/users',
                [
                    'ticket' => 'tk-secret',
                    'holder' => 'alexander',
                    'note'   => 'card 4111111111111111',
                    'page'   => 2,
                ]
            )
        );
    }

    public function testHidingTheParametersLeavesNothingToMask(): void
    {
        $formatter = new RequestDataFormatter(
            urlPatterns: ['/api/*'],
            hideAllRequestParameters: true,
            requestParameters: ['ticket']
        );

        self::assertSame(
            ['__cleaned' => null],
            $formatter->prepareRequestParameters('/api/users', ['ticket' => 'tk-secret'])
        );
    }

    public function testResponseHeadersAreMasked(): void
    {
        $formatter = new RequestDataFormatter(
            urlPatterns: ['/api/*'],
            responseHeaders: new Masks(fullKeys: ['set-cookie'])
        );

        $prepared = $formatter->prepareResponseHeaders(
            '/api/users',
            [
                'set-cookie'   => ['session=abc123'],
                'content-type' => ['application/json'],
            ]
        );

        self::assertSame('********', $prepared['set-cookie'] ?? null);
        self::assertSame('application/json', $prepared['content-type'] ?? null);
    }

    public function testResponseFieldsAreMasked(): void
    {
        $formatter = new RequestDataFormatter(
            urlPatterns: ['/api/*'],
            responseFields: new Masks(fullKeys: ['account_number'])
        );

        $dataResolver = new DataResolver(
            static fn() => [
                'account_number' => '4111111111111111',
                'balance'        => 100,
            ]
        );

        self::assertTrue($formatter->prepareResponseData('/api/users', $dataResolver));

        self::assertSame(
            [
                'account_number' => '********',
                'balance'        => 100,
            ],
            $dataResolver->getData()
        );
    }

    public function testWithoutMasksTheBodyIsNotReadAtAll(): void
    {
        $formatter = new RequestDataFormatter(['/api/*']);

        $read = false;

        $dataResolver = new DataResolver(
            static function () use (&$read): array {
                $read = true;

                return [];
            }
        );

        self::assertTrue($formatter->prepareResponseData('/api/users', $dataResolver));

        // decoding a body nobody asked for is a cost the traced application pays
        // for nothing
        self::assertFalse($read);
    }

    public function testTheAddMethodsWidenWhatWasConfigured(): void
    {
        $formatter = new RequestDataFormatter(
            urlPatterns: ['/api/*'],
            requestHeaders: ['authorization']
        );

        $formatter->addRequestHeaders(['x-partner-signature']);

        $prepared = $formatter->prepareRequestHeaders(
            '/api/users',
            [
                'authorization'       => 'Bearer secret',
                'x-partner-signature' => 'sig',
                'accept'              => 'application/json',
            ]
        );

        self::assertSame('********', $prepared['authorization'] ?? null);
        self::assertSame('********', $prepared['x-partner-signature'] ?? null);
        self::assertSame('application/json', $prepared['accept'] ?? null);
    }

    public function testTheAddMethodsTakeMasksToo(): void
    {
        $formatter = new RequestDataFormatter(['/api/*']);

        $formatter
            ->addRequestParameters(new Masks(partialKeys: ['holder']))
            ->addResponseFields(['account_number'])
            ->addResponseHeaders(['set-cookie']);

        self::assertSame(
            ['holder' => 'al*****er'],
            $formatter->prepareRequestParameters('/api/users', ['holder' => 'alexander'])
        );

        self::assertSame(
            ['set-cookie' => '********'],
            $formatter->prepareResponseHeaders('/api/users', ['set-cookie' => 'session=abc'])
        );

        $dataResolver = new DataResolver(static fn() => ['account_number' => '4111']);

        self::assertTrue($formatter->prepareResponseData('/api/users', $dataResolver));
        self::assertSame(['account_number' => '********'], $dataResolver->getData());
    }
}
