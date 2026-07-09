# 2. 技術前提

- **Backend**: Laravel
- **DB**: MySQL
- **Hosting**: XSERVER
- **Queue**: database queue
- **Batch**: cron から `php artisan schedule:run`
- **Auth**: Microsoft Entra ID SSO
- **User Integration**: Microsoft Graph によるユーザー参照または同期
- **Notification**: Teams Webhook または Graph API
- **Architecture**: CQRS + Event Sourcing 風設計

XSERVER では常駐プロセスを前提にしない。キューは DB に積み、cron で
`queue:work --stop-when-empty` を起動する。
