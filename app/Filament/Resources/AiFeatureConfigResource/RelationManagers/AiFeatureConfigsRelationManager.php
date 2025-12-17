<?php

namespace App\Filament\Resources\AiFeatureResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Eloquent\Model;

class AiFeatureConfigsRelationManager extends RelationManager
{
    protected static string $relationship = 'configs';

    protected static ?string $recordTitleAttribute = 'endpoint';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
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
                    ->label('Status Check Endpoint')
                    ->required()
                    ->url()
                    ->maxLength(500)
                    ->columnSpanFull()
                    ->helperText('Optional: Separate endpoint for checking job status.'),
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
                Forms\Components\TextInput::make('auth_header_key')
                    ->label('Auth Header Key')
                    ->placeholder('e.g., Authorization')
                    ->default('Authorization')
                    ->maxLength(100),
                Forms\Components\TextInput::make('auth_value_from_env')
                    ->label('Auth Value from ENV')
                    ->placeholder('e.g., KLING_ACCESS_KEY')
                    ->required()
                    ->maxLength(100),
                Forms\Components\TextInput::make('auth_secret_key_env')
                    ->label('Secret Key from ENV (JWT)')
                    ->placeholder('e.g., KLING_SECRET_KEY')
                    ->maxLength(100)
                    ->visible(fn (Forms\Get $get) => $get('auth_type') === 'jwt')
                    ->required(fn (Forms\Get $get) => $get('auth_type') === 'jwt'),
                Forms\Components\TextInput::make('jwt_expiry_seconds')
                    ->label('JWT Expiry (seconds)')
                    ->numeric()
                    ->default(1800)
                    ->visible(fn (Forms\Get $get) => $get('auth_type') === 'jwt'),
                Forms\Components\TextInput::make('jwt_nbf_offset_seconds')
                    ->label('JWT NBF Offset (seconds)')
                    ->numeric()
                    ->default(5)
                    ->visible(fn (Forms\Get $get) => $get('auth_type') === 'jwt'),
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
                Forms\Components\Textarea::make('standard_schema')
                    ->label('Standard Schema')
                    ->placeholder('{"fields": [{"name": "image", "type": "string", "required": true}]}')
                    ->rows(8)
                    ->required()
                    ->columnSpanFull()
                    ->helperText('Define input fields with validation, types, and defaults')
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
                    ->placeholder('{"effect_scene": "{{effect_scene}}", "input": {"image": "{{image}}"}}')
                    ->rows(8)
                    ->columnSpanFull()
                    ->helperText('API request structure with {{placeholders}}')
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
                Forms\Components\Textarea::make('response_mapping')
                    ->label('Response Mapping')
                    ->placeholder('{"status_path": "data.task_status", "asset_array_path": "data.task_result.videos"}')
                    ->required()
                    ->rows(8)
                    ->columnSpanFull()
                    ->helperText('Paths to extract data from API response')
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
                Forms\Components\Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
                Forms\Components\TextInput::make('priority')
                    ->label('Priority')
                    ->numeric()
                    ->default(1)
                    ->minValue(0),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
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
                    ->limit(40)
                    ->searchable()
                    ->placeholder('Same as main')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('method')
                    ->label('Method')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'POST' => 'success',
                        'GET' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('auth_type')
                    ->label('Auth')
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
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'Configs';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('dataSource');
    }
}