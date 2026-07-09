<?php

namespace App\Domain\User\Graph;

/**
 * UC-002 (MS365ユーザーを同期する) のためのMicrosoft Graphアクセス抽象。
 * 実運用では HttpMicrosoftGraphClient を、テストではFakeを利用する。
 */
interface MicrosoftGraphClient
{
    /**
     * @return iterable<int, MicrosoftGraphUser>
     */
    public function listUsers(): iterable;
}
