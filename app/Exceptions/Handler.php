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
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    use ResponseTrait;

    protected $dontReport = [
        ValidationException::class,
        AuthenticationException::class,
        ModelNotFoundException::class,
        NotFoundHttpException::class,
    ];

    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            if (!$this->shouldntReport($e)) {
                Log::error('Exception occurred', [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'user_id' => auth()->id(),
                    'url' => request()->fullUrl(),
                    'method' => request()->method(),
                ]);
            }
        });
    }



    /**
     * Handle unauthenticated users for API requests
     *
     * This method is called when a user is not authenticated and tries to access
     * a protected route. For API routes, we return a JSON error response instead
     * of redirecting to a login page.
     */
    protected function unauthenticated($request, AuthenticationException $exception): JsonResponse|\Illuminate\Http\RedirectResponse
    {
        // For API requests, return JSON error
        if ($request->expectsJson() || $request->is('api/*')) {
            return $this->error(
                errors: null,
                message: 'Unauthenticated',
                status: Response::HTTP_UNAUTHORIZED
            );
        }

        // For Filament admin panel requests
        if ($request->is('admin') || $request->is('admin/*')) {
            return redirect()->guest('/admin/login');
        }

        // For web routes (if any exist in the future), redirect to login
        // NOTE: This will only work if you define a 'login' route in web.php
        return redirect()->guest(route('login'));
    }

    public function render($request, Throwable $e)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
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
        } elseif ($exception instanceof AuthenticationException) {
            $status = Response::HTTP_UNAUTHORIZED;
            $message = 'Unauthenticated';
        } elseif ($exception instanceof AccessDeniedHttpException) {
            $status = Response::HTTP_FORBIDDEN;
            $message = 'Unauthorized';
        } elseif ($exception instanceof ModelNotFoundException) {
            $status = Response::HTTP_NOT_FOUND;
            $model = $exception->getModel();
            $modelName = class_basename($model);
            $message = "{$modelName} not found";
        } elseif ($exception instanceof NotFoundHttpException) {
            $status = Response::HTTP_NOT_FOUND;
            $message = 'Not Found';
        } elseif ($exception instanceof MethodNotAllowedHttpException) {
            $status = Response::HTTP_METHOD_NOT_ALLOWED;
            $message = 'HTTP method not allowed for this endpoint';
        } elseif ($exception instanceof MissingRateLimiterException) {
            $message = 'Rate limiter not defined';
        } elseif ($exception instanceof BadMethodCallException) {
            $message = 'Invalid method call';
        } elseif (method_exists($exception, 'getStatusCode')) {
            $status = $exception->getStatusCode();
            $message = $exception->getMessage() ?: 'An error occurred';
        }

        // Validate status code (must be between 100-599)
        if ($status < 100 || $status > 599) {
            Log::warning('Invalid status code detected', [
                'original_status' => $status,
                'exception' => get_class($exception),
            ]);
            $status = Response::HTTP_INTERNAL_SERVER_ERROR;
        }

        // Add debug info in development mode
        if (config('app.debug')) {
            $errors = [
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => collect($exception->getTrace())
                    ->take(5)
                    ->map(function ($trace) {
                        return [
                            'file' => $trace['file'] ?? 'unknown',
                            'line' => $trace['line'] ?? 0,
                            'function' => $trace['function'] ?? 'unknown',
                            'class' => $trace['class'] ?? null,
                            'type' => $trace['type'] ?? null,
                        ];
                    })
                    ->toArray(),
            ];
        }

        return $this->error($errors, $message, $status);
    }
}