<?php

namespace App\Filament\Resources\TemplateCategoryResource\Pages;

use App\Filament\Resources\TemplateCategoryResource;
use App\Models\TemplateCategory;
use App\Events\CategoryEvent;  // Import the event
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ListTemplateCategories extends ListRecords
{
    protected static string $resource = TemplateCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),  // This ensures the Create button is always visible in the header
        ];
    }

    protected function getTableActions(): array
    {
        return [
            Tables\Actions\EditAction::make(),
            DeleteAction::make()
                ->after(function (TemplateCategory $record) {
                    // Dispatch the CategoryEvent after successful deletion (soft delete)
                    event(new CategoryEvent($record, 'deleted'));

                    Notification::make()
                        ->title('Template Category Deleted')
                        ->body('The category "' . $record->template_category_name . '" has been deleted successfully.')
                        ->success()
                        ->send();
                })
                ->requiresConfirmation(),
        ];
    }

    protected function getTableBulkActions(): array
    {
        return [
            BulkAction::make('delete')
                ->action(function (Collection $records) {
                    try {
                        $records->each->delete();

                        // Dispatch events for each deleted record
                        $records->each(function ($record) {
                            event(new CategoryEvent($record, 'deleted'));
                        });

                        Notification::make()
                            ->title('Template Categories Deleted')
                            ->body(count($records) . ' categories have been deleted successfully.')
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        Log::error('Failed to bulk delete TemplateCategories: ' . $e->getMessage(), [
                            'records_count' => $records->count(),
                            'user_id' => auth()->id(),
                        ]);

                        Notification::make()
                            ->title('Bulk Deletion Failed')
                            ->body('An error occurred while deleting the categories: ' . $e->getMessage())
                            ->danger()
                            ->send();

                        throw $e;
                    }
                })
                ->requiresConfirmation()
                ->deselectRecordsAfterCompletion(),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}