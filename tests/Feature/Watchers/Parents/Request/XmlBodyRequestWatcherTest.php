<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Request;

use SLoggerLaravel\Helpers\BodyDecoder;
use SLoggerLaravel\Helpers\MaskHelper;
use SLoggerLaravel\Helpers\TraceDataMasker;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Watchers\Parents\RequestWatcher;

/**
 * Laravel parses form and JSON bodies into input() and leaves an XML one alone, and
 * the response side only recorded a body when the client asked for JSON. A SOAP or
 * XML-API call was therefore traced with no body at all, in either direction.
 */
class XmlBodyRequestWatcherTest extends BaseWatcherTestCase
{
    public function testAnXmlRequestAndResponseAreRecordedAndMasked(): void
    {
        $this->registerWatcher(RequestWatcher::class, null);

        $sent = '<order><api_token>sk-live-request</api_token><amount>100</amount></order>';

        $this->call(
            method: 'POST',
            uri: route('slogger.xml'),
            server: [
                'CONTENT_TYPE' => 'application/xml',
                'HTTP_ACCEPT'  => 'application/xml',
            ],
            content: $sent
        )->assertOk();

        $creating = $this->dispatcher->findCreating(type: 'request');

        self::assertCount(1, $creating);

        $updating = $this->dispatcher->findUpdating(traceId: $creating[0]->traceId);

        self::assertCount(1, $updating);

        $data = $updating[0]->data ?? [];

        // the document itself, not an array approximation of it: converting XML to an
        // array loses attributes, repeated elements and namespaces
        self::assertSame($sent, $data['request']['parameters'][BodyDecoder::XML_KEY]);

        self::assertStringContainsString(
            '<api_token>sk-live-response</api_token>',
            $data['response']['data'][BodyDecoder::XML_KEY]
        );

        $masked = app(TraceDataMasker::class)->mask($data);

        foreach (
            [
                $masked['request']['parameters'][BodyDecoder::XML_KEY],
                $masked['response']['data'][BodyDecoder::XML_KEY],
            ] as $document
        ) {
            self::assertStringContainsString(
                '<api_token>' . MaskHelper::FULL_MASK . '</api_token>',
                $document
            );
        }

        // and what did not match is still there
        self::assertStringContainsString('<amount>100</amount>', $masked['request']['parameters'][BodyDecoder::XML_KEY]);
        self::assertStringContainsString('<page>2</page>', $masked['response']['data'][BodyDecoder::XML_KEY]);
    }

    public function testAFormRequestIsUnaffected(): void
    {
        $this->registerWatcher(RequestWatcher::class, null);

        $this->post(route('slogger.xml'), ['page' => '2'])->assertOk();

        $creating = $this->dispatcher->findCreating(type: 'request');

        $updating = $this->dispatcher->findUpdating(traceId: $creating[0]->traceId);

        $parameters = ($updating[0]->data ?? [])['request']['parameters'];

        // the ordinary path is untouched: input() answered, so the body is not read
        self::assertSame(['page' => '2'], $parameters);
    }
}
