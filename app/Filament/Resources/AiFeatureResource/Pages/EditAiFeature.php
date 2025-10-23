<?php

namespace App\Filament\Resources\AiFeatureResource\Pages;

use App\Filament\Resources\AiFeatureResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class EditAiFeature extends EditRecord
{
    protected static string $resource = AiFeatureResource::class;

    protected function handleRecordUpdate(\Illuminate\Database\Eloquent\Model $record, array $data): \Illuminate\Database\Eloquent\Model
    {
        try {
            $record = parent::handleRecordUpdate($record, $data);
            Notification::make()
                ->title('AI Feature Updated')
                ->body('The AI feature "' . $record->name . '" has been updated successfully.')
                ->success()
                ->send();
            return $record;
        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error('Failed to update AiFeature: ' . $e->getMessage(), [
                'data' => $data,
                'record_id' => $record->id,
                'user_id' => auth()->id(),
            ]);

            Notification::make()
                ->title('Update Failed')
                ->body('An error occurred while updating the AI feature: ' . $e->getMessage())
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