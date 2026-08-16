<x-filament-panels::page>
    <div class="space-y-4">
        @if(empty($this->mensagens))
            <div class="rounded-xl bg-white border border-gray-200 shadow-sm p-8 text-center text-gray-400">
                <x-heroicon-o-chat-bubble-left-right class="mx-auto w-12 h-12 mb-3 opacity-40" />
                <p>Nenhuma mensagem ainda. Clique em "Nova Mensagem" para começar.</p>
            </div>
        @else
            <div class="rounded-xl bg-white border border-gray-200 shadow-sm overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                        <tr>
                            <th class="px-4 py-3 text-left">Data</th>
                            <th class="px-4 py-3 text-left">Seller</th>
                            <th class="px-4 py-3 text-left">Assunto</th>
                            <th class="px-4 py-3 text-left">Direção</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($this->mensagens as $msg)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-gray-500">{{ \Carbon\Carbon::parse($msg->created_at)->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-3 font-medium text-gray-800">{{ $msg->seller_name ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ $msg->subject ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $msg->direction === 'outbound' ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700' }}">
                                        {{ $msg->direction === 'outbound' ? 'Enviada' : 'Recebida' }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>
