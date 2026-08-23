<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Request;

use Illuminate\Http\UploadedFile;
use SLoggerLaravel\Helpers\BodyDecoder;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Watchers\Parents\RequestWatcher;

/**
 * A body above the cap is not recorded at all.
 *
 * The cap is not about disk: the masker refuses to look inside a string this long,
 * so a body it will not read would travel to the receiver exactly as the client
 * sent it - secrets included. Every one of these caps was uncovered, and the
 * request side had none at all: an 8 MB json field was recorded whole.
 */
class BodySizeCapTest extends BaseWatcherTestCase
{
    private const CAP = 1000;

    /**
     * A body the request itself declares as oversized is dropped before input()
     * decodes it: this runs in the traced application's own request, and a 20 MB
     * json body must not be parsed just to be thrown away afterwards.
     *
     * The padding is what makes that visible - the document is far over the cap and
     * what it decodes to is two fields, so anything recorded here means the body was
     * parsed first.
     */
    public function testABodyDeclaredTooLargeIsNotEvenParsed(): void
    {
        $this->registerCappedWatcher();

        $content = '{"page":"2",' . str_repeat(' ', self::CAP * 2) . '"note":"s3cr3t"}';

        $this->call(
            method: 'POST',
            uri: route('slogger.xml'),
            server: [
                'CONTENT_TYPE'   => 'application/json',
                'CONTENT_LENGTH' => (string) strlen($content),
            ],
            content: $content
        )->assertOk();

        $data = $this->recordedRequestData();

        self::assertSame(['__skipped' => 'request_too_large'], $data['request']['parameters']);

        self::assertStringNotContainsString('s3cr3t', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testAJsonRequestBodyBelowTheCapIsRecordedAsBefore(): void
    {
        $this->registerCappedWatcher();

        $this->call(
            method: 'POST',
            uri: route('slogger.xml'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['page' => '2'], JSON_THROW_ON_ERROR)
        )->assertOk();

        self::assertSame(['page' => '2'], $this->recordedRequestData()['request']['parameters']);
    }

    /**
     * A chunked request declares no length, so the header check cannot see it. What
     * was actually collected is measured too - the json path had neither check, and
     * an 8 MB field was recorded whole and then shipped unmasked, because the masker
     * refuses to read a string that long.
     */
    public function testAnUndeclaredOversizedBodyIsCaughtAfterItIsParsed(): void
    {
        $this->registerCappedWatcher();

        $content = json_encode(['blob' => str_repeat('s3cr3t', self::CAP)], JSON_THROW_ON_ERROR);

        $this->call(
            method: 'POST',
            uri: route('slogger.xml'),
            // no Content-Length, which is what a chunked request looks like
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $content
        )->assertOk();

        $data = $this->recordedRequestData();

        self::assertSame(['__skipped' => 'request_too_large'], $data['request']['parameters']);

        self::assertStringNotContainsString('s3cr3t', json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * The declared length of a multipart request is mostly file bytes, and of a file
     * only the name and the size is ever recorded. Measuring it would drop the
     * fields of every upload.
     */
    public function testAnUploadIsNotSkippedBecauseOfTheFileItCarries(): void
    {
        $this->registerCappedWatcher();

        $this->call(
            method: 'POST',
            uri: route('slogger.xml'),
            parameters: ['page' => '2'],
            files: ['report' => UploadedFile::fake()->create('report.csv', 50)],
            server: [
                'CONTENT_TYPE'   => 'multipart/form-data; boundary=----slogger',
                'CONTENT_LENGTH' => (string) (self::CAP * 100),
            ]
        )->assertOk();

        $parameters = $this->recordedRequestData()['request']['parameters'];

        self::assertSame('2', $parameters['page']);
        self::assertSame('report.csv', $parameters['report']['name']);
    }

    public function testAnXmlRequestBodyAboveTheCapIsNotRecorded(): void
    {
        $this->registerCappedWatcher();

        $this->call(
            method: 'POST',
            uri: route('slogger.xml'),
            server: ['CONTENT_TYPE' => 'application/xml'],
            content: '<order><note>' . str_repeat('s3cr3t', self::CAP) . '</note></order>'
        )->assertOk();

        $data = $this->recordedRequestData();

        self::assertSame(['__skipped' => 'request_too_large'], $data['request']['parameters']);

        self::assertStringNotContainsString('s3cr3t', json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * The two caps are separate settings, and the xml path measured the request
     * against the response one: with a small `output.max_content_length` an XML
     * request body well inside `input.max_content_length` was dropped, and with a
     * large one it was parsed in the traced application's own request path before
     * anything checked its size.
     */
    public function testAnXmlRequestBodyIsMeasuredAgainstTheInputCap(): void
    {
        $this->registerWatcher(
            RequestWatcher::class,
            [
                'input'  => ['max_content_length' => self::CAP * 100],
                'output' => ['max_content_length' => self::CAP],
            ]
        );

        $content = '<order><note>' . str_repeat('x', self::CAP * 2) . '</note></order>';

        $this->call(
            method: 'POST',
            uri: route('slogger.xml'),
            server: [
                'CONTENT_TYPE'   => 'application/xml',
                'CONTENT_LENGTH' => (string) strlen($content),
            ],
            content: $content
        )->assertOk();

        $parameters = $this->recordedRequestData()['request']['parameters'];

        self::assertSame($content, $parameters[BodyDecoder::XML_KEY]);
    }

    public function testAResponseBodyAboveTheCapIsNotRecorded(): void
    {
        $this->registerCappedWatcher();

        $this->getJson(route('slogger.big', ['bytes' => self::CAP * 10]))->assertOk();

        self::assertSame(
            ['__skipped' => 'response_too_large'],
            $this->recordedRequestData()['response']['data']
        );
    }

    public function testAResponseBodyBelowTheCapIsRecordedAsBefore(): void
    {
        $this->registerCappedWatcher();

        $this->getJson(route('slogger.big', ['bytes' => 10]))->assertOk();

        self::assertSame(
            ['blob' => str_repeat('a', 10)],
            $this->recordedRequestData()['response']['data']
        );
    }

    private function registerCappedWatcher(): void
    {
        $this->registerWatcher(
            RequestWatcher::class,
            [
                'input'  => ['max_content_length' => self::CAP],
                'output' => ['max_content_length' => self::CAP],
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function recordedRequestData(): array
    {
        $creating = $this->dispatcher->findCreating(type: 'request');

        self::assertCount(1, $creating);

        $updating = $this->dispatcher->findUpdating(traceId: $creating[0]->traceId);

        self::assertCount(1, $updating);

        /** @var array<string, mixed> $data */
        $data = $updating[0]->data ?? [];

        return $data;
    }
}
