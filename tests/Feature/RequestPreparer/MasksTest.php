<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\RequestPreparer;

use SLoggerLaravel\RequestPreparer\Masks;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class MasksTest extends BaseTestCase
{
    public function testAPlainListIsAListOfFullMasks(): void
    {
        $masks = Masks::from(['authorization']);

        self::assertNotNull($masks);
        self::assertSame(
            ['authorization' => '********', 'accept' => 'application/json'],
            $masks->apply(['authorization' => 'Bearer secret', 'accept' => 'application/json'])
        );
    }

    public function testTheNamedFormTakesAllThreeLists(): void
    {
        $masks = Masks::from([
            'full_keys'      => ['ticket'],
            'partial_keys'   => ['holder'],
            'value_patterns' => ['/\d{16}/'],
        ]);

        self::assertNotNull($masks);

        self::assertSame(
            [
                'ticket' => '********',
                'holder' => 'al*****er',
                'note'   => 'card 41************11',
            ],
            $masks->apply([
                'ticket' => 'tk-secret',
                'holder' => 'alexander',
                'note'   => 'card 4111111111111111',
            ])
        );
    }

    public function testTheTopLevelIsMasked(): void
    {
        // unlike a trace, whose first level belongs to the watcher, a header bag is
        // the application's own all the way up
        $masks = Masks::from(['token']);

        self::assertNotNull($masks);
        self::assertSame(['token' => '********'], $masks->apply(['token' => 'secret']));
    }

    public function testNothingUsableIsNoMasksAtAll(): void
    {
        self::assertNull(Masks::from([]));
        self::assertNull(Masks::from(null));
        self::assertNull(Masks::from('token'));
        self::assertNull(Masks::from(['full_keys' => []]));
        // a stray non-string must not reach the compiler
        self::assertNull(Masks::from([null, 1, '']));
        self::assertNull(Masks::from(new Masks()));
    }

    public function testAStringListIsAcceptedInTheNamedForm(): void
    {
        $masks = Masks::from(['full_keys' => 'ticket']);

        self::assertNotNull($masks);
        self::assertSame(['ticket' => '********'], $masks->apply(['ticket' => 'tk']));
    }

    public function testMergeKeepsBothSides(): void
    {
        $merged = (new Masks(fullKeys: ['ticket']))
            ->merge(new Masks(partialKeys: ['holder']));

        self::assertSame(
            ['ticket' => '********', 'holder' => 'al*****er'],
            $merged->apply(['ticket' => 'tk-secret', 'holder' => 'alexander'])
        );
    }

    public function testMergeLeavesTheOriginalsAlone(): void
    {
        $original = new Masks(fullKeys: ['ticket']);

        $original->merge(new Masks(fullKeys: ['holder']));

        self::assertSame(
            ['ticket' => '********', 'holder' => 'alexander'],
            $original->apply(['ticket' => 'tk-secret', 'holder' => 'alexander'])
        );
    }
}
