<?php

namespace SLoggerLaravel\RequestPreparer;

use Illuminate\Support\Str;
use SLoggerLaravel\DataResolver;

class RequestDataFormatter
{
    /**
     * @var string[]
     */
    protected array $urlPatterns;

    protected ?Masks $requestHeaders;

    protected ?Masks $requestParameters;

    protected ?Masks $responseHeaders;

    protected ?Masks $responseFields;

    /**
     * The masks are what only these urls need - one client, one endpoint of it. The
     * global `masking` lists run afterwards, in the dispatcher job, over every trace.
     *
     * Each of them takes a plain list of full masks, or the named form
     * `['full_keys' => [...], 'partial_keys' => [...], 'value_patterns' => [...]]`.
     *
     * @param string[]                $urlPatterns
     * @param Masks|array<mixed>|null $requestHeaders
     * @param Masks|array<mixed>|null $requestParameters
     * @param Masks|array<mixed>|null $responseHeaders
     * @param Masks|array<mixed>|null $responseFields
     */
    public function __construct(
        array $urlPatterns,
        protected bool $hideAllRequestParameters = false,
        Masks|array|null $requestHeaders = null,
        Masks|array|null $requestParameters = null,
        protected bool $hideAllResponseData = false,
        Masks|array|null $responseHeaders = null,
        Masks|array|null $responseFields = null,
    ) {
        $this->urlPatterns = array_map(
            fn(string $urlPattern) => trim($urlPattern, '/'),
            $urlPatterns
        );

        $this->requestHeaders    = Masks::from($requestHeaders);
        $this->requestParameters = Masks::from($requestParameters);
        $this->responseHeaders   = Masks::from($responseHeaders);
        $this->responseFields    = Masks::from($responseFields);
    }

    public function setHideAllRequestParameters(bool $hideAllRequestParameters): static
    {
        $this->hideAllRequestParameters = $hideAllRequestParameters;

        return $this;
    }

    /**
     * @param Masks|array<mixed> $masks
     */
    public function addRequestHeaders(Masks|array $masks): static
    {
        $this->requestHeaders = self::merge($this->requestHeaders, $masks);

        return $this;
    }

    /**
     * @param Masks|array<mixed> $masks
     */
    public function addRequestParameters(Masks|array $masks): static
    {
        $this->requestParameters = self::merge($this->requestParameters, $masks);

        return $this;
    }

    public function setHideAllResponseData(bool $hideAllResponseData): static
    {
        $this->hideAllResponseData = $hideAllResponseData;

        return $this;
    }

    /**
     * @param Masks|array<mixed> $masks
     */
    public function addResponseHeaders(Masks|array $masks): static
    {
        $this->responseHeaders = self::merge($this->responseHeaders, $masks);

        return $this;
    }

    /**
     * @param Masks|array<mixed> $masks
     */
    public function addResponseFields(Masks|array $masks): static
    {
        $this->responseFields = self::merge($this->responseFields, $masks);

        return $this;
    }

    /**
     * @param array<int|string, mixed> $headers
     *
     * @return array<int|string, mixed>
     */
    public function prepareRequestHeaders(string $url, array $headers): array
    {
        if (!$this->is($url)) {
            return $headers;
        }

        return $this->mask($this->prepareHeaders($headers), $this->requestHeaders);
    }

    /**
     * @param array<int|string, mixed> $parameters
     *
     * @return array<int|string, mixed>
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

        return $this->mask($parameters, $this->requestParameters);
    }

    /**
     * @param array<int|string, mixed> $headers
     *
     * @return array<int|string, mixed>
     */
    public function prepareResponseHeaders(string $url, array $headers): array
    {
        if (!$this->is($url)) {
            return $headers;
        }

        return $this->mask($this->prepareHeaders($headers), $this->responseHeaders);
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

        if ($this->responseFields) {
            // the resolver is read here and nowhere else: without masks to apply, a
            // body nobody asked for is never decoded
            $dataResolver->setData(
                $this->responseFields->apply($dataResolver->getData())
            );
        }

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

    /**
     * Whether this formatter would discard the parameters of that url outright.
     *
     * Asked before the body is read: parsing one only to replace it with `__cleaned`
     * is a cost the traced application pays for nothing.
     */
    public function hidesRequestParameters(string $url): bool
    {
        return $this->hideAllRequestParameters && $this->is($url);
    }

    protected function is(string $url): bool
    {
        return Str::is($this->urlPatterns, trim($url, '/'));
    }

    /**
     * @param array<int|string, mixed> $headers
     *
     * @return array<int|string, mixed>
     */
    protected function prepareHeaders(array $headers): array
    {
        return collect($headers)
            ->map(fn($header) => implode(', ', (array) $header))
            ->all();
    }

    /**
     * @param array<int|string, mixed> $data
     *
     * @return array<int|string, mixed>
     */
    protected function mask(array $data, ?Masks $masks): array
    {
        return is_null($masks) ? $data : $masks->apply($data);
    }

    /**
     * Whatever was configured before stays: an `add*()` widens the list, it does not
     * replace it.
     *
     * @param Masks|array<mixed> $masks
     */
    protected static function merge(?Masks $current, Masks|array $masks): ?Masks
    {
        $added = Masks::from($masks);

        if (is_null($added)) {
            return $current;
        }

        return is_null($current) ? $added : $current->merge($added);
    }
}
