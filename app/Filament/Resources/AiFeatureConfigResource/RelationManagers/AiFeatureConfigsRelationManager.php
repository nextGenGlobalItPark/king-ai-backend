<?php

namespace App\Filament\Resources\AiFeatureResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AiFeatureConfigsRelationManager extends RelationManager
{
    protected static string $relationship = 'configs';

    protected static ?string $recordTitleAttribute = 'endpoint';

    /**
     * Kling presets (shared with main resource)
     */
    private static function getKlingPresets(): array
    {
        return [
            'kling_image2video' => [
                'label' => 'Kling — Image to Video',
                'endpoint' => 'https://api-singapore.klingai.com/v1/videos/image2video',
                'status_check_endpoint' => 'https://api-singapore.klingai.com/v1/videos/image2video',
                'method' => 'POST',
                'auth_type' => 'jwt',
                'auth_header_key' => 'Authorization',
                'content_type' => 'application/json',
                'body_format' => 'json',
                'standard_schema' => [
                    'fields' => [
                        ['name' => 'image', 'type' => 'string', 'required' => true, 'label' => 'Source image URL'],
                        ['name' => 'image_tail', 'type' => 'string', 'required' => false, 'label' => 'Tail image URL (optional)'],
                        ['name' => 'prompt', 'type' => 'string', 'required' => true, 'label' => 'Motion prompt'],
                        ['name' => 'negative_prompt', 'type' => 'string', 'required' => false, 'default' => '', 'label' => 'Negative prompt'],
                        ['name' => 'duration', 'type' => 'string', 'required' => false, 'default' => '5', 'label' => 'Video duration (seconds)'],
                        ['name' => 'mode', 'type' => 'string', 'required' => false, 'default' => 'pro', 'label' => 'Generation mode'],
                        ['name' => 'model_name', 'type' => 'string', 'required' => false, 'default' => 'kling-v2-6', 'hidden' => true],
                        ['name' => 'sound', 'type' => 'string', 'required' => false, 'default' => 'off', 'hidden' => true],
                        ['name' => 'callback_url', 'type' => 'string', 'required' => false, 'default' => '', 'hidden' => true],
                        ['name' => 'external_task_id', 'type' => 'string', 'required' => false, 'default' => '', 'hidden' => true],
                    ],
                ],
                'request_body_template' => [
                    'model_name' => '{{model_name}}',
                    'image' => '{{image}}',
                    'image_tail' => '{{image_tail}}',
                    'prompt' => '{{prompt}}',
                    'negative_prompt' => '{{negative_prompt}}',
                    'duration' => '{{duration}}',
                    'mode' => '{{mode}}',
                    'sound' => '{{sound}}',
                    'callback_url' => '{{callback_url}}',
                    'external_task_id' => '{{external_task_id}}',
                ],
                'response_mapping' => [
                    'task_id' => 'data.task_id',
                    'status_path' => 'data.task_status',
                    'result_path' => 'data.task_result',
                    'asset_array_path' => 'data.task_result.videos',
                    'asset_url_path' => 'url',
                    'asset_id_path' => 'id',
                    'video_duration_path' => 'duration',
                    'error_code_path' => 'code',
                    'error_message_path' => 'data.task_status_msg',
                ],
            ],
            'kling_text2video' => [
                'label' => 'Kling — Text to Video',
                'endpoint' => 'https://api-singapore.klingai.com/v1/videos/text2video',
                'status_check_endpoint' => 'https://api-singapore.klingai.com/v1/videos/text2video',
                'method' => 'POST',
                'auth_type' => 'jwt',
                'auth_header_key' => 'Authorization',
                'content_type' => 'application/json',
                'body_format' => 'json',
                'standard_schema' => [
                    'fields' => [
                        ['name' => 'prompt', 'type' => 'string', 'required' => true, 'label' => 'Text prompt'],
                        ['name' => 'negative_prompt', 'type' => 'string', 'required' => false, 'default' => '', 'label' => 'Negative prompt'],
                        ['name' => 'duration', 'type' => 'string', 'required' => false, 'default' => '5', 'label' => 'Video duration (seconds)'],
                        ['name' => 'aspect_ratio', 'type' => 'string', 'required' => false, 'default' => '16:9', 'label' => 'Aspect ratio'],
                        ['name' => 'mode', 'type' => 'string', 'required' => false, 'default' => 'pro', 'label' => 'Generation mode'],
                        ['name' => 'model_name', 'type' => 'string', 'required' => false, 'default' => 'kling-v2-6', 'hidden' => true],
                        ['name' => 'sound', 'type' => 'string', 'required' => false, 'default' => 'off', 'hidden' => true],
                        ['name' => 'callback_url', 'type' => 'string', 'required' => false, 'default' => '', 'hidden' => true],
                        ['name' => 'external_task_id', 'type' => 'string', 'required' => false, 'default' => '', 'hidden' => true],
                    ],
                ],
                'request_body_template' => [
                    'model_name' => '{{model_name}}',
                    'prompt' => '{{prompt}}',
                    'negative_prompt' => '{{negative_prompt}}',
                    'duration' => '{{duration}}',
                    'aspect_ratio' => '{{aspect_ratio}}',
                    'mode' => '{{mode}}',
                    'sound' => '{{sound}}',
                    'callback_url' => '{{callback_url}}',
                    'external_task_id' => '{{external_task_id}}',
                ],
                'response_mapping' => [
                    'task_id' => 'data.task_id',
                    'status_path' => 'data.task_status',
                    'result_path' => 'data.task_result',
                    'asset_array_path' => 'data.task_result.videos',
                    'asset_url_path' => 'url',
                    'asset_id_path' => 'id',
                    'video_duration_path' => 'duration',
                    'error_code_path' => 'code',
                    'error_message_path' => 'data.task_status_msg',
                ],
            ],
        ];
    }

