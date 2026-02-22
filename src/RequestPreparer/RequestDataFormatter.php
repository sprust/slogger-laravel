<?php

namespace SLoggerLaravel\RequestPreparer;

use Illuminate\Support\Str;
use SLoggerLaravel\DataResolver;
use SLoggerLaravel\Helpers\MaskHelper;

class RequestDataFormatter
{
    /**
     * @var string[]
     */
    protected array $urlPatterns;

    /**
     * @param string[] $urlPatterns
     * @param string[] $requestHeaders
     * @param string[] $requestParameters
     * @param string[] $responseHeaders
     * @param string[] $responseFields
     */
    public function __construct(
        array $urlPatterns,
        protected bool $hideAllRequestParameters = false,
        protected array $requestHeaders = [],
        protected array $requestParameters = [],
        protected bool $hideAllResponseData = false,
        protected array $responseHeaders = [],
        protected array $responseFields = [],
    ) {
        $this->urlPatterns = array_map(
            fn(string $urlPattern) => trim($urlPattern, '/'),
            $urlPatterns
        );
    }

    public function setHideAllRequestParameters(bool $hideAllRequestParameters): static
    {
        $this->hideAllRequestParameters = $hideAllRequestParameters;

        return $this;
    }

    /**
     * @param string[] $headers
     */
    public function addRequestHeaders(array $headers): static
    {
        $this->requestHeaders = array_merge($this->requestHeaders, $headers);

        return $this;
    }

    /**
     * @param string[] $parameters
     */
    public function addRequestParameters(array $parameters): static
    {
        $this->requestParameters = array_merge($this->requestParameters, $parameters);

        return $this;
    }

    public function setHideAllResponseData(bool $hideAllResponseData): static
    {
        $this->hideAllResponseData = $hideAllResponseData;

        return $this;
    }

    /**
     * @param string[] $headers
     */
    public function addResponseHeaders(array $headers): static
    {
        $this->responseHeaders = array_merge($this->responseHeaders, $headers);

        return $this;
    }

    /**
     * @param string[] $fields
     */
    public function addResponseFields(array $fields): static
    {
        $this->responseFields = array_merge($this->responseFields, $fields);

        return $this;
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return array<string, mixed>
     */
    public function prepareRequestHeaders(string $url, array $headers): array
    {
        if (!$this->is($url)) {
            return $headers;
        }

        return MaskHelper::maskArrayByList(
            data: $this->prepareHeaders($headers),
            patterns: $this->requestHeaders
        );
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    public function prepareRequestParameters(string $url, array $parameters): array
    {
        if (!$this->is($url)) {
            return $parameters;
        }

        if ($this->hideAllRequestParameters) {
            return [
                '__cleaned' => null,
            ];
        }

        return MaskHelper::maskArrayByPatterns(
            data: $parameters,
            patterns: $this->requestParameters
        );
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return array<string, mixed>
     */
    public function prepareResponseHeaders(string $url, array $headers): array
    {
        if (!$this->is($url)) {
            return $headers;
        }

        return MaskHelper::maskArrayByList(
            data: $this->prepareHeaders($headers),
            patterns: $this->responseHeaders
        );
    }

    public function prepareResponseData(string $url, DataResolver $dataResolver): bool
    {
        if (!$this->is($url)) {
            return true;
        }

        if ($this->hideAllResponseData) {
            $dataResolver->setData([
                '__cleaned' => null,
            ]);

            return false;
        }

        $dataResolver->setData(
            MaskHelper::maskArrayByPatterns(
                data: $dataResolver->getData(),
                patterns: $this->responseFields
            )
        );

        return true;
    }

    public function isHideAllResponseData(): bool
    {
        return $this->hideAllResponseData;
    }

    public function isHideAllRequestParameters(): bool
    {
        return $this->hideAllRequestParameters;
    }

    protected function is(string $url): bool
    {
        return Str::is($this->urlPatterns, trim($url, '/'));
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return array<string, mixed>
     */
    protected function prepareHeaders(array $headers): array
    {
        return collect($headers)
            ->map(fn($header) => implode(', ', (array) $header))
            ->all();
    }
}
