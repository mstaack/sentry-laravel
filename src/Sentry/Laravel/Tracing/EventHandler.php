<?php

namespace Sentry\Laravel\Tracing;

use Exception;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Route;
use RuntimeException;
use Sentry\Laravel\Integration;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\Transaction;

class EventHandler
{
    /**
     * Map event handlers to events.
     *
     * @var array
     */
    protected static $eventHandlerMap = [
        'router.matched'    => 'routerMatched',  // Until Laravel 5.1
        RouteMatched::class => 'routeMatched',   // Since Laravel 5.2

        'illuminate.query'   => 'query',         // Until Laravel 5.1
        QueryExecuted::class => 'queryExecuted', // Since Laravel 5.2
    ];

    /**
     * The Laravel event dispatcher.
     *
     * @var \Illuminate\Contracts\Events\Dispatcher
     */
    private $events;

    /**
     * EventHandler constructor.
     *
     * @param \Illuminate\Contracts\Events\Dispatcher $events
     */
    public function __construct(Dispatcher $events)
    {
        $this->events = $events;
    }

    /**
     * Attach all event handlers.
     */
    public function subscribe(): void
    {
        foreach (static::$eventHandlerMap as $eventName => $handler) {
            $this->events->listen($eventName, [$this, $handler]);
        }
    }

    /**
     * Pass through the event and capture any errors.
     *
     * @param string $method
     * @param array  $arguments
     */
    public function __call($method, $arguments)
    {
        $handlerMethod = $handlerMethod = "{$method}Handler";

        if (!method_exists($this, $handlerMethod)) {
            throw new RuntimeException("Missing tracing event handler: {$handlerMethod}");
        }

        $parentSpan = Integration::currentTracingSpan();

        // If there is no tracing span active there is no need to handle the event
        if ($parentSpan === null) {
            return;
        }

        try {
            call_user_func_array([$this, $handlerMethod], $arguments);
        } catch (Exception $exception) {
            // Ignore
        }
    }

    /**
     * Until Laravel 5.1
     *
     * @param \Illuminate\Routing\Route $route
     */
    protected function routerMatchedHandler(Route $route): void
    {
        $transaction = SentrySdk::getCurrentHub()->getTransaction();

        if ($transaction instanceof Transaction) {
            $routeName = Integration::extractNameForRoute($route) ?? '<unlabeled transaction>';

            $transaction->setName($routeName);
            $transaction->setData([
                'action' => $route->getActionName(),
                'name'   => $route->getName(),
            ]);
        }
    }

    /**
     * Since Laravel 5.2
     *
     * @param \Illuminate\Routing\Events\RouteMatched $match
     */
    protected function routeMatchedHandler(RouteMatched $match): void
    {
        $this->routerMatchedHandler($match->route);
    }

    /**
     * Until Laravel 5.1
     *
     * @param string $query
     * @param array  $bindings
     * @param int    $time
     * @param string $connectionName
     */
    protected function queryHandler($query, $bindings, $time, $connectionName): void
    {
        $this->recordQuerySpan($query, $time);
    }

    /**
     * Since Laravel 5.2
     *
     * @param \Illuminate\Database\Events\QueryExecuted $query
     */
    protected function queryExecutedHandler(QueryExecuted $query): void
    {
        $this->recordQuerySpan($query->sql, $query->time);
    }

    /**
     * Helper to add an query breadcrumb.
     *
     * @param string     $query
     * @param float|null $time
     */
    private function recordQuerySpan($query, $time): void
    {
        $parentSpan = Integration::currentTracingSpan();

        $context = new SpanContext();
        $context->setOp('sql.query');
        $context->setDescription($query);
        $context->setStartTimestamp(microtime(true) - $time / 1000);
        $context->setEndTimestamp($context->getStartTimestamp() + $time / 1000);

        $parentSpan->startChild($context);
    }
}
