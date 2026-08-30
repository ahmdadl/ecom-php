<?php

namespace HZ\Illuminate\Mongez\Events;

use HZ\Illuminate\Mongez\Models\ResponseLog;
use HZ\Illuminate\Mongez\Contracts\Users\UserAccountTypeContract;

class LogResponse
{
    /**
     * {@inheritDoc}
     */
    public function log(mixed $response, int $statusCode): mixed
    {
        $request = request();

        $userInfo = null;

        if ($user = user()) {
            $userInfo = $user->sharedInfo();
            if ($user instanceof UserAccountTypeContract) { // @phpstan-ignore class.notFound
                $userInfo['accountType'] = $user->getAccountType(); // @phpstan-ignore class.notFound
            }
        }

        $response = json_decode((string) (new \Illuminate\Http\Response($response))->getContent(), true);

        ResponseLog::create([ // @phpstan-ignore method.staticCall
            'response' => $response,
            'statusCode' => $statusCode,
            'request' => $request->all(),
            'userAgent' => $request->userAgent(),
            'route' => $request->uri(),
            'ip' => $request->ip(),
            'method' => $request->method(),
            'headers' => $request->headers->all(),
            'user' => $userInfo,
        ]);

        return $response;
    }
}
