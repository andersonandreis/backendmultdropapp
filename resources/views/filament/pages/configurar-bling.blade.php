<x-filament-panels::page>
    @if (! $supplierId)
        <x-filament::section>
            <x-slot name="heading">Acesso indisponível</x-slot>
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Seu usuário não está vinculado a nenhum fornecedor. Entre em contato com o suporte para liberar o acesso.
            </p>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Status da conexão</x-slot>
            <x-slot name="description">
                Conecte sua conta no Bling ERP para sincronizar produtos, estoque e pedidos.
            </x-slot>

            <div class="flex items-center gap-3">
                <x-filament::badge :color="$this->getStatusColor()" size="lg">
                    {{ $this->getStatusLabel() }}
                </x-filament::badge>

                @if ($erpAccount)
                    <span class="text-sm text-gray-600 dark:text-gray-300">
                        Conta: <strong>{{ $erpAccount->account_name ?: 'Bling ERP' }}</strong>
                    </span>
                @endif
            </div>

            @if ($erpAccount)
                <dl class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-medium uppercase text-gray-500">Versão da API</dt>
                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                            {{ strtoupper($erpAccount->api_version ?? 'v3') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase text-gray-500">Token expira em</dt>
                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                            {{ $erpAccount->token_expires_at?->format('d/m/Y H:i') ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase text-gray-500">Última sincronização</dt>
                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                            {{ $erpAccount->last_sync_at?->diffForHumans() ?? 'Nunca' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase text-gray-500">Conectado em</dt>
                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                            {{ $erpAccount->created_at?->format('d/m/Y H:i') ?? '—' }}
                        </dd>
                    </div>
                </dl>
            @else
                <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">
                    Você ainda não conectou o Bling. Clique em <strong>Conectar minha conta Bling</strong> acima
                    para autorizar o acesso e começar a sincronizar.
                </p>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Como funciona</x-slot>

            <ol class="list-decimal space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                <li>Clique em <strong>Conectar minha conta Bling</strong>.</li>
                <li>Faça login no Bling com a conta da sua empresa.</li>
                <li>Autorize o acesso ao seu catálogo, estoque e pedidos.</li>
                <li>Você será redirecionado de volta para esta página com o status <em>Conectado</em>.</li>
            </ol>

            <p class="mt-4 text-xs text-gray-500">
                A integração usa OAuth oficial do Bling — não pedimos sua senha. Os tokens são renovados automaticamente.
            </p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
