# Introdução à Arquitetura HubAI

Bem-vindo à documentação oficial do **HubAI**. Este sistema foi arquitetado como uma aplicação SaaS B2B de Dropshipping operando no modelo Multi-tenant.

## 1. O que é o HubAI?
A ferramenta serve como ponte dupla: ela permite que **Fornecedores (Galpões)** cadastrem seus produtos físicos num acervo mestre, e permite que **Lojistas (Sellers Dropshippers)** assinem a plataforma para clonar esses produtos (com marcação de preço reversa via margem de lucro) diretamente para contas no Mercado Livre e Shopee.

---

## 2. A Barreira de Acessos (Isolamento de Painel)

A maior dúvida no HubAI é "Onde cada perfil clica?". Por questões de segurança, a plataforma dividiu os acessos em duas URLs físicas impossíveis de serem cruzadas:

### A) O Painel Central: `/admin`
* **Quem entra:** Super Admin (Você, dono da HubAI) e o Profile `supplier` (O Fornecedor que é dono das mercadorias).
* **O que faz:** Controle duro de notas fiscais de fábrica, aprovação de mercadoria física desembarcada (`Scan Shipment`), cadastro oficial da Caixa/Dimensões do item, filas de expedição (Imprimir etiquetas Meli/Shopee e colar no pacote), e conciliação de saldo do galpão via tela de Saques.

### B) O Painel do Vendedor: `/app`
* **Quem entra:** Lojistas, Dropshippers (Profile `client`).
* **Regra de Bloqueio:** O código obriga o Lojista a usar apenas esta interface limpa.
* **O que faz:** Paga mensalidades para você via PIX (Asaas). Navega no Acervo, Clona SKUS. Regula apenas a **Margem de Lucro (%**) que ele deseja ter sobre o custo do Fornecedor. Conecta as contas Oauth do Mercado Livre e monitora os pacotes que o Galpão `/admin` tá despachando pelo nome dele.

---

## 3. Requisitos para Hospedagem
Desenvolvido em Laravel 11 (Filament 3), rodando no **PHP 8.3**.
O deploy oficial deve ser feio no *CyberPanel* + OpenLiteSpeed para tirar proveito do Middleware `LScache` injetado no código, que impede a API de cair durante picos de sincronização de estoque de Sellers Simultâneos.

Para ambiente local Windows (XAMPP/WAMP), não esqueça de ativar a extensão `;extension=intl` no seu `php.ini` a fim de destravar as formatações monetárias do Filament em `R$`.
