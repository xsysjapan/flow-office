<?php

namespace App\Domain\EventSourcing\Contracts;

/**
 * 状態変更の意図を表すマーカーインターフェース。
 * Commandは検証済みの入力値のみを持ち、副作用を持たない。
 */
interface Command {}
