<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class UnauthorizedException extends Exception
{
    /**
     * @param  string  $message
     * @param  array  $details  Datos extra (por ejemplo: ['sku'=>'ABC123','stock'=>0])
     */
    public function __construct(
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct('No tienes permisos para acceder a esta página.', $code, $previous);
    }

    /**
     * Report the exception.
     *
     * @return void
     */
    public function report()
    {
        // Optionally integrate with external error tracking services or add custom logging
        Log::error('InvalidOrderException: '.$this->getMessage(), [
            'code' => $this->getCode(),
            'trace' => $this->getTrace(),
        ]);
    }

    /**
     * Render the exception into an HTTP response.
     *
     * @param  Request  $request
     * @return Response|JsonResponse
     */
    public function render($request)
    {
        // Return JSON if it's an AJAX or API request
        if ($request->expectsJson()) {
            return response()->json([
                'error' => $this->getMessage(),
                'message' => $this->getMessage(),
            ], 403);
        }

        // Otherwise, show a custom error view
        return response()->view('errors.unauthorized', [
            'message' => $this->getMessage(),
        ], 403);
    }
}
