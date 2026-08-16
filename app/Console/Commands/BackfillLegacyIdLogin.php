<?php

namespace App\Console\Commands;

use App\Models\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillLegacyIdLogin extends Command
{
    protected $signature = 'backfill:legacy-id-login
                            {--dry-run : Simulate without persisting changes}
                            {--force-all : Re-process clients that already have legacy_id_login}';

    protected $description = 'Backfill clients.legacy_id_login by matching users.email (primary) or document/cpf (fallback) against legacy.login table';

    public function handle(): int
    {
        $dryRun  = $this->option('dry-run');
        $forceAll = $this->option('force-all');

        $query = Client::with('user');

        if (! $forceAll) {
            $query->whereNull('legacy_id_login');
        }

        $total      = $query->count();
        $matchedEmail = 0;
        $matchedCpf   = 0;
        $unmatched    = 0;
        $skipped      = 0;

        if ($total === 0) {
            $this->info('No clients to process.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Processing %d client(s)%s...',
            $total,
            $dryRun ? ' [DRY-RUN]' : ''
        ));

        $query->chunkById(100, function ($clients) use (
            $dryRun,
            &$matchedEmail,
            &$matchedCpf,
            &$unmatched,
            &$skipped
        ) {
            foreach ($clients as $client) {
                // -- Primary match: email (lowercase + trim) -----------------
                $email = strtolower(trim($client->user->email ?? ''));

                if ($email && ! in_array($email, ['adm@gmail.com', 'cliente@gmail.com'])) {
                    $login = DB::connection('legacy')
                        ->table('login')
                        ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
                        ->orderBy('id', 'desc')   // newest wins when duplicates exist
                        ->first();

                    if ($login) {
                        $this->line(sprintf(
                            '  [EMAIL] client_id=%d email=%s -> legacy_id=%d',
                            $client->id, $email, $login->id
                        ));

                        if (! $dryRun) {
                            $client->update(['legacy_id_login' => $login->id]);
                        }

                        $matchedEmail++;
                        continue;
                    }
                }

                // -- Fallback: CPF/CNPJ (digits only) -----------------------
                $doc = preg_replace('/[^0-9]/', '', $client->document ?? '');

                if (strlen($doc) >= 11) {
                    $login = DB::connection('legacy')
                        ->table('login')
                        ->whereRaw("REGEXP_REPLACE(COALESCE(cpf_cnpj,''), '[^0-9]', '') = ?", [$doc])
                        ->orderBy('id', 'desc')
                        ->first();

                    if ($login) {
                        $this->line(sprintf(
                            '  [CPF] client_id=%d doc=%s -> legacy_id=%d (email_legado=%s)',
                            $client->id, $doc, $login->id, $login->email ?? ''
                        ));

                        if (! $dryRun) {
                            $client->update(['legacy_id_login' => $login->id]);
                        }

                        $matchedCpf++;
                        continue;
                    }
                }

                // -- No match ------------------------------------------------
                $this->warn(sprintf(
                    '  [UNMATCHED] client_id=%d email=%s doc=%s',
                    $client->id, $email ?: 'NO_EMAIL', $doc ?: 'NO_DOC'
                ));

                $unmatched++;
            }
        });

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Matched by email',   $matchedEmail],
                ['Matched by CPF/doc', $matchedCpf],
                ['Unmatched',          $unmatched],
                ['Skipped (no user)',  $skipped],
                ['Total processed',    $matchedEmail + $matchedCpf + $unmatched + $skipped],
            ]
        );

        if ($dryRun) {
            $this->warn('DRY-RUN: no changes were persisted.');
        }

        return self::SUCCESS;
    }
}
