<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\ApiDocs;

use Exception;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

class ApiRequest
{
    /**
     * Map keys
     * 
     * @const string
     */
    // base keys
    const REQUEST_TITLE_KEY = 'title';
    const REQUEST_DESCRIPTION_KEY = 'description';
    const REQUEST_AUTHORIZED_KEY = 'authorized';

    // request keys
    const REQUEST_CONTENT_TYPE_KEY = 'request.contentType';
    const REQUEST_METHOD_KEY = 'request.method';
    const REQUEST_ROUTE_KEY = 'request.route';
    const REQUEST_PARAMS_KEY = 'request.params';
    const REQUEST_BODY_KEY = 'request.body';
    const REQUEST_HEADERS_KEY = 'request.headers';

    // addtional content
    const ADDTIONAL_CONTENT_BEFORE_HEADING_KEY = 'additionalContent.beforeHeading';
    const ADDTIONAL_CONTENT_AFTER_HEADING_KEY = 'additionalContent.afterHeading';
    const ADDTIONAL_CONTENT_BEFORE_REQUEST_KEY = 'additionalContent.beforeRequest';
    const ADDTIONAL_CONTENT_AFTER_REQUEST_KEY = 'additionalContent.afterRequest';
    const ADDTIONAL_CONTENT_BEFORE_RESPONSE_KEY = 'additionalContent.beforeResponse';
    const ADDTIONAL_CONTENT_AFTER_RESPONSE_KEY = 'additionalContent.afterResponse';

    // response keys
    const RESPONSES_CONTENT_KEY = 'responses';

    // default headers
    const DEFAULT_REQUEST_HEADERS = [
        'DEVICE-ID' => '{{ deviceId }}',
        'OS' => 'Android | iOS | Web',
        'OS-VERSION' => '{{ Version }}'
    ];

    /**
     * Json header content type
     * 
     * @const string
     */
    const JSON_CONTENT_TYPE = 'application/json; charset=utf-8';

    /**
     * Multi Part Form Data Content
     * 
     * @const string
     */
    const MULTI_TYPE_FORM_DATA_CONTENT = 'multipart/form-data';

    /**
     * Missing key flag
     */
    const MISSING_KEY = '__MISSING__KEY__';

    /**
     * Json Docs file path
     */
    protected string $filePath = '';

    /**
     * Json content
     * 
     * @var array<string, mixed>
     */
    protected array $jsonContent = [];

    /**
     * final markdown content
     */
    protected string $markdownContent = '';

    /**
     * Set docs file path
     */
    public function setFilePath(string $filePath): ApiRequest
    {
        $this->filePath = $filePath;

        return $this;
    }

    /**
     * Append the given content to the markdown content
     */
    protected function append(mixed $content): ApiRequest
    {
        if (!$content) return $this;

        $this->markdownContent .= $content . PHP_EOL;

        return $this;
    }

    /**
     * Append the given content but add a new line before it
     *
     * @param  string $content
     */
    protected function appendLine($content): ApiRequest
    {
        if (!$content) return $this;

        return $this->append(PHP_EOL . $content);
    }

    /**
     * Get value from content
     *
     * @param  mixed $default
     * @return mixed
     */
    protected function get(string $key, $default = '')
    {
        return Arr::get($this->jsonContent, $key, $default);
    }

    /**
     * Get value from the content
     * If the key does not exist, then throw error
     *
     * @return string
     */
    protected function strictGet(string $key)
    {
        $content = $this->get($key, static::MISSING_KEY);

        if ($content === static::MISSING_KEY) {
            throw new MissingApiDocsKeyException(sprintf('%s key is missing from the json file %s', $key, $this->filePath));
        }

        return $content;
    }

    /**
     * Parse the file and return valid markdown syntax
     */
    public function parse(): string
    {
        $this->jsonContent = json_decode(File::get($this->filePath), true);

        $this->setBeforeDocumentHeading();
        $this->setDocumentHeading();
        $this->setAfterDocumentHeading();

        $this->setBeforeRequestInformation();

        $this->setRequestInformation();

        $this->setRequestHeaders();
        $this->setRequestParams();

        $this->setRequestBody();

        $this->setAfterRequestInformation();

        $this->setBeforeResponseInformation();

        $this->setResponseInformation();

        $this->setResponses();

        $this->setAfterResponseInformation();

        return $this->markdownContent;
    }

    /**
     * Check if the file has addtional information that 
     * should be added before the document heading
     * 
     * @return void
     */
    protected function setBeforeDocumentHeading()
    {
        $this->appendLine($this->get(static::ADDTIONAL_CONTENT_BEFORE_HEADING_KEY));
    }

    /**
     * Set document heading
     * 
     * @return void
     */
    protected function setDocumentHeading()
    {
        $title = $this->strictGet(static::REQUEST_TITLE_KEY);

        $description = $this->strictGet(static::REQUEST_DESCRIPTION_KEY);

        $this->append("# $title")
            ->appendLine($description);
    }

    /**
     * Check if the file has addtional information that 
     * should be added after the document heading
     * 
     * @return void
     */
    protected function setAfterDocumentHeading()
    {
        $this->append($this->get(static::ADDTIONAL_CONTENT_AFTER_REQUEST_KEY));
    }

