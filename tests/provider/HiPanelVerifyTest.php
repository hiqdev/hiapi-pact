<?php

namespace hiapi\pact\tests\provider;

use Exception;
use GuzzleHttp\Psr7\Uri;
use hiapi\pact\tests\ProviderTestCase;
use PhpPact\Standalone\ProviderVerifier\Model\VerifierConfig;
use PhpPact\Standalone\ProviderVerifier\Verifier;
use Yii;

class HiPanelVerifyTest extends ProviderTestCase
{
    public const PACT_DIR = "@hiapi/pact/tests/pacts/";

    public function testHiPanel()
    {
        $url = 'http://' . WEB_SERVER_HOST . ':' . WEB_SERVER_PORT;

        $config = new VerifierConfig();
        $config
            ->setProviderName('HiPanel')
            ->setProviderBaseUrl(new Uri($url)) // URL of the Provider.
            ->addCustomProviderHeader('X-Json-Prefer-Array', '1')
            ->setBrokerUri(new Uri('http://localhost'));


        $hasException = false;
        $exceptionDetails = '';
        try {
            $file = Yii::getAlias(self::PACT_DIR) . 'hipanel-hiapi.json';

            // could be build an object mapper to make this easier
            $verifier = new Verifier($config);
            $verifier->verifyFiles([$file]);
        } catch (Exception $e) {
            $hasException = true;
            $exceptionDetails = $e->getMessage();
        }

        $this->assertFalse($hasException, "Expect Pact to validate: " . $exceptionDetails);
    }
}
