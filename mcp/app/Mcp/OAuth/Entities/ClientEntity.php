<?php

namespace App\Mcp\OAuth\Entities;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

class ClientEntity implements ClientEntityInterface
{
    use ClientTrait;
    use EntityTrait;

    public function __construct(string $identifier, string $name, string|array $redirectUri, bool $isConfidential = false)
    {
        $this->setIdentifier($identifier);
        $this->name = $name;
        $this->redirectUri = $redirectUri;
        $this->isConfidential = $isConfidential;
    }
}
