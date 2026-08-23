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

    /**
     * @param string[] $urlPatterns
     */
    public function __construct(
        array $urlPatterns,
        protected bool $hideAllRequestParameters = false,
        protected bool $hideAllResponseData = false,
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

    public function setHideAllResponseData(bool $hideAllResponseData): static
    {
        $this->hideAllResponseData = $hideAllResponseData;

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

        return $this->prepareHeaders($headers);
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

        return $parameters;
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

        return $this->prepareHeaders($headers);
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
}
