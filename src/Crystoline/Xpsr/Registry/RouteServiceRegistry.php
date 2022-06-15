<?php

namespace Crystoline\Xpsr\Registry;

use Crystoline\Xpsr\Exception\ExceptionHandlerInterface;
use Crystoline\Xpsr\Http\Router\RouteHandlerInterface;
use Crystoline\Xpsr\ServiceContainer\ServiceContainer;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Sunrise\Http\Message\ResponseFactory;
use Sunrise\Http\Router\Exception\MethodNotAllowedException;
use Sunrise\Http\Router\Exception\RouteNotFoundException;
use Sunrise\Http\Router\Loader\ConfigLoader;
use Sunrise\Http\Router\Middleware\CallableMiddleware;
use Sunrise\Http\Router\Router;
use Throwable;

class RouteServiceRegistry extends ServiceRegistry
{

    private Router $router;

    protected bool $delayed = false;

    public function __construct(ServiceContainer $container)
    {
        $this->router = new Router();
        parent::__construct($container);
    }
    public function providedServices(): array
    {
        return [
            RouteHandlerInterface::class,
        ];
    }

    public function register(): void
    {
        $this->container->singleton(RouteHandlerInterface::class, function () {
            return $this->router;
        });

//        $this->container->bind('session', \App\Http\Middleware\SessionMiddleware::class);

        // $loader = new ConfigLoader();
        $this->registerErrorHandler();
        $this->registerRoutes();



        //$this->container->bind('request', HttpRequestFactory::class);

    }

    public function registerRoutes()
    {


        $loader = new ConfigLoader();

// set container if necessary...
        $loader->setContainer($this->container);

// attach configs...
        $loader->attach('../routes/api.php');
        $loader->attach('../routes/web.php');
        $this->router->load($loader);

    }

    private function registerErrorHandler()
    {

       // $this->router->addMiddleware(new \CakephpWhoops\Error\Middleware\WhoopsHandlerMiddleware());
        $this->router->addMiddleware(new CallableMiddleware(function ($request, $handler) {
            try {
                return $handler->handle($request);
//            } catch (MethodNotAllowedException $e) {
//                return (new ResponseFactory)->createResponse(405);
//            } catch (RouteNotFoundException $e) {
//                return (new ResponseFactory)->createResponse(404);
            } catch (Throwable $e) {
                return $this->handelException($request, $e);
            }
        }));
    }

    /**
     * @param $request
     * @param Throwable $e
     * @return \Psr\Http\Message\ResponseInterface
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    private function handelException($request, Throwable $e): \Psr\Http\Message\ResponseInterface
    {
        $exceptionHandler = $this->container->get(ExceptionHandlerInterface::class);

        if ($exceptionHandler instanceof ExceptionHandlerInterface) {
            return $exceptionHandler->render($request, $e);
        }
        return (new ResponseFactory)->createResponse(500);
    }
}