    private static function jsonFormatState($state): ?string
    {
        if (is_array($state)) {
            return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        return $state;
    }

    private static function jsonDehydrateState($state)
    {
        if (is_string($state)) {
            return json_decode($state, true);
        }
        return $state;
    }

    public function form(Form $form): Form
    {
        $presets = self::getKlingPresets();
        $presetOptions = collect($presets)->mapWithKeys(fn($p, $key) => [$key => $p['label']])->toArray();

        return $form
            ->schema([
                // Preset loader
                Forms\Components\Actions::make([
                    Forms\Components\Actions\Action::make('loadPreset')
                        ->label('Load preset')
                        ->icon('heroicon-o-bolt')
                        ->color('warning')
                        ->form([
                            Forms\Components\Select::make('preset')
                                ->label('Select a preset')
                                ->options($presetOptions)
                                ->required(),
                        ])
                        ->action(function (array $data, Set $set) use ($presets) {
                            $preset = $presets[$data['preset']] ?? null;
                            if (!$preset) return;

                            $set('endpoint', $preset['endpoint']);
                            $set('status_check_endpoint', $preset['status_check_endpoint']);
                            $set('method', $preset['method']);
                            $set('auth_type', $preset['auth_type']);
                            $set('auth_header_key', $preset['auth_header_key']);
                            $set('content_type', $preset['content_type']);
                            $set('body_format', $preset['body_format']);
                            $set('standard_schema', json_encode($preset['standard_schema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                            $set('request_body_template', json_encode($preset['request_body_template'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                            $set('response_mapping', json_encode($preset['response_mapping'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                            if ($preset['auth_type'] === 'jwt') {
                                $set('auth_value_from_env', 'KLING_ACCESS_KEY');
                                $set('auth_secret_key_env', 'KLING_SECRET_KEY');
                                $set('jwt_expiry_seconds', 1800);
                                $set('jwt_nbf_offset_seconds', 5);
                            }
                        }),
                ])->columnSpanFull(),

                Forms\Components\Select::make('data_source_id')
                    ->label('Data source')
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
                    ->label('Status check endpoint')
                    ->required()
                    ->url()
                    ->maxLength(500)
                    ->columnSpanFull()
                    ->helperText('For Kling: same as endpoint. For Vidu: derived automatically.'),
                Forms\Components\Select::make('method')
                    ->label('HTTP method')
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
                    ->label('Auth type')
                    ->options([
                        'static_token' => 'Static Token (Vidu)',
                        'jwt' => 'JWT (Kling)',
                    ])
                    ->default('static_token')
                    ->required()
                    ->live(),
                Forms\Components\TextInput::make('auth_header_key')
                    ->label('Auth header key')
                    ->placeholder('Authorization')
                    ->default('Authorization')
                    ->maxLength(100),
                Forms\Components\TextInput::make('auth_value_from_env')
                    ->label(fn (Get $get) => $get('auth_type') === 'jwt' ? 'Access key env variable' : 'API key env variable')
                    ->placeholder(fn (Get $get) => $get('auth_type') === 'jwt' ? 'KLING_ACCESS_KEY' : 'VIDU_API_KEY')
                    ->required()
                    ->maxLength(100),
                Forms\Components\TextInput::make('auth_secret_key_env')
                    ->label('Secret key env variable (JWT)')
                    ->placeholder('KLING_SECRET_KEY')
                    ->maxLength(100)
                    ->visible(fn (Get $get) => $get('auth_type') === 'jwt')
                    ->required(fn (Get $get) => $get('auth_type') === 'jwt'),
                Forms\Components\TextInput::make('jwt_expiry_seconds')
                    ->label('JWT expiry (seconds)')
                    ->numeric()
                    ->default(1800)
                    ->visible(fn (Get $get) => $get('auth_type') === 'jwt'),
                Forms\Components\TextInput::make('jwt_nbf_offset_seconds')
                    ->label('JWT NBF offset (seconds)')
                    ->numeric()
                    ->default(5)
                    ->visible(fn (Get $get) => $get('auth_type') === 'jwt'),
                Forms\Components\Select::make('content_type')
                    ->label('Content type')
                    ->options([
                        'application/json' => 'application/json',
                        'multipart/form-data' => 'multipart/form-data',
                    ])
                    ->default('application/json')
                    ->required(),
                Forms\Components\Select::make('body_format')
                    ->label('Body format')
                    ->options([
                        'json' => 'JSON',
                        'form-data' => 'Form Data',
                        'form_params' => 'Form Params',
                    ])
                    ->default('json')
                    ->required(),
                Forms\Components\Textarea::make('standard_schema')
                    ->label('Standard schema')
                    ->rows(10)
                    ->required()
                    ->columnSpanFull()
                    ->helperText('Define input fields: name, type, required, default, hidden, label')
                    ->formatStateUsing(fn ($state) => self::jsonFormatState($state))
                    ->dehydrateStateUsing(fn ($state) => self::jsonDehydrateState($state)),

                // Auto-generate template button
                Forms\Components\Actions::make([
                    Forms\Components\Actions\Action::make('generateTemplate')
                        ->label('Auto-generate template from schema')
                        ->icon('heroicon-o-sparkles')
                        ->color('info')
                        ->action(function (Get $get, Set $set) {
                            $schemaRaw = $get('standard_schema');
                            $schema = is_string($schemaRaw) ? json_decode($schemaRaw, true) : $schemaRaw;
                            if (!$schema || empty($schema['fields'])) return;

                            $template = [];
                            foreach ($schema['fields'] as $field) {
                                if ($name = $field['name'] ?? null) {
                                    $template[$name] = '{{' . $name . '}}';
                                }
                            }
                            $set('request_body_template', json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                        })
                        ->requiresConfirmation()
                        ->modalDescription('Creates a flat template from schema fields. Edit the structure to match the vendor API format.'),
                ])->columnSpanFull(),

                Forms\Components\Textarea::make('request_body_template')
                    ->label('Request body template')
                    ->rows(10)
                    ->columnSpanFull()
                    ->helperText('Use {{field_name}} placeholders. For nested JSON: {"input": {"image": "{{image}}"}}')
                    ->formatStateUsing(fn ($state) => self::jsonFormatState($state))
                    ->dehydrateStateUsing(fn ($state) => self::jsonDehydrateState($state)),
                Forms\Components\Textarea::make('response_mapping')
                    ->label('Response mapping')
                    ->required()
                    ->rows(10)
                    ->columnSpanFull()
                    ->helperText('Dot-notation paths to extract data from vendor API response')
                    ->formatStateUsing(fn ($state) => self::jsonFormatState($state))
                    ->dehydrateStateUsing(fn ($state) => self::jsonDehydrateState($state)),
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
                    ->label('Data source')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('endpoint')
                    ->label('Endpoint')
                    ->limit(50)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status_check_endpoint')
                    ->label('Status endpoint')
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
            ->filters([])
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