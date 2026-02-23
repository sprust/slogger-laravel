<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\RequestPreparer;

use SLoggerLaravel\DataResolver;
use SLoggerLaravel\Helpers\MaskHelper;
use SLoggerLaravel\RequestPreparer\RequestDataFormatter;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class RequestDataFormatterTest extends BaseTestCase
{
    public function testPrepareRequestHeadersNoMatch(): void
    {
        $formatter = new RequestDataFormatter(['/api/*'], requestHeaders: ['Authorization']);

        $headers = [
            'Authorization' => 'secret',
            'X-Test'        => ['a', 'b'],
        ];

        self::assertSame(
            $headers,
            $formatter->prepareRequestHeaders('/other/path', $headers)
        );
    }

    public function testPrepareRequestHeadersMasksByListAndPreparesValues(): void
    {
        $formatter = new RequestDataFormatter(['/api/*'], requestHeaders: ['authorization']);

        $headers = [
            'Authorization' => ['Bearer secret', 'extra'],
            'X-Test'        => ['a', 'b'],
        ];

        $prepared = $formatter->prepareRequestHeaders('/api/users', $headers);

        self::assertSame(
            MaskHelper::maskValue('Bearer secret, extra'),
            $prepared['Authorization'] ?? null
        );

        self::assertSame(
            'a, b',
            $prepared['X-Test'] ?? null
        );
    }

    public function testPrepareRequestParametersNoMatch(): void
    {
        $formatter = new RequestDataFormatter(['/api/*'], requestParameters: ['token']);

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
        $formatter = new RequestDataFormatter(
            ['/api/*'],
            hideAllRequestParameters: true,
            requestParameters: ['token']
        );

        $parameters = [
            'token' => 'abc',
            'name'  => 'test',
        ];

        self::assertSame(
            ['__cleaned' => null],
            $formatter->prepareRequestParameters('/api/users', $parameters)
        );
    }

    public function testPrepareRequestParametersMasksByPatterns(): void
    {
        $formatter = new RequestDataFormatter(
            ['/api/*'],
            requestParameters: ['user.password', 'token']
        );

        $parameters = [
            'user' => [
                'name'     => 'Bob',
                'password' => 'secret',
            ],
            'token' => 'abc',
            'list'  => ['a', 'b'],
        ];

        $prepared = $formatter->prepareRequestParameters('/api/users', $parameters);

        self::assertSame(
            MaskHelper::maskValue('secret'),
            $prepared['user']['password'] ?? null
        );

        self::assertSame(
            MaskHelper::maskValue('abc'),
            $prepared['token'] ?? null
        );

        self::assertSame(
            'Bob',
            $prepared['user']['name'] ?? null
        );
    }

    public function testPrepareResponseHeadersNoMatch(): void
    {
        $formatter = new RequestDataFormatter(['/api/*'], responseHeaders: ['X-Token']);

        $headers = [
            'X-Token' => 'secret',
            'X-Test'  => ['a', 'b'],
        ];

        self::assertSame(
            $headers,
            $formatter->prepareResponseHeaders('/other/path', $headers)
        );
    }

    public function testPrepareResponseHeadersMasksByListAndPreparesValues(): void
    {
        $formatter = new RequestDataFormatter(['/api/*'], responseHeaders: ['x-token']);

        $headers = [
            'X-Token' => ['secret', 'extra'],
            'X-Test'  => ['a', 'b'],
        ];

        $prepared = $formatter->prepareResponseHeaders('/api/users', $headers);

        self::assertSame(
            MaskHelper::maskValue('secret, extra'),
            $prepared['X-Token'] ?? null
        );

        self::assertSame(
            'a, b',
            $prepared['X-Test'] ?? null
        );
    }

    public function testPrepareResponseDataNoMatch(): void
    {
        $formatter = new RequestDataFormatter(['/api/*'], responseFields: ['token']);

        $dataResolver = new DataResolver(
            static fn() => [
                'token' => 'abc',
                'name'  => 'test',
            ]
        );

        $result = $formatter->prepareResponseData('/other/path', $dataResolver);

        self::assertTrue($result);
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
        $formatter = new RequestDataFormatter(
            ['/api/*'],
            hideAllResponseData: true,
            responseFields: ['token']
        );

        $dataResolver = new DataResolver(
            static fn() => [
                'token' => 'abc',
                'name'  => 'test',
            ]
        );

        $result = $formatter->prepareResponseData('/api/users', $dataResolver);

        self::assertFalse($result);
        self::assertSame(
            ['__cleaned' => null],
            $dataResolver->getData()
        );
    }

    public function testPrepareResponseDataMasksByPatterns(): void
    {
        $formatter = new RequestDataFormatter(
            ['/api/*'],
            responseFields: ['user.password', 'token']
        );

        $dataResolver = new DataResolver(
            static fn() => [
                'user' => [
                    'name'     => 'Bob',
                    'password' => 'secret',
                ],
                'token' => 'abc',
            ]
        );

        $result = $formatter->prepareResponseData('/api/users', $dataResolver);

        self::assertTrue($result);

        $data = $dataResolver->getData();

        self::assertSame(
            MaskHelper::maskValue('secret'),
            $data['user']['password'] ?? null
        );

        self::assertSame(
            MaskHelper::maskValue('abc'),
            $data['token'] ?? null
        );

        self::assertSame(
            'Bob',
            $data['user']['name'] ?? null
        );
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
