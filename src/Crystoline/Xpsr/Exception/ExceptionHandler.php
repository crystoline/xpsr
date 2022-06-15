<?php

namespace Crystoline\Xpsr\Exception;

use Illuminate\Support\Arr;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Sunrise\Http\Message\ResponseFactory;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ExceptionHandler implements ExceptionHandlerInterface
{

    public function report(Throwable $e)
    {
        throw $e;
    }

    public function shouldReport(Throwable $e): bool
    {
        return false;
    }

    public function render(RequestInterface $request, Throwable $e): ResponseInterface
    {
        if($this->shouldReport($e)){
            $this->report($e);
        }
        $statusCode = $this->isHttpException($e) ? $e->getStatusCode() : 500;

        if($request->hasHeader('application/json')){
            return (new ResponseFactory())->createJsonResponse(
                $statusCode,
                $this->exceptionToArray($e),
                JSON_PRETTY_PRINT
            );
       }

        $html = $this->exceptionToHtml($e);

        return (new ResponseFactory())->createHtmlResponse(
            $statusCode,
            $html,

        );

    }

    protected function exceptionToArray(Throwable $e): array
    {
        //dd($e);
        return config('app.debug') ? [
            'message' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => collect($e->getTrace())->map(function ($trace) {
                return Arr::except($trace, ['args']);
            })->all(),
        ] : [
            'message' => $this->isHttpException($e) ? $e->getMessage() : 'Server Error',
        ];
    }

    /**
     * Determine if the given exception is an HTTP exception.
     */
    protected function isHttpException(Throwable $e): bool
    {
        return $e instanceof HttpExceptionInterface;
    }

    /**
     * @param Throwable $exception
     * @return string
     */
    private function exceptionToHtml(Throwable $exception): string
    {

        $whoops = new \Whoops\Run;
        $whoops->allowQuit(false);
        $whoops->writeToOutput(false);
        $whoops->pushHandler(new \Whoops\Handler\PrettyPageHandler);
        return $whoops->handleException($exception);
    }
}