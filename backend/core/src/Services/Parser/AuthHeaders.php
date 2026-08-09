<?php

/*
 * This file is part of jwt-auth.
 *
 * (c) Sean Tymon <tymon148@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace QCH\Core\Services\Parser;

use Illuminate\Http\Request;
use Tymon\JWTAuth\Contracts\Http\Parser as ParserContract;
use Tymon\JWTAuth\Http\Parser\AuthHeaders as BaseAuthHeaders;

class AuthHeaders extends BaseAuthHeaders implements ParserContract
{
    /**
     * Try to parse the token from the request header.
     *
     * @return null|string
     */
    public function parse(Request $request)
    {
        $header = $request->headers->get($this->header) ?: $this->fromAltHeaders($request);
        if ($request->method() == 'GET' && $request->get('Authorization')) {
            $header = $request->get('Authorization');
        }

        if ($header && preg_match('/'.$this->prefix.'\s*(\S+)\b/i', $header, $matches)) {
            return $matches[1];
        }
    }
}
