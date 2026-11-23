<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * 端末アプリ(リポジトリ外の共有Android打刻リーダー)向けの端末設定。ペアリング・心拍の応答に使う。
 *
 * 端末アプリの更新が完了するまでの互換期間として、旧名(allow_offline・require_location・
 * auto_detect_punch_type)も併せて返す。旧名の扱いはこのクラスだけに置く。
 * 端末アプリの更新後に削除する(変更セット 20261123-boolean-column-naming 論点9)。
 * 管理画面向けは新名だけの`DeviceResource`を使う。
 */
class DeviceForDeviceAppResource extends DeviceResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'allow_offline' => $this->allows_offline,
            'require_location' => $this->requires_location,
            'auto_detect_punch_type' => $this->punch_type_auto_detection_enabled,
        ]);
    }
}
