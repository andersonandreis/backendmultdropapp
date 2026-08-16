<?php
$dir = __DIR__ . '/../../app/Filament/Resources/';
$files = glob($dir . '*.php');

$replacements = [
    "'Financial'" => "'Financeiro e Saldo'",
    "'Settings & Users'" => "'Configurações e Acessos'",
    "'Platform settings'" => "'Configurações Globais'",
    "'Fulfillment & Storage'" => "'Logística e Fulfillment'",
    "'Catalog & Orders'" => "'Catálogo e Pedidos'",
    "'Integrations'" => "'Integrações'",
];

foreach ($files as $file) {
    $content = file_get_contents($file);
    $newContent = str_replace(array_keys($replacements), array_values($replacements), $content);
    if ($content !== $newContent) {
        file_put_contents($file, $newContent);
    }
}
echo "Groups translated!\n";
