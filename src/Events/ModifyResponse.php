<?php

namespace HZ\Illuminate\Mongez\Events;

use Symfony\Component\HttpFoundation\Response;

class ModifyResponse
{
    /**
     * {@inheritDoc}
     */
    public function modifyResponse($response, $statusCode)
    {
        if (in_array($statusCode, [Response::HTTP_OK, Response::HTTP_CREATED])) {
            $response = [
                'data' => $response,
            ];
        }

        return $response;
    }
}
