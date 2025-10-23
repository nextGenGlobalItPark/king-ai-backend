<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TemplateResource\Pages;
use App\Models\Template;
use App\Models\TemplateCategory;
use App\Models\AiFeature;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\ForceDeleteAction;
use Filament\Tables\Actions\RestoreAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\ForceDeleteBulkAction;
use Filament\Tables\Actions\RestoreBulkAction;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TemplateResource extends Resource
{
    protected static ?string $model = Template::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Templates';

    protected static ?string $pluralLabel = 'Templates';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Template Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('template_category_id')
                            ->label('Template Category')
                            ->options(TemplateCategory::pluck('template_category_name', 'id'))
                            ->required()
                            ->searchable(),
                        Forms\Components\Select::make('ai_feature_id')
                            ->label('AI Feature')
                            ->options(AiFeature::pluck('name', 'id'))
                            ->required()
                            ->searchable(),
                        Forms\Components\TextInput::make('credit_cost')
                            ->label('Credit Cost')
                            ->numeric()
                            ->minValue(0)
                            ->nullable(),
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
                            ->default(0)
                            ->minValue(0),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Template Contents')
                    ->schema([
                        Repeater::make('templateContents')
                            ->relationship('templateContents')  // Auto-load and sync existing data
                            ->label('Contents')
                            ->schema([
                                Select::make('template_content_type')
                                    ->label('Type')
                                    ->options([
                                        'image' => 'Image',
                                        'video' => 'Video',
                                        'cover' => 'Cover',
                                    ])
                                    ->required(),
                                FileUpload::make('template_content')
                                    ->label('Content File')
                                    ->acceptedFileTypes([
                                        'image/jpeg',
                                        'image/png',
                                        'image/gif',
                                        'video/mp4',
                                        'video/webm',
                                        'video/ogg',
                                    ])
                                    ->maxSize(102400)  // 100MB
                                    ->directory('templates/contents')
                                    ->visibility('public')
                                    ->required(),
                                Textarea::make('text')
                                    ->label('Text')
                                    ->rows(3)
                                    ->columnSpanFull(),
                            ])
                            ->columns(1)
                            ->defaultItems(1)
                            ->reorderable(),
                    ])
                    ->collapsible(),
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
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('templateCategory.template_category_name')
                    ->label('Category')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('aiFeature.name')
                    ->label('AI Feature')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('credit_cost')
                    ->label('Credit Cost')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->sortable(),
                TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'test' => 'warning',
                        'live' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('sequence')
                    ->label('Sequence')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('template_contents_count')
                    ->label('Contents Count')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => $state ?? 0),  // Fallback to 0 if null
                TextColumn::make('createdBy.name')
                    ->label('Created By')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->actions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make(),
                    ForceDeleteAction::make(),
                    RestoreAction::make(),
                ]),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
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
            'index' => Pages\ListTemplates::route('/'),
            'create' => Pages\CreateTemplate::route('/create'),
            'edit' => Pages\EditTemplate::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount('templateContents as template_contents_count')  // Alias the count attribute
            ->with(['templateCategory', 'aiFeature', 'createdBy', 'templateContents'])
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}