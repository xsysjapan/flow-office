<?php

use App\Domain\Attachment\Events\AttachmentDownloaded;
use App\Domain\Attachment\Events\AttachmentUploaded;
use App\Domain\AuthenticationKey\Events\AuthenticationKeyDisabled;
use App\Domain\AuthenticationKey\Events\AuthenticationKeyIssued;
use App\Domain\BackOffice\Events\BackOfficeTaskAssigned;
use App\Domain\BackOffice\Events\BackOfficeTaskCompleted;
use App\Domain\BackOffice\Events\BackOfficeTaskCreated;
use App\Domain\BackOffice\Events\BackOfficeTaskStatusChanged;
use App\Domain\Device\Events\DeviceDeleted;
use App\Domain\Device\Events\DeviceDisabled;
use App\Domain\Device\Events\DevicePaired;
use App\Domain\Device\Events\DevicePairingClaimIssued;
use App\Domain\Device\Events\DeviceRegistered;
use App\Domain\Device\Events\DeviceRevoked;
use App\Domain\Device\Events\DeviceRoleAssigned;
use App\Domain\Device\Events\DeviceScopeGranted;
use App\Domain\Device\Events\DeviceSettingsUpdated;
use App\Domain\DeviceAdminSession\Events\DeviceAdminSessionEnded;
use App\Domain\DeviceAdminSession\Events\DeviceAdminSessionStarted;
use App\Domain\Integration\Events\ApplicationIntegrationRegistered;
use App\Domain\Integration\Events\ApplicationIntegrationRevoked;
use App\Domain\Integration\Events\ApplicationIntegrationTokenReissued;
use App\Domain\Notification\Events\NotificationConfirmed;
use App\Domain\Notification\Events\NotificationQueued;
use App\Domain\Notification\Events\NotificationSent;
use App\Domain\PaidLeave\Events\PaidLeaveGranted;
use App\Domain\PaidLeave\Events\PaidLeaveRequestApproved;
use App\Domain\PaidLeave\Events\PaidLeaveRequestCancelled;
use App\Domain\PaidLeave\Events\PaidLeaveRequested;
use App\Domain\PaidLeave\Events\PaidLeaveRequestReturned;
use App\Domain\PaidLeave\Events\PaidLeaveUsed;
use App\Domain\PaidLeave\Events\PaidLeaveWarningRaised;
use App\Domain\SpecialLeave\Events\SpecialLeaveGranted;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestApproved;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestCancelled;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequested;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestReturned;
use App\Domain\SpecialLeave\Events\SpecialLeaveUsed;
use App\Domain\Workflow\Events\WorkflowRequestApproved;
use App\Domain\Workflow\Events\WorkflowRequestCancelled;
use App\Domain\Workflow\Events\WorkflowRequestDrafted;
use App\Domain\Workflow\Events\WorkflowRequestReturned;
use App\Domain\Workflow\Events\WorkflowRequestSubmitted;
use Spatie\EventSourcing\EventSerializers\JsonEventSerializer;
use Spatie\EventSourcing\Snapshots\EloquentSnapshot;
use Spatie\EventSourcing\Snapshots\EloquentSnapshotRepository;
use Spatie\EventSourcing\StoredEvents\HandleStoredEventJob;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Spatie\EventSourcing\StoredEvents\Repositories\EloquentStoredEventRepository;
use Spatie\EventSourcing\Support\CarbonNormalizer;
use Spatie\EventSourcing\Support\ModelIdentifierNormalizer;
use Spatie\EventSourcing\Support\ObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;

