<?php

namespace App\Filament\Resources\TemplateResource\Pages;

use App\Filament\Resources\TemplateResource;
use Exception;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class CreateTemplate extends CreateRecord
{
    protected static string $resource = TemplateResource::class;

    /**
     * @throws Exception
     */
    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        try {
            $record = parent::handleRecordCreation($data);

            Notification::make()
                ->title('Template Created')
                ->body('The template "' . $record->name . '" has been created successfully.')
                ->success()
                ->send();
            return $record;
        } catch (Exception $e) {
            // Log the error for debugging
            Log::error('Failed to create Template: ' . $e->getMessage(), [
                'data' => $data,
                'user_id' => auth()->id(),
            ]);

            Notification::make()
                ->title('Creation Failed')
                ->body('An error occurred while creating the template: ' . $e->getMessage())
                ->danger()
                ->send();

            throw $e;  // Re-throw to let Filament handle rollback
        }
    }
}