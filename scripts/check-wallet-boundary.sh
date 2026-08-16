#!/usr/bin/env bash
# MUL-363: fronteira do nucleo financeiro.
# So o WalletLedger (e as cascas do ClientWalletService) escrevem em
# client_supplier_transactions / client_supplier_balances.
#
# Os arquivos no ALLOWLIST sao os 14 bypasses historicos mapeados na MUL-362 —
# a lista SO PODE ENCOLHER (fases 1-3 da MUL-363 migram cada um). Arquivo novo
# escrevendo no ledger = commit barrado.
#
# Uso: bash scripts/check-wallet-boundary.sh   (exit 1 = violacao)
# Hook: ln -sf ../../scripts/check-wallet-boundary.sh .git/hooks/pre-commit

set -u
cd "$(dirname "$0")/.."

ALLOWLIST=(
  "app/Services/Financial/Ledger/WalletLedger.php"          # nucleo — o unico escritor legitimo
  # --- bypasses historicos (MUL-362), migrados nas fases da MUL-363 ---
  # Fase 1 (11/08): AutoPayService — migrado
  # Fase 2 (11/08): WalletController — migrado
  # Fase 3 (11/08): ManualOrderController, SupplierAdminPanelController,
  #                 OrderPaymentService, AuditUnpaidOrdersJob — migrados
  # --- importadores do legado, DESATIVADOS desde FOR-038 (2026-06-27); morrem com o legado ---
  "app/Console/Commands/ImportLegacyHistory.php"
  "app/Console/Commands/ReconcileLegacyBalances.php"
  "app/Console/Commands/SyncLegacyFinance.php"
)

PATTERN='ClientSupplierTransaction::create|ClientSupplierTransaction::insert|DB::table\((["'"'"'])client_supplier_transactions\1\)->insert|->update\(\[.?.?balance'

violations=0
while IFS= read -r hit; do
  file="${hit%%:*}"
  allowed=0
  for a in "${ALLOWLIST[@]}"; do
    [ "$file" = "$a" ] && allowed=1 && break
  done
  if [ "$allowed" -eq 0 ]; then
    echo "VIOLACAO da fronteira do ledger: $hit"
    violations=$((violations+1))
  fi
done < <(grep -rnE "$PATTERN" app/ --include="*.php" | grep -v "app/Models/")

if [ "$violations" -gt 0 ]; then
  echo ""
  echo "$violations escrita(s) no ledger fora do WalletLedger."
  echo "Regra 31 do CLAUDE.md: todo credito/debito passa pelo nucleo (MUL-363)."
  exit 1
fi

echo "check-wallet-boundary: OK (nenhuma escrita fora do nucleo alem dos bypasses historicos)"
exit 0
