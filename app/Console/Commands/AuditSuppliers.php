<?php

namespace App\Console\Commands;

use App\Models\DirectorySupplier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-130: Auditoria dos 784 fornecedores do diretorio (directory_suppliers).
 *
 * Faz varredura da tabela e sinaliza registros com problemas:
 *  - catalog_url com link Drive/pasta generica (sem produtos individuais)
 *  - categories vazio ou NULL (equivalente ao pedido category_ids do briefing)
 *  - Marcado como "so-contato" (contact_only sintetico: nenhum
 *    canal de contato preenchido - whatsapp, phone, email, site)
 *  - name (display_name no briefing) sem qualquer sinal de CNPJ
 *    associado nos campos description/notes/commercial_terms
 *
 * O comando escreve CSV em storage/app/audit-suppliers-YYYY-MM-DD.csv
 * (uma linha por fornecedor sinalizado), loga cada linha no channel
 * daily (Log::channel('daily')->info(...)) e imprime o sumario no
 * stdout.
 *
 * --fix: para fornecedores marcados como "so-contato" que nao tem
 * NENHUM canal de contato preenchido, define is_active=0 pra tirar do
 * diretorio publico.
 *
 * IMPORTANTE: o briefing SEL-130 fala em "suppliers" e "contact_only",
 * mas a tabela que tem 784 fornecedores e o modelo com esses campos e
 * a directory_suppliers (SEL-048). Este comando trabalha nessa tabela.
 * A tabela suppliers e operacional (dropshipping), com regras
 * completamente diferentes.
 */
class AuditSuppliers extends Command
{
    protected $signature = 'suppliers:audit {--fix : Aplica correcoes (is_active=0 em so-contato sem contato)}';

    protected $description = 'SEL-130: audita directory_suppliers e gera CSV + log com registros irregulares';

    /** Domainios/padroes que indicam catalog_url generico (pasta em vez de produto). */
    private const GENERIC_CATALOG_PATTERNS = [
        'drive.google.com/drive/folders',
        'drive.google.com/folder',
        'drive.google.com/open?id=',
        'onedrive.live.com',
        '1drv.ms',
        'dropbox.com/sh/',
        'dropbox.com/scl/fo/',
        'mega.nz/folder',
        'mediafire.com/folder',
        'wa.me/',
        'chat.whatsapp.com/',
    ];

    /**
     * Padroes que sinalizam presenca de CNPJ nos campos textuais.
     * CNPJ formatado (99.999.999/9999-99) ou 14 digitos consecutivos.
     */
    private const CNPJ_REGEX = '/(\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}|\d{14})/';

