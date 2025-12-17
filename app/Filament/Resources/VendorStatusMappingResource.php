<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VendorStatusMappingResource\Pages;
use App\Models\VendorStatusMapping;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class VendorStatusMappingResource extends Resource
{
    protected static ?string $model = VendorStatusMapping::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'Vendor Status Mappings';

    protected static ?string $pluralLabel = 'Vendor Status Mappings';

    protected static ?string $navigationGroup = 'AI Configuration';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Mapping Configuration')
                    ->schema([
                        Forms\Components\Select::make('data_source_id')
                            ->label('Data Source')
                            ->relationship('dataSource', 'name')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->searchable()
                            ->preload()
                            ->helperText('Each data source can only have one status mapping'),
                    ])
                    ->columns(1),

                Forms\Components\Section::make('Status Mapping')
                    ->description('Map vendor-specific status values to internal statuses (completed, failed, processing)')
                    ->schema([
                        Forms\Components\Repeater::make('completed_statuses')
                            ->label('Completed Statuses')
                            ->schema([
                                Forms\Components\TextInput::make('status')
                                    ->label('Vendor Status')
                                    ->required()
                                    ->placeholder('e.g., succeed, success, done')
                                    ->maxLength(100),
                            ])
                            ->defaultItems(3)
                            ->minItems(1)
                            ->maxItems(10)
                            ->columns(1)
                            ->columnSpanFull()
                            ->helperText('Vendor statuses that mean the task completed successfully')
                            ->default([
                                ['status' => 'succeed'],
                                ['status' => 'success'],
                                ['status' => 'completed'],
                            ]),

                        Forms\Components\Repeater::make('failed_statuses')
                            ->label('Failed Statuses')
                            ->schema([
                                Forms\Components\TextInput::make('status')
                                    ->label('Vendor Status')
                                    ->required()
                                    ->placeholder('e.g., failed, error, rejected')
                                    ->maxLength(100),
                            ])
                            ->defaultItems(2)
                            ->minItems(1)
                            ->maxItems(10)
                            ->columns(1)
                            ->columnSpanFull()
                            ->helperText('Vendor statuses that mean the task failed')
                            ->default([
                                ['status' => 'failed'],
                                ['status' => 'error'],
                            ]),

                        Forms\Components\Repeater::make('processing_statuses')
                            ->label('Processing Statuses')
                            ->schema([
                                Forms\Components\TextInput::make('status')
                                    ->label('Vendor Status')
                                    ->required()
                                    ->placeholder('e.g., processing, pending, queued')
                                    ->maxLength(100),
                            ])
                            ->defaultItems(3)
                            ->minItems(1)
                            ->maxItems(10)
                            ->columns(1)
                            ->columnSpanFull()
                            ->helperText('Vendor statuses that mean the task is still processing')
                            ->default([
                                ['status' => 'submitted'],
                                ['status' => 'processing'],
                                ['status' => 'pending'],
                            ]),
                    ])
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('dataSource.name')
                    ->label('Data Source')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status_mapping')
                    ->label('Completed Statuses')
                    ->getStateUsing(fn ($record) => implode(', ', $record->status_mapping['completed'] ?? []))
                    ->limit(50)
                    ->tooltip(fn ($record) => implode(', ', $record->status_mapping['completed'] ?? [])),
                Tables\Columns\TextColumn::make('status_mapping')
                    ->label('Failed Statuses')
                    ->getStateUsing(fn ($record) => implode(', ', $record->status_mapping['failed'] ?? []))
                    ->limit(50)
                    ->tooltip(fn ($record) => implode(', ', $record->status_mapping['failed'] ?? [])),
                Tables\Columns\TextColumn::make('status_mapping')
                    ->label('Processing Statuses')
                    ->getStateUsing(fn ($record) => implode(', ', $record->status_mapping['processing'] ?? []))
                    ->limit(50)
                    ->tooltip(fn ($record) => implode(', ', $record->status_mapping['processing'] ?? [])),
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->label('Created By')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateActions([
                Tables\Actions\CreateAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVendorStatusMappings::route('/'),
            'create' => Pages\CreateVendorStatusMapping::route('/create'),
            'edit' => Pages\EditVendorStatusMapping::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['dataSource', 'createdBy']);
    }
}