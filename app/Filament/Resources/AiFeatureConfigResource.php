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
                            ->options(\App\Models\AiFeature::pluck('name', 'id'))
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
                            ->maxLength(500)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('status_check_endpoint')
                            ->label('Status Check Endpoint URL')
                            ->required()
                            ->url()
                            ->maxLength(500)
                            ->columnSpanFull()
                            ->helperText('Optional: Separate endpoint for checking job status. Leave empty if status is checked at the same endpoint.'),
                        Forms\Components\Select::make('method')
                            ->label('HTTP Method')
                            ->options([
                                'POST' => 'POST',
                                'GET' => 'GET',
                                'PUT' => 'PUT',
                                'PATCH' => 'PATCH',
                                'DELETE' => 'DELETE',
                            ])
                            ->default('POST')
                            ->required(),
                        Forms\Components\Select::make('auth_type')
                            ->label('Auth Type')
                            ->options([
                                'static_token' => 'Static Token',
                                'jwt' => 'JWT',
                            ])
                            ->default('static_token')
                            ->required()
                            ->live(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Authentication')
                    ->schema([
                        Forms\Components\TextInput::make('auth_header_key')
                            ->label('Auth Header Key')
                            ->placeholder('e.g., Authorization')
                            ->default('Authorization')
                            ->maxLength(100),
                        Forms\Components\TextInput::make('auth_value_from_env')
                            ->label('Auth Value from ENV')
                            ->placeholder('e.g., KLING_ACCESS_KEY')
                            ->required()
                            ->maxLength(100)
                            ->helperText('For JWT: Access Key env variable'),
                        Forms\Components\TextInput::make('auth_secret_key_env')
                            ->label('Secret Key from ENV (JWT only)')
                            ->placeholder('e.g., KLING_SECRET_KEY')
                            ->maxLength(100)
                            ->visible(fn (Forms\Get $get) => $get('auth_type') === 'jwt')
                            ->required(fn (Forms\Get $get) => $get('auth_type') === 'jwt')
                            ->helperText('Required for JWT authentication'),
                        Forms\Components\TextInput::make('jwt_expiry_seconds')
                            ->label('JWT Expiry (seconds)')
                            ->numeric()
                            ->default(1800)
                            ->minValue(60)
                            ->maxValue(86400)
                            ->visible(fn (Forms\Get $get) => $get('auth_type') === 'jwt'),
                        Forms\Components\TextInput::make('jwt_nbf_offset_seconds')
                            ->label('JWT NBF Offset (seconds)')
                            ->numeric()
                            ->default(5)
                            ->minValue(0)
                            ->maxValue(300)
                            ->visible(fn (Forms\Get $get) => $get('auth_type') === 'jwt'),
                    ])
                    ->columns(2)
                    ->collapsible(),

                Forms\Components\Section::make('Request Configuration')
                    ->schema([
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
                                'form_params' => 'Form Params',
                            ])
                            ->default('json')
                            ->required(),
                    ])
                    ->columns(2)
                    ->collapsible(),

                Forms\Components\Section::make('Request Body Template')
                    ->schema([
                        Forms\Components\Textarea::make('standard_schema')
                            ->label('Standard Schema')
                            ->placeholder('{"fields": [{"name": "image", "type": "string", "required": true}, {"name": "duration", "type": "string", "default": "5", "hidden": false}, {"name": "effect_scene", "type": "string", "default": "balloon_parade", "hidden": true}]}')
                            ->rows(10)
                            ->required()
                            ->columnSpanFull()
                            ->helperText('Define input fields with validation, types, defaults, and hidden fields. Each field must have: name (required), type (required), and optionally: required, default, hidden, label, ui_hint.')
                            ->formatStateUsing(function ($state) {
                                // Convert array to pretty JSON when loading
                                if (is_array($state)) {
                                    return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                                }
                                return $state;
                            })
                            ->dehydrateStateUsing(function ($state) {
                                // Convert JSON string to array when saving
                                if (is_string($state)) {
                                    return json_decode($state, true);
                                }
                                return $state;
                            }),
                        Forms\Components\Textarea::make('request_body_template')
                            ->label('Request Body Template')
                            ->placeholder('{"effect_scene": "{{effect_scene}}", "input": {"duration": "{{duration}}", "image": "{{image}}"}}')
                            ->rows(10)
                            ->columnSpanFull()
                            ->helperText('Exact API request structure with {{placeholder}} syntax. Placeholders must match field names in standard_schema.')
                            ->formatStateUsing(function ($state) {
                                // Convert array to pretty JSON when loading
                                if (is_array($state)) {
                                    return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                                }
                                return $state;
                            })
                            ->dehydrateStateUsing(function ($state) {
                                // Convert JSON string to array when saving
                                if (is_string($state)) {
                                    return json_decode($state, true);
                                }
                                return $state;
                            }),
                    ])
                    ->collapsible()
                    ->collapsed(false),

                Forms\Components\Section::make('Response Mapping')
                    ->schema([
                        Forms\Components\Textarea::make('response_mapping')
                            ->label('Response Mapping')
                            ->placeholder('{"status_path": "data.task_status", "asset_array_path": "data.task_result.videos", "asset_url_path": "url", "asset_id_path": "id", "video_duration_path": "duration", "error_code_path": "code", "error_message_path": "message"}')
                            ->rows(10)
                            ->required()
                            ->columnSpanFull()
                            ->helperText('Define paths to extract data from API response using dot notation (e.g., "data.task_status")')
                            ->formatStateUsing(function ($state) {
                                // Convert array to pretty JSON when loading
                                if (is_array($state)) {
                                    return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                                }
                                return $state;
                            })
                            ->dehydrateStateUsing(function ($state) {
                                // Convert JSON string to array when saving
                                if (is_string($state)) {
                                    return json_decode($state, true);
                                }
                                return $state;
                            }),
                    ])
                    ->collapsible(),

                Forms\Components\Section::make('Settings')
                    ->schema([
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                        Forms\Components\TextInput::make('priority')
                            ->label('Priority')
                            ->numeric()
                            ->default(1)
                            ->minValue(0),
                    ])
                    ->columns(2)
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
                Tables\Columns\TextColumn::make('status_check_endpoint')
                    ->label('Status Endpoint')
                    ->limit(50)
                    ->searchable()
                    ->placeholder('Same as main endpoint')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('method')
                    ->label('Method')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'POST' => 'success',
                        'GET' => 'info',
                        'PUT', 'PATCH' => 'warning',
                        'DELETE' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('auth_type')
                    ->label('Auth Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'jwt' => 'warning',
                        'static_token' => 'success',
                        default => 'gray',
                    })
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
                Tables\Filters\SelectFilter::make('auth_type')
                    ->options([
                        'static_token' => 'Static Token',
                        'jwt' => 'JWT',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status'),
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
            ->with(['feature', 'dataSource', 'createdBy']);
    }
}