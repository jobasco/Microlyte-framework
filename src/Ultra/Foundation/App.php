<?php

declare(strict_types=1);

namespace Ultra\Foundation;

use Ultra\Exceptions\AppException;
use Ultra\Interfaces\ConfigInterface;
use Ultra\Interfaces\ServiceProviderInterface;
use DI\Container;
use Psr\Container\ContainerInterface as PsrContainer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Slim\App as SlimApp;
use Slim\Factory\ServerRequestCreatorFactory;
use Slim\Handlers\ErrorHandler as SlimErrorHandler;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\ResponseEmitter;

class App
{

    /**
     * Instance
     *
     * @var App|null
     */
    protected static $instance;

    /**
     * Container
     *
     * @var Container
     */
    protected $container;

    /**
     * Configs
     *
     * @var ConfigInterface
     */
    protected $config;

    /**
     * Slim App
     *
     * @var SlimApp
     */
    protected $slimApp;

    /**
     * Response
     *
     * @var ResponseInterface
     */
    protected $response;


    /**
     * App constructor
     *
     * @throws AppException
     */
    public function __construct()
    {
        static::$instance = $this;

        $this->slimApp = new SlimApp((new ResponseFactory()), (new PsrContainer($this->setContainer())));

        $this->response = $this->init();

        return $this;
    }

    /**
     * Get App Instance
     *
     * @return App|null
     */
    public static function getInstance(): ?App
    {
        return static::$instance;
    }

    /**
     * Get Container
     *
     * @return Container
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * Get Configs
     *
     * @return ConfigInterface
     */
    public function getConfig(): ConfigInterface
    {
        return $this->config;
    }

    /**
     * Get Slim App
     *
     * @return SlimApp
     */
    public function getSlimApp(): SlimApp
    {
        return $this->slimApp;
    }

