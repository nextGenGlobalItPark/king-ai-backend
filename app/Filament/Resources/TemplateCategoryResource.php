<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TemplateCategoryResource\Pages;
use App\Models\TemplateCategory;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\IconColumn;  // Added this import to fix the error
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TemplateCategoryResource extends Resource
{
    protected static ?string $model = TemplateCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-folder';

    protected static ?string $navigationLabel = 'Template Categories';

    protected static ?string $pluralLabel = 'Template Categories';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Category Details')
                    ->schema([
                        Forms\Components\TextInput::make('template_category_name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)  // Auto-generate slug on blur
                            ->afterStateUpdated(function ($state, callable $set) {
                                $set('template_category_slug', str($state)->slug());
                            }),
                        Forms\Components\TextInput::make('template_category_slug')
                            ->label('Slug')
                            ->required()
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn ($rule) => $rule->whereNull('deleted_at')
                            )
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label('Is Active')
                            ->default(true),
                        Select::make('state')
                            ->label('State')
                            ->options([
                                'test' => 'Test',
                                'live' => 'Live',
                            ])
                            ->default('test')
                            ->required(),
                        TextInput::make('sequence')
                            ->label('Sequence')
                            ->numeric()
                            ->default(1)
                            ->minValue(0),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Relations')
                    ->schema([
                        Forms\Components\Select::make('created_by')
                            ->label('Created By')
                            ->relationship('createdBy', 'name')
                            ->searchable()
                            ->preload()
                            ->dehydrated(false)  // Set automatically in mutateFormDataBeforeCreate
                            ->visible(false),  // Hide if auto-set
                        Forms\Components\Select::make('data_sources')
                            ->multiple()
                            ->label('Data Sources')
                            ->relationship('dataSources', 'name')  // Adjust field if DataSource has different key
                            ->searchable()
                            ->preload(),
                    ])
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sequence')  // Enables drag-and-drop reordering using the 'sequence' column
            ->columns([
                Tables\Columns\TextColumn::make('template_category_name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('template_category_slug')
                    ->label('Slug')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_active')  // Now resolves correctly with the use statement
                ->label('Active')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->sortable(),
                Tables\Columns\TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'test' => 'warning',
                        'live' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('sequence')
                    ->label('Sequence')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('templates_count')
                    ->label('Templates Count')
                    ->sortable(),
                Tables\Columns\TextColumn::make('data_sources_count')
                    ->label('Data Sources Count')
                    ->sortable(),
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
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                    Tables\Actions\ForceDeleteAction::make(),
                    Tables\Actions\RestoreAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
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
            'index' => Pages\ListTemplateCategories::route('/'),
            'create' => Pages\CreateTemplateCategory::route('/create'),
            'edit' => Pages\EditTemplateCategory::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount(['templates as templates_count', 'dataSources as data_sources_count'])
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ])
            ->orderBy('sequence', 'asc');  // Ensures rows are displayed in sequence order
    }
}