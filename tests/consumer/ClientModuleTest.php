<?php

namespace hiapi\pact\tests\consumer;

use hiapi\pact\tests\ConsumerTestCase;

class ClientModuleTest extends ConsumerTestCase
{
    public function testClientModule(): void
    {
        $this->runInteraction();
    }

    public function dataProvider(): array
    {
        return [
            'clientSearch' => [
                [
                    'login' => 'tofid',
                ],
                '123',
            ],
        ];
    }
}
