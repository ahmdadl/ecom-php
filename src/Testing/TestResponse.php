<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Testing;

use HZ\Illuminate\Mongez\Testing\Traits\Messageable;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Testing\TestResponse as BaseTestResponse;
use PHPUnit\TextUI\XmlConfiguration\PHPUnit;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;

class TestResponse extends BaseTestResponse
{
    use Messageable;

    /**
     * Response object
     * 
     * @var \Illuminate\Testing\TestResponse
     */
    protected $response;

    /**
     * Request Body
     */
    protected array $requestBody;

    /**
     * Request method
     */
    protected string $requestMethod;

    /**
     * Request route
     */
    protected string $route;

    /**
     * Response shape
     */
    protected array $setResponseShape;

    /**
     * Test case
     * 
     * @var TestCase
     */
    protected $testCase;

    /**
     * Response body
     * 
     * @var object
     */
    protected $responseBody;

    /**
     * Set request route
     */
    public function setRoute(string $route): self
    {
        $this->route = $route;
        return $this;
    }

    /**
     * Set request method
     */
    public function setRequestMethod(string $requestMethod): self
    {
        $this->requestMethod = $requestMethod;
        return $this;
    }

    /**
     * Set request body
     */
    public function setRequestBody(array $requestBody): self
    {
        $this->requestBody = $requestBody;
        return $this;
    }

    /**
     * Get request route
     */
    public function getRoute(): string
    {
        return $this->route;
    }

    /**
     * Get request method
     */
    public function getRequestMethod(): string
    {
        return $this->requestMethod;
    }

    /**
     * Get request body
     */
    public function getRequestBody(): array
    {
        return $this->requestBody;
    }

    /**
     * Get response object
     */
    public function getResponse(): Response
    {
        return $this->baseResponse;
    }

    /**
     * Get response status code
     */
    public function getStatusCode(): int
    {
        return $this->baseResponse->getStatusCode();
    }

    /**
     * Set test case
     *
     * @return void
     */
    public function setTestSuit(TestCase $testCase)
    {
        $this->testCase = $testCase;
    }

    /**
     * Get response status code
     */
    public function statusCode(): int
    {
        return $this->baseResponse->getStatusCode();
    }

    /**
     * Get response body
     * 
     * @return mixed
     */
    public function getResponseBody()
    {
        return json_decode($this->baseResponse->getContent());
    }

    /**
     * Get response body
     * 
     * @return mixed
     */
    public function body()
    {
        return json_decode($this->baseResponse->getContent());
    }

    /**
     * Get response as array
     */
    public function toArray(): array
    {
        return json_decode($this->baseResponse->getContent(), true);
    }

    /**
     * Get response as object
     */
    public function toObject(): object
    {
        return $this->responseBody;
    }

    /**
     * Try to get last insert id
     */
    public function getLastInsertId(): int
    {
        return (int) Arr::get($this->toArray(), 'data.record.nid');
    }

    /**
     * Assert success response
     * 
     * @return $this
     */
    public function assertSuccess()
    {
        return $this->assertStatus(HttpFoundationResponse::HTTP_OK);
    }

    /**
     * Assert success create response
     * 
     * @return $this
     */
    public function assertSuccessCreate()
    {
        return $this->assertStatus(HttpFoundationResponse::HTTP_CREATED);
    }

    /**
     * Assert bad request response
     * 
     * @return $this
     */
    public function assertBadRequest()
    {
        return $this->assertStatus(HttpFoundationResponse::HTTP_BAD_REQUEST);
    }

    /**
     * Assert not found response
     * 
     * @return $this
     */
    public function assertNotFound()
    {
        return $this->assertStatus(HttpFoundationResponse::HTTP_NOT_FOUND);
    }

    /**
     * Assert unauthorized
     * 
     * @return $this
     */
    public function assertUnauthorized()
    {
        return $this->assertStatus(HttpFoundationResponse::HTTP_UNAUTHORIZED);
    }

    /**
     * Assert the current response to be the given response schema
     * 
     * @param  ResponseSchemaIterface $responseSchema
     * @return $this
     */
    public function assertResponse(ResponseSchemaInterface $responseSchema)
    {
        $responseSchema->setValue($this->toArray())->validate();

        if (!$responseSchema->isValid()) {
            $errors = new ErrorsMessagesParser($responseSchema->errorsList())->parse();

            $message = $this->color('Response Schema Failed:', 'red', ['bold']) . PHP_EOL;

            foreach ($errors as $error) {
                $message .= $error . PHP_EOL;
            }

            $this->testCase->fail($message);
        }

        return $this;
    }
}
