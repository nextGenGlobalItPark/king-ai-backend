<?php

namespace App\Filament\Resources\DataSourceResource\Pages;

use App\Filament\Resources\DataSourceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;

class ListDataSources extends ListRecords
{
    protected static string $resource = DataSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),  // Ensures the Create button is visible in the header
        ];
    }

    protected function getTableActions(): array
    {
        return [
            Tables\Actions\EditAction::make(),
            DeleteAction::make()
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

                        Notification::make()
                            ->title('Data Sources Deleted')
                            ->body(count($records) . ' data sources have been deleted successfully.')
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        \Log::error('Failed to bulk delete DataSources: ' . $e->getMessage(), [
                            'records_count' => $records->count(),
                            'user_id' => auth()->id(),
                        ]);

                        Notification::make()
                            ->title('Bulk Deletion Failed')
                            ->body('An error occurred while deleting the data sources: ' . $e->getMessage())
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
        return parent::getEloquentQuery();
    }
}