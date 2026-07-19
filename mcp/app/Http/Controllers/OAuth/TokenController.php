<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Nyholm\Psr7\Response as Psr7Response;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class TokenController extends Controller
{
    public function __construct(private readonly AuthorizationServer $server)
    {
    }

    public function issue(Request $request): SymfonyResponse
    {
        $psrRequest = (new PsrHttpFactory)->createRequest($request);

        try {
            $psrResponse = $this->server->respondToAccessTokenRequest($psrRequest, new Psr7Response);
        } catch (OAuthServerException $e) {
            $psrResponse = $e->generateHttpResponse(new Psr7Response);
        }

        return (new HttpFoundationFactory)->createResponse($psrResponse);
    }
}
