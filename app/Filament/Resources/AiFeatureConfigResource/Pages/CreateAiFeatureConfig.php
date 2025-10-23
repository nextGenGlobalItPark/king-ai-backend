<?php

namespace App\Filament\Resources\AiFeatureConfigResource\Pages;

use App\Filament\Resources\AiFeatureConfigResource;
use Exception;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class CreateAiFeatureConfig extends CreateRecord
{
    protected static string $resource = AiFeatureConfigResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Auto-set created_by to current user
        $data['created_by'] = auth()->id();

        return $data;
    }

    /**
     * @throws Exception
     */
    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        try {
            $record = parent::handleRecordCreation($data);
            $featureName = $record->feature?->name ?? 'Unknown Feature (ID: ' . $data['ai_feature_id'] . ')';
            Notification::make()
                ->title('AI Feature Config Created')
                ->body('The config for "' . $featureName . '" has been created successfully.')
                ->success()
                ->send();
            return $record;
        } catch (Exception $e) {
            // Log the error for debugging
            Log::error('Failed to create AiFeatureConfig: ' . $e->getMessage(), [
                'data' => $data,
                'user_id' => auth()->id(),
            ]);

            Notification::make()
                ->title('Creation Failed')
                ->body('An error occurred while creating the AI feature config: ' . $e->getMessage())
                ->danger()
                ->send();

            throw $e;  // Re-throw to let Filament handle rollback
        }
    }
}