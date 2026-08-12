<?php

namespace App\Providers;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\Notification\GraphMailNotifier;
use App\Domain\Notification\Notifier;
use App\Domain\UserManagement\Graph\HttpMicrosoftGraphClient;
use App\Domain\UserManagement\Graph\MicrosoftGraphClient;
use App\Domain\UserManagement\LocalAzureProvider;
use App\Domain\UserManagement\Ms365ConfigResolver;
use App\Models\AttendanceDay;
use App\Models\AttendanceMonth;
use App\Models\ExpenseClaim;
use App\Models\ExpenseItem;
use App\Models\WorkflowRequest;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CommandBus::class);
        $this->app->bind(MicrosoftGraphClient::class, HttpMicrosoftGraphClient::class);
        $this->app->bind(Notifier::class, GraphMailNotifier::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 初回オンボーディング中にもm365_mock_enabledが更新されるため、実Entra IDと
        // mock-oidcを実行時に切り替えられるProviderを常に登録する。
        Event::listen(
            SocialiteWasCalled::class,
            fn (SocialiteWasCalled $event) => $event->extendSocialite('azure', LocalAzureProvider::class)
        );

        // attachments.owner_type / backoffice_tasks.source_type にDBへ安定な短い別名を保存する。
        Relation::morphMap([
            'workflow_request' => WorkflowRequest::class,
            'attendance_day' => AttendanceDay::class,
            'attendance_month' => AttendanceMonth::class,
            'expense_claim' => ExpenseClaim::class,
            'expense_item' => ExpenseItem::class,
        ]);

        // 単体リソースを "data" キーで包まない(ページネーション付きコレクションは
        // Laravel側の制約で data/links/meta を維持する)。APIレスポンスをシンプルにするため。
        JsonResource::withoutWrapping();

        // ローカル開発(Caddy+ngrok, docs/27-release-runbook.md)ではリバースプロキシが
        // マウントパス('/flow-office')を剥がしてからこのアプリへ転送するため、
        // 生のリクエストURLにはマウントパスの情報が含まれない。route()/redirect()->route()は
        // APP_URLを起点にURLを生成するよう強制する。
        if ($url = config('app.url')) {
            URL::forceRootUrl($url);

            // ページネーションの next_page_url/prev_page_url はURL facadeを経由せず
            // 生のリクエストURLから組み立てられる(Illuminate\Pagination\PaginationState)ため、
            // 上記のforceRootUrlだけでは直らない。APP_URL起点で組み立て直す。
            Paginator::currentPathResolver(
                fn () => rtrim($url, '/').'/'.ltrim(request()->path(), '/')
            );
        }
    }
}