    /**
     * Get Response
     *
     * @return ResponseInterface
     */
    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }


    /**
     * Init App
     *
     * @return ResponseInterface
     * @throws AppException
     */
    protected function init(): ResponseInterface
    {
        $this->registerBootstrapServices();

        $this->registerBootstrapMiddlewares();

        $this->setAppConfig();


        $request = $this->getRequestObject();

        $errorHandler = $this->setErrorHandler();

        $this->setErrorMiddleware($errorHandler);

        $this->setShutdownHandler($request, $errorHandler);


        $this->setRouteStrategy();

        $this->registerAppServices();

        $this->registerAppMiddlewares();

        $this->registerRoutes();


        $this->response = $this->slimApp->handle($request);


        $this->emitResponseEmitters($this->response);

        return $this->response;
    }

    /**
     * Set Container
     *
     * @return Container
     */
    protected function setContainer(): Container
    {
        $container = $this->container = new Container();

        $container['app'] = $this;

        return $container;
    }

    /**
     * Register Bootstrap Service Providers
     *
     * @throws AppException
     */
    protected function registerBootstrapServices(): void
    {
        $services = require base_path('bootstrap/services.php');

        $this->registerServices($services);
    }

    /**
     * Register Bootstrap Middlewares
     *
     * @throws AppException
     */
    protected function registerBootstrapMiddlewares(): void
    {
        $middlewares = require base_path('bootstrap/middlewares.php');
        $middlewares = array_reverse($middlewares);

        $this->registerMiddlewares($middlewares);
    }

    /**
     * Set App Configs
     */
    protected function setAppConfig(): void
    {
        $this->config = $this->container['config'];
    }

    /**
     * Register Request Object
     *
     * @return ServerRequestInterface
     */
    protected function getRequestObject(): ServerRequestInterface
    {
        $request = ServerRequestCreatorFactory::create()
            ->createServerRequestFromGlobals();

        $this->container['request'] = $request;

        return $request;
    }

    /**
     * Set Error Handler
     *
     * @return SlimErrorHandler
     */
    protected function setErrorHandler(): SlimErrorHandler
    {
        $handler = $this->config->get('handlers.errorHandler');

        $handler = new $handler(
            $this->slimApp->getCallableResolver(),
            $this->slimApp->getResponseFactory(),
            $this->container[LoggerInterface::class] ?? null
        );

        set_error_handler([$handler, 'handleError']);

        set_exception_handler([$handler, 'handleException']);

        return $handler;
    }

    /**
     * Set Error Middleware
     *
     * @param SlimErrorHandler $errorHandler
     */
    protected function setErrorMiddleware(SlimErrorHandler $errorHandler): void
    {
        $configs = $this->config->get('app');

        $errorMiddleware = $this->slimApp->addErrorMiddleware(
            $configs['displayErrorDetails'],
            $configs['logErrors'],
            $configs['logErrorDetails']
        );

        $errorMiddleware->setDefaultErrorHandler($errorHandler);
    }

    /**
     * Set Shutdown Handler
     *
     * @param ServerRequestInterface $request
     * @param SlimErrorHandler $errorHandler
     */
    protected function setShutdownHandler(ServerRequestInterface $request, SlimErrorHandler $errorHandler): void
    {
        $configs = $this->config->get('app');

        $handler = $this->config->get('handlers.shutdownHandler');

        $shutdownHandler = new $handler(
            $request,
            $errorHandler,
            $configs['displayErrorDetails'],
            $configs['logErrors'],
            $configs['logErrorDetails']
        );

        register_shutdown_function($shutdownHandler);
    }

    /**
     * Set Route Strategy
     */
    protected function setRouteStrategy(): void
    {
        $routeCollector = $this->slimApp->getRouteCollector();

        $routeCollector->setDefaultInvocationStrategy(new RouteStrategy());
    }

    /**
     * Register App Services
     *
     * @throws AppException
     */
    protected function registerAppServices(): void
    {
        $services = $this->config->get('services');

        $this->registerServices($services);
    }

    /**
     * Register App Middlewares
     *
     * @throws AppException
     */
    protected function registerAppMiddlewares(): void
    {
        $middlewares = array_reverse($this->config->get('middlewares'));

        $this->registerMiddlewares($middlewares);
    }

    /**
     * Register Routes
     */
    protected function registerRoutes(): void
    {
        $app = $this->slimApp;

        require app_path('Routes/app.php');

        unset($app);
    }

    /**
     * Emit Response Emitters
     *
     * @param ResponseInterface $response
     */
    protected function emitResponseEmitters(ResponseInterface $response): void
    {
        $emitters = $this->container['config']->get('emitters');

        if (is_array($emitters) && !empty($emitters)) {
            foreach ($emitters as $emitter) {
                $response = (new $emitter())->emit($response);
            }

            if (ob_get_contents()) {
                ob_clean();
            }

            (new ResponseEmitter())->emit($response);
        }
    }

    /**
     * Register Services
     *
     * @param array $services
     * @throws AppException
     */
    protected function registerServices(array $services): void
    {
        if (is_array($services) && !empty($services)) {
            foreach ($services as $service) {
                if (!class_exists($service)) {
                    throw new AppException('"' . $service . '" Not Found');
                }

                /**
                 * @var $instance ServiceProviderInterface
                 */
                $instance = new $service();

                if (!$instance instanceof ServiceProviderInterface) {
                    throw new AppException('"' . $service . '" must implements ServiceProviderInterface');
                }

                $this->container[$instance->name()] = $instance->register($this->container);
            }
        }
    }

    /**
     * Register Middlewares
     *
     * @param array $middlewares
     * @throws AppException
     */
    protected function registerMiddlewares(array $middlewares): void
    {
        if (is_array($middlewares) && !empty($middlewares)) {
            foreach ($middlewares as $middleware) {
                if (!class_exists($middleware)) {
                    throw new AppException('"' . $middleware . '" Not Found');
                }

                /**
                 * @var $instance MiddlewareInterface
                 */
                $instance = new $middleware();

                if (!$instance instanceof MiddlewareInterface) {
                    throw new AppException('"' . $middleware . '" must implements MiddlewareInterface');
                }

                $this->slimApp->add($instance);
            }
        }
    }
}