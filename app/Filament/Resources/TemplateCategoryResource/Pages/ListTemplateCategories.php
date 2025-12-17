<?php

namespace App\Filament\Resources\TemplateCategoryResource\Pages;

use App\Filament\Resources\TemplateCategoryResource;
use App\Models\TemplateCategory;
use App\Events\CategoryEvent;  // Import the event
use App\Http\Resources\CategoryCollection;  // Changed from CategoryResource to CategoryCollection
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;  // Use Factory directly
use Exception;
use Illuminate\Support\Facades\Config;

class ListTemplateCategories extends ListRecords
{
    protected static string $resource = TemplateCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportToFirebase')
                ->label('Export to Firebase')
                ->icon('heroicon-o-cloud-arrow-up')  // Updated icon name
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Export to Firebase')
                ->modalDescription('This will export all template categories with vendor status mappings to Firebase.')
                ->modalSubmitActionLabel('Export')
                ->action(function () {
                    try {
                        // Instantiate Firebase Factory directly from config (avoids contract issues)
                        $factory = (new Factory)
                            ->withServiceAccount(storage_path('app/vidio-king-ai-firebase.json'))  // Adjust path if needed
                            ->withDatabaseUri(Config::get('firebase.projects.your-project-id.database.url'));

                        $database = $factory->createDatabase();

                        // Fetch all categories with necessary relations, ordered by sequence
                        $categories = TemplateCategory::with([
                            'templates.aiFeature.configs.dataSource',
                            'templates.templateContents',
                            'templates.createdBy',
                            'templates.dataSource',
                            'dataSources',  // No need for .vendorStatusMapping (CategoryCollection handles this)
                            'createdBy'
                        ])
                            ->orderBy('sequence', 'asc')
                            ->get();

                        // Transform using CategoryCollection (this adds status_mapping at top level)
                        $categoryCollection = new CategoryCollection($categories);
                        $transformedData = $categoryCollection->toArray(new Request());
                        // The CategoryCollection already wraps data in the correct format
                        // So we just need to add metadata
                        $payload = [
                            'data' => $transformedData,  // This has status_mapping + categories
                            'last_updated' => now()->toIso8601String(),
                        ];

                        // Upload to Firebase (adjust path as needed, e.g., 'template_categories')
                        $database->getReference('template_categories')->set($payload);

                        Notification::make()
                            ->title('Success')
                            ->body('Successfully exported ' . $categories->count() . ' categories to Firebase')
                            ->success()
                            ->duration(5000)
                            ->send();

                        Log::info('Template categories exported to Firebase', [
                            'count' => $categories->count(),
                            'user_id' => auth()->id(),
                            'user_name' => auth()->user()->name ?? 'unknown',
                            'timestamp' => now(),
                        ]);

                    } catch (Exception $e) {
                        Log::error('Failed to export template categories to Firebase: ' . $e->getMessage(), [
                            'user_id' => auth()->id(),
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);

                        Notification::make()
                            ->title('Export Failed')
                            ->body('An error occurred: ' . $e->getMessage())
                            ->danger()
                            ->send();

                        throw $e; // Re-throw to prevent partial saves if needed
                    }
                }),
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
        return parent::getEloquentQuery();
    }
}