<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TemplateResource\Pages;
use App\Models\Template;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
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
     * Parse comma-separated string into options array structure.
     * "720p, 1080p" → {"values": ["720p", "1080p"], "default": "720p"}
     */
    private static function parseOptionsString(?string $input): ?array
    {
        if (empty($input)) {
            return null;
        }

        $values = array_map('trim', explode(',', $input));
        $values = array_filter($values, fn($v) => $v !== '');

        if (empty($values)) {
            return null;
        }

        return [
            'values' => array_values($values),
            'default' => $values[0],
        ];
    }

    /**
     * Convert options array back to comma-separated string for display.
     * {"values": ["720p", "1080p"], "default": "720p"} → "720p, 1080p"
     */
    private static function optionsToString($state): ?string
    {
        if (is_array($state) && isset($state['values'])) {
            return implode(', ', $state['values']);
        }
        if (is_string($state)) {
            return $state;
        }
        return null;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // ===== BASIC INFO =====
                Forms\Components\Section::make('Basic info')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g., Heart Gesture'),

                        Forms\Components\TextInput::make('credit_cost')
                            ->label('Credit cost')
                            ->numeric()
                            ->default(5)
                            ->minValue(0)
                            ->required(),

                        Forms\Components\Select::make('ai_feature_id')
                            ->label('AI feature')
                            ->relationship('aiFeature', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->helperText('The vendor/data source is determined by the AI feature\'s active data source.'),

                        Forms\Components\Select::make('templateCategories')
                            ->label('Categories')
                            ->relationship('templateCategories', 'template_category_name')
                            ->multiple()
                            ->required()
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                Forms\Components\TextInput::make('template_category_name')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('template_category_slug')
                                    ->required()
                                    ->maxLength(255),
                            ]),

                        Forms\Components\TextInput::make('sequence')
                            ->label('Display order')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->helperText('Lower numbers appear first'),

                        Forms\Components\Select::make('state')
                            ->options([
                                'test' => 'Test',
                                'live' => 'Live',
                            ])
                            ->default('test')
                            ->required(),
                    ])
                    ->columns(2),

                // ===== TEMPLATE CONTENTS (existing uploads) =====
                Forms\Components\Section::make('Template contents')
                    ->description('Upload sample images and preview videos. These can be referenced by input fields below as sample images.')
                    ->schema([
                        Forms\Components\Repeater::make('templateContents')
                            ->relationship()
                            ->schema([
                                Forms\Components\FileUpload::make('template_content')
                                    ->label('File')
                                    ->disk('public')
                                    ->directory('templates')
                                    ->required()
                                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/quicktime', 'video/webm'])
                                    ->maxSize(102400)
                                    ->columnSpan(1),

                                Forms\Components\Select::make('template_content_type')
                                    ->label('Type')
                                    ->options([
                                        'image' => 'Image',
                                        'video' => 'Video',
                                        'cover' => 'Cover',
                                    ])
                                    ->required()
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('text')
                                    ->label('Caption / description')
                                    ->maxLength(255)
                                    ->placeholder('e.g., Left person sample')
                                    ->columnSpan(1),
                            ])
                            ->columns(3)
                            ->itemLabel(fn (array $state): ?string =>
                                '#' . ($state['id'] ?? '?') . ' — ' . ($state['template_content_type'] ?? 'new') .
                                ($state['text'] ? ' — "' . $state['text'] . '"' : '')
                            )
                            ->collapsible()
                            ->cloneable()
                            ->defaultItems(0)
                            ->addActionLabel('Add content')
                            ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                                $data['created_by'] = auth()->id();
                                return $data;
                            }),
                    ])
                    ->collapsible(),

                // ===== TEMPLATE DETAILS (NEW — metadata.description, output_type, preview) =====
                Forms\Components\Section::make('Template details')
                    ->description('User-facing description and output configuration. Shown on the template detail screen in the app.')
                    ->schema([
                        Forms\Components\Textarea::make('metadata.description')
                            ->label('Description')
                            ->rows(2)
                            ->placeholder('Two people form a heart shape with their hands in a short video')
                            ->helperText('Short description shown to the end user')
                            ->columnSpanFull(),

                        Forms\Components\Select::make('metadata.output_type')
                            ->label('Output type')
                            ->options([
                                'video' => 'Video',
                                'image' => 'Image',
                            ])
                            ->default('video')
                            ->required(),

                        Forms\Components\TextInput::make('metadata.preview_video_content_index')
                            ->label('Preview video (content index)')
                            ->numeric()
                            ->placeholder('e.g., 2')
                            ->helperText('Index of the template content to use as preview video (0-based). Leave empty if no preview.'),
                    ])
                    ->columns(2)
                    ->collapsible(),

                // ===== OUTPUT OPTIONS (NEW — metadata.options) =====
                Forms\Components\Section::make('Output options')
                    ->description('Quality and format choices. Comma-separated values — first value is the default. Single value = static label, multiple = picker in the app.')
                    ->schema([
                        Forms\Components\TextInput::make('metadata.options.resolution')
                            ->label('Resolution')
                            ->placeholder('720p, 1080p')
                            ->helperText('Comma-separated. First = default.')
                            ->formatStateUsing(fn ($state) => self::optionsToString($state))
                            ->dehydrateStateUsing(fn ($state) => self::parseOptionsString($state)),

                        Forms\Components\TextInput::make('metadata.options.duration')
                            ->label('Duration')
                            ->placeholder('5s, 10s')
                            ->formatStateUsing(fn ($state) => self::optionsToString($state))
                            ->dehydrateStateUsing(fn ($state) => self::parseOptionsString($state)),

                        Forms\Components\TextInput::make('metadata.options.aspect_ratio')
                            ->label('Aspect ratio')
                            ->placeholder('16:9, 9:16, 1:1')
                            ->formatStateUsing(fn ($state) => self::optionsToString($state))
                            ->dehydrateStateUsing(fn ($state) => self::parseOptionsString($state)),

                        Forms\Components\TextInput::make('metadata.options.mode')
                            ->label('Mode')
                            ->placeholder('standard, pro')
                            ->formatStateUsing(fn ($state) => self::optionsToString($state))
                            ->dehydrateStateUsing(fn ($state) => self::parseOptionsString($state)),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(fn (string $operation) => $operation === 'edit'),

                // ===== INPUT FIELDS (NEW — metadata.guide.input_fields repeater) =====
                Forms\Components\Section::make('Input fields')
                    ->description('Define what the user needs to provide. Each field becomes a UI component in the app. The field name must match the key sent in the generate request input payload.')
                    ->schema([
                        Forms\Components\Repeater::make('metadata.guide.input_fields')
                            ->label('')
                            ->schema([
                                Forms\Components\Grid::make(3)
                                    ->schema([
                                        Forms\Components\TextInput::make('name')
                                            ->label('Field name')
                                            ->required()
                                            ->placeholder('image')
                                            ->helperText('Must match the generate payload key'),

                                        Forms\Components\Select::make('type')
                                            ->label('Type')
                                            ->options([
                                                'image' => 'Image',
                                                'text' => 'Text',
                                            ])
                                            ->required()
                                            ->live(),

                                        Forms\Components\TextInput::make('label')
                                            ->label('Label')
                                            ->required()
                                            ->placeholder('Left Person'),
                                    ]),

                                Forms\Components\Grid::make(2)
                                    ->schema([
                                        Forms\Components\Toggle::make('required')
                                            ->label('Required')
                                            ->default(true),

                                        // Image-specific: sample content reference
                                        Forms\Components\TextInput::make('sample_content_index')
                                            ->label('Sample image (content index)')
                                            ->numeric()
                                            ->placeholder('0')
                                            ->helperText('Index of the template content above to show as sample (0-based)')
                                            ->visible(fn (Get $get) => $get('type') === 'image'),

                                        // Text-specific: placeholder
                                        Forms\Components\TextInput::make('placeholder')
                                            ->label('Placeholder text')
                                            ->placeholder('Describe the motion you want...')
                                            ->visible(fn (Get $get) => $get('type') === 'text'),
                                    ]),

                                Forms\Components\Grid::make(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('tips')
                                            ->label('Tips')
                                            ->placeholder('Half-body or full-body photo, facing right'),

                                        // Text-specific: max length
                                        Forms\Components\TextInput::make('max_length')
                                            ->label('Max length')
                                            ->numeric()
                                            ->placeholder('2500')
                                            ->visible(fn (Get $get) => $get('type') === 'text'),
                                    ]),
                            ])
                            ->itemLabel(fn (array $state): ?string =>
                                ($state['label'] ?? 'New field') .
                                ' (' . ($state['type'] ?? '?') . ')' .
                                (($state['required'] ?? false) ? ' — required' : ' — optional')
                            )
                            ->collapsible()
                            ->cloneable()
                            ->defaultItems(0)
                            ->addActionLabel('Add input field'),
                    ])
                    ->collapsible(),

                // ===== USER GUIDE (NEW — metadata.guide notes/limitations) =====
                Forms\Components\Section::make('User guide')
                    ->description('Helpful tips and known limitations shown on the template detail screen.')
                    ->schema([
                        Forms\Components\TextInput::make('metadata.guide.recommended_image_type')
                            ->label('Recommended image type')
                            ->placeholder('Single person per photo')
                            ->helperText('Brief description of what kind of images work best')
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('metadata.guide.notes')
                            ->label('Notes')
                            ->rows(2)
                            ->placeholder('Both photos should have similar lighting and background for best results.')
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('metadata.guide.limitations')
                            ->label('Limitations')
                            ->rows(2)
                            ->placeholder('There is a low probability that an extra person is generated.')
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(fn (string $operation) => $operation === 'edit'),

                // ===== SETTINGS =====
                Forms\Components\Section::make('Settings')
                    ->schema([
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Only active templates are visible to users'),
                    ])
                    ->columns(1),
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
                    ->label('AI feature')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('success'),

                Tables\Columns\TextColumn::make('metadata.output_type')
                    ->label('Output')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'video' => 'info',
                        'image' => 'warning',
                        default => 'gray',
                    })
                    ->default('—'),

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
                    ->label('Created by')
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
                    ->label('AI feature')
                    ->relationship('aiFeature', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('state')
                    ->options([
                        'test' => 'Test',
                        'live' => 'Live',
                    ]),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active status')
                    ->placeholder('All')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),
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
        return [];
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
            ->with(['templateCategories', 'aiFeature', 'createdBy', 'templateContents']);
    }
}
