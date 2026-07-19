<?php

namespace App\Providers;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\EventStore;
use App\Domain\Notification\GraphMailNotifier;
use App\Domain\Notification\Notifier;
use App\Domain\User\Graph\HttpMicrosoftGraphClient;
use App\Domain\User\Graph\MicrosoftGraphClient;
use App\Domain\User\LocalAzureProvider;
use App\Domain\User\Ms365ConfigResolver;
use App\Models\AttendanceDay;
use App\Models\WorkflowRequest;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Azure\AzureExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(EventStore::class);
        $this->app->singleton(CommandBus::class);
        $this->app->bind(MicrosoftGraphClient::class, HttpMicrosoftGraphClient::class);
        $this->app->bind(Notifier::class, GraphMailNotifier::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ProjectStoredEvent / CreateBackOfficeTaskOnApproval は app/Listeners配下にあり、
        // handle()の型ヒットからLaravelのイベント自動検出で登録されるため、ここで
        // 明示登録すると二重登録になる。ここでは自動検出の対象外(vendor配下)のみ登録する。
        //
        // ローカル開発でモックOIDC(mock-oidc/)を使う場合は、実際のEntra IDドライバの代わりに
        // LocalAzureProviderを "azure" ドライバとして登録する(docs/06-usecases-auth.md UC-001)。
        if (Ms365ConfigResolver::mockEnabled()) {
            Event::listen(
                SocialiteWasCalled::class,
                fn (SocialiteWasCalled $event) => $event->extendSocialite('azure', LocalAzureProvider::class)
            );
        } else {
            Event::listen(SocialiteWasCalled::class, AzureExtendSocialite::class.'@handle');
        }

        // attachments.owner_type / backoffice_tasks.source_type にDBへ安定な短い別名を保存する。
        Relation::morphMap([
            'workflow_request' => WorkflowRequest::class,
            'attendance_day' => AttendanceDay::class,
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
