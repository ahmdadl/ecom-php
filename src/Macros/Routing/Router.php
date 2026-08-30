<?php
namespace HZ\Illuminate\Mongez\Macros\Routing;

use Illuminate\Routing\Router as IlluminateRouter;

/**
 * @mixin IlluminateRouter
 */
class Router
{
    /**
     * Route an API resource to a controller. (extended)
     * 
     * @param  string  $name
     * @param  string  $controller
     * @param  array<string, mixed>  $options
     * @return \Closure
     */
    public function restfulApi(string $name = '', string $controller = '', array $options = []): \Closure
    {
        return function ($name, $controller, array $options = []) {
            $this->patch($name . '/{id}', [$controller , 'patch']);

            $this->apiResource($name, $controller, $options);
        };
    }

}