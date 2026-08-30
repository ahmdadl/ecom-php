<?php

namespace HZ\Illuminate\Mongez\Services\Payments\HyperPay;

use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use GuzzleHttp\Exception\BadResponseException;
use HZ\Illuminate\Mongez\Models\ServiceLog;
use HZ\Illuminate\Mongez\Models\Services\Payments\CardRegistration;
use HZ\Illuminate\Mongez\Services\Payments\HyperPay\HyperPayResponse;
use HZ\Illuminate\Mongez\Contracts\Services\Payments\PaymentGatewayResponse;
use HZ\Illuminate\Mongez\Contracts\Services\Payments\PaymentMethodInterface;
use HZ\Illuminate\Mongez\Exceptions\Services\Payments\InvalidPaymentMethodException;

class HyperPayPayment implements PaymentMethodInterface
{
    /**
     * Payment Settings
     *
     * @var array<string, mixed>
     */
    private $settings = [];

    /**
     * Request response
     *
     * @var \Psr\Http\Message\ResponseInterface
     */
    private $response;

    /**
     * Constructor
     */
    public function __construct()
    {
        $settings = config('services.payments.hyperPay');

        if ($settings['mode'] === 'LIVE') {
            $mode = $settings['data']['live'];
        } else {
            $mode = $settings['data']['sandbox'];
        }

        unset($settings['data']);

        $this->settings = array_merge($settings, $mode);
    }

    /**
     * Generate payment url
     *
     * @param int|string $orderId
     * @return mixed
     */
    public function initiate(int|string $orderId, int|float|string $amount, string $paymentMethod): mixed
    {
        $user = user(); // Current User

        $paymentMethod = strtoupper($paymentMethod);

        $options = [
            'form_params' => [
                'entityId' => $this->getEntityIdOf($paymentMethod),
                'amount' => number_format((float) $amount, 2, '.', ''),
                'currency' => $this->option('currency'),
                'createRegistration' => $saveCards = $this->option('saveCards'),
                'merchantTransactionId' => $orderId,
                'customer.email' => $user->email,
                'customer.givenName' => $user->name,
                'customer.surname' => $user->name,
                'billing.street1' => 'no-street',
                'billing.state' => 'no-state',
                'billing.city' => 'no-city',
                'billing.country' => 'SA',
                'paymentType' => 'DB',
                'notificationUrl' => url('/') . "/notify",
            ],
        ];

        if ($saveCards) {
            $cardRegistrations = CardRegistration::where('createdBy.id', $user->id)->get(); // @phpstan-ignore class.notFound

            foreach ($cardRegistrations as $index => $cardRegistration) {
                $options['form_params']["registrations[$index].id"] = $cardRegistration->registrationId;
            }
        }

        if ($this->isSandboxMode()) {
            $options['form_params']['testMode'] = strtoupper($paymentMethod) === 'MADA' ? 'INTERNAL' : "EXTERNAL";
        }

        $response = $this->send('/v1/checkouts', $options);

        $this->log([
            'channel' => 'initiate',
            'orderId' => $orderId,
            'request' => $options,
            'response' => $response,
                'hyperPayId' => $response->id, // @phpstan-ignore property.nonObject
        ]);

        return $response->id; // @phpstan-ignore property.nonObject
    }

    /**
     * Get payment status as log
     * 
     * @param int $orderId
     * @param string $paymentMethod
     * @return HyperPayResponse
     */
    public function query($orderId, $paymentMethod)
    {
        $entityId = $this->getEntityIdOf($paymentMethod);
        $content = ($this->send("/v1/query?entityId=$entityId&merchantTransactionId=$orderId", [], 'GET'));

        $content = $content->payments[0] ?? $content;

        // $content = $this->send("/v1/checkouts/$hyperPayId/payment", $options, 'GET');

        $responseStatusCode = $this->response->getStatusCode();

        $responseData = [
            'response' => $content,
            'statusCode' => $content->result->code,
            'message' => $content->result->description,
            'responseStatusCode' => $responseStatusCode,
        ];

        $response = new HyperPayResponse($responseData);

        $this->log([
            'orderId' => $orderId,
            // 'request' => $options,
            'response' => $content,
            // 'hyperPayId' => $hyperPayId,
            'channel' => 'paymentStatus',
            'responseCode' => $this->response->getStatusCode(),
        ]);

        if (
            $response->isCompleted() &&
            ($registrationId = $response->get('registrationId')) &&
            !CardRegistration::where('registrationId', $registrationId)->exists() // @phpstan-ignore class.notFound
        ) {
            CardRegistration::create([ // @phpstan-ignore class.notFound
                'registrationId' => $registrationId,
            ]);
        }

        return $response;
    }

