<?php

namespace Crystoline\Xpsr\ServiceContainer;


use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Registry\ViewServiceRegistry;
use Crystoline\Xpsr\View\ViewHandlerInterface;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;

class ServiceContainer implements ContainerInterface
{
    private array $instances = [];
    private array $singletons = [];
    protected array $services = [];
    /** @var string[] */
    private array $deferredServices = [];

    private int $cycle = 0;
    public function get($id)
    {
        $isSingleton = in_array($id, $this->singletons, true);

        if($isSingleton && array_key_exists($id, $this->instances)){

            return $this->instances[$id];
        }

        $item = $this->resolve($id);
        $instance = $instance = $item;
        if ($item instanceof ReflectionClass) {
            $instance = $this->loadInstance($item);
            if($item->hasMethod('__invoke') || is_callable($instance)) {
                $instance = $instance($this);
            }
        }

        if($isSingleton){
            $this->instances[$id] = $instance;
        }

        return $instance;
    }

    public function has($id)
    {
        try {
            $item = $this->resolve($id);
        } catch (NotFoundException $e) {
            return false;
        }
        if ($item instanceof ReflectionClass) {
            return $item->isInstantiable();
        }
        return isset($item);
    }

    public function bind(string $key, $concrete)
    {
        $this->services[$key] = $concrete;
        return $this;
    }

    public function singleton(string $key, $concrete)
    {
        $this->singletons[] = $key;
        return $this->bind($key, $concrete);
    }
    protected function resolve($id)
    {
//        if($this->cycle > 50 ){
//            exit;
//        }

//        $this->cycle++;


        try {
            $name = $id;
            if (isset($this->services[$id])) {

                $name = $this->services[$id];

                if (is_callable($name) ) {

                    return $name($this);
                }

                if(is_object($name)){
                    return $name;
                }
            }

           else if(isset($this->deferredServices[$id])){
                $registry = $this->deferredServices[$id];
                $this->register($registry);
                return $this->resolve($id);
            }

            return (new ReflectionClass($name));
        } catch (ReflectionException $e) {
            throw new NotFoundException($e->getMessage(), $e->getCode(), $e);
        }
    }

    private function loadInstance(ReflectionClass $item): ?object
    {
        $constructor = $item->getConstructor();
        if (is_null($constructor) || $constructor->getNumberOfRequiredParameters() == 0) {
            return $item->newInstance();
        }
        $params = [];
        foreach ($constructor->getParameters() as $param) {
            if ($type = $param->getType() ) {
                $params[] = $this->get($type->getName());
            }else{
                if(isset($_SESSION[$param->getName()])){
                    $params[] = $_SESSION[$param->getName()];
                }else{
                    $params[] = null;
                }
            }
        }
        return $item->newInstanceArgs($params);
    }

    protected function register(string $register, bool $checkDeferred = false): void
    {
        $serviceRegistry = new $register($this);

        if (!$serviceRegistry instanceof ServiceRegistry) {
            throw new \Exception('Invalid service registry'); //
        }

        if ($checkDeferred  && $serviceRegistry->isDelayed()) {

            $services = $serviceRegistry->providedServices();
            foreach ($services as $service) {
                $this->deferredServices[$service] = $register;
            }

            return;

        }

        $serviceRegistry->register();


    }
}