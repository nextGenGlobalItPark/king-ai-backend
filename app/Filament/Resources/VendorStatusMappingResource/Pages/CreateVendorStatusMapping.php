<?php

namespace App\Filament\Resources\VendorStatusMappingResource\Pages;

use App\Filament\Resources\VendorStatusMappingResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class CreateVendorStatusMapping extends CreateRecord
{
    protected static string $resource = VendorStatusMappingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Auto-set created_by to current user
        $data['created_by'] = auth()->id();

        // Build status_mapping from repeater fields
        $data['status_mapping'] = [
            'completed' => array_column($data['completed_statuses'] ?? [], 'status'),
            'failed' => array_column($data['failed_statuses'] ?? [], 'status'),
            'processing' => array_column($data['processing_statuses'] ?? [], 'status'),
        ];

        // Remove temporary repeater fields
        unset($data['completed_statuses'], $data['failed_statuses'], $data['processing_statuses']);

        return $data;
    }

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        try {
            $record = parent::handleRecordCreation($data);

            Notification::make()
                ->title('Vendor Status Mapping Created')
                ->body('The mapping for "' . $record->dataSource->name . '" has been created successfully.')
                ->success()
                ->send();

            return $record;
        } catch (\Exception $e) {
            Log::error('Failed to create VendorStatusMapping: ' . $e->getMessage(), [
                'data' => $data,
                'user_id' => auth()->id(),
            ]);

            Notification::make()
                ->title('Creation Failed')
                ->body('An error occurred while creating the vendor status mapping: ' . $e->getMessage())
                ->danger()
                ->send();

            throw $e;
        }
    }
}