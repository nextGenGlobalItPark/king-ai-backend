<?php

namespace App\Exceptions;

use App\Traits\ResponseTrait;
use BadMethodCallException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Exceptions\MissingRateLimiterException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    use ResponseTrait;

    protected $dontReport = [
        //
    ];

    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            // Custom reporting logic (e.g., to a logging service) can be added here
        });
    }

    public function render($request, Throwable $e)
    {
        Log::debug("asdfasdfasdfasdfasdf");
        if ($request->expectsJson()) {
            return $this->handleApiException($request, $e);
        }

        return parent::render($request, $e);
    }

    protected function handleApiException($request, Throwable $exception): JsonResponse
    {
        // Default status and message
        $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        $message = $exception->getMessage() ?: 'An unexpected error occurred';
        $errors = null;

        // Handle specific exceptions
        if ($exception instanceof ValidationException) {
            $message = 'Validation failed';
            $errors = $exception->errors();
        } elseif ($exception instanceof MissingRateLimiterException) {
            $message = 'Rate limiter not defined';
        } elseif ($exception instanceof BadMethodCallException) {
            $message = 'Invalid method call';
        } elseif ($exception instanceof ModelNotFoundException) {
            $status = Response::HTTP_NOT_FOUND;
            $message = 'Resource not found';
        } elseif ($exception instanceof AuthenticationException) {
            $status = Response::HTTP_UNAUTHORIZED;
            $message = 'Unauthenticated';
        } elseif ($exception instanceof AccessDeniedHttpException) {
            $status = Response::HTTP_FORBIDDEN;
            $message = 'Unauthenticated';
        } elseif ($exception instanceof NotFoundHttpException) {
            $status = Response::HTTP_NOT_FOUND;
            $message = 'Not Found';
        } elseif (method_exists($exception, 'getStatusCode')) {
            $status = $exception->getStatusCode();
        }

        return $this->error($errors, $message, $status);
    }
}