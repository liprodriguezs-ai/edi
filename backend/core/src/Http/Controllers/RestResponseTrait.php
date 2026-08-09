<?php

namespace QCH\Core\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Class RestResponseTrait
 */
trait RestResponseTrait
{
    /**
     * Get Message Language
     *
     * @param  string  $message  Message
     *
     * @category Controller
     *
     * @return mixed
     */
    public function getMsg($message)
    {
        $file = strtolower($this->module);
        if (isset($this->messages) && $this->messages) {
            return trans(
                $this->module."::{$this->messages}.rest.{$message}"
            );
        }

        return trans(
            $this->module."::{$file}.rest.{$message}"
        );
    }

    /**
     * Create response success create resource
     *
     * @param  array  $data  Data
     *
     * @category Controller
     *
     * @return Response
     */
    protected function storeResponse($data)
    {
        $response = [
            'code' => Response::HTTP_CREATED,
            'status' => Response::$statusTexts[Response::HTTP_CREATED],
            'data' => $data['data'],
        ];

        if (! isset($data['message'])) {
            $data['message'] = $this->getMsg('store');
        }

        return $this->response($response);
    }

    /**
     * Create response success
     *
     * @param  array  $data  Data
     *
     * @category Controller
     *
     * @return Response
     */
    protected function successResponse($data)
    {
        $response = [
            'code' => Response::HTTP_OK,
            'status' => Response::$statusTexts[Response::HTTP_OK],
        ];

        if (isset($data['message'])) {
            $response['message'] = $data['message'];
        }

        if (isset($data['data'])) {
            $response['data'] = $data['data'];
        }

        return $this->response($response);
    }

    /**
     * Create response query list resource
     *
     * @param  array  $response  Data
     *
     * @category Controller
     *
     * @return Response
     */
    protected function queryResponse($response)
    {
        $response['code'] = Response::HTTP_OK;
        $response['status'] = Response::$statusTexts[Response::HTTP_OK];

        return $this->response($response);
    }

    /**
     * Create response no found resource
     *
     * @category Controller
     *
     * @return Response
     */
    protected function notFoundResponse()
    {
        $response = [
            'code' => Response::HTTP_NOT_FOUND,
            'status' => Response::$statusTexts[Response::HTTP_NOT_FOUND],
            'message' => $this->getMsg('notFound'),
        ];

        if (! isset($data['message'])) {
            $data['message'] = $this->getMsg('notFound');
        }

        return $this->response($response);
    }

    /**
     * Create response validate
     *
     * @param  array  $data  Data
     *
     * @category Controller
     *
     * @return Response
     */
    protected function validateResponse($data)
    {
        $response = [
            'code' => Response::HTTP_UNPROCESSABLE_ENTITY,
            'status' => Response::$statusTexts[Response::HTTP_UNPROCESSABLE_ENTITY],
            'data' => $data,
            'message' => $this->getMsg('unprocessableEntity'),
        ];

        if (! isset($data['message'])) {
            $data['message'] = $this->getMsg('unprocessableEntity');
        }

        return $this->response($response);
    }

    /**
     * Create response error
     *
     * @param  string  $error  Error
     *
     * @category Controller
     *
     * @return Response
     */
    protected function errorResponse($error)
    {
        $response = [
            'code' => Response::HTTP_INTERNAL_SERVER_ERROR,
            'status' => Response::$statusTexts[Response::HTTP_INTERNAL_SERVER_ERROR],
            'message' => 'Ha ocurrido un error al ratar de procesar los datos',
        ];

        if (env('APP_DEBUG')) {
            $response['trace'] = $error->getTrace();
            $response['message'] = $error->getMessage();
        }

        return $this->response($response);
    }

    /**
     * Unauthorized token response
     *
     * @category Controller
     *
     * @return Response
     */
    protected function unauthorizedResponse()
    {
        $response = [
            'code' => Response::HTTP_UNAUTHORIZED,
            'status' => Response::$statusTexts[Response::HTTP_UNAUTHORIZED],
        ];

        return $this->response($response);
    }

    /**
     * Response
     *
     *
     * @category Controller
     *
     * @return Response
     */
    private function response($response)
    {
        $lumenFinish = microtime(true);
        $executionTime = $lumenFinish - LARAVEL_START;

        return response()->json($response, $response['code'], [
            'X-Execution-Time' => $executionTime,
            'X-Timestamp' => date('Y-m-d H:i:s u'),
        ], JSON_NUMERIC_CHECK);
    }
}
