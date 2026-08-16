# TEST ML OAuth PKCE and Webhook HMAC Validation
Date: 2026-04-24
Agent: Pulsar (novohubai-integracoes)
Environment: Production api.hubai.io (66.94.100.155)

## Setup
- Added mercadolivre, openai, pagarme entries to config/services.php
- Ran php artisan config:cache and route:cache
- Created/deleted temporary test MarketplaceAccount (account_name=TESTE-OAUTH-*)

## 1. OAuth Redirect URL PKCE Parameters

Generated URL (via tinker MercadoLivreService::getAuthUrl):
https://auth.mercadolivre.com.br/authorization?response_type=code&client_id=7460452711550142&redirect_uri=https%3A%2F%2Fapi.hubai.io%2Foauth%2Fmercadolivre%2Fcallback&code_challenge=Wn0k1Ab6kzEm3fbODBpGXZRDbl_2VEkrwYoRGAvldwM&code_challenge_method=S256&state=1

Parameters verified:
- response_type=code: PRESENT
- client_id=7460452711550142: PRESENT
- redirect_uri=https://api.hubai.io/oauth/mercadolivre/callback: PRESENT
- code_challenge (non-empty SHA256): PRESENT
- code_challenge_method=S256: PRESENT
- state={account_id}: PRESENT

Verdict: PASS

## 2. Webhook HMAC Validation

Endpoint: POST https://api.hubai.io/api/webhooks/mercadolivre

Test A - Invalid signature (x-signature: ts=1234,v1=invalidsig):
HTTP Response: 401

Test B - No signature header at all:
HTTP Response: 401

Implementation: HMAC-SHA256 over ts/data_id using ML_SECRET_KEY.
Controller: app/Http/Controllers/Api/Webhooks/MercadoLivreWebhookController::isSignatureValid()

Verdict: PASS

## 3. refreshToken() Method

File: app/Services/MercadoLivreService.php
- Method exists: YES
- Uses appId from config mercadolivre.app_id / ML_APP_ID: YES
- Uses secretKey from config mercadolivre.secret_key / ML_SECRET_KEY: YES
- Tokens encrypted with Laravel encrypt/decrypt: YES
- Does NOT call ML API without ml_refresh_token present: YES

Verdict: PASS

## Summary Table

| Check                         | Result |
|-------------------------------|--------|
| Redirect URL valid            | YES    |
| PKCE params all present (6/6) | YES    |
| HMAC invalid signature -> 401 | YES    |
| HMAC missing signature -> 401 | YES    |
| refreshToken() exists         | YES    |
| Uses correct env vars         | YES    |
| Tokens encrypted at rest      | YES    |

## Fixes Applied

1. config/services.php: Added mercadolivre, openai, pagarme config blocks (were missing, causing TypeError on appId)
2. php artisan config:cache + route:cache re-run after update

Test performed by Pulsar - novohubai-integracoes agent
