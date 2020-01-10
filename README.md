# HiAPI PACT

**HiAPI PACT**

[![Latest Stable Version](https://poser.pugx.org/hiqdev/hiapi-pact/v/stable)](https://packagist.org/packages/hiqdev/hiapi-pact)
[![Total Downloads](https://poser.pugx.org/hiqdev/hiapi-pact/downloads)](https://packagist.org/packages/hiqdev/hiapi-pact)
[![Build Status](https://img.shields.io/travis/hiqdev/hiapi-pact.svg)](https://travis-ci.org/hiqdev/hiapi-pact)
[![Scrutinizer Code Coverage](https://img.shields.io/scrutinizer/coverage/g/hiqdev/hiapi-pact.svg)](https://scrutinizer-ci.com/g/hiqdev/hiapi-pact/)
[![Scrutinizer Code Quality](https://img.shields.io/scrutinizer/g/hiqdev/hiapi-pact.svg)](https://scrutinizer-ci.com/g/hiqdev/hiapi-pact/)

How to run test?

First of all you should setup `auth_login` and `auth_password` env variables to `phpunit.consumer.local.xml`
Then you should check out the `WEB_SERVER_HOST` const in `phpunit.provider.local.xml`

Then run consumer tests to generate pact
- `$ php phpunit --configuration phpunit.consumer.local.xml`

After all you can run provider tests
- `$ php phpunit --configuration phpunit.provider.local.xml`
