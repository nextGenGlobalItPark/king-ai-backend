<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AiFeatureConfigResource\Pages;
use App\Models\AiFeatureConfig;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Forms\Get;
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

    /**
     * Kling API presets for quick configuration
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
                    'generation' => [
                        'task_id_path' => 'data.task_id',
                        'error_code_path' => 'code',
                        'error_message_path' => 'message',
                        'success_codes' => [0],
                    ],
                    'polling' => [
                        'method' => 'GET',
                        'url_template' => '{status_check_endpoint}/{task_id}',
                        'status_path' => 'data.task_status',
                        'progress_path' => null,
                        'error_code_path' => 'code',
                        'error_message_path' => 'data.task_status_msg',
                    ],
                    'result' => [
                        'asset_array_path' => 'data.task_result.videos',
                        'asset_url_path' => 'url',
                        'asset_id_path' => 'id',
                        'duration_path' => 'duration',
                        'thumbnail_path' => null,
                    ],
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
                    'generation' => [
                        'task_id_path' => 'data.task_id',
                        'error_code_path' => 'code',
                        'error_message_path' => 'message',
                        'success_codes' => [0],
                    ],
                    'polling' => [
                        'method' => 'GET',
                        'url_template' => '{status_check_endpoint}/{task_id}',
                        'status_path' => 'data.task_status',
                        'progress_path' => null,
                        'error_code_path' => 'code',
                        'error_message_path' => 'data.task_status_msg',
                    ],
                    'result' => [
                        'asset_array_path' => 'data.task_result.videos',
                        'asset_url_path' => 'url',
                        'asset_id_path' => 'id',
                        'duration_path' => 'duration',
                        'thumbnail_path' => null,
                    ],
                ],
            ],
            'kling_effects' => [
                'label' => 'Kling — Video Effects',
                'endpoint' => 'https://api-singapore.klingai.com/v1/videos/effects',
                'status_check_endpoint' => 'https://api-singapore.klingai.com/v1/videos/effects',
                'method' => 'POST',
                'auth_type' => 'jwt',
                'auth_header_key' => 'Authorization',
                'content_type' => 'application/json',
                'body_format' => 'json',
                'standard_schema' => [
                    'fields' => [
                        ['name' => 'image', 'type' => 'string', 'required' => true, 'label' => 'Source image URL'],
                        ['name' => 'effect_scene', 'type' => 'string', 'required' => true, 'label' => 'Effect scene name'],
                        ['name' => 'model_name', 'type' => 'string', 'required' => false, 'default' => 'kling-v1', 'hidden' => true],
                        ['name' => 'callback_url', 'type' => 'string', 'required' => false, 'default' => '', 'hidden' => true],
                        ['name' => 'external_task_id', 'type' => 'string', 'required' => false, 'default' => '', 'hidden' => true],
                    ],
                ],
                'request_body_template' => [
                    'model_name' => '{{model_name}}',
                    'input' => [
                        'image' => '{{image}}',
                    ],
                    'effect_scene' => '{{effect_scene}}',
                    'callback_url' => '{{callback_url}}',
                    'external_task_id' => '{{external_task_id}}',
                ],
                'response_mapping' => [
                    'generation' => [
                        'task_id_path' => 'data.task_id',
                        'error_code_path' => 'code',
                        'error_message_path' => 'message',
                        'success_codes' => [0],
                    ],
                    'polling' => [
                        'method' => 'GET',
                        'url_template' => '{status_check_endpoint}/{task_id}',
                        'status_path' => 'data.task_status',
                        'progress_path' => null,
                        'error_code_path' => 'code',
                        'error_message_path' => 'data.task_status_msg',
                    ],
                    'result' => [
                        'asset_array_path' => 'data.task_result.videos',
                        'asset_url_path' => 'url',
                        'asset_id_path' => 'id',
                        'duration_path' => 'duration',
                        'thumbnail_path' => null,
                    ],
                ],
            ],
        ];
    }

    /**
     * Helper: convert array/json to pretty JSON for display
     */
    private static function jsonFormatState($state): ?string
    {
        if (is_array($state)) {
            return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        return $state;
    }

    /**
     * Helper: convert JSON string back to array for saving
     */
    private static function jsonDehydrateState($state)
    {
        if (is_string($state)) {
            return json_decode($state, true);
        }
        return $state;
    }

    /**
     * Build the "Load Preset" action that fills the form
     */
    private static function presetAction(): Forms\Components\Actions\Action
    {
        $presets = self::getKlingPresets();
        $options = collect($presets)->mapWithKeys(fn($p, $key) => [$key => $p['label']])->toArray();

        return Forms\Components\Actions\Action::make('loadPreset')
            ->label('Load preset')
            ->icon('heroicon-o-bolt')
            ->color('warning')
            ->form([
                Forms\Components\Select::make('preset')
                    ->label('Select a preset')
                    ->options($options)
                    ->required()
                    ->helperText('This will populate all fields below. You can still edit them before saving.'),
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

                // Set JSON fields as pretty-printed strings (textarea expects string)
                $set('standard_schema', json_encode($preset['standard_schema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $set('request_body_template', json_encode($preset['request_body_template'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $set('response_mapping', json_encode($preset['response_mapping'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                // JWT defaults
                if ($preset['auth_type'] === 'jwt') {
                    $set('auth_value_from_env', 'KLING_ACCESS_KEY');
                    $set('auth_secret_key_env', 'KLING_SECRET_KEY');
                    $set('jwt_expiry_seconds', 1800);
                    $set('jwt_nbf_offset_seconds', 5);
                }
            });
    }

    /**
     * Build the "Generate template from schema" action
     */
    private static function generateTemplateAction(): Forms\Components\Actions\Action
    {
        return Forms\Components\Actions\Action::make('generateTemplate')
            ->label('Auto-generate from schema')
            ->icon('heroicon-o-sparkles')
            ->color('info')
            ->action(function (Get $get, Set $set) {
                $schemaRaw = $get('standard_schema');
                $schema = is_string($schemaRaw) ? json_decode($schemaRaw, true) : $schemaRaw;

                if (!$schema || empty($schema['fields'])) {
                    return;
                }

                // Build a flat request body template from schema fields
                $template = [];
                foreach ($schema['fields'] as $field) {
                    $name = $field['name'] ?? null;
                    if ($name) {
                        $template[$name] = '{{' . $name . '}}';
                    }
                }

                $set('request_body_template', json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            })
            ->requiresConfirmation()
            ->modalHeading('Generate request body template')
            ->modalDescription('This will create a flat template with {{placeholder}} for every field in the standard schema. You can then rearrange the JSON structure to match the vendor API format. Any existing template will be overwritten.');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // Preset loader at the top
                Forms\Components\Section::make('Quick setup')
                    ->schema([
                        Forms\Components\Actions::make([
                            self::presetAction(),
                        ]),
                        Forms\Components\Placeholder::make('preset_hint')
                            ->content('Load a preset to auto-fill all fields for a known vendor API, or configure manually below.')
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(fn (string $operation) => $operation === 'edit'),

                Forms\Components\Section::make('Configuration details')
                    ->schema([
                        Forms\Components\Select::make('ai_feature_id')
                            ->label('AI feature')
                            ->options(\App\Models\AiFeature::pluck('name', 'id'))
                            ->required()
                            ->searchable(),
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
                            ->columnSpanFull()
                            ->placeholder('https://api-singapore.klingai.com/v1/videos/image2video'),
                        Forms\Components\TextInput::make('status_check_endpoint')
                            ->label('Status check endpoint URL')
                            ->required()
                            ->url()
                            ->maxLength(500)
                            ->columnSpanFull()
                            ->placeholder('https://api-singapore.klingai.com/v1/videos/image2video')
                            ->helperText('For Kling: same as endpoint (task_id is appended by the poll strategy). For Vidu: derived automatically.'),
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
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Authentication')
                    ->schema([
                        Forms\Components\TextInput::make('auth_header_key')
                            ->label('Auth header key')
                            ->placeholder('Authorization')
                            ->default('Authorization')
                            ->maxLength(100),
                        Forms\Components\TextInput::make('auth_value_from_env')
                            ->label(fn (Get $get) => $get('auth_type') === 'jwt' ? 'Access key env variable' : 'API key env variable')
                            ->placeholder(fn (Get $get) => $get('auth_type') === 'jwt' ? 'KLING_ACCESS_KEY' : 'VIDU_API_KEY')
                            ->required()
                            ->maxLength(100)
                            ->helperText('Name of the environment variable (not the value itself).'),
                        Forms\Components\TextInput::make('auth_secret_key_env')
                            ->label('Secret key env variable (JWT only)')
                            ->placeholder('KLING_SECRET_KEY')
                            ->maxLength(100)
                            ->visible(fn (Get $get) => $get('auth_type') === 'jwt')
                            ->required(fn (Get $get) => $get('auth_type') === 'jwt')
                            ->helperText('Required for JWT. The env var holding the secret key.'),
                        Forms\Components\TextInput::make('jwt_expiry_seconds')
                            ->label('JWT expiry (seconds)')
                            ->numeric()
                            ->default(1800)
                            ->minValue(60)
                            ->maxValue(86400)
                            ->visible(fn (Get $get) => $get('auth_type') === 'jwt')
                            ->helperText('Default: 1800 (30 minutes)'),
                        Forms\Components\TextInput::make('jwt_nbf_offset_seconds')
                            ->label('JWT NBF offset (seconds)')
                            ->numeric()
                            ->default(5)
                            ->minValue(0)
                            ->maxValue(300)
                            ->visible(fn (Get $get) => $get('auth_type') === 'jwt')
                            ->helperText('Clock skew tolerance. Default: 5'),
                    ])
                    ->columns(2)
                    ->collapsible(),

                Forms\Components\Section::make('Request configuration')
                    ->schema([
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
                    ])
                    ->columns(2)
                    ->collapsible(),

                Forms\Components\Section::make('Standard schema')
                    ->description('Define what input fields the mobile app sends. Each field needs a name and type. Fields with "hidden: true" are auto-filled from defaults and not shown to the user.')
                    ->schema([
                        Forms\Components\Textarea::make('standard_schema')
                            ->label('Schema definition')
                            ->rows(14)
                            ->required()
                            ->columnSpanFull()
                            ->placeholder(json_encode([
                                'fields' => [
                                    ['name' => 'image', 'type' => 'string', 'required' => true, 'label' => 'Source image URL'],
                                    ['name' => 'prompt', 'type' => 'string', 'required' => true, 'label' => 'Motion prompt'],
                                    ['name' => 'duration', 'type' => 'string', 'default' => '5', 'label' => 'Duration'],
                                    ['name' => 'model_name', 'type' => 'string', 'default' => 'kling-v2-6', 'hidden' => true],
                                ],
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                            ->helperText('JSON object with a "fields" array. Each field: name (required), type (required), and optionally: required, default, hidden, label.')
                            ->formatStateUsing(fn ($state) => self::jsonFormatState($state))
                            ->dehydrateStateUsing(fn ($state) => self::jsonDehydrateState($state)),
                    ])
                    ->collapsible()
                    ->collapsed(false),

                Forms\Components\Section::make('Request body template')
                    ->description('Map schema fields to the exact JSON structure the vendor API expects. Use {{field_name}} placeholders.')
                    ->schema([
                        Forms\Components\Actions::make([
                            self::generateTemplateAction(),
                        ]),
                        Forms\Components\Textarea::make('request_body_template')
                            ->label('Template')
                            ->rows(14)
                            ->columnSpanFull()
                            ->placeholder(json_encode([
                                'model_name' => '{{model_name}}',
                                'image' => '{{image}}',
                                'prompt' => '{{prompt}}',
                                'duration' => '{{duration}}',
                                'mode' => '{{mode}}',
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                            ->helperText('Placeholders must match field names in the standard schema above. For nested structures, use normal JSON nesting: {"input": {"image": "{{image}}"}}')
                            ->formatStateUsing(fn ($state) => self::jsonFormatState($state))
                            ->dehydrateStateUsing(fn ($state) => self::jsonDehydrateState($state)),
                    ])
                    ->collapsible()
                    ->collapsed(false),

                Forms\Components\Section::make('Response mapping')
                    ->description('Dot-notation paths to extract data from the vendor API response.')
                    ->schema([
                        Forms\Components\Textarea::make('response_mapping')
                            ->label('Mapping')
                            ->rows(12)
                            ->required()
                            ->columnSpanFull()
                            ->placeholder(json_encode([
                                'task_id' => 'data.task_id',
                                'status_path' => 'data.task_status',
                                'result_path' => 'data.task_result',
                                'asset_array_path' => 'data.task_result.videos',
                                'asset_url_path' => 'url',
                                'asset_id_path' => 'id',
                                'error_code_path' => 'code',
                                'error_message_path' => 'data.task_status_msg',
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                            ->helperText('Three sections: generation (task_id extraction + error checking), polling (URL template + status/progress paths), result (asset extraction paths). Use dot notation for nested fields.')
                            ->formatStateUsing(fn ($state) => self::jsonFormatState($state))
                            ->dehydrateStateUsing(fn ($state) => self::jsonDehydrateState($state)),
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
                            ->minValue(0)
                            ->helperText('Lower number = higher priority. Used for fallback ordering when multiple configs exist.'),
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
                    ->label('AI feature')
                    ->searchable()
                    ->sortable(),
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
                    ->label('Auth type')
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
                    ->label('Created by')
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
                    ->label('Active status'),
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
        return [];
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