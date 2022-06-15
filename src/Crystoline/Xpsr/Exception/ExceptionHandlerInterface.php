<?php

namespace Crystoline\Xpsr\Exception;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

interface ExceptionHandlerInterface
{
    /**
     * Report or log an exception.
     *
     * @param  \Throwable  $e
     * @return void
     *
     * @throws \Throwable
     */
    public function report(Throwable $e);

    /**
     * Determine if the exception should be reported.
     *
     * @param  \Throwable  $e
     * @return bool
     */
    public function shouldReport(Throwable $e): bool;

    /**
     * Render an exception into an HTTP response.
     */
    public function render(RequestInterface $request, Throwable $e): ResponseInterface;
//
//    /**
//     * Render an exception to the console.
//     *
//     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
//     * @param  \Throwable  $e
//     * @return void
//     */
   // public function renderForConsole($output, Throwable $e);
}