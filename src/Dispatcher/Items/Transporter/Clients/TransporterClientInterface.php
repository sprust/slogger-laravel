<?php

namespace SLoggerLaravel\Dispatcher\Items\Transporter\Clients;

interface TransporterClientInterface
{
    /**
     * @param array{tp: string, dt: string}[] $actions
     */
    public function dispatch(array $actions): void;
}
