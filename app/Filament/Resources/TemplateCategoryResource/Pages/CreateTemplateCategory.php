<?php

namespace App\Filament\Resources\TemplateCategoryResource\Pages;

use App\Filament\Resources\TemplateCategoryResource;
use App\Events\CategoryEvent;  // Import the event
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class CreateTemplateCategory extends CreateRecord
{
    protected static string $resource = TemplateCategoryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Auto-set created_by to current user
        $data['created_by'] = auth()->id();

        // Optional: Auto-generate slug if not provided
        if (empty($data['template_category_slug'])) {
            $data['template_category_slug'] = str($data['template_category_name'])->slug();
        }

        return $data;
    }

    /**
     * @throws \Exception
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            $record = parent::handleRecordCreation($data);

            // Dispatch the CategoryEvent after successful creation
            event(new CategoryEvent($record, 'created'));

            Notification::make()
                ->title('Template Category Created')
                ->body('The category "' . $record->template_category_name . '" has been created successfully.')
                ->success()
                ->send();
            return $record;
        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error('Failed to create TemplateCategory: ' . $e->getMessage(), [
                'data' => $data,
                'user_id' => auth()->id(),
            ]);

            Notification::make()
                ->title('Creation Failed')
                ->body('An error occurred while creating the category: ' . $e->getMessage())
                ->danger()
                ->send();

            throw $e;  // Re-throw to let Filament handle rollback
        }
    }
}