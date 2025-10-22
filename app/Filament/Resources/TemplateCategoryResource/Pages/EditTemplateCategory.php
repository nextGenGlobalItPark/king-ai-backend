<?php

namespace App\Filament\Resources\TemplateCategoryResource\Pages;

use App\Filament\Resources\TemplateCategoryResource;
use App\Events\CategoryEvent;  // Import the event
use Exception;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class EditTemplateCategory extends EditRecord
{
    protected static string $resource = TemplateCategoryResource::class;

    /**
     * @throws Exception
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            $record = parent::handleRecordUpdate($record, $data);

            // Dispatch the CategoryEvent after successful update
            event(new CategoryEvent($record, 'updated'));

            Notification::make()
                ->title('Template Category Updated')
                ->body('The category "' . $record->template_category_name . '" has been updated successfully.')
                ->success()
                ->send();
            return $record;
        } catch (Exception $e) {
            // Log the error for debugging
            Log::error('Failed to update TemplateCategory: ' . $e->getMessage(), [
                'data' => $data,
                'record_id' => $record->id,
                'user_id' => auth()->id(),
            ]);

            Notification::make()
                ->title('Update Failed')
                ->body('An error occurred while updating the category: ' . $e->getMessage())
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