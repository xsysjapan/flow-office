<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UserResourceの軽量版。承認者選択(UserPicker)など、一般社員も含め誰でも使える
 * 検索エンドポイント(UserController::search)専用。入社日・退社日・雇用区分・ロールは
 * 含めない(それらはuser.view Permissionで保護されたUserResourceでのみ返す)。
 */
class UserSearchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'department' => $this->department,
            'job_title' => $this->job_title,
        ];
    }
}
