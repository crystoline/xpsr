<?php

namespace Crystoline\Xpsr;

use Crystoline\Xpsr\Exception\ExceptionHandler;
use Crystoline\Xpsr\Exception\ExceptionHandlerInterface;
use Crystoline\Xpsr\Http\Response\ResponseWriter;

use Crystoline\Xpsr\Http\Router\RouteHandlerInterface;
use Crystoline\Xpsr\ServiceContainer\NotFoundException;
use Crystoline\Xpsr\ServiceContainer\ServiceContainer;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Dotenv\Dotenv;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use const __SITEPATH__;

final class Application extends ServiceContainer
{
    /**
     * @var self
     */
    private static $instance;

    /**
     *
     * @var Configuration
     */
    private $config;

    /**
     * @var array
     */
    protected $paths = [];
    public string $rootDir;

    /**
     * Initialize configuration.
     */
    public function __construct(string $public_dir)
    {
        self::$instance = $this;
        $this->rootDir = $public_dir;

        $this->setupPaths();
        $this->config = new Configuration();
        $this->config->loadConfigurationFiles(
            $this->paths['config_path'],
            $this->getEnvironment()
        );

    }

    /**
     * Initialize the paths.
     */
    private function setupPaths(): void
    {
        $this->paths['env_file_path'] = $this->getRootDir();
        $this->paths['env_file'] = $this->getRootDir(). DIRECTORY_SEPARATOR . '.env';
        $this->paths['config_path'] = $this->getRootDir(). DIRECTORY_SEPARATOR . 'config';
    }

    /**
     *
     * Detect the environment. Defaults to production.
     */
    private function getEnvironment(): ?string
    {
        if (is_file($this->paths['env_file'])) {
            $dotenv = Dotenv::createUnsafeImmutable($this->paths['env_file_path'])->load();
        }

        return null;
        //return getenv('ENVIRONMENT') ?: 'production';
        return getenv('APP_ENV') ?: '';
    }

    public static function getInstance(): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        throw new Exception('Instance not initiated');
    }

    public function getConfig(): Configuration
    {
        return $this->config;
    }

    public function isConsole(): bool{
        return php_sapi_name() === 'cli';
    }

    public function boot(): void {
        $this->registerServices();

        $writer = new ResponseWriter();

        $request = $this->get(ServerRequestInterface::class);

        if(!$request instanceof ServerRequestInterface){
            throw new \Exception('Can\'t create request' ); //TODO
        }

        try {

            if ($this->isConsole()) {
                $this->handleCommand($writer);
                return;
            }
            $this->handleHttp($writer, $request);
            exit;
        }catch (\Throwable $exception) {
            $exceptionHandler = $this->get(ExceptionHandlerInterface::class);
            if (!$exceptionHandler instanceof ExceptionHandler) {
                throw new \Exception(''); //TODO
            }

            $response = $exceptionHandler->render($request, $exception);
            $writer->write($response);
            exit;
        }
    }

    private function handleHttp(ResponseWriter $writer, ServerRequestInterface $request):void{
        $router = $this->build(RouteHandlerInterface::class);

        if(!$router instanceof RequestHandlerInterface){
            throw new \Exception('Can not found router handler');
        }

        $writer = new ResponseWriter();

        $response = $router->run($request);

        $writer->write($response);
    }

    private function handleCommand(ResponseWriter $writer):void{

    }

    private function registerServices()
    {
        $this->registerSelf();
        $this->registerConfigService();

        $registry = $this->getConfig()->get('app.registry', []);

        foreach ($registry as $register){

            if(!is_string($register)){
                continue;
            }

            if(!is_subclass_of($register, ServiceRegistry::class)){
                continue;
            }

            $this->register($register, true);
        }
    }

    private function registerConfigService()
    {
        $this->bind('config', function (){
            return $this->getConfig();
        });

        $this->bind(Configuration::class, function (){
            return $this->getConfig();
        });
    }

    private function registerSelf()
    {
        // Bind self
        $this->singleton(ContainerInterface::class, function (){
            return $this;
        });
    }

    public function build(string $id)
    {
        if($this->has($id)){
            return $this->get($id);
        }

        throw new NotFoundException(sprintf('service %s not found', $id));
    }

    public function getRootDir(): string
    {
        return $this->rootDir;
    }
}