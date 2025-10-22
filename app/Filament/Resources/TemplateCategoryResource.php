<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TemplateCategoryResource\Pages;
use App\Models\TemplateCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
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
            ->columns([
                Tables\Columns\TextColumn::make('template_category_name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('template_category_slug')
                    ->label('Slug')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('templates_count')
                    ->label('Templates Count')
                    ->counts('templates')
                    ->sortable(),
                Tables\Columns\TextColumn::make('dataSourcesCount')
                    ->label('Data Sources Count')
                    ->counts('dataSources')
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
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}