    /**
     * Get access token based on current environment
     */
    private function getAccessToken(): string
    {
        $accessToken = $this->option('accessToken');
        return "Bearer {$accessToken}";
    }

    /**
     * Get entity id of the given payment method
     *
     * @return string
     */
    private function getEntityIdOf(string $paymentMethod)
    {
        $paymentMethod = strtoupper($paymentMethod);

        if (!in_array($paymentMethod, ['VISA', 'MADA', 'MASTER'])) {
            throw new InvalidPaymentMethodException(sprintf('Invalid payment method %s.', $paymentMethod)); // @phpstan-ignore class.notFound, class.notFound
        }

        if ($paymentMethod === 'MASTER') {
            $paymentMethod = 'VISA';
        }

        return $this->option("entityId.$paymentMethod");
    }

    /**
     * Check if current status of payment is sandbox mode
     */
    private function isSandboxMode(): bool
    {
        return !$this->isLiveMode();
    }

    /**
     * Check if current status of payment is sandbox mode
     */
    private function isLiveMode(): bool
    {
        return $this->option("mode") === 'LIVE';
    }

    /**
     * Get payment response status
     *
     * @param int     $orderId
     * @param string  $hyperPayId
     * @param string  $paymentMethod
     */
    public function confirm($orderId, $hyperPayId, $paymentMethod): PaymentGatewayResponse
    {
        $options = [
            "query" => [
                "entityId" => $this->getEntityIdOf($paymentMethod)
            ],
        ];

        $content = $this->send($route = "/v1/checkouts/$hyperPayId/payment", $options, 'GET');

        $responseStatusCode = $this->response->getStatusCode();

        $responseData = [
            'response' => $content,
            'statusCode' => $content->result->code, // @phpstan-ignore property.nonObject
            'message' => $content->result->description, // @phpstan-ignore property.nonObject
            'responseStatusCode' => $responseStatusCode,
        ];

        $response = new HyperPayResponse($responseData);

        $this->log([
            'route' => $route,
            'paymentMethod' => $paymentMethod,
            'orderId' => $orderId,
            'request' => $options,
            'response' => $content,
            'hyperPayId' => $hyperPayId,
            'channel' => 'paymentStatus',
            'responseCode' => $this->response->getStatusCode(),
        ]);

        if ($response->isCompleted() && ($registrationId = $response->get('registrationId')) && !CardRegistration::where('registrationId', $registrationId)->exists()) { // @phpstan-ignore class.notFound
            CardRegistration::create([ // @phpstan-ignore class.notFound
                'registrationId' => $registrationId,
            ]);
        }

        return $response;
    }

    /**
     * Log the given data
     *
     * @param array<string, mixed> $data
     * @return void
     */
    private function log(array $data)
    {
        $data = array_merge($data, [
            'type' => 'payment',
            'gateway' => 'hyperPay',
            'settings' => $this->settings,
            'userAgent' => request()->userAgent(),
        ]);

        $mapData = function ($data) use (&$mapData) {
            $details = [];

            foreach ($data as $key => $value) {
                $details[Str::camel(str_replace('.', '_', $key))] = is_array($value) || is_object($value) ? $mapData((array) $value) : $value;
            }

            return $details;
        };

        $details = $mapData($data);

        ServiceLog::create($details); // @phpstan-ignore method.staticCall
    }

    /**
     * Send the given request
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>|object
     */
    private function send(string $route, array $options, string $requestMethod = 'POST')
    {
        $requestMethod = strtolower($requestMethod);

        $client = new Client();

        $options['http_errors'] = true;

        $options['headers'] = [
            "Authorization" => $this->getAccessToken(),
        ];

        try {
            $this->response = $client->$requestMethod(rtrim($this->option('url'), '/') . $route, $options);
        } catch (BadResponseException $e) {
            $this->response = $e->getResponse();
        }

        return json_decode($this->response->getBody()->getContents());
    }

    /**
     * Get settings value
     *
     * @param mixed $default
     * @return mixed
     */
    private function option(string $key, $default = null)
    {
        return Arr::get($this->settings, $key, $default);
    }
}
