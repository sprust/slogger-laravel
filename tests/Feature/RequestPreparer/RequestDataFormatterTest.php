<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\RequestPreparer;

use SLoggerLaravel\DataResolver;
use SLoggerLaravel\RequestPreparer\RequestDataFormatter;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

/**
 * The formatter only hides and reshapes at runtime - masking is the dispatcher
 * job's business.
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
}
