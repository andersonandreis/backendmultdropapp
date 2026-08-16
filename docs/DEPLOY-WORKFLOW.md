# Deploy Workflow — api.hubai.io

**Data:** 2026-04-24  
**Servidor:** 66.94.100.155 (ols.hubai.club)

---

## Fluxo Atual (Servidor como Fonte de Verdade)

```
Editar arquivos no servidor
         ↓
   prod-sync "mensagem"
         ↓
  git add -A → commit → push
         ↓
  GitHub: ruanipanema2-collab/hubai-plataforma (branch main)
         ↓
  CyberPanel serve diretamente do diretório → zero deploy adicional
```

O servidor é o **source of truth**. Não há CI/CD automático — o push para o GitHub é apenas backup/histórico.

---

## Quando Usar o post-deploy.sh

O script `/home/api.hubai.io/public_html/post-deploy.sh` deve ser rodado **manualmente** após:

| Situação | Motivo |
|---|---|
| Novas migrations | Criar tabelas/colunas no banco |
| Novos pacotes composer | Autoloader precisa ser atualizado |
| Mudanças em config/*.php | Cache de configuração precisa rebuild |
| Mudanças em routes/*.php | Cache de rotas precisa rebuild |
| Mudanças em views Blade | Cache de views |
| Atualizações do Filament | Resources/panels precisam ser re-otimizados |

**NÃO rodar automaticamente** via webhook, cron ou CI/CD.

---

## Como Usar o Script

```bash
# Via SSH no servidor
sshpass -p '...' ssh root@66.94.100.155

# Navegar até o projeto
cd /home/api.hubai.io/public_html

# Executar post-deploy
bash post-deploy.sh
```

Ou diretamente:
```bash
sshpass -p '...' ssh root@66.94.100.155 \
  "cd /home/api.hubai.io/public_html && bash post-deploy.sh"
```

---

## Detalhes do Script

**Path:** `/home/api.hubai.io/public_html/post-deploy.sh`  
**Owner:** cyberpanel:cyberpanel  
**Permissões:** 755 (executável)

**O que o script faz (5 passos):**

1. `composer install --no-dev --optimize-autoloader` — instala dependências
2. `php artisan migrate --force` — aplica migrations pendentes (**sem fresh/rollback**)
3. `php artisan config:cache && route:cache && view:cache` — recacheia tudo
4. `php artisan filament:optimize` — otimiza painéis Filament
5. `php artisan cache:clear` — limpa cache de aplicação

---

## Segurança

- ✅ Script usa `/usr/local/lsws/lsphp82/bin/php` (PHP 8.2, não 8.1)
- ✅ `set -e` — para na primeira falha
- ❌ **NUNCA** usar: `migrate:fresh`, `migrate:refresh`, `migrate:rollback`, `db:wipe`
- ✅ Backup disponível: `/root/hubaiapp-backup-20260424-165241.sql.gz`

---

## PHP em Produção

| PHP | Path | Versão |
|---|---|---|
| ✅ Correto | `/usr/local/lsws/lsphp82/bin/php` | 8.2.29 |
| ❌ Não usar | `php` (sistema) | 8.1.2 |
| ❌ Não usar | `composer` direto | usa PHP 8.1 |
| ✅ Composer correto | `/usr/local/lsws/lsphp82/bin/php /usr/bin/composer` | com PHP 8.2 |

---

## Próximos Passos Opcionais

1. **Webhook GitHub → Servidor** (descartado em 2026-04-24 — servidor é source of truth)
2. **GitHub Actions** para validação automática de syntax/testes em PRs (opcional)
3. **Cron de backup** automático do banco (ex: `/root/backup-hubai.sh` agendado)
4. **Monitoramento** via UptimeRobot apontando para `https://api.hubai.io/api/health`

---

## Referências

- Script: `/home/api.hubai.io/public_html/post-deploy.sh`
- Diretório raiz: `/home/api.hubai.io/public_html/`
- GitHub: `https://github.com/ruanipanema2-collab/hubai-plataforma`
- prod-sync: `/usr/local/bin/prod-sync`
