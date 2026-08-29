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
     * @param  array  $options
     * @return \Illuminate\Routing\PendingResourceRegistration
     */
    public function restfulApi()
    {
        return function ($name, $controller, array $options = []) {
            $this->patch($name . '/{id}', [$controller , 'patch']);

            $this->apiResource($name, $controller, $options);
        };
    }

}