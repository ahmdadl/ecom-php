<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Events;

use Symfony\Component\HttpFoundation\Response;

class ModifyResponse
{
    /**
     * {@inheritDoc}
     */
    public function modifyResponse(mixed $response, int $statusCode): mixed
    {
        if (in_array($statusCode, [Response::HTTP_OK, Response::HTTP_CREATED])) {
            return [
                'data' => $response,
            ];
        }

        return $response;
    }
}
