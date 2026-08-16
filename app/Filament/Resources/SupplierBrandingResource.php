<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierBrandingResource\Pages;
use App\Models\SupplierBranding;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** NOV-136 — Personalização visual da plataforma do supplier. */
class SupplierBrandingResource extends Resource
{
    protected static ?string $model = SupplierBranding::class;
    protected static ?string $slug = 'personalizacao';
    protected static ?string $modelLabel = 'Branding';
    protected static ?string $pluralModelLabel = 'Personalização';
    protected static ?string $navigationIcon = 'heroicon-o-paint-brush';
    protected static ?string $navigationGroup = 'Equipe & Acessos';
    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identidade')->schema([
                Forms\Components\Select::make('supplier_id')
                    ->relationship('supplier', 'company_name')->required()
                    ->visible(fn () => auth()->user()?->role === 'super_admin'),
                Forms\Components\TextInput::make('platform_name')->label('Nome da plataforma')
                    ->maxLength(100)->placeholder('Minha Loja'),
                Forms\Components\FileUpload::make('logo_url')->label('Logo')
                    ->image()->directory('supplier-branding')->disk('public')->maxSize(2048),
                Forms\Components\FileUpload::make('favicon_url')->label('Favicon')
                    ->image()->directory('supplier-branding')->disk('public')->maxSize(512),
            ])->columns(2),

            Forms\Components\Section::make('Cores')->schema([
                Forms\Components\ColorPicker::make('primary_color')->label('Cor primária')->default('#3b82f6'),
                Forms\Components\ColorPicker::make('secondary_color')->label('Cor secundária')->default('#1e40af'),
                Forms\Components\ColorPicker::make('accent_color')->label('Destaque (CTA)')->default('#f59e0b'),
                Forms\Components\ColorPicker::make('background_color')->label('Fundo')->default('#ffffff'),
                Forms\Components\ColorPicker::make('text_color')->label('Texto')->default('#111827'),
            ])->columns(3),

            Forms\Components\Section::make('Contato')->schema([
                Forms\Components\TextInput::make('contact_email')->email(),
                Forms\Components\TextInput::make('contact_phone'),
            ])->columns(2),

            Forms\Components\Section::make('Avançado')->schema([
                Forms\Components\Textarea::make('custom_css')->label('CSS customizado')->rows(6)
                    ->helperText('Aplicado no painel Lovable do tenant. Use com cuidado.'),
            ])->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('logo_url')->label('Logo')->circular(),
                Tables\Columns\TextColumn::make('platform_name')->searchable(),
                Tables\Columns\ColorColumn::make('primary_color')->label('Primária'),
                Tables\Columns\ColorColumn::make('accent_color')->label('CTA'),
                Tables\Columns\TextColumn::make('updated_at')->dateTime('d/m/Y')->label('Atualizado'),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSupplierBrandings::route('/'),
            'create' => Pages\CreateSupplierBranding::route('/create'),
            'edit'   => Pages\EditSupplierBranding::route('/{record}/edit'),
        ];
    }
}