return [

    /*
     * These directories will be scanned for projectors and reactors. They
     * will be registered to Projectionist automatically.
     */
    'auto_discover_projectors_and_reactors' => [
        app()->path(),
    ],

    /*
     * This directory will be used as the base path when scanning
     * for projectors and reactors.
     */
    'auto_discover_base_path' => base_path(),

    /*
     * Projectors are classes that build up projections. You can create them by performing
     * `php artisan event-sourcing:create-projector`. When not using auto-discovery,
     * Projectors can be registered in this array or a service provider.
     */
    'projectors' => [
        // App\Projectors\YourProjector::class
    ],

    /*
     * Reactors are classes that handle side-effects. You can create them by performing
     * `php artisan event-sourcing:create-reactor`. When not using auto-discovery
     * Reactors can be registered in this array or a service provider.
     */
    'reactors' => [
        // App\Reactors\YourReactor::class
    ],

    /*
     * A queue is used to guarantee that all events get passed to the projectors in
     * the right order. Here you can set of the name of the queue.
     */
    'queue' => env('EVENT_PROJECTOR_QUEUE_NAME', null),

    /*
     * When a Projector or Reactor throws an exception the event Projectionist can catch it
     * so all other projectors and reactors can still do their work. The exception will
     * be passed to the `handleException` method on that Projector or Reactor.
     */
    'catch_exceptions' => env('EVENT_PROJECTOR_CATCH_EXCEPTIONS', false),

    /*
     * This class is responsible for storing events in the EloquentStoredEventRepository.
     * To add extra behaviour you can change this to a class of your own. It should
     * extend the \Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent model.
     */
    'stored_event_model' => EloquentStoredEvent::class,

    /*
     * This class is responsible for storing events. To add extra behaviour you
     * can change this to a class of your own. The only restriction is that
     * it should implement \Spatie\EventSourcing\StoredEvents\Repositories\EloquentStoredEventRepository.
     */
    'stored_event_repository' => EloquentStoredEventRepository::class,

    /*
     * This class is responsible for storing snapshots. To add extra behaviour you
     * can change this to a class of your own. The only restriction is that
     * it should implement \Spatie\EventSourcing\Snapshots\EloquentSnapshotRepository.
     */
    'snapshot_repository' => EloquentSnapshotRepository::class,

    /*
     * This class is responsible for storing events in the EloquentSnapshotRepository.
     * To add extra behaviour you can change this to a class of your own. It should
     * extend the \Spatie\EventSourcing\Snapshots\EloquentSnapshot model.
     */
    'snapshot_model' => EloquentSnapshot::class,

    /*
     * This class is responsible for handling stored events. To add extra behaviour you
     * can change this to a class of your own. The only restriction is that
     * it should implement \Spatie\EventSourcing\StoredEvents\HandleDomainEventJob.
     */
    'stored_event_job' => HandleStoredEventJob::class,

    /*
     * backend/CLAUDE.md の原則により、stored_events.event_class にはPHPクラス名ではなく
     * 短い文字列(例: 'attachment.uploaded')を保存する。イベントクラスの名前空間・クラス名を
     * 後から変更しても既存イベントの再生(event-sourcing:replay)に影響しないようにするため、
     * event_class_map への登録を必須にする。
     */
    'enforce_event_class_map' => true,

    /*
     * Similar to Relation::morphMap() you can define which alias responds to which
     * event class. This allows you to change the namespace or class names
     * of your events but still handle older events correctly.
     *
     * ドメインイベントを追加・移行したら必ずここに <aggregate>.<past_tense_verb> 形式の
     * 短い文字列を登録すること(docs/17-events.md の命名規則。.claude/skills/add-domain-event 参照)。
     */
    'event_class_map' => [
        'attachment.uploaded' => AttachmentUploaded::class,
        'attachment.downloaded' => AttachmentDownloaded::class,

        'application_integration.registered' => ApplicationIntegrationRegistered::class,
        'application_integration.token_reissued' => ApplicationIntegrationTokenReissued::class,
        'application_integration.revoked' => ApplicationIntegrationRevoked::class,

        'authentication_key.issued' => AuthenticationKeyIssued::class,
        'authentication_key.disabled' => AuthenticationKeyDisabled::class,

        'device.registered' => DeviceRegistered::class,
        'device.paired' => DevicePaired::class,
        'device.pairing_claim_issued' => DevicePairingClaimIssued::class,
        'device.disabled' => DeviceDisabled::class,
        'device.revoked' => DeviceRevoked::class,
        'device.deleted' => DeviceDeleted::class,
        'device.role_assigned' => DeviceRoleAssigned::class,
        'device.scope_granted' => DeviceScopeGranted::class,
        'device.settings_updated' => DeviceSettingsUpdated::class,

        'device_admin_session.started' => DeviceAdminSessionStarted::class,
        'device_admin_session.ended' => DeviceAdminSessionEnded::class,

        'notification.queued' => NotificationQueued::class,
        'notification.sent' => NotificationSent::class,
        'notification.confirmed' => NotificationConfirmed::class,

        'workflow_request.drafted' => WorkflowRequestDrafted::class,
        'workflow_request.submitted' => WorkflowRequestSubmitted::class,
        'workflow_request.approved' => WorkflowRequestApproved::class,
        'workflow_request.returned' => WorkflowRequestReturned::class,
        'workflow_request.cancelled' => WorkflowRequestCancelled::class,

        'paid_leave.granted' => PaidLeaveGranted::class,
        'paid_leave.requested' => PaidLeaveRequested::class,
        'paid_leave.request_approved' => PaidLeaveRequestApproved::class,
        'paid_leave.request_returned' => PaidLeaveRequestReturned::class,
        'paid_leave.request_cancelled' => PaidLeaveRequestCancelled::class,
        'paid_leave.used' => PaidLeaveUsed::class,
        'paid_leave.warning_raised' => PaidLeaveWarningRaised::class,

        'special_leave.granted' => SpecialLeaveGranted::class,
        'special_leave.requested' => SpecialLeaveRequested::class,
        'special_leave.request_approved' => SpecialLeaveRequestApproved::class,
        'special_leave.request_returned' => SpecialLeaveRequestReturned::class,
        'special_leave.request_cancelled' => SpecialLeaveRequestCancelled::class,
        'special_leave.used' => SpecialLeaveUsed::class,

        'backoffice_task.created' => BackOfficeTaskCreated::class,
        'backoffice_task.assigned' => BackOfficeTaskAssigned::class,
        'backoffice_task.completed' => BackOfficeTaskCompleted::class,
        'backoffice_task.status_changed' => BackOfficeTaskStatusChanged::class,
    ],

    /*
     * This class is responsible for serializing events. By default an event will be serialized
     * and stored as json. You can customize the class name. A valid serializer
     * should implement Spatie\EventSourcing\EventSerializers\EventSerializer.
     */
    'event_serializer' => JsonEventSerializer::class,

    /*
     * These classes normalize and restore your events when they're serialized. They allow
     * you to efficiently store PHP objects like Carbon instances, Eloquent models, and
     * Collections. If you need to store other complex data, you can add your own normalizers
     * to the chain. See https://symfony.com/doc/current/components/serializer.html#normalizers
     */
    'event_normalizers' => [
        CarbonNormalizer::class,
        ModelIdentifierNormalizer::class,
        DateTimeNormalizer::class,
        ArrayDenormalizer::class,
        ObjectNormalizer::class,
    ],

    /*
     * In production, you likely don't want the package to auto-discover the event handlers
     * on every request. The package can cache all registered event handlers.
     * More info:
     * https://spatie.be/docs/laravel-event-sourcing/v7/advanced-usage/discovering-projectors-and-reactors#content-caching-discovered-projectors-and-reactors
     *
     * Here you can specify where the cache should be stored.
     */
    'cache_path' => base_path('bootstrap/cache'),

    /*
     * When storable events are fired from aggregates roots, the package can fire off these
     * events as regular events as well.
     */

    'dispatch_events_from_aggregate_roots' => false,

    /*
     * This setting determines which column is used to order events when retrieving
     * events for a specific aggregate.
     *
     * Options:
     * - 'id' (default): Orders by the auto-incrementing ID column. This is the traditional
     *   behavior but can cause MySQL "Out of sort memory" errors with large event payloads
     *   because it requires a filesort operation.
     *
     * - 'aggregate_version': Orders by the aggregate_version column. This is semantically
     *   correct for event sourcing and uses the existing (aggregate_uuid, aggregate_version)
     *   index, avoiding filesort operations and preventing memory issues with large payloads.
     *
     * Note: This only affects queries filtered by aggregate_uuid. Global event queries
     * (without uuid filter) always use 'id' for proper cross-aggregate ordering.
     */
    'aggregate_event_order_column' => 'aggregate_version',
];
