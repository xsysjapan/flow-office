<?php

namespace App\Domain\EventSourcing\Rebuild;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * 全Projectorの書き込み先テーブルの一覧(手順書で「空にするテーブル」に使う。論点18 6章)。
 *
 * app/Domain/<X>/Projectors/*.php のソースから、DB::table('名前') の文字列と、参照しているEloquentモデル
 * (App\Models\ または App\Domain\<X>\Models\ 等の Model 派生クラス)のテーブル名を集める。
 * Projectorが `->breaks()->create(...)` のようにリレーション経由で書くテーブル(関連先・中間テーブル)も拾う。
 * 呼ぶのは戻り値型がRelationのメソッドだけ(save等のモデルのメソッドは呼ばない)。
 * 文字列の静的な検査なので、動的に決まるテーブル名は拾えない(手順書では人が確認する)。
 *
 * Projectorが読むだけのテーブル(参照マスタ・直接更新の正データ・認証情報)は NOT_READ_MODELS で一覧から除く
 * (空にすると再生で戻らず消えるため)。Projectorが書くテーブルをここへ入れてはならない(空にされず再生で重複する)。
 */
final class ReadModelTableLister
{
    /**
     * Projectorのソースに現れるが、書き込み先ではないテーブル(テーブル名 => 理由)。
     * 全Projectorを確認した結果、モデルとして参照されて読むだけなのは system_settings(SystemSetting::current() で既定タイムゾーン・
     * M365テナントIDを読む)のみ。ほかは Projector が参照しうる参照マスタ・認証情報を念のため除く。
     */
    public const NOT_READ_MODELS = [
        'features' => '参照マスタ(機能カタログ)。シーダーで投入し、Projectorは読むだけ',
        'permissions' => '参照マスタ(権限カタログ)。シーダーで投入し、Projectorは読むだけ',
        'permission_scope_types' => '参照マスタ(権限スコープ種別)。シーダーで投入し、Projectorは読むだけ',
        'system_settings' => '管理者専用APIから直接更新する正データ(監査イベントのみ記録)。Projectorは既定値を読むだけ',
        'user_credentials' => '認証情報(パスワード・remember_token)。イベントに載せず直接更新する正データ。Projectorは書かない',
    ];

    /** @return array<string, list<string>> テーブル名 => 参照元のProjectorファイル */
    public function tables(string $domainRoot): array
    {
        $result = [];
        foreach (glob($domainRoot.'/*/Projectors/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            $origin = str_replace($domainRoot.'/', '', $file);

            foreach ($this->literalTables($source) as $table) {
                $result[$table][] = $origin;
            }
            foreach ($this->modelTables($source) as $table) {
                $result[$table][] = $origin;
            }
        }
        $result = array_diff_key($result, self::NOT_READ_MODELS);
        ksort($result);
        foreach ($result as $table => $origins) {
            $result[$table] = array_values(array_unique($origins));
        }

        return $result;
    }

    /** @return list<string> */
    private function literalTables(string $source): array
    {
        preg_match_all("/DB::table\\(\\s*['\"]([a-z_][a-z0-9_]*)['\"]\\s*\\)/", $source, $m);

        return array_values(array_unique($m[1]));
    }

    /** @return list<string> */
    private function modelTables(string $source): array
    {
        preg_match_all('/\b(?:use\s+)?((?:App\\\\)[A-Za-z0-9_\\\\]+)/', $source, $m);
        preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+);/m', $source, $uses);
        $classes = array_unique(array_merge($uses[1] ?? [], $m[1] ?? []));

        $tables = [];
        foreach ($classes as $class) {
            $class = ltrim($class, '\\');
            if (! class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if (! $reflection->isSubclassOf(Model::class) || $reflection->isAbstract()) {
                continue;
            }
            /** @var Model $model */
            $model = $reflection->newInstanceWithoutConstructor();
            $tables[] = $model->getTable();
            foreach ($this->relationTables($model, $source) as $table) {
                $tables[] = $table;
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Projectorのソースに `->名前(` として現れる、モデルのリレーションメソッドの関連先・中間テーブル。
     *
     * @return list<string>
     */
    private function relationTables(Model $model, string $source): array
    {
        preg_match_all('/->([A-Za-z_][A-Za-z0-9_]*)\(/', $source, $m);

        $tables = [];
        foreach (array_unique($m[1]) as $name) {
            if (! method_exists($model, $name)) {
                continue;
            }
            $method = new ReflectionMethod($model, $name);
            if (! $method->isPublic() || $method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
                continue;
            }
            if (str_starts_with($method->getDeclaringClass()->getName(), 'Illuminate\\')) {
                continue;
            }
            $returnType = $method->getReturnType();
            if (! $returnType instanceof ReflectionNamedType || $returnType->isBuiltin()
                || ! is_a($returnType->getName(), Relation::class, true)) {
                continue;
            }
            try {
                $relation = $model->{$name}();
            } catch (Throwable) {
                continue;
            }
            if (! $relation instanceof Relation) {
                continue;
            }
            $tables[] = $relation->getRelated()->getTable();
            if ($relation instanceof BelongsToMany) {
                $tables[] = $relation->getTable();
            }
        }

        return $tables;
    }
}
