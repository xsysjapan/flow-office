<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * ローカルログインのパスワードのハッシュとremember_token。usersとは別の表で、system_settingsと
 * 同じくイベントではなく直接更新する正データ(Projectorの書き込み先ではない。usersを
 * イベントから再生成しても残る)。主キーはusers.id。
 */
#[Fillable(['user_id', 'password', 'remember_token'])]
class UserCredential extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            // ローカルパスワードは平文でDBに保持しない (Laravel標準のhashedキャストで自動ハッシュ化する)。
            'password' => 'hashed',
        ];
    }
}
