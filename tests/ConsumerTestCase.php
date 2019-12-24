<?php

namespace hiapi\pact\tests;

use Exception;
use Faker\Factory;
use Generator;
use hiapi\exceptions\ConfigurationException;
use hiapi\legacy\lib\apiCommand;
use hiapi\legacy\lib\deps\arr;
use hiapi\legacy\lib\mrdpBase;
use hiapi\legacy\lib\mrdpCommand;
use hipanel\hiart\Connection;
use hiqdev\hiart\guzzle\Request;
use hiqdev\hiart\RequestErrorException;
use hiqdev\hiart\RequestInterface;
use hiqdev\yii\compat\yii;
use PhpPact\Consumer\InteractionBuilder;
use PhpPact\Consumer\Matcher\Matcher;
use PhpPact\Consumer\Model\ConsumerRequest;
use PhpPact\Consumer\Model\ProviderResponse;
use PhpPact\Standalone\MockService\MockServerConfigInterface;
use PhpPact\Standalone\MockService\MockServerEnvConfig;
use ReflectionClass;
use ReflectionException;
use yii\di\Container;
use yii\helpers\StringHelper;

abstract class ConsumerTestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Container
     */
    private $di;
    /**
     * @var mrdpBase
     */
    private $base;
    /**
     * @var string[]
     */
    protected $allowedTags = [];
    /**
     * @var string
     */
    private $moduleName;
    /**
     * @var \Faker\Generator
     */
    protected $faker;
    /**
     * @var array
     */
    private $data = [];
    /**
     * @var MockServerConfigInterface
     */
    private $serverEnvConfig;
    /**
     * @var Connection
     */
    private $connection;

    public function getServerEnvConfig(): MockServerConfigInterface
    {
        return $this->serverEnvConfig;
    }

    public function setServerEnvConfig(MockServerConfigInterface $serverEnvConfig): void
    {
        $this->serverEnvConfig = $serverEnvConfig;
    }

    public function setData(array $data): void
    {
        $this->data = $data;
    }

    protected function setUp(): void
    {
        $this->di = yii::getContainer();
        $this->faker = Factory::create();
        $this->base = $this->di->get(mrdpBase::class);
        $this->setServerEnvConfig(new MockServerEnvConfig());
        $this->serverEnvConfig->setPactFileWriteMode('merge');
        $this->connection = $this->di->get(
            Connection::class,
            [],
            [
                'requestClass' => Request::class,
                'name'         => 'hiart',
                'baseUri'      => $this->serverEnvConfig->getBaseUri(),
            ]
        );
    }

    abstract public function dataProvider(): array;

    public function setModuleName(string $moduleName): void
    {
        $this->moduleName = $moduleName;
    }

    /**
     * @throws ReflectionException
     */
    public function getModuleName(): string
    {
        if ($this->moduleName) {
            return $this->moduleName;
        }
        $className = (new ReflectionClass(static::class))->getShortName();

        return strtolower(substr($className, 0, strpos($className, 'Module')));
    }

    /**
     * @throws RequestErrorException
     */
    protected function runInteraction(array $data = []): void
    {
        $requests = [];
        $commands = iterator_to_array($this->getModuleCommands());
        $this->assertNotEmpty($commands);
        $commands = array_slice($commands, 0, 10);

        foreach ($commands as $name => $command) {
            $requests[$name] = $this->buildRequest($command, $this->buildBody($command));
        }

        $mockService = new InteractionBuilder($this->serverEnvConfig);

        foreach ($requests as $commandName => $request) {
            $consumerRequest = $this->buildConsumerRequest($request);
            $matcher = $this->buildMatcher($commands[$commandName], $request->getQuery()->body);
            $providerResponse = $this->buildProviderResponse($matcher);
            $mockService
                ->given($commandName)
                ->uponReceiving("To get request from `{$commandName}`")
                ->with($consumerRequest)
                ->willRespondWith($providerResponse);
            $mockServiceResponse = $request->send();
            $this->assertEquals(
                '200',
                $mockServiceResponse->getStatusCode(),
                'Let\'s make sure we have an OK response'
            );
            $body = (string)$mockServiceResponse->getBody();
            $this->assertTrue((json_decode($body) ? true : false), 'Expect the JSON to be decoded without error');
            $hasException = false;

            try {
                $mockService->verify();
            } catch (Exception $e) {
                $hasException = true;
            }

            $this->assertFalse($hasException, 'We expect the pacts to validate');
        }

        $this->assertFalse(false, 'We expect the pacts to validate');
    }

    protected function buildConsumerRequest(Request $request): ConsumerRequest
    {
        return (new ConsumerRequest())
            ->setMethod($request->getMethod())
            ->setPath('/' . $request->getUri())
            ->setBody($request->getBody())
            ->setHeaders($request->getHeaders());
    }

    protected function buildProviderResponse(array $body = []): ProviderResponse
    {
        return (new ProviderResponse())
            ->setStatus(200)
            ->addHeader('Content-Type', 'application/json')
            ->setBody(empty($body) ? null : $body);
    }

    private function getModuleCommands(): ?Generator
    {
        try {
            $moduleName = $this->getModuleName();
        } catch (ReflectionException $e) {
        }
        foreach ($this->base->rawCommands() as $name => $command) {
            try {
                if (!$this->commandIsVisible($name, $command)) {
                    continue;
                }
                if (isset($moduleName) && $command->module === $moduleName) {
                    yield $name => $command;
                }
            } catch (ConfigurationException $exception) {
            }
        }
    }

    private function commandIsVisible(string $name, mrdpCommand $command): bool
    {
        return $this->base->isCommandAllowed($name) && (
                empty($this->allowedTags) || in_array($command->module, $this->allowedTags, true)
            );
    }

    protected function buildMatcher(apiCommand $command, array $data): array
    {
        $object = (object)$data;
        $matcher = new Matcher();
        [$name,] = arr::camelSplit($command->command, 2);

        return StringHelper::endsWith($name, 's') ? $matcher->eachLike($object) : $matcher->like($object);
    }

    protected function buildRequest(mrdpCommand $command, array $body): RequestInterface
    {
        return $this->connection->callWithDisabledAuth(
            function () use ($command, $body) {
                $request = $this->connection
                    ->createCommand()
                    ->db
                    ->getQueryBuilder()
                    ->perform($command->command, null, $body);
                $request->build();

                return $request;
            }
        );
    }

    private function getAttributes(apiCommand $command): array
    {
        $properties = [];
        $allFields = $command->wise_search ? $command->getModule($command->module)->getFields() : $command->fields;
        foreach ($allFields as $fields => $checks) {
            if ($fields === 'arrayof') {
                $relatedCommand = $this->base->getCommand($checks);

                return $this->getAttributes($relatedCommand);
            }
            $props = [];
            foreach (arr::csplit($fields) as $field) {
                if (strpos($field, '->') !== false) {
                    [$old, $new] = explode('->', $field, 2);
                    $props[] = $new;
                } else {
                    $props[] = $field;
                }
            }

            $props = array_unique($props);
            $checksArray = arr::csplit($checks);
            foreach ($props as $prop) {
                if (empty($checksArray)) {
                    $properties[$prop] = 'string';
                    continue;
                }
                foreach ($checksArray as $i => $func) {
                    if ($func === 'self') {
                        unset($checksArray[$i]);
                        $checksArray[] = $prop;
                    }
                }
                if (count($checksArray) > 1) {
                    $properties[$prop] = array_map(
                        static function (string $check) {
                            return $check;
                        },
                        $checksArray
                    );
                } else {
                    $properties[$prop] = [reset($checksArray)];
                }
            }
        }

        return $properties;
    }

    private function fake(array $attributes): array
    {
        $result = [];
        $faker = [
            'randomNumber' => ['variants' => ['id']],
            'word' => ['variants' => ['label', 'ref', 'fref']],
            'userName' => ['variants' => ['login', 'client', 'seller', 'buyer']],
            'freeEmail' => ['variants' => ['email']],
            'password' => ['variants' => ['password']],
            'boolean' => ['args' => [50], 'variants' => ['bool']],
            'randomFloat' => ['variants' => ['money']],
            'tollFreePhoneNumber' => ['variants' => ['phone']],
            'url' => ['variants' => ['url']],
        ];

        foreach ($attributes as $name => $meta) {
            $checks = $meta['check'] ? arr::csplit($meta['check']) : $meta;
            foreach ($checks as $check) {
                foreach ($faker as $fake => $data) {
                    $bulk = false;
                    if (StringHelper::endsWith($check, 's')) {
                        $check = rtrim($check, 's');
                        $bulk = true;
                    }
                    if (in_array($check, $data['variants'], true)) {
                        $value = call_user_func([$this->faker, $fake], $data['args']);
                        $result[$name] = $bulk ? [$value] : $value;
                    }
                }
            }
        }

        return $result;
    }

    private function buildBody(mrdpCommand $command): array
    {
        $attributes = $this->getAttributes($command);

        return $this->fake($attributes);
    }
}
