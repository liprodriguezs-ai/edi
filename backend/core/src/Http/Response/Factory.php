<?php

namespace QCH\Core\Http\Response;

use Dingo\Api\Http\Response;
use Dingo\Api\Http\Response\Factory as BaseFactory;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Factory extends BaseFactory
{
    /**
     * Respond with a created response and associate a location if provided.
     *
     * @param  null|string  $location
     * @return Response
     */
    public function success($content = null)
    {
        $response = new Response($content);
        $response->setStatusCode(200);

        return $response;
    }

    /**
     * Return a 401 unauthorized error.
     *
     * @param  string  $message
     *
     * @throws HttpException
     */
    public function errorUnauthorized($message = 'Unauthorized')
    {
        $this->error($message, 401);
    }

    /**
     * Respond with a created response and associate a location if provided.
     *
     * @param  null  $location
     * @param  array  $content
     * @param  null|string  $data
     * @return Response
     */
    public function created($location = [], $content = [])
    {
        $content['status_code'] = 201;

        /* $response = new Response($content);
        $response->setStatusCode(201); */
        return response()->json($location)->setStatusCode(201);

        // return $response;
    }

    /**
     * Return an error response.
     *
     * @param  string  $message
     * @param  int  $statusCode
     *
     * @throws HttpException
     */
    public function teapot()
    {
        return new Response([], 418, []);
    }

    /**
     * Return a 400 bad request error.
     *
     * @param  string  $message
     *
     * @throws HttpException
     */
    public function errorUnprocessable($message = 'Bad Request')
    {
        return new Response($message, 422);
    }
}
