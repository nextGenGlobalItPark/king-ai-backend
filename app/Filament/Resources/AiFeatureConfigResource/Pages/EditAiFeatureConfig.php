<?php

namespace App\Filament\Resources\AiFeatureConfigResource\Pages;

use App\Filament\Resources\AiFeatureConfigResource;
use Exception;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class EditAiFeatureConfig extends EditRecord
{
    protected static string $resource = AiFeatureConfigResource::class;

    /**
     * @throws Exception
     */
    protected function handleRecordUpdate(\Illuminate\Database\Eloquent\Model $record, array $data): \Illuminate\Database\Eloquent\Model
    {
        try {
            $record = parent::handleRecordUpdate($record, $data);
            $featureName = $record->feature?->name ?? 'Unknown Feature (ID: ' . $record->ai_feature_id . ')';
            Notification::make()
                ->title('AI Feature Config Updated')
                ->body('The config for "' . $featureName . '" has been updated successfully.')
                ->success()
                ->send();
            return $record;
        } catch (Exception $e) {
            // Log the error for debugging
            Log::error('Failed to update AiFeatureConfig: ' . $e->getMessage(), [
                'data' => $data,
                'record_id' => $record->id,
                'user_id' => auth()->id(),
            ]);

            Notification::make()
                ->title('Update Failed')
                ->body('An error occurred while updating the AI feature config: ' . $e->getMessage())
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