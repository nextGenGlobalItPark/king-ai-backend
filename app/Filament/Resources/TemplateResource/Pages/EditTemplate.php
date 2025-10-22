<?php

namespace App\Filament\Resources\TemplateResource\Pages;

use App\Filament\Resources\TemplateResource;
use Exception;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class EditTemplate extends EditRecord
{
    protected static string $resource = TemplateResource::class;

    /**
     * @throws Exception
     */
    protected function handleRecordUpdate(\Illuminate\Database\Eloquent\Model $record, array $data): \Illuminate\Database\Eloquent\Model
    {
        try {
            $record = parent::handleRecordUpdate($record, $data);

            Notification::make()
                ->title('Template Updated')
                ->body('The template "' . $record->name . '" has been updated successfully.')
                ->success()
                ->send();
            return $record;
        } catch (Exception $e) {
            // Log the error for debugging
            Log::error('Failed to update Template: ' . $e->getMessage(), [
                'data' => $data,
                'record_id' => $record->id,
                'user_id' => auth()->id(),
            ]);

            Notification::make()
                ->title('Update Failed')
                ->body('An error occurred while updating the template: ' . $e->getMessage())
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