    public function handle(): int
    {
        if (!Schema::hasTable('directory_suppliers')) {
            $this->error('Tabela directory_suppliers nao existe. Rode as migrations SEL-048.');
            return self::FAILURE;
        }

        $fix = (bool) $this->option('fix');
        $date = date('Y-m-d');
        $csvPath = storage_path("app/audit-suppliers-{$date}.csv");
        $handle = fopen($csvPath, 'w');

        if ($handle === false) {
            $this->error("Falha abrindo {$csvPath} para escrita.");
            return self::FAILURE;
        }

        fputcsv($handle, [
            'id', 'slug', 'name', 'is_active', 'verified',
            'issue_catalog_generic', 'issue_categories_empty',
            'issue_no_contact', 'issue_no_cnpj_hint',
            'catalog_url', 'whatsapp', 'phone', 'email', 'site',
            'action',
        ]);

        $total = 0;
        $flagged = 0;
        $counters = [
            'catalog_generic'   => 0,
            'categories_empty'  => 0,
            'no_contact'        => 0,
            'no_cnpj_hint'      => 0,
            'deactivated'       => 0,
        ];

        DirectorySupplier::query()->orderBy('id')->chunkById(500, function ($chunk) use ($handle, $fix, &$total, &$flagged, &$counters) {
            foreach ($chunk as $supplier) {
                $total++;

                $catalogGeneric = $this->isCatalogGeneric($supplier->catalog_url);
                $categoriesEmpty = $this->areCategoriesEmpty($supplier->categories);
                $noContact = $this->hasNoContact($supplier);
                $noCnpjHint = $this->lacksCnpjHint($supplier);

                if (!($catalogGeneric || $categoriesEmpty || $noContact || $noCnpjHint)) {
                    continue;
                }

                $flagged++;
                if ($catalogGeneric)  { $counters['catalog_generic']++;  }
                if ($categoriesEmpty) { $counters['categories_empty']++; }
                if ($noContact)       { $counters['no_contact']++;       }
                if ($noCnpjHint)      { $counters['no_cnpj_hint']++;     }

                $action = 'flagged';

                // --fix: desativa fornecedores marcados como so-contato mas
                // sem NENHUM canal de contato preenchido (equivalente ao
                // "contact_only=1 sem WhatsApp/email" do briefing).
                if ($fix && $noContact && $supplier->is_active) {
                    $supplier->is_active = false;
                    $supplier->saveQuietly();
                    $counters['deactivated']++;
                    $action = 'deactivated';
                }

                $row = [
                    'id'                       => $supplier->id,
                    'slug'                     => (string) $supplier->slug,
                    'name'                     => (string) $supplier->name,
                    'is_active'                => (int) $supplier->is_active,
                    'verified'                 => (int) $supplier->verified,
                    'issue_catalog_generic'    => $catalogGeneric  ? 1 : 0,
                    'issue_categories_empty'   => $categoriesEmpty ? 1 : 0,
                    'issue_no_contact'         => $noContact       ? 1 : 0,
                    'issue_no_cnpj_hint'       => $noCnpjHint      ? 1 : 0,
                    'catalog_url'              => (string) $supplier->catalog_url,
                    'whatsapp'                 => (string) $supplier->whatsapp,
                    'phone'                    => (string) $supplier->phone,
                    'email'                    => (string) $supplier->email,
                    'site'                     => (string) $supplier->site,
                    'action'                   => $action,
                ];

                fputcsv($handle, array_values($row));
                Log::channel('daily')->info('suppliers:audit', $row);
            }
        });

        fclose($handle);

        $this->info("=== SEL-130 auditoria de fornecedores concluida ===");
        $this->line("Total varridos:            {$total}");
        $this->line("Sinalizados:               {$flagged}");
        $this->line("  catalog generico:        {$counters['catalog_generic']}");
        $this->line("  sem categoria:           {$counters['categories_empty']}");
        $this->line("  sem contato:             {$counters['no_contact']}");
        $this->line("  sem sinal de CNPJ:       {$counters['no_cnpj_hint']}");
        if ($fix) {
            $this->line("  desativados (--fix):     {$counters['deactivated']}");
        }
        $this->line("CSV: {$csvPath}");

        return self::SUCCESS;
    }

    /**
     * Detecta se o catalog_url e um link generico (pasta Drive, chat
     * WhatsApp) em vez de um catalogo real com produtos individuais.
     */
    private function isCatalogGeneric(?string $url): bool
    {
        if (empty($url)) {
            return false;
        }
        $normalized = strtolower($url);
        foreach (self::GENERIC_CATALOG_PATTERNS as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Categorias vazias ou NULL (adaptacao do "category_ids vazio/NULL"
     * do briefing — a coluna real e categories JSON array).
     */
    private function areCategoriesEmpty(mixed $categories): bool
    {
        if ($categories === null) {
            return true;
        }
        if (is_array($categories) && count($categories) === 0) {
            return true;
        }
        if (is_string($categories) && trim($categories) === '') {
            return true;
        }
        return false;
    }

    /**
     * Fornecedor "so-contato" sintetico: nenhum canal de contato
     * preenchido (whatsapp, phone, email, site). Equivale a um
     * contact_only=1 sem canal preenchido — nao ha como o cliente
     * chegar nele.
     */
    private function hasNoContact(DirectorySupplier $supplier): bool
    {
        $channels = [
            trim((string) $supplier->whatsapp),
            trim((string) $supplier->phone),
            trim((string) $supplier->email),
            trim((string) $supplier->site),
        ];
        foreach ($channels as $ch) {
            if ($ch !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Sem qualquer sinal textual de CNPJ nos campos description/notes/
     * commercial_terms (adaptacao do "display_name sem CNPJ vinculado"
     * — nao existe coluna document/cnpj em directory_suppliers).
     */
    private function lacksCnpjHint(DirectorySupplier $supplier): bool
    {
        $haystack = trim(
            (string) $supplier->description . ' ' .
            (string) $supplier->notes . ' ' .
            (string) $supplier->commercial_terms
        );
        if ($haystack === '') {
            return true;
        }
        return preg_match(self::CNPJ_REGEX, $haystack) !== 1;
    }
}
