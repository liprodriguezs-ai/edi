<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class UnprocessableEntityHttpException extends Exception
{
    protected $errors;

    /**
     * @param  array  $errors  Datos extra (por ejemplo: ['sku'=>'ABC123','stock'=>0])
     */
    public function __construct(
        $errors = [],
        int $code = 422,
        ?Throwable $previous = null
    ) {
        parent::__construct('Valores no válidos', $code, $previous);
        $this->errors = $errors;
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
                'errors' => $this->errors,
            ], $this->getCode());
        }

        // Siempre devolver JSON para prevenir errores con vistas no existentes
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => $this->errors,
        ], $this->getCode());

        // Descomentar si la vista existe
        // return response()->view('errors.invalid_order', [
        //     'message' => $this->getMessage(),
        //     'errors' => $this->errors,
        // ], $this->getCode());
    }
}
