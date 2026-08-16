<?php

namespace App\Filament\App\Pages;

use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Hash;

class Profile extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon    = 'heroicon-o-user-circle';
    protected static ?string $navigationLabel   = 'Meu Perfil';
    protected static ?int $navigationSort = 8;
    protected static string  $view              = 'filament.app.pages.profile';

    public ?array $data = [];

    public function mount(): void
    {
        $user = auth()->user();
        $this->form->fill([
            'name'   => $user->name,
            'email'  => $user->email,
            'avatar' => $user->avatar ? [$user->avatar] : [],
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Foto de Perfil')
                    ->schema([
                        Forms\Components\FileUpload::make('avatar')
                            ->label('Foto')
                            ->image()
                            ->avatar()
                            ->disk('public')
                            ->directory('avatars')
                            ->maxSize(2048)
                            ->helperText('JPG, PNG ou GIF. Máximo 2MB.')
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Dados da Conta')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->label('E-mail')
                            ->email()
                            ->required()
                            ->unique('users', 'email', auth()->user())
                            ->maxLength(255),
                    ])->columns(2),

                Forms\Components\Section::make('Alterar Senha')
                    ->description('Deixe em branco para manter a senha atual.')
                    ->schema([
                        Forms\Components\TextInput::make('password')
                            ->label('Nova Senha')
                            ->password()
                            ->minLength(8)
                            ->dehydrated(fn ($state) => filled($state))
                            ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                            ->helperText('Mínimo 8 caracteres.')
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Meu Plano')
                    ->schema([
                        Forms\Components\Placeholder::make('plano_info')
                            ->label('')
                            ->content(function () {
                                $client = auth()->user()->client;
                                if (!$client) {
                                    return new \Illuminate\Support\HtmlString(
                                        '<p class=text-sm text-amber-600>Nenhum perfil de cliente vinculado à sua conta.</p>'
                                    );
                                }
                                $sub = $client->subscriptions()
                                    ->whereIn('status', ['active', 'trialing'])
                                    ->with('plan')
                                    ->latest()
                                    ->first();

                                if ($sub) {
                                    $preco = $sub->plan?->price_monthly
                                        ? ' — R$ ' . number_format((float) $sub->plan->price_monthly, 2, ',', '.')
                                        : '';
                                    $end = $sub->ends_at
                                        ? '<br><strong>Válido até:</strong> ' . \Carbon\Carbon::parse($sub->ends_at)->format('d/m/Y')
                                        : '';
                                    return new \Illuminate\Support\HtmlString(
                                        '<p class=text-sm><strong>Plano:</strong> ' . ($sub->plan?->name ?? '—') . $preco . '</p>' .
                                        '<p class=text-sm><strong>Status:</strong> ' . $sub->status . $end . '</p>'
                                    );
                                }
                                return new \Illuminate\Support\HtmlString(
                                    '<p class=text-sm text-amber-600>Nenhum plano ativo. Entre em contato com o suporte.</p>'
                                );
                            }),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $avatar = is_array($data['avatar'] ?? null) ? (reset($data['avatar']) ?: null) : ($data['avatar'] ?? null);

        auth()->user()->update(array_filter([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => $data['password'] ?? null,
            'avatar'   => $avatar,
        ], fn ($v) => $v !== null));

        Notification::make()
            ->title('Perfil atualizado com sucesso!')
            ->success()
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            \Filament\Actions\Action::make('save')
                ->label('Salvar alterações')
                ->submit('save'),
        ];
    }
}
