<x-filament-widgets::widget>
    <x-filament::section class="bg-gradient-to-tr from-primary-500 to-amber-500 text-white"
        icon="heroicon-o-academic-cap" icon-color="white"
        heading="Parabéns pela sua venda! 🎉 Veja como funciona o fluxo HubAI:">

        <div class="py-4">
            <p class="text-sm font-medium mb-4">
                Identificamos que você está realizando suas primeiras vendas no sistema (Total de Vendas Atuais:
                {{ $orderCount }}).
                Para garantir que seu pacote chegue rápido ao cliente, siga este caminho lógico guiado:
            </p>

            <ul class="space-y-4">
                <li class="flex items-start">
                    <span
                        class="flex-shrink-0 h-6 w-6 rounded-full bg-white text-primary-600 flex items-center justify-center font-bold text-xs ring-2 ring-white">1</span>
                    <span class="ml-3 font-semibold text-white">Checagem de Pedido:
                        <span class="font-normal block text-sm mt-1 opactiy-90">O pedido importado do
                            MercadoLivre/Shopee caiu aqui no aba "Pedidos" do aplicativo. Clique em "Ver ou Editar" o
                            registro.</span>
                    </span>
                </li>

                <li class="flex items-start">
                    <span
                        class="flex-shrink-0 h-6 w-6 rounded-full bg-white text-primary-600 flex items-center justify-center font-bold text-xs ring-2 ring-white">2</span>
                    <span class="ml-3 font-semibold text-white">Pagamento do Galpão (Pix):
                        <span class="font-normal block text-sm mt-1 opactiy-90">Como a venda ocorreu, você já tem o
                            dinheiro garantido no Marketplace. Agora, dentro do pedido, clique no botão "Gerar Pix" para
                            pagar o custo do Produto de Fábrica + Frete (se houver). O pagamento cai na hora, 24 horas
                            por dia.</span>
                    </span>
                </li>

                <li class="flex items-start">
                    <span
                        class="flex-shrink-0 h-6 w-6 rounded-full bg-white text-primary-600 flex items-center justify-center font-bold text-xs ring-2 ring-white">3</span>
                    <span class="ml-3 font-semibold text-white">O Fornecedor Pega o Bastão:
                        <span class="font-normal block text-sm mt-1 opactiy-90">Com o status "Pago", o sistema avisa o
                            Fornecedor no painel dele. Ele separa fisicamente o item, imprime a Etiqueta
                            de Envio correta do marketplace automaticamente e aciona a transportadora.</span>
                    </span>
                </li>

                <li class="flex items-start">
                    <span
                        class="flex-shrink-0 h-6 w-6 rounded-full bg-white text-primary-600 flex items-center justify-center font-bold text-xs ring-2 ring-white">4</span>
                    <span class="ml-3 font-semibold text-white">Você só assiste (Notificações):
                        <span class="font-normal block text-sm mt-1 opactiy-90">Sempre que o Fornecedor imprimir a
                            etiqueta ou confirmar o item para envio, o painel do seu pedido mudara de status automaticamente
                            para "Enviado". Fique tranquilo, o prazo de envio e controlado rigorosamente pela
                            HubAI.</span>
                    </span>
                </li>
            </ul>

            <div class="mt-6 p-4 bg-black/20 rounded-lg text-sm border border-white/30 hidden md:block">
                <strong>Dica Pro:</strong> Você não precisa falar com o suporte nessas etapas, o sistema te envia
                alertas de cada movimentação se o sino ali em cima estiver acionado. Esta mensagem de Guia sumirá
                sozinha após a sua 5ª venda bem-sucedida!
            </div>
        </div>

    </x-filament::section>
</x-filament-widgets::widget>