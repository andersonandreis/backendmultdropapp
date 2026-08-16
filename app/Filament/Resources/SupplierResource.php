<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierResource\Pages;
use App\Filament\Resources\SupplierResource\RelationManagers;
use App\Models\Supplier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;
    protected static ?string $slug = 'fornecedores';
    protected static ?string $modelLabel = 'Fornecedor';
    protected static ?string $pluralModelLabel = 'Fornecedores';

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';
    protected static ?string $navigationGroup = 'Cat\u00e1logo & Produtos';


    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }


    public static function canCreate(): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery();
        if (auth()->user()?->role === 'supplier') {
            $supplierId = auth()->user()->supplier?->id;
            if ($supplierId) {
                $query->where('id', $supplierId);
            }
        }
        return $query;
    }
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('Dados do Fornecedor')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Dados Principais')
                            ->schema([
                                Forms\Components\Select::make('user_id')
                                    ->label('Usuário Responsável')
                                    ->relationship('user', 'name')
                                    ->required()
                                    ->searchable(),
                                Forms\Components\Select::make('type')
                                    ->label('Tipo de Operação')
                                    ->options([
                                        'producer' => 'Produtor',
                                        'warehouse' => 'Galpão (Despacho)',
                                    ])
                                    ->required(),
                                Forms\Components\TextInput::make('company_name')
                                    ->label('Razão Social / Nome Interno')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('document')
                                    ->label('CNPJ / CPF')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('phone')
                                    ->label('WhatsApp / Telefone')
                                    ->tel(),
                                Forms\Components\Toggle::make('is_active')
                                    ->label('Ativo no Sistema')
                                    ->default(true),
                            ])->columns(2),

                        Forms\Components\Tabs\Tab::make('Fiscal / NF-e')
                            ->schema([
                                Forms\Components\TextInput::make('trade_name')
                                    ->label('Nome Fantasia')
                                    ->maxLength(255)
                                    ->placeholder('Ex: Multdrop Logística'),
                                Forms\Components\TextInput::make('ie')
                                    ->label('Inscrição Estadual (IE)')
                                    ->maxLength(20)
                                    ->placeholder('Ex: 1234567890')
                                    ->helperText('Obrigatório para emissão de NF-e no Bling como Contribuinte ICMS.'),
                                Forms\Components\Select::make('indicator_icms')
                                    ->label('Indicador ICMS')
                                    ->options([
                                        1 => '1 - Contribuinte ICMS',
                                        2 => '2 - Contribuinte Isento',
                                        9 => '9 - Não Contribuinte',
                                    ])
                                    ->default(1)
                                    ->required(),
                                Forms\Components\TextInput::make('address')
                                    ->label('Endereço (Logradouro)')
                                    ->maxLength(255)
                                    ->columnSpan(2),
                                Forms\Components\TextInput::make('address_number')
                                    ->label('Número')
                                    ->maxLength(20)
                                    ->placeholder('S/N'),
                                Forms\Components\TextInput::make('address_complement')
                                    ->label('Complemento')
                                    ->maxLength(100),
                                Forms\Components\TextInput::make('neighborhood')
                                    ->label('Bairro')
                                    ->maxLength(100),
                                Forms\Components\TextInput::make('city')
                                    ->label('Cidade')
                                    ->maxLength(100),
                                Forms\Components\TextInput::make('state')
                                    ->label('UF')
                                    ->maxLength(2)
                                    ->placeholder('SP'),
                                Forms\Components\TextInput::make('zipcode')
                                    ->label('CEP')
                                    ->maxLength(10)
                                    ->placeholder('00000-000'),
                            ])->columns(2),

                        Forms\Components\Tabs\Tab::make('Financeiro')
                            ->schema([
                                Forms\Components\Toggle::make('allows_direct_payment')
                                    ->label('Aceita Pagamento Direto (Saldo)')
                                    ->helperText('Permite que vendedores paguem pedidos usando o saldo de vendas.')
                                    ->default(false),
                                Forms\Components\TextInput::make('pix_fee')
                                    ->label('Taxa Administrativa Pix (%)')
                                    ->numeric()
                                    ->default(0)
                                    ->prefix('%'),
                                Forms\Components\Toggle::make('allows_direct_deposit')
                                    ->label('Aceita Depósito Direto em Conta')
                                    ->default(true),
                                Forms\Components\Select::make('pix_key_type')
                                    ->label('Tipo de Chave PIX')
                                    ->options([
                                        'cpf'       => 'CPF',
                                        'cnpj'      => 'CNPJ',
                                        'email'     => 'Email',
                                        'telefone'  => 'Telefone',
                                        'aleatoria' => 'Chave aleatória',
                                    ])
                                    ->nullable(),
                                Forms\Components\TextInput::make('pix_key')
                                    ->label('Chave PIX')
                                    ->nullable()
                                    ->maxLength(255),
                            ])->columns(2),

                        Forms\Components\Tabs\Tab::make('Operacional')
                            ->schema([
                                Forms\Components\Toggle::make('is_factory')
                                    ->label('É Fábrica/Indústria')
                                    ->default(false),
                                Forms\Components\Toggle::make('supports_meli_flex')
                                    ->label('Suporta Mercado Livre Flex')
                                    ->default(false),
                                Forms\Components\TextInput::make('flex_fee')
                                    ->label('Taxa Fixa Flex / Entrega')
                                    ->numeric()
                                    ->default(0)
                                    ->prefix('R$'),
                            ])->columns(2),

                        Forms\Components\Tabs\Tab::make('Branding / UI')
                            ->schema([
                                Forms\Components\TextInput::make('display_name')
                                    ->label('Nome de Exibição (Checkout/Catálogo)')
                                    ->placeholder('Ex: Hubai Logística Prime')
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('theme_color')
                                    ->label('Cor do Tema / Identidade')
                                    ->placeholder('Ex: from-blue-500 to-cyan-500'),
                                Forms\Components\FileUpload::make('logo')
                                    ->label('Logo do Fornecedor')
                                    ->image()
                                    ->directory('suppliers/logos'),
                                Forms\Components\Textarea::make('description')
                                    ->label('Descrição / Bio')
                                    ->columnSpanFull(),
                            ])->columns(2),
                    ])->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('legacy_id')
                    ->label('Dep. Legado')
                    ->placeholder('-')
                    ->sortable(),
                Tables\Columns\TextColumn::make('company_name')
                    ->label('Fornecedor')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('active_products_count')
                    ->label('Produtos')
                    ->counts('activeProducts')
                    ->sortable()
                    ->badge()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'producer' => 'Produtor',
                        'warehouse' => "Galp\u00e3o",
                        default => $state,
                    })
                    ->badge(),
                Tables\Columns\TextColumn::make('pix_key_type')
                    ->label('Tipo PIX')
                    ->placeholder('—')
                    ->formatStateUsing(fn(?string $state): string => match ($state) {
                        'cpf'       => 'CPF',
                        'cnpj'      => 'CNPJ',
                        'email'     => 'Email',
                        'telefone'  => 'Telefone',
                        'aleatoria' => 'Aleatória',
                        default     => $state ?? '—',
                    })
                    ->badge()
                    ->color('info')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('pix_key')
                    ->label('Chave PIX')
                    ->placeholder('Não cadastrada')
                    ->copyable()
                    ->copyMessage('Chave PIX copiada!')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Editar'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('Excluir'),
                ]),
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
            'index' => Pages\ListSuppliers::route('/'),
            'create' => Pages\CreateSupplier::route('/create'),
            'edit' => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }
}
