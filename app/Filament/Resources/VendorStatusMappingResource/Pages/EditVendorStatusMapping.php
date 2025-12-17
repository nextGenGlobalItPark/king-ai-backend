<?php

namespace App\Filament\Resources\VendorStatusMappingResource\Pages;

use App\Filament\Resources\VendorStatusMappingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class EditVendorStatusMapping extends EditRecord
{
    protected static string $resource = VendorStatusMappingResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Convert status_mapping JSON to repeater format for display
        if (isset($data['status_mapping'])) {
            $statusMapping = $data['status_mapping'];

            // Convert completed statuses
            if (isset($statusMapping['completed'])) {
                $data['completed_statuses'] = array_map(
                    fn($status) => ['status' => $status],
                    $statusMapping['completed']
                );
            }

            // Convert failed statuses
            if (isset($statusMapping['failed'])) {
                $data['failed_statuses'] = array_map(
                    fn($status) => ['status' => $status],
                    $statusMapping['failed']
                );
            }

            // Convert processing statuses
            if (isset($statusMapping['processing'])) {
                $data['processing_statuses'] = array_map(
                    fn($status) => ['status' => $status],
                    $statusMapping['processing']
                );
            }
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Convert repeater format back to JSON for saving
        $data['status_mapping'] = [
            'completed' => array_column($data['completed_statuses'] ?? [], 'status'),
            'failed' => array_column($data['failed_statuses'] ?? [], 'status'),
            'processing' => array_column($data['processing_statuses'] ?? [], 'status'),
        ];

        // Remove temporary repeater fields
        unset($data['completed_statuses'], $data['failed_statuses'], $data['processing_statuses']);

        return $data;
    }

    protected function handleRecordUpdate(\Illuminate\Database\Eloquent\Model $record, array $data): \Illuminate\Database\Eloquent\Model
    {
        try {
            $record = parent::handleRecordUpdate($record, $data);

            Notification::make()
                ->title('Vendor Status Mapping Updated')
                ->body('The mapping for "' . $record->dataSource->name . '" has been updated successfully.')
                ->success()
                ->send();

            return $record;
        } catch (\Exception $e) {
            Log::error('Failed to update VendorStatusMapping: ' . $e->getMessage(), [
                'data' => $data,
                'record_id' => $record->id,
                'user_id' => auth()->id(),
            ]);

            Notification::make()
                ->title('Update Failed')
                ->body('An error occurred while updating the vendor status mapping: ' . $e->getMessage())
                ->danger()
                ->send();

            throw $e;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}