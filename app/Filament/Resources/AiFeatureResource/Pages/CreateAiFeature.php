<?php

namespace App\Filament\Resources\AiFeatureResource\Pages;

use App\Filament\Resources\AiFeatureResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class CreateAiFeature extends CreateRecord
{
    protected static string $resource = AiFeatureResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Auto-set created_by to current user
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        try {
            $record = parent::handleRecordCreation($data);
            Notification::make()
                ->title('AI Feature Created')
                ->body('The AI feature "' . $record->name . '" has been created successfully.')
                ->success()
                ->send();
            return $record;
        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error('Failed to create AiFeature: ' . $e->getMessage(), [
                'data' => $data,
                'user_id' => auth()->id(),
            ]);

            Notification::make()
                ->title('Creation Failed')
                ->body('An error occurred while creating the AI feature: ' . $e->getMessage())
                ->danger()
                ->send();

            throw $e;  // Re-throw to let Filament handle rollback
        }
    }
}