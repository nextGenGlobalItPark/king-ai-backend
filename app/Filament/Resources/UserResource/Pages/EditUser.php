<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordUpdate(\Illuminate\Database\Eloquent\Model $record, array $data): \Illuminate\Database\Eloquent\Model
    {
        try {
            $record = parent::handleRecordUpdate($record, $data);
            Notification::make()
                ->title('User Updated')
                ->body('The user "' . $record->name . '" has been updated successfully.')
                ->success()
                ->send();
            return $record;
        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error('Failed to update User: ' . $e->getMessage(), [
                'data' => $data,
                'record_id' => $record->id,
                'user_id' => auth()->id(),
            ]);

            Notification::make()
                ->title('Update Failed')
                ->body('An error occurred while updating the user: ' . $e->getMessage())
                ->danger()
                ->send();

            throw $e;  // Re-throw to let Filament handle rollback
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}