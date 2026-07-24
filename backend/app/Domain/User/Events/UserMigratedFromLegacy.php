<?php

namespace App\Domain\User\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * user.migrated_from_legacy(本番カットオーバー移行時にusersテーブルの既存行を
 * 移行時点の状態として記録した合成イベント。移行済み履歴の一部として恒久的に残る)。
 *
 * spatie移行前(main)のusersテーブルに既に存在していた行を、新しいイベントストアへ
 * 「移行時点の状態」として1件のイベントに変換したもの。`UserOnboardedAsAdmin`等の
 * 既存イベントは特定のオンボーディングフロー(SSO連携・ローカルパスワード発行等)の
 * 意味を持つため転用せず、移行専用の別イベントとして扱う。
 *
 * @param  array<string, mixed>  $attributes  移行時点のusers属性一式(id・created_at・
 *                                            updated_atを除く)。
 */
class UserMigratedFromLegacy extends ShouldBeStored
{
    public function __construct(
        public readonly array $attributes,
    ) {}
}
