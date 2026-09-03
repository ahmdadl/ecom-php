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
            // Named so Mongez admin./site. group prefixes do not collapse
            // every unnamed PATCH onto the same bare route name (breaks route:cache).
            $this->patch($name . '/{id}', [$controller , 'patch'])->name($name . '.patch');

            $this->apiResource($name, $controller, $options);
        };
    }

}