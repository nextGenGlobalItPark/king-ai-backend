<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AiFeatureConfigResource\Pages;
use App\Models\AiFeatureConfig;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AiFeatureConfigResource extends Resource
{
    protected static ?string $model = AiFeatureConfig::class;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'AI Feature Configs';

    protected static ?string $pluralLabel = 'AI Feature Configs';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Configuration Details')
                    ->schema([
                        Forms\Components\Select::make('ai_feature_id')
                            ->label('AI Feature')
                            ->options(\App\Models\AiFeature::pluck('name', 'id'))  // Manual options to avoid relationship saving issue
                            ->required()
                            ->searchable(),
                        Forms\Components\Select::make('data_source_id')
                            ->label('Data Source')
                            ->relationship('dataSource', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),
                        Forms\Components\TextInput::make('endpoint')
                            ->label('Endpoint URL')
                            ->required()
                            ->url()
                            ->maxLength(255),
                        Forms\Components\Select::make('method')
                            ->label('HTTP Method')
                            ->options([
                                'POST' => 'POST',
                                'GET' => 'GET',
                                'PUT' => 'PUT',
                                'DELETE' => 'DELETE',
                            ])
                            ->default('POST')
                            ->required(),
                        Forms\Components\Select::make('auth_type')
                            ->label('Auth Type')
                            ->options([
                                'token' => 'Token',
                                'bearer' => 'Bearer',
                            ])
                            ->default('token')
                            ->required(),
                        Forms\Components\TextInput::make('auth_header_key')
                            ->label('Auth Header Key')
                            ->placeholder('e.g., Authorization')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('auth_value_from_env')
                            ->label('Auth Value from ENV')
                            ->placeholder('e.g., VIDU_API_KEY')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('content_type')
                            ->label('Content Type')
                            ->options([
                                'application/json' => 'application/json',
                                'multipart/form-data' => 'multipart/form-data',
                            ])
                            ->default('application/json')
                            ->required(),
                        Forms\Components\Select::make('body_format')
                            ->label('Body Format')
                            ->options([
                                'json' => 'JSON',
                                'form-data' => 'Form Data',
                            ])
                            ->default('json')
                            ->required(),
                        Forms\Components\Textarea::make('standard_schema')
                            ->label('Standard Schema')
                            ->placeholder('{"required": ["prompt"], "optional": ["duration"]}')
                            ->json()
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('param_mapping')
                            ->label('Param Mapping')
                            ->placeholder('{"prompt": "prompt", "duration": "duration"}')
                            ->required()
                            ->json()
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('default_params')
                            ->label('Default Params')
                            ->placeholder('{"model": "viduq1"}')
                            ->json()
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('extra_params')
                            ->label('Extra Params')
                            ->placeholder('{"off_peak": false}')
                            ->json()
                            ->columnSpanFull(),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                        Forms\Components\TextInput::make('priority')
                            ->label('Priority')
                            ->numeric()
                            ->default(0)
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
                            ->dehydrated(false)
                            ->visible(false),
                    ])
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('feature.name')
                    ->label('AI Feature')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('dataSource.name')
                    ->label('Data Source')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('endpoint')
                    ->label('Endpoint')
                    ->limit(50)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('method')
                    ->label('Method')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->sortable(),
                Tables\Columns\TextColumn::make('priority')
                    ->label('Priority')
                    ->numeric()
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
            'index' => Pages\ListAiFeatureConfigs::route('/'),
            'create' => Pages\CreateAiFeatureConfig::route('/create'),
            'edit' => Pages\EditAiFeatureConfig::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['feature', 'dataSource', 'createdBy'])  // Eager-load relationships for display
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}