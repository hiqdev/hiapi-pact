<?php

namespace hiapi\pact\tests\consumer;

use hiapi\pact\tests\ConsumerTestCase;

class ClientModuleTest extends ConsumerTestCase
{
    public function testClientModule(): void
    {
        $this->runInteraction();
    }
}