    /**
     * Check if the file has addtional information that 
     * should be added before the request information
     * 
     * @return void
     */
    protected function setBeforeRequestInformation()
    {
        $this->append($this->get(static::ADDTIONAL_CONTENT_BEFORE_REQUEST_KEY));
    }

    /**
     * Set request base infrmation
     * 
     * @return void
     */
    protected function setRequestInformation()
    {
        $this->appendLine('## Request Definitions');

        $requestMethod = $this->strictGet(static::REQUEST_METHOD_KEY);
        $route = $this->strictGet(static::REQUEST_ROUTE_KEY);

        $this->appendLine("**{$requestMethod}** `{$route}`");
    }

    /**
     * Set request params information
     * 
     * @return void
     */
    protected function setRequestParams()
    {
        $params = $this->get(static::REQUEST_PARAMS_KEY);

        if (!$params) return;

        $this->appendLine('## Request Query Params');

        $this->appendLine('The following table illustrates the available query params that can be sent with the request');

        $this->tableHead([
            'Key', 'Type', 'Required', 'Description', 'Allowed Values',
        ]);

        foreach ($params as $param) {
            $required = empty($param['required']) ? 'No' : 'Yes';

            $optionsList = $param['options'] ?? [];

            $options = '';

            foreach ($optionsList as $option) {
                $options .= "`$option` | ";
            }

            $this->tableRow([
                "`{$param['key']}`",
                "**{$param['type']}**",
                "**{$required}**",
                "{$param['description']}",
                $options
            ]);
        }
    }

    /**
     * Set request Headers information
     * 
     * @return void
     */
    protected function setRequestHeaders()
    {
        $headers = array_merge(static::DEFAULT_REQUEST_HEADERS, $this->get(static::REQUEST_HEADERS_KEY, []));

        $this->appendLine('### Request Headers');

        $this->appendLine('The following table illustrates list of all headers that MUST BE sent with the request.');

        $this->tableHead([
            'Header', 'value'
        ]);

        $headers['Authorization'] = $this->get(static::REQUEST_AUTHORIZED_KEY, true) ? "Bearer {{ accessToken }}" : "key {{ apiKey }}";

        $requestContentType = $this->get(static::REQUEST_CONTENT_TYPE_KEY);

        if (!$requestContentType) {
            if (strtoupper($this->get(static::REQUEST_METHOD_KEY)) === 'POST') {
                $requestContentType = 'formData';
            } else {
                $requestContentType = 'json';
            }
        }

        if ($requestContentType === 'json') {
            $headers['Content-Type'] = static::JSON_CONTENT_TYPE;
        } elseif ($requestContentType === 'formData') {
            $headers['Content-Type'] = static::MULTI_TYPE_FORM_DATA_CONTENT;
        }

        foreach ($headers as $headerKey => $headerValue) {
            $this->tableRow(["`$headerKey`", "**$headerValue**"]);
        }
    }

    /**
     * Create a table head
     *
     * @param array<string, mixed> $columns
     */
    protected function tableHead(array $columns): ApiRequest
    {
        $content = '';

        foreach ($columns as $column) {
            $content .= '| ' . $column . ' ';
        }

        $content .= '|';

        $this->appendLine($content);

        $content = '';

        foreach ($columns as $column) {
            $content .= '| :---: ';
        }

        $content .= '|';

        $this->append($content);

        return $this;
    }


    /**
     * Create a table row
     *
     * @param array<string, mixed> $columns
     */
    protected function tableRow(array $columns): ApiRequest
    {
        if (!$columns) return $this;

        $content = '';

        foreach ($columns as $column) {
            $content .= '| ' . $column . ' ';
        }

        $content .= '|';

        $this->append($content);

        return $this;
    }


    /**
     * Set request body
     * 
     * @return void
     */
    protected function setRequestBody()
    {
        $requestBody = $this->get(static::REQUEST_BODY_KEY);

        if (!$requestBody) return;

        $this->appendLine('## Request Payload');

        $this->appendLine('The following table illustrates the request body that should be sent with the request.');

        $this->tableHead([
            'Key', 'Type', 'Required', 'Description', 'Allowed Values', 'Validation Rules',
        ]);

        foreach ($requestBody as $param) {
            $required = empty($param['required']) ? 'No' : 'Yes';

            $optionsList = $param['options'] ?? [];

            $options = '';

            foreach ((array) $optionsList as $option) {
                $options .= "`$option` | ";
            }

            $rulesList = $param['rules'] ?? [];

            $rules = '';

            foreach ((array) $rulesList as $rule) {
                $rules .= "`$rule` | ";
            }

            $this->tableRow([
                "`{$param['key']}`",
                "**{$param['type']}**",
                "**{$required}**",
                "{$param['description']}",
                $options,
                $rules,
            ]);
        }
    }

    /**
     * Check if the file has addtional information that 
     * should be added after the request information
     * 
     * @return void
     */
    protected function setAfterRequestInformation()
    {
        $this->append($this->get(static::ADDTIONAL_CONTENT_BEFORE_REQUEST_KEY));
    }

