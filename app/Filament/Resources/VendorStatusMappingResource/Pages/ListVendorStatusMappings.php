<?php

namespace App\Filament\Resources\VendorStatusMappingResource\Pages;

use App\Filament\Resources\VendorStatusMappingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ListVendorStatusMappings extends ListRecords
{
    protected static string $resource = VendorStatusMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
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
                            ->title('Vendor Status Mappings Deleted')
                            ->body(count($records) . ' mappings have been deleted successfully.')
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        Log::error('Failed to bulk delete VendorStatusMappings: ' . $e->getMessage(), [
                            'records_count' => $records->count(),
                            'user_id' => auth()->id(),
                        ]);

                        Notification::make()
                            ->title('Bulk Deletion Failed')
                            ->body('An error occurred while deleting the vendor status mappings: ' . $e->getMessage())
                            ->danger()
                            ->send();

                        throw $e;
                    }
                })
                ->requiresConfirmation()
                ->deselectRecordsAfterCompletion(),
        ];
    }
}