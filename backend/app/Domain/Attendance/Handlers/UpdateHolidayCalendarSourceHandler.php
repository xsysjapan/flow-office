<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\HolidayCalendarSourceAggregate;
use App\Domain\Attendance\Commands\UpdateHolidayCalendarSource;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\HolidayCalendarSource;
use Illuminate\Support\Facades\Storage;

/**
 * UC-C012: 祝日iCalendarソースのURL/アップロードファイルを編集する。
 *
 * @implements CommandHandler<UpdateHolidayCalendarSource>
 */
class UpdateHolidayCalendarSourceHandler implements CommandHandler
{
    public function handle(Command $command): HolidayCalendarSource
    {
        assert($command instanceof UpdateHolidayCalendarSource);

        $before = HolidayCalendarSource::query()->findOrFail($command->holidayCalendarSourceId);
        $oldUploadedIcsPath = $before->uploaded_ics_path;

        HolidayCalendarSourceAggregate::retrieve($command->holidayCalendarSourceId)
            ->update(
                name: $command->name,
                sourceKind: $command->sourceKind,
                icsUrl: $command->icsUrl,
                uploadedIcsPath: $command->uploadedIcsPath,
                uploadedIcsFilename: $command->uploadedIcsFilename,
                updatedByUserId: $command->updatedByUserId,
            )
            ->persist();

        if ($oldUploadedIcsPath !== null && $oldUploadedIcsPath !== $command->uploadedIcsPath) {
            Storage::disk('local')->delete($oldUploadedIcsPath);
        }

        return HolidayCalendarSource::query()->findOrFail($command->holidayCalendarSourceId);
    }
}
