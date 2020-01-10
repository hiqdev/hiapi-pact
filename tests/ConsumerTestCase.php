<?php

namespace hiapi\pact\tests;

use Exception;
use Generator;
use hiapi\exceptions\ConfigurationException;
use hiapi\legacy\lib\apiCommand;
use hiapi\legacy\lib\deps\arr;
use hiapi\legacy\lib\mrdpBase;
use hiapi\legacy\lib\mrdpCommand;
use hipanel\hiart\Connection;
use hiqdev\hiart\guzzle\Request;
use hiqdev\hiart\RequestInterface;
use hiqdev\yii\compat\yii;
use PhpPact\Consumer\InteractionBuilder;
use PhpPact\Consumer\Matcher\Matcher;
use PhpPact\Consumer\Model\ConsumerRequest;
use PhpPact\Consumer\Model\ProviderResponse;
use PhpPact\Standalone\Exception\MissingEnvVariableException;
use PhpPact\Standalone\MockService\MockServerConfigInterface;
use PhpPact\Standalone\MockService\MockServerEnvConfig;
use ReflectionClass;
use ReflectionException;
use StdClass;
use yii\di\Container;
use yii\helpers\StringHelper;

use function getenv;

abstract class ConsumerTestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * @var int
     */
    public $batchSize = 5;
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

    protected function runInteraction(): void
    {
        $interactionsHeap = [];
        $commands = iterator_to_array($this->getModuleCommands());
        $this->assertNotEmpty($commands);

        foreach ($commands as $commandName => $command) {
            if ($examples = $command->getExamples()) {
                foreach ($examples as $example) {
                    $service = new StdClass();
                    $service->request = $this->buildRequest($command, $example->getInput());
                    $service->response = $this->buildMatcher($command, $example->getOutput());
                    $interactionsHeap[$commandName][] = $service;
                }
            }
        }

        $mockService = new InteractionBuilder($this->serverEnvConfig);

        foreach (array_chunk($interactionsHeap, $this->batchSize, true) as $interactions) {
            foreach ($interactions as $commandName => $interaction) {
                foreach ($interaction as $service) {
                    $consumerRequest = $this->buildConsumerRequest($service->request);
                    $providerResponse = $this->buildProviderResponse($service->response);
                    $mockService
                        ->given($commandName)
                        ->uponReceiving("To get request from `{$commandName}`")
                        ->with($consumerRequest)
                        ->willRespondWith($providerResponse);
                    $mockServiceResponse = $service->request->send();
                    $this->assertEquals(
                        '200',
                        $mockServiceResponse->getStatusCode(),
                        'Let\'s make sure we have an OK response'
                    );
                    $body = (string)$mockServiceResponse->getBody();
                    $this->assertTrue(
                        (json_decode($body) ? true : false),
                        'Expect the JSON to be decoded without error'
                    );
                    $hasException = false;

                    try {
                        $mockService->verify();
                    } catch (Exception $e) {
                        $hasException = true;
                    }

                    $this->assertFalse($hasException, 'We expect the pacts to validate');
                }
            }
        }
    }

    protected function buildConsumerRequest(RequestInterface $request): ConsumerRequest
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
        $output = (object)$data;
        $matcher = new Matcher();
        [$name,] = arr::camelSplit($command->command, 2);

        return StringHelper::endsWith($name, 's') ? $matcher->eachLike($output) : $matcher->like($output);
    }

    protected function buildRequest(mrdpCommand $command, array $body): RequestInterface
    {
        $auth_login = getenv('AUTH_LOGIN');
        $auth_password = getenv('AUTH_PASSWORD');
        if (empty($auth_login)) {
            throw new MissingEnvVariableException('AUTH_LOGIN');
        }
        if (empty($auth_password)) {
            throw new MissingEnvVariableException('AUTH_PASSWORD');
        }
        return $this->connection->callWithDisabledAuth(
            function () use ($command, $body, $auth_login, $auth_password) {
                $request = $this->connection
                    ->createCommand()
                    ->db
                    ->getQueryBuilder()
                    ->perform(
                        $command->command,
                        null,
                        array_merge($body, compact('auth_login', 'auth_password'))
                    );
                $request->build();

                return $request;
            }
        );
    }
}
