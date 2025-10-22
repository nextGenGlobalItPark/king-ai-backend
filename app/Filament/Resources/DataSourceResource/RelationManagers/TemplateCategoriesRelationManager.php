<?php

namespace App\Filament\Resources\DataSourceResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TemplateCategoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'templateCategories';

    protected static ?string $recordTitleAttribute = 'template_category_name';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('template_category_id')
                    ->label('Template Category')
                    ->relationship('templateCategory', 'template_category_name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->dehydrated(false),  // Auto-set from owner
                Forms\Components\Select::make('created_by')
                    ->label('Created By')
                    ->relationship('createdBy', 'name')
                    ->searchable()
                    ->preload(),
                Forms\Components\TextInput::make('source_db_id')
                    ->label('Source DB ID')
                    ->numeric()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('templateCategory.template_category_name')
                    ->label('Template Category')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->label('Created By')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('pivot.source_db_id')
                    ->label('Source DB ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('pivot.created_at')
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
        return 'Template Categories';
    }
}