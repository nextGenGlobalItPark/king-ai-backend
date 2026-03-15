<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TemplateResource\Pages;
use App\Models\Template;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TemplateResource extends Resource
{
    protected static ?string $model = Template::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?string $navigationLabel = 'Templates';

    protected static ?string $navigationGroup = 'Content Management';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Template Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g., Balloon Parade'),

                        Forms\Components\Select::make('templateCategories')
                            ->label('Template Categories')
                            ->relationship('templateCategories', 'template_category_name')
                            ->multiple()
                            ->required()
                            ->searchable()
                            ->preload()
                            ->helperText('Select one or more categories for this template')
                            ->createOptionForm([
                                Forms\Components\TextInput::make('template_category_name')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('template_category_slug')
                                    ->required()
                                    ->maxLength(255),
                            ]),

                        Forms\Components\TextInput::make('credit_cost')
                            ->label('Credit Cost')
                            ->numeric()
                            ->default(5)
                            ->minValue(0)
                            ->required()
                            ->helperText('Number of credits required to use this template'),

                        Forms\Components\TextInput::make('sequence')
                            ->label('Display Order')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->required()
                            ->helperText('Lower numbers appear first'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('AI Configuration')
                    ->schema([
                        Forms\Components\Select::make('ai_feature_id')
                            ->label('AI Feature')
                            ->relationship('aiFeature', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->helperText('Select the AI feature this template uses. The vendor/data source is determined by the AI Feature\'s active data source setting.'),
                    ])
                    ->columns(1)
                    ->description('The data source (vendor) is configured on the AI Feature, not the template.'),

                Forms\Components\Section::make('Status & Settings')
                    ->schema([
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Only active templates are visible to users'),

                        Forms\Components\Select::make('state')
                            ->options([
                                'test' => 'Test',
                                'live' => 'Live',
                            ])
                            ->default('test')
                            ->required()
                            ->helperText('Test templates are only visible in test environment'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('templateCategories.template_category_name')
                    ->label('Categories')
                    ->searchable()
                    ->badge()
                    ->color('info')
                    ->separator(',')
                    ->limitList(2)
                    ->tooltip(fn ($record) => $record->templateCategories->pluck('template_category_name')->join(', ')),

                Tables\Columns\TextColumn::make('aiFeature.name')
                    ->label('AI Feature')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('success'),

                Tables\Columns\TextColumn::make('credit_cost')
                    ->label('Credits')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color('primary'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->sortable(),

                Tables\Columns\TextColumn::make('state')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'live' => 'success',
                        'test' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('sequence')
                    ->label('Order')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('createdBy.name')
                    ->label('Created By')
                    ->searchable()
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
                Tables\Filters\SelectFilter::make('templateCategories')
                    ->label('Category')
                    ->relationship('templateCategories', 'template_category_name')
                    ->searchable()
                    ->preload()
                    ->multiple(),

                Tables\Filters\SelectFilter::make('ai_feature_id')
                    ->label('AI Feature')
                    ->relationship('aiFeature', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('data_source_id')
                    ->label('Data Source')
                    ->relationship('dataSource', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('state')
                    ->options([
                        'test' => 'Test',
                        'live' => 'Live',
                    ]),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status')
                    ->placeholder('All')
                    ->trueLabel('Active Only')
                    ->falseLabel('Inactive Only'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\ForceDeleteAction::make(),
                Tables\Actions\RestoreAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('sequence', 'asc')
            ->reorderable('sequence')
            ->striped();
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
            'index' => Pages\ListTemplates::route('/'),
            'create' => Pages\CreateTemplate::route('/create'),
            'edit' => Pages\EditTemplate::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['templateCategories', 'aiFeature', 'dataSource', 'createdBy']);
    }
}