    /**
     * Check if the file has addtional information that 
     * should be added before the response information
     * 
     * @return void
     */
    protected function setBeforeResponseInformation()
    {
        $this->append($this->get(static::ADDTIONAL_CONTENT_BEFORE_RESPONSE_KEY));
    }

    /**
     * Set bae response information
     * 
     * @return void
     */
    protected function setResponseInformation()
    {
        $this->appendLine('## Responses');

        $this->appendLine('The following list is the available responses for this request.');
    }

    /**
     * Set responses
     * 
     * @return void
     */
    protected function setResponses()
    {
        $responses = $this->strictGet(static::RESPONSES_CONTENT_KEY);

        if (!is_array($responses)) {
            throw new Exception('responses key must be an array.');
        }

        foreach ($responses as $index => $response) {
            if (empty($response['statusCode'])) {
                throw new Exception(sprintf('Response %d has missing statusCode key', $index));
            }

            if (empty($response['body']) && empty($response['simpleBody'])) {
                throw new Exception(sprintf('Response %d has missing body key', $index));
            }

            $this->appendLine('### Response ' . $response['statusCode']);

            if (!empty($response['description'])) {
                $this->appendLine($response['description']);
            }

            if (!empty($response['body'])) {
                $this->setResponseBodyTable($response['body'], $response['statusCode']);
                $this->setResponseBodyJsonStructure($response);
            } elseif (!empty($response['simpleBody'])) {
                $this->appendJsonStructure($response['simpleBody'], $response['statusCode']);
            }
        }
    }

    /**
     * Set response body table
     *
     * @return void
     */
    protected function setResponseBodyTable(array $responseBody, int $responseStatusCode, string $parent = '')
    {
        if ($parent) {
            $this->appendLine("#### Response $responseStatusCode " . $parent);
        }

        $this->appendLine('The following table illustrates the response payload structure. for ' . ("`$parent`" ?: 'Response Body'));

        $this->tableHead([
            'Key', 'Type', 'Description', 'Nullable', 'Presentable', 'Allowed Values',
        ]);

        foreach ($responseBody as $param) {
            $nullable = empty($param['nullable']) ? 'No' : 'Yes';

            if ($param['Presentable'] ?? true === false) {
                $presentable = 'No';
            } else {
                $presentable = 'Yes';
            }

            $optionsList = $param['options'] ?? [];

            $options = '';

            foreach ((array) $optionsList as $option) {
                $options .= "`$option` | ";
            }

            $valuesList = $param['values'] ?? [];

            $values = '';

            foreach ((array) $valuesList as $value) {
                $values .= "`$value` | ";
            }

            $paramKey = $param['key'];

            $key = "`$paramKey`";

            if (!empty($param['children'])) {
                $parentKey = $parent;
                if ($parent) {
                    $parentKey .= '-';
                }

                $key = "[$paramKey](#response-{$responseStatusCode}-{$parentKey}{$paramKey})";
            }

            // #### Response 200 record.user

            $description = $param['description'] ?? '';

            $this->tableRow([
                "$key",
                "**{$param['type']}**",
                "{$description}",
                $nullable,
                $presentable,
                $values,
            ]);

            if (!empty($param['children'])) {
                $parentKey = $paramKey;
                if ($parent) {
                    $parentKey = $parent . '-' . $parentKey;
                }
                $this->setResponseBodyTable($param['children'], $responseStatusCode, $parentKey);
            }
        }
    }


    /**
     * Set response body as json strcture
     *
     * @return void
     */
    protected function setResponseBodyJsonStructure(array $response)
    {
        $shape = $this->createJsonShape($response['body']);

        $this->appendJsonStructure($shape, $response['statusCode']);
    }

    /**
     * Create json shape from the given response body
     */
    protected function createJsonShape(array $response): array
    {
        $body = [];
        foreach ($response as $responsePayload) {
            $type = $responsePayload['type'];
            $key = $responsePayload['key'];

            if (strtolower($type) === 'array') {
                if (!Str::endsWith($key, '[]')) {
                    $key .= '[]';
                }
            }

            if (!empty($responsePayload['children'])) {
                $type = $this->createJsonShape($responsePayload['children']);
            }

            $body[$key] = $type;
        }

        return $body;
    }

    /**
     * Add a json structure
     * 
     * @param  array $json
     * @param  int $responseStatusCode
     * @return void
     */
    protected function appendJsonStructure($json, $responseStatusCode)
    {
        $this->appendLine("#### Response $responseStatusCode JSON Structure");

        $this->appendLine('```json' . PHP_EOL . json_encode($json, JSON_PRETTY_PRINT) . PHP_EOL . '```');
    }

    /**
     * Check if the file has addtional information that 
     * should be added after the response information
     * 
     * @return void
     */
    protected function setAfterResponseInformation()
    {
        $this->append($this->get(static::ADDTIONAL_CONTENT_AFTER_RESPONSE_KEY));
    }

    /**
     * Save to the given file path
     */
    public function saveTo(string $filePath)
    {
        File::put($filePath, $this->parse());
    }
}
